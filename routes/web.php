<?php

declare(strict_types=1);

use App\Http\Controllers\StopImpersonationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// D-47: POST only (CSRF protected). Starting an impersonation is a Filament action, not a URL.
Route::post('/impersonation/leave', StopImpersonationController::class)->middleware('auth')->name('impersonation.leave');
