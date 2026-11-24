<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * D-89: the one place that decides what a viewer may read on a person's profile page. Only an active person has a
 * profile (not deactivated, anonymised or deleted: the same rule as `OrgTree`), so a URL cannot reach someone the
 * chart hides. Phone and university need the HR-record right on that person, or the viewer being that person.
 */
final class PersonDetailsQuery
{
    public static function find(User $viewer, int $userId): ?PersonDetails
    {
        $person = User::query()
            ->with(['department.parent', 'manager', 'profile'])
            ->whereNull('banned_at')
            ->whereNull('anonymized_at')
            ->find($userId);

        if (! $person instanceof User) {
            return null;
        }

        $manager = $person->manager;
        $manager = $manager instanceof User && ! $manager->isBanned() && $manager->anonymized_at === null ? $manager : null;

        $showsPrivate = $viewer->is($person) || $viewer->can('viewHrRecord', $person);
        $department = $person->department;
        $departmentColor = $department?->color;
        $phone = $person->profile?->phone;
        $university = $person->profile?->university;

        return new PersonDetails(
            id: $person->id,
            name: $person->getFilamentName(),
            position: filled($person->position) ? (string) $person->position : null,
            avatarUrl: filled($person->avatar_path) ? Storage::disk('public')->url((string) $person->avatar_path) : null,
            initials: mb_strtoupper(mb_substr($person->name, 0, 1).mb_substr((string) $person->surname, 0, 1)),
            department: $department?->name,
            color: OrgTree::safeColor(filled($departmentColor) ? (string) $departmentColor : $department?->parent?->color),
            email: $person->email,
            managerId: $manager?->id,
            managerName: $manager?->getFilamentName(),
            showsPrivate: $showsPrivate,
            phone: $showsPrivate && filled($phone) ? (string) $phone : null,
            university: $showsPrivate && filled($university) ? (string) $university : null,
        );
    }
}
