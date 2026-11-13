<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-only policy for master data (SPEC 4.1). Never inspects role names.
 *
 * - create: `.create` permission when the resource has one (assistant "add", D-10), else `.manage`.
 * - viewAny/view: anyone who can create or manage, or holds the optional view permission.
 *   [ASSUMED D-10] The assistant needs to see existing rows to add without duplicating them.
 * - update/delete: `.manage` only.
 */
abstract class MasterDataPolicy
{
    abstract protected function managePermission(): string;

    final public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    final public function view(User $user, Model $model): bool
    {
        return $this->canView($user);
    }

    final public function create(User $user): bool
    {
        return $user->can($this->createPermission() ?? $this->managePermission());
    }

    final public function update(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    final public function delete(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    final public function restore(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    final public function forceDelete(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    protected function createPermission(): ?string
    {
        return null;
    }

    protected function viewPermission(): ?string
    {
        return null;
    }

    private function canView(User $user): bool
    {
        return ($this->viewPermission() !== null && $user->can($this->viewPermission()))
            || $user->can($this->managePermission())
            || ($this->createPermission() !== null && $user->can($this->createPermission()));
    }
}
