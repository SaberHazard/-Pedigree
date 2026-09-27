<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Services\Access\PersonAccess;

/**
 * سیاست دسترسی اشخاص؛ منطق اصلی در PersonAccess است.
 */
class PersonPolicy
{
    public function __construct(private readonly PersonAccess $access) {}

    public function view(?User $user, Person $person): bool
    {
        return $this->access->canView($user, $person);
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function update(User $user, Person $person): bool
    {
        return $this->access->canEdit($user, $person);
    }

    public function manageSensitive(User $user, Person $person): bool
    {
        return $this->access->canManageSensitive($user, $person);
    }

    public function uploadMedia(User $user, Person $person): bool
    {
        return $this->access->canUploadMedia($user, $person);
    }

    public function viewHistory(User $user, Person $person): bool
    {
        return $user->isAdmin() || $user->person_id === $person->id || $this->access->canEdit($user, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->access->canDelete($user, $person);
    }

    public function restore(User $user, Person $person): bool
    {
        return $user->isAdmin();
    }

    public function merge(User $user): bool
    {
        return $user->isAdmin();
    }
}
