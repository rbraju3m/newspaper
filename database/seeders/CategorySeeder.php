<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Taxonomy modelled on the reference papers — Ittefaq and Naya Diganta both
     * run 20+ sections, and readers of Bangladeshi papers expect ধর্ম, প্রবাস,
     * ক্যাম্পাস and সাহিত্য as first-class sections rather than "misc".
     *
     * [name, slug, colour, [children...]]
     */
    private const TREE = [
        ['বাংলাদেশ', 'bangladesh', '#C8102E', [
            ['জাতীয়', 'national'],
            ['রাজধানী', 'capital'],
            ['সারাদেশ', 'country'],
            ['অপরাধ', 'crime'],
            ['দুর্ঘটনা', 'accident'],
        ]],
        ['রাজনীতি', 'politics', '#8B2FC9', []],
        ['আন্তর্জাতিক', 'international', '#1A5FB4', [
            ['ভারত', 'india'],
            ['যুক্তরাষ্ট্র', 'usa'],
            ['মধ্যপ্রাচ্য', 'middle-east'],
            ['এশিয়া', 'asia'],
            ['ইউরোপ', 'europe'],
        ]],
        ['অর্থনীতি', 'economy', '#0E7C66', [
            ['ব্যাংক ও বিমা', 'bank-insurance'],
            ['শেয়ারবাজার', 'stock-market'],
            ['শিল্প ও বাণিজ্য', 'industry-trade'],
            ['বাজেট', 'budget'],
        ]],
        ['খেলা', 'sports', '#197A3D', [
            ['ক্রিকেট', 'cricket'],
            ['ফুটবল', 'football'],
            ['টেনিস', 'tennis'],
            ['অন্যান্য খেলা', 'other-sports'],
        ]],
        ['বিনোদন', 'entertainment', '#C2185B', [
            ['ঢালিউড', 'dhallywood'],
            ['বলিউড', 'bollywood'],
            ['হলিউড', 'hollywood'],
            ['সংগীত', 'music'],
            ['টেলিভিশন', 'television'],
        ]],
        ['মতামত', 'opinion', '#B45309', [
            ['সম্পাদকীয়', 'editorial'],
            ['উপসম্পাদকীয়', 'sub-editorial'],
            ['সাক্ষাৎকার', 'interview'],
        ]],
        ['শিক্ষা', 'education', '#6D28D9', [
            ['ক্যাম্পাস', 'campus'],
            ['ভর্তি', 'admission'],
            ['পরীক্ষা', 'exam'],
        ]],
        ['প্রযুক্তি', 'technology', '#0369A1', [
            ['গ্যাজেট', 'gadget'],
            ['সোশ্যাল মিডিয়া', 'social-media'],
            ['বিজ্ঞান', 'science'],
        ]],
        ['স্বাস্থ্য', 'health', '#0891B2', []],
        ['জীবনযাপন', 'lifestyle', '#DB6B00', [
            ['ফ্যাশন', 'fashion'],
            ['রান্নাবান্না', 'recipe'],
            ['ভ্রমণ', 'travel'],
        ]],
        ['ধর্ম', 'religion', '#15803D', [
            ['ইসলাম', 'islam'],
            ['অন্যান্য ধর্ম', 'other-religion'],
        ]],
        ['চাকরি', 'jobs', '#7C3AED', []],
        ['প্রবাস', 'diaspora', '#B91C1C', []],
        ['সাহিত্য', 'literature', '#92400E', []],
        ['আইন ও আদালত', 'law-court', '#374151', []],
        ['ভিডিও', 'video', '#DC2626', []],
        ['ফটো', 'photo', '#4F46E5', []],
    ];

    public function run(): void
    {
        $navLimit = config('site.nav_limit', 11);

        foreach (self::TREE as $i => [$name, $slug, $color, $children]) {
            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'color' => $color,
                    'position' => $i,
                    'is_active' => true,
                    // Only the first N sit in the main bar; the rest live in the
                    // mega menu, which is how Prothom Alo and Ittefaq handle it.
                    'show_in_nav' => $i < $navLimit,
                    'show_in_footer' => true,
                ],
            );

            foreach ($children as $j => [$childName, $childSlug]) {
                Category::updateOrCreate(
                    ['slug' => $childSlug],
                    [
                        'parent_id' => $parent->id,
                        'name' => $childName,
                        'color' => $color,
                        'position' => $j,
                        'is_active' => true,
                        'show_in_nav' => false,
                        'show_in_footer' => false,
                    ],
                );
            }
        }
    }
}
