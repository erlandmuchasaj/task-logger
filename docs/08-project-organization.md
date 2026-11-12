# 08 — Project organisation

## 1. Coding standards

- PHP 8.4+, `declare(strict_types=1)`, typed properties and returns, backed enums with Filament labels (`HasLabel`).
- Laravel conventions: plural snake_case tables, singular models, `{model}_id` keys (SPEC §0.2.5).
- Laravel Pint (Laravel preset) `[PROPOSED]`. Larastan (`larastan/larastan`) level 6 at M0, 7 at M7 (8 is the stretch goal: see NFR-15).
- Money only via `Money` VO / `*_minor` ints; no floats. Strings via `__()`.
- Business logic in Actions, not models/resources/controllers. Policies check permissions only.
- Pest 4 for tests `[PROPOSED]` (PHPUnit-compatible). Test names cite BR-xx.
- Commit messages: imperative, one logical change.

## 2. Branching `[PROPOSED]`

Trunk-based with short-lived branches: `main` protected; `feat/<milestone>-<topic>`; PRs need green CI; squash merge. One PR per slice of a milestone. Do not commit unrelated working-tree changes.

## 3. Environments

| Env | Notes |
|---|---|
| local | Laravel Herd (`task-logger.test`), MySQL and Redis via Herd services; debugbar, ide-helper dev only |
| ci | GitHub Actions; MySQL 8.4 and Redis service containers |
| staging | Mirrors prod, seeded anonymised data |
| production | Supervisor for queue worker and scheduler cron, HTTPS, backups |

## 4. CI pipeline

1. `composer install`, `composer audit`
2. Pint `--test`
3. Larastan
4. Pest (unit, feature, architecture) with MySQL and Redis
5. Frontend build
6. Traceability check: script fails if any BR-xx has no test (see 10-testing-strategy.md)

## 5. CD `[PROPOSED]`

Build artifact → deploy → `php artisan migrate --force` → cache config/routes/views → `queue:restart`. Maintenance mode only for breaking migrations. Rollback = redeploy previous artifact (migrations written backwards-compatible).

## 6. Documentation

`docs/` is the source of truth; every design change updates the Decision Log and an ADR in `docs/adr/`.

## Open questions for the owner

1. Git host and CI provider (GitHub Actions assumed).
2. Production hosting target and deploy mechanism.
3. Confirm Pest over PHPUnit and Pint as the formatter.
