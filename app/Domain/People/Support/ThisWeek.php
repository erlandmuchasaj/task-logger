<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\Work\Support\MyWorkSummary;
use Carbon\CarbonImmutable;

/**
 * D-78: who joins and who leaves in the viewer's current week (Monday to Sunday, in their own calendar). Only the
 * name, job title and date are returned. A leaver is someone whose offboarding is in progress or completed: a
 * pending one is not announced to the company, and a cancelled one never is. Anonymised people are left out.
 */
final class ThisWeek
{
    /** @return list<array{name: string, position: ?string, date: CarbonImmutable}> */
    public static function joiners(User $viewer): array
    {
        [$monday, $sunday] = self::bounds($viewer);

        return array_values(User::query()
            ->whereNull('banned_at')
            ->whereNull('anonymized_at')
            ->whereBetween('start_date', [$monday->toDateString(), $sunday->toDateString()])
            ->oldest('start_date')->orderBy('name')->orderBy('surname')
            ->get(['id', 'name', 'surname', 'position', 'start_date'])
            ->map(fn (User $user): array => [
                'name' => $user->getFilamentName(),
                'position' => filled($user->position) ? $user->position : null,
                'date' => CarbonImmutable::parse($user->start_date->toDateString()),
            ])->all());
    }

    /** @return list<array{name: string, position: ?string, date: CarbonImmutable}> */
    public static function leavers(User $viewer): array
    {
        [$monday, $sunday] = self::bounds($viewer);

        return array_values(Offboarding::query()
            ->with('employee:id,name,surname,position,anonymized_at')
            ->whereIn('status', [OffboardingStatus::InProgress, OffboardingStatus::Completed])
            ->whereBetween('end_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereHas('employee', fn ($query) => $query->whereNull('anonymized_at'))
            ->orderBy('end_date')
            ->get()
            ->map(fn (Offboarding $offboarding): array => [
                'name' => $offboarding->employee->getFilamentName(),
                'position' => filled($offboarding->employee->position) ? $offboarding->employee->position : null,
                'date' => CarbonImmutable::parse($offboarding->end_date->toDateString()),
            ])->all());
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function bounds(User $viewer): array
    {
        $monday = MyWorkSummary::weekStart($viewer);

        return [$monday, $monday->addDays(6)];
    }
}
