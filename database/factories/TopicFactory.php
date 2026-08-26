<?php

namespace Database\Factories;

use App\Models\Topic;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => BanglaContent::sentence(3),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'description' => BanglaContent::sentence(14),
            'color' => fake()->hexColor(),
            'is_active' => true,
            'is_trending' => false,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    public function trending(): static
    {
        return $this->state(fn () => ['is_trending' => true]);
    }
}
