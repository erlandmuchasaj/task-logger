<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Support\CountryDirectory;
use App\Filament\Resources\Countries\Pages\ManageCountries;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(fn () => Cache::flush());

/** @return array<string, mixed> */
function albaniaFixture(array $overrides = []): array
{
    return array_merge([
        'name' => 'Albania', 'alpha2Code' => 'AL', 'callingCodes' => ['355'],
        'currencies' => [['code' => 'ALL', 'name' => 'Albanian lek', 'symbol' => 'L']],
        'languages' => [['name' => 'Albanian', 'iso639_1' => 'sq', 'iso639_2' => 'sqi', 'nativeName' => 'Shqip']],
        'flag' => '🇦🇱',
    ], $overrides);
}

it('D-61 searches countries.dev and maps name, code, prefix, currency, language and flag', function (): void {
    Http::fake(['countries.dev/name/*' => Http::response([albaniaFixture()])]);

    $entry = CountryDirectory::search('alb')[0];

    expect($entry->name)->toBe('Albania')->and($entry->code)->toBe('AL')->and($entry->prefix)->toBe('+355')
        ->and($entry->currencyCode)->toBe('ALL')->and($entry->language)->toBe('sq')->and($entry->label())->toBe('🇦🇱 Albania (AL)');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://countries.dev/name/alb'));
})->group('D-61');

it('D-61 an unknown name, a failing service and a short term all give an empty answer without throwing', function (): void {
    Http::fake([
        'countries.dev/name/zzzz*' => Http::response(['status' => 404, 'message' => 'Not found'], 404),
        'countries.dev/name/boom*' => Http::response('oops', 500),
        'countries.dev/name/slow*' => Http::response('{not json', 200),
    ]);

    expect(CountryDirectory::search('zzzz'))->toBe([])
        ->and(CountryDirectory::search('boom'))->toBe([])
        ->and(CountryDirectory::search('slow'))->toBe([])
        ->and(CountryDirectory::search('a'))->toBe([]);
    Http::assertSentCount(3); // "a" never leaves the app
})->group('D-61');

it('D-61 a connection failure is swallowed', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('timeout'));

    expect(CountryDirectory::search('albania'))->toBe([])->and(CountryDirectory::find('AL'))->toBeNull();
})->group('D-61');

it('D-61 drops a currency, language, prefix or flag the API sends in an unexpected shape', function (): void {
    Http::fake(['countries.dev/name/*' => Http::response([albaniaFixture([
        'currencies' => [['code' => 'ZZZ'], ['code' => 'not-a-code']], 'languages' => [['iso639_1' => 'SQ'], ['iso639_1' => '<b>']],
        'callingCodes' => ['<script>'], 'flag' => 'https://evil.test/x.svg',
    ]), ['name' => 'No code'], 'junk'])]);

    $entries = CountryDirectory::search('alb');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->currencyCode)->toBeNull()->and($entries[0]->language)->toBeNull()
        ->and($entries[0]->prefix)->toBeNull()->and($entries[0]->flag)->toBeNull();
})->group('D-61');

it('D-61 find only accepts two capital letters, so nothing odd from the browser reaches the API', function (string $code): void {
    Http::fake();

    expect(CountryDirectory::find($code))->toBeNull();
    Http::assertNothingSent();
})->group('D-61')->with(['al', 'AL;', '../etc', 'ALB', '']);

it('D-61 find asks the API for a code it has not seen, and caches the answer', function (): void {
    Http::fake(['countries.dev/alpha/AL*' => Http::response(albaniaFixture())]);

    expect(CountryDirectory::find('AL')?->name)->toBe('Albania')->and(CountryDirectory::find('AL')?->name)->toBe('Albania');
    Http::assertSentCount(1);
})->group('D-61');

