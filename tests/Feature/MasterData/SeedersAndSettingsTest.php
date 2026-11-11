<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Settings\LeaveSettings;
use App\Domain\MasterData\Settings\PrivacySettings;
use App\Filament\Pages\ManageSettings;
use Database\Seeders\MasterDataSeeder;
use Livewire\Livewire;

it('D-19 seeds the leave types, labels and payment plans named in the spec, idempotently', function () {
    $this->seed(MasterDataSeeder::class);
    $this->seed(MasterDataSeeder::class);

    expect(LeaveType::pluck('name')->sort()->values()->all())
        ->toBe(['annual_leave', 'parental_leave', 'personal_leave', 'sick_leave', 'unpaid_leave'])
        ->and(LeaveType::where('affects_balance', true)->pluck('name')->sort()->values()->all())->toBe(['annual_leave', 'sick_leave'])
        ->and(Label::pluck('name')->sort()->values()->all())
        ->toBe(['Bug', 'Design', 'Documentation', 'Enhancement', 'Feature', 'Refactoring', 'Testing'])
        ->and(PaymentPlan::count())->toBe(4);
});

it('D-15 / D-08 settings default to 2 months retention and carry-over off', function () {
    expect(app(PrivacySettings::class)->anonymize_after_months)->toBe(2)
        ->and(app(LeaveSettings::class)->carry_over_default)->toBeFalse();
});

it('settings.manage holders (superadmin, admin, hr) can change settings', function (RoleName $role) {
    $this->actingAs(userWithRole($role));

    Livewire::test(ManageSettings::class)
        ->fillForm(['anonymize_after_months' => 3, 'carry_over_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(PrivacySettings::class)->anonymize_after_months)->toBe(3)
        ->and(app(LeaveSettings::class)->carry_over_default)->toBeTrue();
})->with([RoleName::Superadmin, RoleName::Admin, RoleName::Hr]);

it('assistants and employees cannot open settings', function (RoleName $role) {
    $this->actingAs(userWithRole($role))->get('/app/manage-settings')->assertForbidden();
})->with([RoleName::Assistant, RoleName::Employee]);
