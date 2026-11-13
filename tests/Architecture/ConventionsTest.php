<?php

declare(strict_types=1);

// Guards for operating rules in docs/SPEC.md §0.2 and docs/07-security.md.

it('never authorises by role name (permission-first, FR-02)', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // BR-30 is a data rule (who may be picked as a project manager), kept in this single file.
        if (str_ends_with($file->getPathname(), 'Clients/Support/ProjectManagerEligibility.php')) {
            continue;
        }

        $code = file_get_contents($file->getPathname());
        if (preg_match('/->(hasRole|hasAnyRole|hasAllRoles)\(|[\'"]role:|@role\b|@hasrole\b/i', $code)) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('does not use debug helpers in app code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

it('keeps money out of float columns (BR-12, NFR-18)', function (): void {
    $offenders = [];
    foreach (glob(database_path('migrations/*.php')) as $file) {
        if (preg_match('/(float|double)\([\'"][a-z_]*(minor|amount|price|rate|salary|budget)[a-z_]*[\'"]/i', file_get_contents($file))) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});
