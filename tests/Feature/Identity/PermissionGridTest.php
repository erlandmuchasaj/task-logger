<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('FR-02 seeds exactly the catalog permissions', function (): void {
    expect(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionCatalog::permissions())->sort()->values()->all());
});

it('FR-02 gives each role exactly its grid permissions', function (RoleName $role): void {
    $actual = Role::findByName($role->value)->permissions->pluck('name')->sort()->values()->all();

    expect($actual)->toBe(collect(PermissionCatalog::forRole($role))->sort()->values()->all());
})->with(RoleName::cases());

it('D-09 every role inherits the employee baseline', function (RoleName $role): void {
    $held = Role::findByName($role->value)->permissions->pluck('name')->all();

    expect(array_diff(PermissionCatalog::employeeBaseline(), $held))->toBe([]);
})->with(RoleName::cases());

it('D-10 D-41 superadmin still holds every permission explicitly, and system.root on top', function (): void {
    $superadmin = Role::findByName('superadmin');

    expect($superadmin->permissions()->count())->toBe(Permission::query()->count())
        ->and($superadmin->hasPermissionTo(Permissions::SYSTEM_ROOT))->toBeTrue();
});

it('D-10 unspecified means denied', function (): void {
    $employee = userWithRole(RoleName::Employee);

    foreach ([Permissions::LABELS_MANAGE, Permissions::COUNTRIES_CREATE, Permissions::SALARIES_VIEW, Permissions::ACTIVITY_LOGS_VIEW, Permissions::SETTINGS_MANAGE] as $permission) {
        expect($employee->can($permission))->toBeFalse($permission);
    }
});

it('D-10 assistant adds master data but cannot edit or delete it', function (): void {
    $assistant = userWithRole(RoleName::Assistant);

    foreach (['countries', 'departments', 'technologies'] as $resource) {
        expect($assistant->can("{$resource}.create"))->toBeTrue()
            ->and($assistant->can("{$resource}.manage"))->toBeFalse();
    }
});

it('D-21 performances.view is not granted to admin; the direct-manager read is a policy rule', function (): void {
    expect(PermissionCatalog::forRole(RoleName::Admin))->not->toContain(Permissions::PERFORMANCES_VIEW)
        ->and(PermissionCatalog::forRole(RoleName::Admin))->toContain(Permissions::PERFORMANCES_CREATE)
        ->and(PermissionCatalog::forRole(RoleName::Hr))->toContain(Permissions::PERFORMANCES_VIEW);
});

it('seeding twice changes nothing', function (): void {
    $permissions = Permission::query()->count();
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->count())->toBe($permissions)->and(Role::query()->count())->toBe(6);
});
