<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateCustomer;
use App\Domain\Clients\Actions\SetCustomerActive;
use App\Domain\Clients\Actions\UpdateCustomer;
use App\Domain\Clients\Data\CustomerData;
use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\Priority;
use App\Filament\Resources\Customers\Pages\CreateCustomer as CreateCustomerPage;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\RatesRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function customerData(array $o = []): CustomerData
{
    $country = Country::query()->where('code', 'AL')->first() ?? Country::factory()->albania()->create();

    return new CustomerData(...array_merge([
        'name' => 'Ardit', 'surname' => 'Hoxha', 'email' => 'ardit@acme.test', 'countryId' => $country->id,
        'priority' => Priority::High, 'phone' => '0692123456', 'address' => 'Tirana', 'companyName' => 'Acme Sh.p.k.',
    ], $o));
}

it('FR-17 superadmin and admin create a client; the currency defaults to the country currency', function (RoleName $role) {
    $actor = userWithRole($role);

    $client = app(CreateCustomer::class)->handle($actor, customerData(['paymentPlanId' => PaymentPlan::factory()->create()->id]));

    expect($client->currency_code)->toBe('ALL')
        ->and($client->phone)->toBe('+355692123456')
        ->and($client->priority)->toBe(Priority::High)
        ->and($client->is_active)->toBeTrue()
        ->and($client->created_by)->toBe($actor->id);
})->group('FR-17')->with([RoleName::Superadmin, RoleName::Admin]);

it('FR-17 hr, assistant and employee cannot create or edit clients', function (RoleName $role) {
    $actor = userWithRole($role);
    $client = Customer::factory()->create();

    expect(fn () => app(CreateCustomer::class)->handle($actor, customerData()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateCustomer::class)->handle($actor, $client, customerData()))->toThrow(AuthorizationException::class);
})->group('FR-17')->with([RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-18 the currency is fixed once the client has rates', function () {
    [$client] = clientWithRates(['Angular' => 2000]);

    app(UpdateCustomer::class)->handle(userWithRole(RoleName::Admin), $client, customerData([
        'countryId' => $client->country_id, 'currencyCode' => 'USD', 'email' => $client->email, 'phone' => null,
    ]));
})->group('D-18')->throws(ValidationException::class, 'currency cannot change');

it('D-18 the currency can change while the client has no rates', function () {
    $client = Customer::factory()->create(['currency_code' => 'EUR']);

    app(UpdateCustomer::class)->handle(userWithRole(RoleName::Admin), $client, customerData(['countryId' => $client->country_id, 'currencyCode' => 'usd', 'email' => $client->email, 'phone' => null]));

    expect($client->fresh()->currency_code)->toBe('USD');
})->group('D-18');

it('FR-17 an invalid phone or email is rejected', function (array $override) {
    app(CreateCustomer::class)->handle(userWithRole(RoleName::Admin), customerData($override));
})->group('FR-17')->throws(ValidationException::class)->with([
    'phone' => [['phone' => '12']],
    'email' => [['email' => 'not-an-email']],
]);

it('D-02 priority belongs to the client; projects have no priority column', function () {
    expect(Schema::hasColumn('customers', 'priority'))->toBeTrue()
        ->and(Schema::hasColumn('projects', 'priority'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'priority'))->toBeTrue();
})->group('D-02');

it('BR-15 payment plans are metadata: there are no invoice tables or models', function () {
    $tables = collect(Schema::getTables(schema: DB::getDatabaseName()))->pluck('name'); // this database only, not the whole MySQL server

    expect($tables->filter(fn (string $t) => str_contains(strtolower($t), 'invoice'))->all())->toBe([])
        ->and(glob(app_path('Domain/*/Models/*Invoice*.php')))->toBe([]);
})->group('BR-15');

