<?php

namespace Database\Seeders;

use App\Enums\HomeBlockType;
use App\Models\Ad;
use App\Models\Category;
use App\Models\HomeBlock;
use App\Models\Page;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Setting;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $this->settings();
        $this->homepageLayout();
        $this->staticPages();
        $this->poll();
        $this->adPlaceholders();
    }

    private function settings(): void
    {
        $defaults = [
            ['site_name', 'দৈনিক সংবাদ', 'string', 'general'],
            ['site_tagline', 'সময়ের সাথে সত্যের পথে', 'string', 'general'],
            ['editor_name', 'মোঃ আব্দুল করিম', 'string', 'imprint'],
            ['publisher_name', 'সংবাদ মিডিয়া লিমিটেড', 'string', 'imprint'],
            ['office_address', '১২৩ কারওয়ান বাজার, ঢাকা-১২১৫', 'string', 'imprint'],
            ['office_phone', '+880 2 55012345', 'string', 'imprint'],
            ['office_email', 'news@newspaper.test', 'string', 'imprint'],
            ['comments_require_approval', '1', 'bool', 'comments'],
            ['comments_min_length', '10', 'int', 'comments'],
            ['articles_per_page', '20', 'int', 'display'],
            ['show_reading_time', '1', 'bool', 'display'],
            ['enable_dark_mode', '1', 'bool', 'display'],
            ['breaking_ticker_enabled', '1', 'bool', 'display'],
            ['google_analytics_id', '', 'string', 'integration'],
        ];

        foreach ($defaults as [$key, $value, $type, $group]) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'group' => $group],
            );
        }
    }

    /**
     * Default front page. The order here is the composite that came out of the
     * competitive read: hero → national → international/business three-up →
     * sports → video → opinion → lifestyle, with the sidebar carrying
     * most-read, latest, poll and ads.
     */
    private function homepageLayout(): void
    {
        if (HomeBlock::exists()) {
            return;
        }

        $cat = fn (string $slug) => Category::where('slug', $slug)->value('id');

        $main = [
            [HomeBlockType::Hero, null, 5],
            [HomeBlockType::CategoryGrid, 'bangladesh', 5],
            [HomeBlockType::Ad, null, 1],
            [HomeBlockType::ThreeColumn, null, 5],
            [HomeBlockType::CategoryGrid, 'sports', 5],
            [HomeBlockType::VideoRow, null, 6],
            [HomeBlockType::OpinionRow, null, 4],
            [HomeBlockType::CategoryGrid, 'entertainment', 5],
            [HomeBlockType::TopicCluster, null, 4],
            [HomeBlockType::PhotoRow, null, 6],
            [HomeBlockType::CategoryList, 'lifestyle', 6],
        ];

        foreach ($main as $i => [$type, $slug, $limit]) {
            HomeBlock::create([
                'type' => $type,
                'category_id' => $slug ? $cat($slug) : null,
                'limit' => $limit,
                'position' => $i,
                'column' => 'main',
                'is_active' => true,
            ]);
        }

        $sidebar = [
            [HomeBlockType::MostRead, 8],
            [HomeBlockType::Ad, 1],
            [HomeBlockType::Latest, 10],
            [HomeBlockType::Poll, 1],
            [HomeBlockType::Newsletter, 1],
        ];

        foreach ($sidebar as $i => [$type, $limit]) {
            HomeBlock::create([
                'type' => $type,
                'limit' => $limit,
                'position' => $i,
                'column' => 'sidebar',
                'is_active' => true,
            ]);
        }
    }

    private function staticPages(): void
    {
        $pages = [
            ['আমাদের সম্পর্কে', 'about'],
            ['যোগাযোগ', 'contact'],
            ['বিজ্ঞাপন', 'advertise'],
            ['গোপনীয়তা নীতি', 'privacy'],
            ['ব্যবহারের শর্তাবলি', 'terms'],
            ['মন্তব্য নীতি', 'comment-policy'],
        ];

        foreach ($pages as [$title, $slug]) {
            Page::firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'body' => '<p>'.BanglaContent::paragraph(6).'</p><p>'.BanglaContent::paragraph(5).'</p>',
                    'is_active' => true,
                ],
            );
        }
    }

    private function poll(): void
    {
        if (Poll::exists()) {
            return;
        }

        $poll = Poll::create([
            'question' => 'রাজধানীর যানজট নিরসনে সবচেয়ে কার্যকর পদক্ষেপ কোনটি?',
            'slug' => 'dhaka-traffic-2026',
            'is_active' => true,
        ]);

        $options = [
            'গণপরিবহন উন্নয়ন',
            'মেট্রোরেল সম্প্রসারণ',
            'ব্যক্তিগত গাড়ি নিয়ন্ত্রণ',
            'অফিস সময় পুনর্বিন্যাস',
        ];

        foreach ($options as $i => $label) {
            PollOption::create([
                'poll_id' => $poll->id,
                'label' => $label,
                'position' => $i,
            ]);
        }
    }

    /**
     * A house ad in every slot, live.
     *
     * These were seeded inactive because there was no creative to show — the
     * point was only to make each slot visible in the admin ads screen.
     * MediaSeeder now fills `asset`, and leaving them switched off meant the
     * CLS-with-ads criterion in PLAN.md could never be reproduced from a fresh
     * seed: every slot would measure as an empty reserved box.
     */
    private function adPlaceholders(): void
    {
        foreach (array_keys(config('site.ad_slots')) as $position) {
            Ad::firstOrCreate(
                ['position' => $position, 'title' => 'ডেমো বিজ্ঞাপন — '.$position],
                ['type' => 'image', 'is_active' => true],
            );
        }
    }
}
