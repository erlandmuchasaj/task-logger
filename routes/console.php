<?php

declare(strict_types=1);

use App\Domain\Exports\Actions\PruneExports;
use App\Domain\Leave\Actions\ExpireCarryOver;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\Leave\Actions\MarkLeaveTaken;
use App\Domain\People\Actions\ActivateDueSalaries;
use App\Domain\People\Actions\AnonymizeDueEmployees;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// BR-18: hourly so 1 January is caught in every country's own timezone; each run is idempotent (NFR-13).
Schedule::call(fn () => resolve(GrantAnnualLeave::class)->handle())
    ->name('leave.grant')
    ->hourly()
    ->withoutOverlapping();

Schedule::call(fn () => resolve(ExpireCarryOver::class)->handle())
    ->name('leave.expire-carry-over')
    ->hourly()
    ->withoutOverlapping();

// BR-19: approved leave becomes taken after its end date.
Schedule::call(fn () => resolve(MarkLeaveTaken::class)->handle())
    ->name('leave.mark-taken')
    ->hourly()
    ->withoutOverlapping();

Schedule::call(fn () => resolve(ActivateDueSalaries::class)->handle())
    ->name('salaries.activate-due')
    ->daily()
    ->withoutOverlapping();

Schedule::call(fn () => resolve(PruneExports::class)->handle())
    ->name('exports.prune')
    ->daily()
    ->withoutOverlapping();

// BR-28 / NFR-07: anonymise people whose completed offboarding is past its retention period. Idempotent.
Schedule::call(fn () => resolve(AnonymizeDueEmployees::class)->handle())
    ->name('people.anonymize')
    ->daily()
    ->withoutOverlapping();
