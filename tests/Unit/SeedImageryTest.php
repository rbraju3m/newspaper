<?php

namespace Tests\Unit;

use Database\Seeders\Support\SeedImagery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `SeedImagery`'s deterministic RNG.
 *
 * The seeders lean on this being *reproducible*: an issue redrawn on the same
 * date has to come out the same paper, or a re-seed quietly changes every
 * thumbnail on the site. What that rests on is the seed mixing being exact
 * 32-bit arithmetic, and PHP has no unsigned int to do it in — `$seed * MIX`
 * is a 64-bit product that overflows to float for roughly one crc32 in five,
 * dropping the very low bits the mask exists to keep and raising a PHP 8.4
 * deprecation on the way past.
 *
 * No database and no GD: this is the arithmetic, pinned against values
 * computed with arbitrary precision.
 */
class SeedImageryTest extends TestCase
{
    private function stateOf(int $seed): int
    {
        $imagery = new SeedImagery($seed);

        return (new \ReflectionProperty(SeedImagery::class, 'state'))->getValue($imagery);
    }

    /** @return array<string, array{int, int}> */
    public static function seeds(): array
    {
        return [
            // The mixing is `(seed * 2654435761) mod 2^32`, floor 1.
            'a seed that fits'                 => [1445730689, 2235734833],
            'the largest that fits'            => [3472328297, 3325726617],
            'crc32 of a section plate'         => [2292072337, 1738308673],
            'crc32 that overflows the product' => [4205858786, 1329007938],
            'every bit set'                    => [4294967295, 1640531535],
            'the sign bit alone'               => [2147483648, 2147483648],
            // A zero state would make the xorshift emit zero for ever.
            'zero, which must not stay zero'   => [0, 1],
        ];
    }

    #[DataProvider('seeds')]
    public function test_the_seed_is_mixed_in_exact_32_bit_arithmetic(int $seed, int $expected): void
    {
        $this->assertSame($expected, $this->stateOf($seed));
    }

    /**
     * The deprecation is the visible half of the bug and the reason it was
     * found at all: it is only a notice on a cold `db:seed`, but it is fatal
     * anywhere deprecations are promoted to exceptions, which aborted a
     * `migrate:fresh --seed` partway through the plate library.
     */
    public function test_no_seed_raises_a_precision_deprecation(): void
    {
        $raised = [];

        set_error_handler(function (int $level, string $message) use (&$raised) {
            $raised[] = $message;

            return true;
        }, E_DEPRECATED | E_WARNING);

        try {
            foreach (self::seeds() as [$seed, $ignored]) {
                $this->stateOf($seed);
            }

            // Well past the point the 64-bit product leaves int range.
            foreach (range(0, 400) as $n) {
                $this->stateOf(crc32('probe'.$n));
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised);
    }

    public function test_the_state_never_leaves_the_32_bit_range(): void
    {
        foreach (range(0, 200) as $n) {
            $state = $this->stateOf(crc32('rung'.$n));

            $this->assertGreaterThan(0, $state, 'A zero state makes the xorshift emit zero for ever.');
            $this->assertLessThanOrEqual(0xFFFFFFFF, $state);
        }
    }

    /** What the seeders actually rely on: same seed, same picture. */
    public function test_an_overflowing_seed_still_draws_the_same_image_twice(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('GD is not available.');
        }

        $render = function (): string {
            $image = (new SeedImagery(4205858786))->photo('#0E7C66', 120, 80);

            ob_start();
            imagepng($image);
            imagedestroy($image);

            return ob_get_clean();
        };

        $this->assertSame($render(), $render());
    }
}
