<?php

namespace App\Enums;

enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'অপেক্ষমাণ',
            self::Approved => 'অনুমোদিত',
            self::Rejected => 'প্রত্যাখ্যাত',
            self::Spam => 'স্প্যাম',
        };
    }

    /** Tailwind classes for the moderation-queue status pill. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-green-100 text-green-800',
            self::Rejected => 'bg-slate-200 text-slate-700',
            self::Spam => 'bg-red-100 text-red-800',
        };
    }
}
