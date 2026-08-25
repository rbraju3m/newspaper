<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageSite();
    }

    public function update(User $user, User $target): bool
    {
        return $user->role->canManageSite() || $user->id === $target->id;
    }

    /** Nobody deletes themselves out of the admin panel by accident. */
    public function delete(User $user, User $target): bool
    {
        return $user->role->canManageSite() && $user->id !== $target->id;
    }

    public function changeRole(User $user, User $target): bool
    {
        return $user->role->canManageSite() && $user->id !== $target->id;
    }
}
