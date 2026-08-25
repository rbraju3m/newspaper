<?php

namespace App\Enums;

/**
 * Block types available in the drag-and-drop homepage layout manager.
 * Each maps to a Blade partial under views/components/home.
 */
enum HomeBlockType: string
{
    case Hero = 'hero';                 // 1 lead + 4 secondary
    case CategoryGrid = 'category_grid'; // lead + 4-card grid for one section
    case CategoryList = 'category_list'; // lead + text list for one section
    case ThreeColumn = 'three_column';   // three sections side by side
    case Carousel = 'carousel';          // horizontal scroll rail
    case VideoRow = 'video_row';
    case PhotoRow = 'photo_row';
    case OpinionRow = 'opinion_row';     // columnist faces
    case MostRead = 'most_read';
    case Latest = 'latest';
    case Poll = 'poll';
    case Ad = 'ad';
    case Newsletter = 'newsletter';
    case TopicCluster = 'topic_cluster'; // running-story cluster, mzamin style

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'প্রধান খবর',
            self::CategoryGrid => 'বিভাগ (গ্রিড)',
            self::CategoryList => 'বিভাগ (তালিকা)',
            self::ThreeColumn => 'তিন কলাম',
            self::Carousel => 'ক্যারোসেল',
            self::VideoRow => 'ভিডিও',
            self::PhotoRow => 'ফটো গ্যালারি',
            self::OpinionRow => 'মতামত',
            self::MostRead => 'সর্বাধিক পঠিত',
            self::Latest => 'সর্বশেষ',
            self::Poll => 'জরিপ',
            self::Ad => 'বিজ্ঞাপন',
            self::Newsletter => 'নিউজলেটার',
            self::TopicCluster => 'বিশেষ আয়োজন',
        };
    }

    /** Whether the block is bound to a single category. */
    public function needsCategory(): bool
    {
        return in_array($this, [self::CategoryGrid, self::CategoryList, self::Carousel], true);
    }
}
