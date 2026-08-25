<?php

namespace App\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'খসড়া',
            self::Review => 'পর্যালোচনায়',
            self::Scheduled => 'নির্ধারিত',
            self::Published => 'প্রকাশিত',
            self::Archived => 'আর্কাইভড',
        };
    }

    /** Tailwind classes for the admin status pill. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-700',
            self::Review => 'bg-amber-100 text-amber-800',
            self::Scheduled => 'bg-blue-100 text-blue-800',
            self::Published => 'bg-green-100 text-green-800',
            self::Archived => 'bg-slate-200 text-slate-600',
        };
    }
}
