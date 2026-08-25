<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Reporter = 'reporter';
    case Reader = 'reader';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'অ্যাডমিন',
            self::Editor => 'সম্পাদক',
            self::Reporter => 'প্রতিবেদক',
            self::Reader => 'পাঠক',
        };
    }

    /** Roles allowed into /admin at all. */
    public function isStaff(): bool
    {
        return $this !== self::Reader;
    }

    /** May publish, or only submit for review. */
    public function canPublish(): bool
    {
        return in_array($this, [self::Admin, self::Editor], true);
    }

    /** May manage users, settings, ads and the homepage layout. */
    public function canManageSite(): bool
    {
        return $this === self::Admin;
    }

    /** May moderate the comment queue. */
    public function canModerate(): bool
    {
        return in_array($this, [self::Admin, self::Editor], true);
    }
}
