<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;

it('permissions are string constants named after their value', function () {
    $constants = (new ReflectionClass(Permissions::class))->getConstants();

    foreach ($constants as $name => $value) {
        expect($value)->toMatch('/^[a-z_]+\.[a-z_]+$/', $name)
            ->and($name)->toBe(strtoupper(str_replace('.', '_', $value)));
    }

    expect(array_values($constants))->toBe(array_values(array_unique($constants)));
});

it('Permissions::all() is exactly what the seeder created', function () {
    expect(collect(Permissions::all())->sort()->values()->all())
        ->toBe(Permission::pluck('name')->sort()->values()->all());
});

it('NFR-03 application code never uses a permission string literal, only constants', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Identity/Permissions.php')) {
            continue;
        }

        $code = file_get_contents($file->getPathname());
        foreach (Permissions::all() as $permission) {
            if (str_contains($code, "'{$permission}'") || str_contains($code, "\"{$permission}\"")) {
                $offenders[] = basename($file->getPathname()).': '.$permission;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('the role map rejects a permission that is not a constant', function () {
    config(['permission.roles.'.RoleName::Employee->value => ['made.up']]);

    PermissionCatalog::forRole(RoleName::Employee);
})->throws(LogicException::class, 'made.up');

it('D-26 two_factor.manage belongs to the superadmin only', function () {
    foreach (RoleName::cases() as $role) {
        expect(in_array(Permissions::TWO_FACTOR_MANAGE, PermissionCatalog::forRole($role), true))
            ->toBe($role === RoleName::Superadmin, $role->value);
    }
});
