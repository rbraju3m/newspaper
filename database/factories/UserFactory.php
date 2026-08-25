<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => BanglaContent::personName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Reader,
            'status' => 'active',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function editor(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Editor,
            'designation' => 'সহকারী সম্পাদক',
        ]);
    }

    public function reporter(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Reporter,
            'designation' => fake()->randomElement(['নিজস্ব প্রতিবেদক', 'ক্রীড়া প্রতিবেদক', 'বিশেষ প্রতিনিধি']),
            'bio' => BanglaContent::sentence(16),
        ]);
    }
}
