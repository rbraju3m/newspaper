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

    /**
     * The English edition's name for each section, keyed by slug.
     *
     * `categories.name_en` was on the table from the first migration and the
     * seeder never filled it — it existed so `Str::slug()` had something
     * Latin to work with, and every row's was null. It is what the English
     * edition prints now, so a missing one is a Bangla heading on an English
     * page rather than a cosmetic gap.
     *
     * Keyed by slug rather than added to `TREE` so the tuples stay readable
     * at three items and the two lists can be checked against each other —
     * `BilingualTest` asserts every seeded section has an entry here.
     *
     * These are the names a Bangladeshi English-language daily uses, not
     * dictionary glosses: `সারাদেশ` is "Countrywide" in the Daily Star and
     * New Age rather than "Whole country", `প্রবাস` is the diaspora desk, and
     * `উপসম্পাদকীয়` is the op-ed page.
     */
    private const NAMES_EN = [
        'bangladesh' => 'Bangladesh',
        'national' => 'National',
        'capital' => 'Capital',
        'country' => 'Countrywide',
        'crime' => 'Crime',
        'accident' => 'Accidents',
        'politics' => 'Politics',
        'international' => 'International',
        'india' => 'India',
        'usa' => 'United States',
        'middle-east' => 'Middle East',
        'asia' => 'Asia',
        'europe' => 'Europe',
        'economy' => 'Economy',
        'bank-insurance' => 'Banking & Insurance',
        'stock-market' => 'Stock Market',
        'industry-trade' => 'Industry & Trade',
        'budget' => 'Budget',
        'sports' => 'Sport',
        'cricket' => 'Cricket',
        'football' => 'Football',
        'tennis' => 'Tennis',
        'other-sports' => 'Other Sport',
        'entertainment' => 'Entertainment',
        'dhallywood' => 'Dhallywood',
        'bollywood' => 'Bollywood',
        'hollywood' => 'Hollywood',
        'music' => 'Music',
        'television' => 'Television',
        'opinion' => 'Opinion',
        'editorial' => 'Editorial',
        'sub-editorial' => 'Op-ed',
        'interview' => 'Interviews',
        'education' => 'Education',
        'campus' => 'Campus',
        'admission' => 'Admissions',
        'exam' => 'Examinations',
        'technology' => 'Technology',
        'gadget' => 'Gadgets',
        'social-media' => 'Social Media',
        'science' => 'Science',
        'health' => 'Health',
        'lifestyle' => 'Lifestyle',
        'fashion' => 'Fashion',
        'recipe' => 'Food',
        'travel' => 'Travel',
        'religion' => 'Religion',
        'islam' => 'Islam',
        'other-religion' => 'Other Faiths',
        'jobs' => 'Jobs',
        'diaspora' => 'Diaspora',
        'literature' => 'Literature',
        'law-court' => 'Law & Courts',
        'video' => 'Video',
        'photo' => 'Photo',
    ];

    public function run(): void
    {
        $navLimit = config('site.nav_limit', 11);

        foreach (self::TREE as $i => [$name, $slug, $color, $children]) {
            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'name_en' => self::NAMES_EN[$slug] ?? null,
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
                        'name_en' => self::NAMES_EN[$childSlug] ?? null,
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
