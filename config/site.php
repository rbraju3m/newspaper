<?php

/**
 * Site-wide presentation config. Anything an editor should be able to change at
 * runtime lives in the `settings` table instead; this file holds the defaults
 * and the things that are genuinely deployment-level.
 */
return [
    'name_bn' => env('SITE_NAME_BN', 'দৈনিক সংবাদ'),
    'name_en' => env('SITE_NAME_EN', 'Dainik Songbad'),
    'tagline' => env('SITE_TAGLINE', 'সময়ের সাথে সত্যের পথে'),
    'description' => env('SITE_DESCRIPTION', 'বাংলাদেশ ও বিশ্বের সর্বশেষ খবর, রাজনীতি, খেলা, বিনোদন, ব্যবসা ও মতামত।'),

    'editor' => env('SITE_EDITOR', ''),
    'publisher' => env('SITE_PUBLISHER', ''),
    'address' => env('SITE_ADDRESS', ''),
    'phone' => env('SITE_PHONE', ''),
    'email' => env('SITE_EMAIL', ''),

    'social' => [
        'facebook' => env('SOCIAL_FACEBOOK', 'https://facebook.com'),
        'youtube' => env('SOCIAL_YOUTUBE', 'https://youtube.com'),
        'twitter' => env('SOCIAL_TWITTER', 'https://x.com'),
        'instagram' => env('SOCIAL_INSTAGRAM', 'https://instagram.com'),
        'linkedin' => env('SOCIAL_LINKEDIN', null),
        'tiktok' => env('SOCIAL_TIKTOK', null),
    ],

    /** How many categories sit in the main bar before overflowing to the mega menu. */
    'nav_limit' => 11,

    /** Homepage / listing pagination. */
    'per_page' => 20,

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