it('FR-17 superadmin and admin deactivate and activate; others cannot', function (RoleName $role, bool $allowed) {
    $client = Customer::factory()->create();
    $toggle = fn () => app(SetCustomerActive::class)->handle(userWithRole($role), $client, false);

    $allowed ? expect($toggle()->is_active)->toBeFalse() : expect($toggle)->toThrow(AuthorizationException::class);
})->group('FR-17')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('D-05 clients are readable by everyone, rates and the payment plan only by superadmin and admin', function (RoleName $role, bool $seesRates) {
    $user = userWithRole($role);
    $client = Customer::factory()->create();

    expect($user->can('view', $client))->toBeTrue()
        ->and($user->can('viewRates', Customer::class))->toBe($seesRates)
        ->and($user->can('manageRates', $client))->toBe($seesRates)
        ->and($user->can('update', $client))->toBe($seesRates)
        ->and($user->can('delete', $client))->toBeFalse(); // never hard-deleted
})->group('D-05')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('D-05 an employee sees client contact data but no payment plan, currency or rates', function (RoleName $role) {
    [$client] = clientWithRates(['Angular' => 2000], '2025-01-01', ['company_name' => 'Visible Co', 'email' => 'contact@visible.test']);
    $client->update(['payment_plan_id' => PaymentPlan::factory()->create(['name' => 'SecretPlan30'])->id]);
    $this->actingAs(userWithRole($role));

    foreach ([Livewire::test(ListCustomers::class), Livewire::test(ViewCustomer::class, ['record' => $client->getKey()])] as $page) {
        $page->assertSee('Visible Co')->assertDontSee('SecretPlan30')->assertDontSee('Payment plan');
    }

    $html = $this->get("/app/clients/{$client->id}")->assertOk()->getContent();
    expect($html)->toContain('Visible Co')->not->toContain('SecretPlan30')->not->toContain('Hourly rates');
    expect(RatesRelationManager::canViewForRecord($client, ViewCustomer::class))->toBeFalse();
})->group('D-05')->with([RoleName::Employee, RoleName::Assistant, RoleName::Hr]);

it('D-05 the superadmin and admin see the payment plan and the rates tab', function (RoleName $role) {
    [$client] = clientWithRates(['Angular' => 2000], '2025-01-01', ['company_name' => 'Visible Co']);
    $client->update(['payment_plan_id' => PaymentPlan::factory()->create(['name' => 'Net30Plan'])->id]);
    $this->actingAs(userWithRole($role));

    Livewire::test(ViewCustomer::class, ['record' => $client->getKey()])->assertSee('Net30Plan');
    expect(RatesRelationManager::canViewForRecord($client, ViewCustomer::class))->toBeTrue();
})->group('D-05')->with([RoleName::Superadmin, RoleName::Admin]);

it('FR-17 employee, assistant and hr cannot open the edit or create screens', function (RoleName $role) {
    $client = Customer::factory()->create();
    $this->actingAs(userWithRole($role));

    $this->get("/app/clients/{$client->id}/edit")->assertForbidden();
    $this->get('/app/clients/create')->assertForbidden();
})->group('FR-17')->with([RoleName::Employee, RoleName::Assistant, RoleName::Hr]);

it('FR-17 the Create screen creates a client for an admin', function () {
    $this->actingAs(userWithRole(RoleName::Admin));
    $country = Country::factory()->albania()->create();

    Livewire::test(CreateCustomerPage::class)
        ->fillForm(['name' => 'Elira', 'email' => 'elira@example.test', 'country_id' => $country->id, 'priority' => 'critical', 'currency_code' => 'all', 'company_name' => 'Elira Ltd'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::where('email', 'elira@example.test')->sole()->priority)->toBe(Priority::Critical);
})->group('FR-17');

it('FR-18 the rates tab adds a rate for a technology', function () {
    $admin = userWithRole(RoleName::Admin);
    [$client] = clientWithRates(['Angular' => 2000]);
    $ionic = Technology::factory()->create(['name' => 'Ionic']);
    $this->actingAs($admin);

    Livewire::test(RatesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewCustomer::class])
        ->callAction(TestAction::make('create')->table(), ['technology_id' => $ionic->id, 'rate' => '18.50', 'effective_from' => '2026-01-01'])
        ->assertHasNoFormErrors();

    expect($client->rates()->where('technology_id', $ionic->id)->sole()->rate_minor)->toBe(1850);
})->group('FR-18');
