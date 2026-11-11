<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Permissions;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;

// model => [create permission, manage permission, view permission]; null = not applicable
$matrix = [
    Country::class => [Permissions::COUNTRIES_CREATE, Permissions::COUNTRIES_MANAGE, null],
    Department::class => [Permissions::DEPARTMENTS_CREATE, Permissions::DEPARTMENTS_MANAGE, null],
    Technology::class => [Permissions::TECHNOLOGIES_CREATE, Permissions::TECHNOLOGIES_MANAGE, null],
    PaymentPlan::class => [null, Permissions::PAYMENT_PLANS_MANAGE, null],
    Label::class => [null, Permissions::LABELS_MANAGE, null],
    LeaveType::class => [null, Permissions::LEAVE_TYPES_MANAGE, null],
    CountryLeavePolicy::class => [null, Permissions::COUNTRY_LEAVE_POLICIES_MANAGE, null],
    BankHoliday::class => [null, Permissions::BANK_HOLIDAYS_MANAGE, Permissions::BANK_HOLIDAYS_VIEW],
];

it('master data policies follow permissions for every role', function (RoleName $role) use ($matrix) {
    $user = userWithRole($role);

    foreach ($matrix as $model => [$create, $manage, $view]) {
        $record = $model::factory()->create();

        expect($user->can('create', $model))->toBe($user->can($create ?? $manage), "$model create")
            ->and($user->can('update', $record))->toBe($user->can($manage), "$model update")
            ->and($user->can('delete', $record))->toBe($user->can($manage), "$model delete")
            ->and($user->can('viewAny', $model))->toBe(
                ($view && $user->can($view)) || $user->can($manage) || ($create && $user->can($create)),
                "$model viewAny",
            );
    }
})->with(RoleName::cases());

it('D-10 assistant can create a country but not update or delete one', function () {
    $assistant = userWithRole(RoleName::Assistant);
    $country = Country::factory()->create();

    expect($assistant->can('create', Country::class))->toBeTrue()
        ->and($assistant->can('update', $country))->toBeFalse()
        ->and($assistant->can('delete', $country))->toBeFalse();
});

it('policies never inspect role names', function () {
    foreach (glob(app_path('Domain/*/Policies/*.php')) as $file) {
        expect(file_get_contents($file))->not->toMatch('/hasRole|hasAnyRole|RoleName::/');
    }
});
