<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\MasterData\Settings\PrivacySettings;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\Work\Support\WorkCalendar;

/**
 * BR-28 / NFR-07: the daily job. A person is anonymised once their completed offboarding's end date plus
 * `anonymize_after_months` has passed in their country's timezone, and only while their login is still
 * deactivated (D-46: someone the superadmin reactivated is never anonymised). Idempotent.
 */
final readonly class AnonymizeDueEmployees
{
    public function __construct(private AnonymizeEmployee $anonymize) {}

    /** @return int people anonymised */
    public function handle(): int
    {
        $months = resolve(PrivacySettings::class)->anonymize_after_months;
        $count = 0;

        Offboarding::query()
            ->where('status', OffboardingStatus::Completed)
            ->whereHas('employee', fn ($query) => $query->whereNull('anonymized_at')->whereNotNull('banned_at'))
            ->with('employee.country')
            ->each(function (Offboarding $offboarding) use ($months, &$count): void {
                $employee = $offboarding->employee;

                if ($offboarding->end_date->copy()->addMonthsNoOverflow($months)->startOfDay()->lessThanOrEqualTo(WorkCalendar::today($employee))
                    && $this->anonymize->handle($employee)) {
                    $count++;
                }
            });

        return $count;
    }
}
