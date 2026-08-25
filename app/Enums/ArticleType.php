<?php

namespace App\Enums;

enum ArticleType: string
{
    case News = 'news';
    case Opinion = 'opinion';
    case Video = 'video';
    case Photo = 'photo';
    case Live = 'live';
    case Interview = 'interview';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::News => 'সংবাদ',
            self::Opinion => 'মতামত',
            self::Video => 'ভিডিও',
            self::Photo => 'ফটো',
            self::Live => 'লাইভ',
            self::Interview => 'সাক্ষাৎকার',
            self::Feature => 'ফিচার',
        };
    }

    /** Types that render a media badge on the card. */
    public function badgeIcon(): ?string
    {
        return match ($this) {
            self::Video => 'play',
            self::Photo => 'camera',
            self::Live => 'live',
            default => null,
        };
    }
}
