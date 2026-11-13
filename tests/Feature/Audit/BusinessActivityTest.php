<?php

declare(strict_types=1);

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\FixedCost;
use App\Domain\Clients\Models\Project;
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
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\EmergencyContact;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Salary;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
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
    EmployeeProfile::class => ['address', '1 New Street'],
    EmergencyContact::class => ['relationship', 'cousin'],
    Customer::class => ['company_name', 'Renamed Co'],
    ClientTechnologyRate::class => ['rate_minor', 3100],
    Project::class => ['name', 'Renamed project'],
    FixedCost::class => ['name', 'Renamed cost'],
    Task::class => ['name', 'Renamed task'],
    Log::class => ['description', 'Edited description'],
    Salary::class => ['status', SalaryStatus::Superseded], // the only column a salary row may change (BR-29)
];

it('BR-32 records create, update and delete with causer, subject and before/after values', function (string $model, string $attribute, mixed $new): void {
    $actor = userWithRole(RoleName::Superadmin);
    $this->actingAs($actor);
    Activity::query()->delete();

    $record = $model::factory()->create();
    $created = Activity::query()->where('event', 'created')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($created->causer_id)->toBe($actor->id);

    $record->update([$attribute => $new]);
    $updated = Activity::query()->where('event', 'updated')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($updated->causer_id)->toBe($actor->id)
        ->and($updated->attribute_changes)->toHaveKeys(['attributes', 'old'])
        ->and($updated->attribute_changes['attributes'])->toHaveKey($attribute)
        ->and($updated->attribute_changes['old'])->toHaveKey($attribute);

    if ($model === Salary::class) {
        return; // salary rows are never deleted (BR-29): there is no delete to audit
    }

    $record->delete();
    $deleted = Activity::query()->where('event', 'deleted')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->sole();
    expect($deleted->causer_id)->toBe($actor->id);
})->group('BR-32')->with(fn () => collect($models)->map(fn (array $m, string $class): array => [$class, $m[0], $m[1]])->all());
