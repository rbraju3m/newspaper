<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@newspaper.test'],
            [
                'name' => 'সিস্টেম অ্যাডমিন',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'status' => 'active',
                'email_verified_at' => now(),
                'designation' => 'প্রধান সম্পাদক',
            ],
        );

        User::updateOrCreate(
            ['email' => 'editor@newspaper.test'],
            [
                'name' => 'বার্তা সম্পাদক',
                'password' => Hash::make('password'),
                'role' => UserRole::Editor,
                'status' => 'active',
                'email_verified_at' => now(),
                'designation' => 'বার্তা সম্পাদক',
            ],
        );

        User::updateOrCreate(
            ['email' => 'reader@newspaper.test'],
            [
                'name' => 'সাধারণ পাঠক',
                'password' => Hash::make('password'),
                'role' => UserRole::Reader,
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        // A reporter pool so author bylines and /author/{slug} pages have data.
        if (User::where('role', UserRole::Reporter)->count() < 8) {
            User::factory()->count(8)->reporter()->create();
        }

        // Readers, for comments and engagement.
        if (User::where('role', UserRole::Reader)->count() < 25) {
            User::factory()->count(25)->create();
        }
    }
}
