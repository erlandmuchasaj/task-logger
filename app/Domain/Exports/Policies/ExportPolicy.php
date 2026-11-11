<?php

declare(strict_types=1);

namespace App\Domain\Exports\Policies;

use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Models\Export;
use App\Domain\Identity\Models\User;

/** People see and download their own exports, and only while they may still export that type (BR-33). */
class ExportPolicy
{
    public function viewAny(User $user): bool
    {
        return collect(ExportType::cases())->contains(fn (ExportType $type): bool => $type->exporter()->canExport($user));
    }

    public function view(User $user, Export $export): bool
    {
        return $export->user_id === $user->getKey();
    }

    public function download(User $user, Export $export): bool
    {
        return $export->user_id === $user->getKey()
            && $export->isDownloadable()
            && $export->type->exporter()->canExport($user);
    }

    public function create(User $user): bool
    {
        return false; // exports are requested from the screen they export (RequestExport)
    }

    public function update(User $user, Export $export): bool
    {
        return false;
    }

    public function delete(User $user, Export $export): bool
    {
        return false;
    }
}
