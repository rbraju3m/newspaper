<?php

namespace Database\Factories;

use App\Models\Page;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => BanglaContent::sentence(4),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'body' => BanglaContent::body(3),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
