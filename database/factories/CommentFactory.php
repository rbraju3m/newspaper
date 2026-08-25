<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'user_id' => User::factory(),
            'body' => BanglaContent::sentence(fake()->numberBetween(8, 25)),
            'status' => CommentStatus::Approved,
            'ip' => fake()->ipv4(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => CommentStatus::Pending]);
    }
}
