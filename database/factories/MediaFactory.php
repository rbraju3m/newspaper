<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        $dir = 'uploads/'.now()->format('Y/m');
        $name = fake()->unique()->lexify('????????????????????????');

        return [
            'user_id' => User::factory(),
            'disk' => 'public',
            'path' => $dir.'/'.$name.'.jpg',
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(80_000, 900_000),
            'width' => 2400,
            'height' => 1350,
            // The ladder ImageService writes. Keys must stay `w<width>`:
            // Media::srcset() parses the width back out of the key.
            'conversions' => [
                'w320' => $dir.'/'.$name.'-w320.webp',
                'w640' => $dir.'/'.$name.'-w640.webp',
                'w768' => $dir.'/'.$name.'-w768.webp',
                'w960' => $dir.'/'.$name.'-w960.webp',
                'w1600' => $dir.'/'.$name.'-w1600.webp',
                'thumb' => $dir.'/'.$name.'-thumb.webp',
            ],
            'alt' => null,
            'caption' => null,
            'credit' => null,
        ];
    }

    /** A source too small to have produced the upper rungs. */
    public function small(): static
    {
        return $this->state(function (array $attributes) {
            $conversions = $attributes['conversions'];

            return [
                'width' => 400,
                'height' => 225,
                'conversions' => [
                    'w320' => $conversions['w320'],
                    'thumb' => $conversions['thumb'],
                ],
            ];
        });
    }

    /** An upload that never produced derivatives — an SVG, or a failed convert. */
    public function withoutConversions(): static
    {
        return $this->state(fn () => ['conversions' => []]);
    }
}
