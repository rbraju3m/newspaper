<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,   // taxonomy first — articles reference it
            UserSeeder::class,       // authors before their bylines
            SiteSeeder::class,       // settings, homepage layout, pages, poll, ads
            ContentSeeder::class,    // articles, comments, tags, topics
            MediaSeeder::class,      // imagery last: it rewrites what the rest wrote
        ]);
    }
}