it('D-61 an assistant picks a country from the lookup and the form fills in the fields', function (): void {
    Http::fake(['countries.dev/name/*' => Http::response([albaniaFixture()]), 'countries.dev/alpha/*' => Http::response(albaniaFixture())]);
    $this->actingAs(userWithRole(RoleName::Assistant));
    CountryDirectory::search('alb'); // what the dropdown does while typing

    Livewire::test(ManageCountries::class)
        ->mountAction(CreateAction::class)
        ->set('mountedActions.0.data.lookup', 'AL')
        ->assertSet('mountedActions.0.data.name', 'Albania')
        ->assertSet('mountedActions.0.data.code', 'AL')
        ->assertSet('mountedActions.0.data.prefix', '+355')
        ->assertSet('mountedActions.0.data.currency_code', 'ALL')
        ->assertSet('mountedActions.0.data.language', 'sq')
        ->assertSet('mountedActions.0.data.timezone', 'Europe/Tirane')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $country = Country::query()->where('code', 'AL')->sole();
    expect($country->name)->toBe('Albania')->and($country->language)->toBe('sq')->and($country->prefix)->toBe('+355')
        ->and($country->currency_code)->toBe('ALL')->and($country->timezone)->toBe('Europe/Tirane');
})->group('D-61');

it('D-61 a country with more than one timezone leaves the timezone to the person', function (): void {
    Http::fake(['countries.dev/*' => Http::response(albaniaFixture(['name' => 'Germany', 'alpha2Code' => 'DE', 'callingCodes' => ['49'], 'currencies' => [['code' => 'EUR']], 'languages' => [['iso639_1' => 'de']]]))]);
    $this->actingAs(userWithRole(RoleName::Assistant));

    Livewire::test(ManageCountries::class)
        ->mountAction(CreateAction::class)
        ->set('mountedActions.0.data.lookup', 'DE')
        ->assertSet('mountedActions.0.data.name', 'Germany')
        ->assertSet('mountedActions.0.data.prefix', '+49')
        ->assertSet('mountedActions.0.data.timezone', null) // PHP knows two zones for DE, so none is guessed
        ->assertNotified('Choose the timezone');
})->group('D-61');

it('D-61 when the lookup fails the form stays usable by hand', function (): void {
    Http::fake(['countries.dev/*' => Http::response('down', 503)]);
    $this->actingAs(userWithRole(RoleName::Assistant));

    Livewire::test(ManageCountries::class)
        ->mountAction(CreateAction::class)
        ->set('mountedActions.0.data.lookup', 'AL')
        ->assertNotified('Country lookup is unavailable')
        ->set('mountedActions.0.data.lookup', null)
        ->fillForm([
            'name' => 'Albania', 'code' => 'AL', 'prefix' => '+355', 'timezone' => 'Europe/Tirane', 'currency_code' => 'ALL', 'working_days' => [1, 2, 3, 4, 5],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(Country::query()->where('code', 'AL')->exists())->toBeTrue();
})->group('D-61');

it('D-61 the lookup is only offered when adding a country, and the language is checked', function (): void {
    $country = Country::factory()->create();

    $this->actingAs(userWithRole(RoleName::Assistant));
    Livewire::test(ManageCountries::class)->mountAction(CreateAction::class)->assertFormFieldVisible('lookup');

    $this->actingAs(userWithRole(RoleName::Superadmin)); // the only role that edits countries
    Livewire::test(ManageCountries::class)
        ->mountTableAction(EditAction::class, $country)
        ->assertFormFieldHidden('lookup')
        ->assertFormFieldExists('language')
        ->fillForm(['language' => 'english'])
        ->callMountedAction()
        ->assertHasFormErrors(['language']);
})->group('D-61');

it('D-61 an employee cannot reach the countries screen, so the lookup is unreachable', function (): void {
    Http::fake();

    $this->actingAs(userWithRole(RoleName::Employee))->get('/app/countries')->assertForbidden();
    Http::assertNothingSent();
})->group('D-61');
