<?php

declare(strict_types=1);

use App\Domain\People\Actions\ActivateDueSalaries;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(ActivateDueSalaries::class)->handle())
    ->name('salaries.activate-due')
    ->daily()
    ->withoutOverlapping();
