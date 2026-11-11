<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;
use Spatie\Activitylog\Models\Activity;

// model => [attribute to change, new value]
$models = [
    Country::class => ['name', 'Renamed'],
    LeaveType::class => ['affects_balance', true],
    Department::class => ['name', 'Renamed'],
    Technology::class => ['name', 'Renamed'],
    PaymentPlan::class => ['days', 45],
    Label::class => ['name', 'Renamed'],
    CountryLeavePolicy::class => ['days_per_year', 25],
    BankHoliday::class => ['name', 'Renamed'],
    User::class => ['position', 'Engineer'],
];

it('BR-32 records create, update and delete with causer, subject and before/after values', function (string $model, string $attribute, mixed $new) {
    $actor = userWithRole(RoleName::Superadmin);
    $this->actingAs($actor);
    Activity::query()->delete();

    $record = $model::factory()->create();
    $created = Activity::where('event', 'created')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($created->causer_id)->toBe($actor->id);

    $record->update([$attribute => $new]);
    $updated = Activity::where('event', 'updated')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($updated->causer_id)->toBe($actor->id)
        ->and($updated->attribute_changes)->toHaveKeys(['attributes', 'old'])
        ->and($updated->attribute_changes['attributes'])->toHaveKey($attribute)
        ->and($updated->attribute_changes['old'])->toHaveKey($attribute);

    $record->delete();
    $deleted = Activity::where('event', 'deleted')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($deleted->causer_id)->toBe($actor->id);
})->group('BR-32')->with(fn () => collect($models)->map(fn (array $m, string $class) => [$class, $m[0], $m[1]])->all());
