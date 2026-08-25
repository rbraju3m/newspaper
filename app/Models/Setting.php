<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'type', 'group'];

    private const CACHE_KEY = 'settings.all';

    /** Per-request memo: settings are read many times per page render. */
    private static ?array $memo = null;

    protected static function booted(): void
    {
        // Settings are read on every request; bust the cache on any write.
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    /** All settings as a key => cast-value map, cached. */
    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function all_(): array
    {
        return self::$memo ??= Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->get()
            ->mapWithKeys(fn (self $s) => [$s->key => $s->castValue()])
            ->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value,
             'type' => $type, 'group' => $group],
        );
    }

    private function castValue(): mixed
    {
        return match ($this->type) {
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $this->value,
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
