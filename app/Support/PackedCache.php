<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * A cache entry stored compressed.
 *
 * Two of this application's cached payloads are whole Eloquent graphs rather
 * than values: the assembled front page and the nav taxonomy. Serialized, they
 * are 555 KB and 106 KB — and `CACHE_STORE` is the database, so that is
 * 660 KB pulled out of MySQL on **every** request to the site, warm or cold.
 * The bulk of it is not content. It is the shape Eloquent serializes in:
 * `original` repeating `attributes` verbatim on every model, and the same
 * twenty property names on each of a few hundred objects.
 *
 * That shape compresses about as well as anything can. The front page goes to
 * 41 KB, a factor of thirteen, and it is measurably *cheaper* to read back —
 * 6.4 ms against 7.5 ms — because inflating 41 KB and parsing the result beats
 * parsing 555 KB of serialize text. Smaller and faster, so there is no trade
 * being made here beyond the compress itself, which happens once per TTL.
 *
 * Three things worth knowing before using it elsewhere:
 *
 * - **The stored form is base64, not raw zlib.** `cache.value` is a
 *   `mediumtext` in `utf8mb4`, and compressed output is binary — MySQL rejects
 *   the invalid sequences outright. Base64 costs a third of the saving back
 *   and is still thirteen times smaller than storing it plain.
 * - **`serializable_classes` still applies.** The value Laravel stores is a
 *   string, so its own guard sees nothing to allow; the real unserialize
 *   happens here and reads the same config key, so there remains one list.
 * - **It only pays on large graphs.** Below a few KB, compressing and
 *   base64-ing a payload makes it bigger. `layout.trending` is 112 bytes and
 *   is deliberately left on plain `Cache::remember`.
 */
class PackedCache
{
    /**
     * Compression level. 6 gives 13.3x on the front page for 10 ms; 9 gives
     * 14.0x for 17.6 ms. The extra 5% is not worth 70% more CPU on the request
     * unlucky enough to rebuild.
     */
    private const LEVEL = 6;

    public static function remember(string $key, DateTimeInterface|int $ttl, Closure $build): mixed
    {
        $packed = Cache::get($key);

        if (is_string($packed)) {
            $hit = self::unpack($packed);

            if ($hit !== []) {
                return $hit['value'];
            }
        }

        $value = $build();

        Cache::put($key, self::pack($value), $ttl);

        return $value;
    }

    private static function pack(mixed $value): string
    {
        // Wrapped so that a legitimately cached null is still distinguishable
        // from a miss on the way back out.
        return base64_encode(gzcompress(serialize(['value' => $value]), self::LEVEL));
    }

    /**
     * @return array{value: mixed}|array{} the empty array meaning "treat as a miss"
     */
    private static function unpack(string $packed): array
    {
        // A truncated or half-written row must cost a rebuild, never the page.
        // Laravel's error handler promotes warnings to exceptions, so the
        // suppression on the two functions that warn is load-bearing.
        $raw = base64_decode($packed, strict: true);

        if ($raw === false) {
            return [];
        }

        $inflated = @gzuncompress($raw);

        if ($inflated === false) {
            return [];
        }

        // Deliberately not caught: a class missing from the allow-list comes
        // back as __PHP_Incomplete_Class and fails loudly at first use, which
        // is what config/cache.php says it should do. Rebuilding silently
        // every request would hide exactly that drift.
        $value = @unserialize($inflated, [
            'allowed_classes' => config('cache.serializable_classes'),
        ]);

        return is_array($value) && array_key_exists('value', $value) ? $value : [];
    }
}
