<?php

/**
 * Site-wide presentation config. Anything an editor should be able to change at
 * runtime lives in the `settings` table instead; this file holds the defaults
 * and the things that are genuinely deployment-level.
 */
return [
    /*
     * The masthead is a **fictional** publication. It was `দৈনিক সংবাদ` until
     * somebody noticed that is the name of a real Bangladeshi daily founded in
     * 1951 — fine as a throwaway placeholder, not fine on something shown to
     * people, where it reads as a clone of an outlet that exists. Any name put
     * here should be checked against the real ones first.
     *
     * Every one of these is overridable from `.env`, so a deployment with a
     * real publication behind it changes them there and touches no code.
     */
    'name_bn' => env('SITE_NAME_BN', 'দৈনিক আলোরেখা'),
    'name_en' => env('SITE_NAME_EN', 'Dainik Alorekha'),
    'tagline' => env('SITE_TAGLINE', 'সময়ের সাথে সত্যের পথে'),
    'description' => env('SITE_DESCRIPTION', 'বাংলাদেশ ও বিশ্বের সর্বশেষ খবর, রাজনীতি, খেলা, বিনোদন, ব্যবসা ও মতামত।'),

    'editor' => env('SITE_EDITOR', ''),
    'publisher' => env('SITE_PUBLISHER', ''),
    'address' => env('SITE_ADDRESS', ''),
    'phone' => env('SITE_PHONE', ''),
    'email' => env('SITE_EMAIL', ''),

    /*
     * Empty by default, and the footer skips any that are blank. They used to
     * default to `https://facebook.com` and friends — bare domains, which
     * render as a full row of live social buttons that take a reader to the
     * front page of Facebook. An absent link is honest; a link that goes
     * nowhere useful is a broken one nobody reports.
     */
    'social' => [
        'facebook' => env('SOCIAL_FACEBOOK', ''),
        'youtube' => env('SOCIAL_YOUTUBE', ''),
        'twitter' => env('SOCIAL_TWITTER', ''),
        'instagram' => env('SOCIAL_INSTAGRAM', ''),
        'linkedin' => env('SOCIAL_LINKEDIN', ''),
        'tiktok' => env('SOCIAL_TIKTOK', ''),
    ],

    /** How many categories sit in the main bar before overflowing to the mega menu. */
    'nav_limit' => 11,

    /** Homepage / listing pagination. */
    'per_page' => 20,

    /**
     * E-paper editions. The key is stored in `epapers.edition`, which is unique
     * per date — one issue per edition per day. A single-edition paper only
     * ever needs `main`.
     */
    'epaper_editions' => [
        'main' => 'প্রধান সংস্করণ',
        'dhaka' => 'ঢাকা',
        'chittagong' => 'চট্টগ্রাম',
    ],

    /** Ad slot dimensions — used to reserve space and prevent layout shift. */
    'ad_slots' => [
        'header_leaderboard' => ['w' => 728, 'h' => 90],
        'home_billboard' => ['w' => 970, 'h' => 250],
        'sidebar_rectangle' => ['w' => 300, 'h' => 250],
        'sidebar_halfpage' => ['w' => 300, 'h' => 600],
        'in_article' => ['w' => 336, 'h' => 280],
        'mobile_banner' => ['w' => 320, 'h' => 100],
    ],
];
