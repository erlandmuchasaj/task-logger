<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Filament\Pages\Auth\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

it('NFR-05 D-50 every web response carries the baseline security headers', function () {
    $response = $this->get('/app/login');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');   // plain HTTP in local development
})->group('NFR-05', 'D-50');

it('NFR-05 HSTS is sent on HTTPS responses', function () {
    $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/app/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
})->group('NFR-05');

it('NFR-05 session cookies are HttpOnly and SameSite, and secure in production', function () {
    expect(config('session.http_only'))->toBeTrue()->and(config('session.same_site'))->toBe('lax');

    $config = (static function (): array {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        return require base_path('config/session.php');
    })();
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';

    expect($config['secure'])->toBeTrue();
})->group('NFR-05');

it('D-50 passwords need 12 characters, mixed case and a number', function (string $password, bool $valid) {
    $passes = Validator::make(['password' => $password], ['password' => Password::default()])->passes();

    expect($passes)->toBe($valid);
})->with([
    'too short' => ['Abcdef12', false],
    'no digit' => ['Abcdefghijklmnop', false],
    'no capital' => ['abcdefghij12345', false],
    'good' => ['Correct-Horse-Battery-9', true],
])->group('D-50');

it('NFR-01 login is rate limited', function () {
    $user = User::factory()->create();

    $login = Livewire\Livewire::test(Login::class);

    foreach (range(1, 6) as $attempt) {
        $login->fillForm(['email' => $user->email, 'password' => 'wrong-password-'.$attempt])->call('authenticate');
    }

    $login->assertNotified();
    $this->assertGuest();
})->group('NFR-01');

it('NFR-01 there is no registration route', function () {
    $this->get('/app/register')->assertNotFound();
    $this->get('/register')->assertNotFound();
})->group('NFR-01', 'FR-01');

it('NFR-03 every panel page redirects a guest to the login page', function () {
    $public = ['login', 'password-reset', 'register', 'email-verification', 'tenant', 'logout'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'filament.app.') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')
            || Str::contains($name, $public)) {
            continue;
        }

        $this->get('/'.$route->uri())->assertRedirect();
        $checked++;
    }

    expect($checked)->toBeGreaterThan(15);
})->group('NFR-03');

it('NFR-03 default deny: a signed-in user with no role reaches no resource, page or relation manager except their own profile', function () {
    $nobody = User::factory()->create();   // no role, so no permission (D-10)
    $this->actingAs($nobody);
    $panel = Filament::getDefaultPanel();
    $allowedPages = [Dashboard::class, EditProfile::class];
    $open = [];

    foreach ($panel->getResources() as $resource) {
        foreach (['canViewAny', 'canCreate'] as $ability) {
            if ($resource::$ability()) {
                $open[] = $resource.'::'.$ability;
            }
        }

        foreach ($resource::getRelations() as $manager) {
            $manager = is_string($manager) ? $manager : null;
            if ($manager !== null && is_subclass_of($manager, RelationManager::class)) {
                $record = new User;
                $record->exists = true;
                if ($manager::canViewForRecord($record, Model::class)) {
                    $open[] = $manager;
                }
            }
        }
    }

    foreach ($panel->getPages() as $page) {
        if (! in_array($page, $allowedPages, true) && $page::canAccess()) {
            $open[] = $page;
        }
    }

    expect($open)->toBe([]);
})->group('NFR-03', 'FR-02');

it('NFR-03 no model switches off mass-assignment protection', function () {
    foreach (glob(app_path('Domain/*/Models/*.php')) as $file) {
        expect(file_get_contents($file))->not->toMatch('/\$guarded\s*=\s*\[\s*\]/');
    }
})->group('NFR-03');
