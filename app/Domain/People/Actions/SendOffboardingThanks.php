<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Notifications\OffboardingThanks;
use App\Domain\Work\Support\WorkCalendar;

/**
 * D-76, the daily job for the last working day. Each offboarding that is not cancelled and whose last day is today, in
 * the person's own calendar, sends one thank-you email. The date and status are read again in the claiming update, so a
 * last day moved or an offboarding cancelled a moment before cannot slip through, and a moved date simply sends on the
 * new day. The claim is made before sending, so a second run, or two at once, sends nothing twice; it is never undone,
 * so changing the date after the email went out does not send another. A completed offboarding still gets it (the login
 * is already switched off, the address is not); an anonymised person has no address left. A day the job missed is not made up.
 */
final class SendOffboardingThanks
{
    /** @return int how many emails went out */
    public function handle(): int
    {
        $sent = 0;

        Offboarding::query()
            ->where('status', '!=', OffboardingStatus::Cancelled)
            ->whereNull('thanks_sent_at')
            ->whereDate('end_date', '>=', now()->subDay()->toDateString())
            ->whereDate('end_date', '<=', now()->addDay()->toDateString())
            ->with('employee.country')
            ->each(function (Offboarding $offboarding) use (&$sent): void {
                $employee = $offboarding->employee;

                if ($employee->anonymized_at !== null || $this->alreadyThanked($offboarding)) {
                    return;
                }

                $today = WorkCalendar::today($employee)->toDateString();

                if ($offboarding->end_date->toDateString() === $today && $this->claim($offboarding, $today)) {
                    $employee->notify(new OffboardingThanks);
                    $sent++;
                }
            });

        return $sent;
    }

    /** One thank-you per person, even if an earlier offboarding of theirs was cancelled after the email went out. */
    private function alreadyThanked(Offboarding $offboarding): bool
    {
        return Offboarding::query()->where('user_id', $offboarding->user_id)->whereNotNull('thanks_sent_at')->exists();
    }

    /** True for exactly one caller: the one whose update changed the row from "not sent" to "sent" while it was still due today. */
    private function claim(Offboarding $offboarding, string $today): bool
    {
        return Offboarding::query()
            ->whereKey($offboarding->getKey())
            ->where('status', '!=', OffboardingStatus::Cancelled)
            ->whereNull('thanks_sent_at')
            ->whereDate('end_date', $today)
            ->update(['thanks_sent_at' => now()]) === 1;
    }
}
