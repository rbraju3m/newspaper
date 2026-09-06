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
            'designation_en' => 'Assistant Editor',
        ]);
    }

    /**
     * The two designations are picked as a **pair**, not independently.
     *
     * Drawing the English one from its own `randomElement` would give a
     * reporter "ক্রীড়া প্রতিবেদক" in Bangla and "Special Correspondent" in
     * English — two different jobs for one person, which is the kind of demo
     * data that looks fine until somebody reads both editions.
     */
    public function reporter(): static
    {
        $desks = [
            ['নিজস্ব প্রতিবেদক', 'Staff Correspondent'],
            ['ক্রীড়া প্রতিবেদক', 'Sports Correspondent'],
            ['বিশেষ প্রতিনিধি', 'Special Correspondent'],
        ];

        return $this->state(function () use ($desks) {
            [$bn, $en] = fake()->randomElement($desks);

            return [
                'role' => UserRole::Reporter,
                'designation' => $bn,
                'designation_en' => $en,
                'bio' => BanglaContent::sentence(16),
                'bio_en' => 'Writes for the paper from Dhaka.',
            ];
        });
    }
}
