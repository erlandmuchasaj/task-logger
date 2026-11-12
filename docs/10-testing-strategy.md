# 10 — Testing strategy

Operating rule 6: every BR-xx has at least one automated test that cites its ID. Tests are named or tagged with the rule (`it('BR-06 snapshots the client rate on the log date')`, plus `->group('BR-06')`), so a script can list coverage per milestone.

## 1. Levels

| Level | Scope | Tooling |
|---|---|---|
| Unit | Pure logic: `CountLeaveDays`, proration, `ResolveClientRate`, `ComputeProjectBudget`, `Money`, `Minutes`, cycle detection | Pest, no DB or in-memory |
| Integration / Feature | Actions with DB, Policies per role × permission, privacy split, month lock, scheduled jobs (run twice), exports | Pest + MySQL 8.4, `RefreshDatabase`, factories |
| Architecture | No `hasRole(` in `app/`; no float money; Domain does not depend on Filament; models have activity logging; no invoice tables (BR-15) | Pest arch |
| Filament / Livewire | Pages render per role; forms validate; actions hidden without permission; My Day toggle | Pest Livewire testing |
| End to end | W-01..W-10 happy paths through the panel | Pest browser (Playwright) `[PROPOSED]` |
| Performance | Seeded 1.9M logs: log list, My Day, export, balance (NFR-08..10) | Pest + timing assertions, run in M7 |
| Static | Larastan, Pint | CI |

## 2. Cross-cutting test suites

- **Permission grid test**: iterates §3 of 07-security.md and asserts each role's permission set exactly (FR-02).
- **Policy matrix**: for each model, each role, each ability → expected allow/deny.
- **Privacy test (BR-26)**: for employee, assistant, admin without HR permission … asserts no private field in directory query, resource, export.
- **Audit test (BR-32)**: create/update/delete each business model → one activity row with causer and before/after.
- **Idempotency**: annual grant, approved→taken, anonymisation run twice.

## 3. Reporting after each milestone

Run `vendor/bin/larastan`, `vendor/bin/pest`, then `pest --group=BR-xx` per rule; list covered BR-xx and deviations.

## 4. Traceability matrix BR → FR → planned test

| BR | FR | Planned test (level) | Milestone |
|---|---|---|---|
| BR-01 | FR-24 | No approval status or approver column on logs; log is final on create (arch + feature) | M4 |
| BR-02 | FR-24 | Task log requires assignee; direct log requires flag + membership + project technology (feature) | M4 |
| BR-03 | FR-25 | My Day lists tasks unchecked, eligible projects checked (Livewire) | M4 |
| BR-04 | FR-24 | Zero duration rejected; minutes > 59 rejected; 24 h daily cap (unit + feature) | M4 |
| BR-05 | FR-24 | `LogType` enum validation (feature) | M4 |
| BR-06 | FR-24 | Snapshot on date; later rate change leaves log intact; missing rate blocks (feature) | M4 |
| BR-07 | FR-27 | Employee blocked in locked month; admin allowed; before/after logged (feature) | M4 |
| BR-08 | FR-25 | Pinned until completed/cancelled (feature) | M4 |
| BR-09 | FR-22 | Second assignee impossible by schema (`assignee_id` single column); assigned task visible to assignee (feature) | M3 |
| BR-10 | FR-19, FR-22 | Technology outside project/client rejected (feature) | M3 |
| BR-11 | FR-22 | Parent in other project rejected; cycle rejected (unit + feature) | M3 |
| BR-12 | FR-36 | Money VO integer only; arch test no float; currencies not summed together (unit + arch) | M1 |
| BR-13 | FR-20 | Budget = Σ hours × rate at start_date + fixed costs (unit) | M3 |
| BR-14 | FR-21 | Fixed cost counts in budget; currency mismatch rejected (feature) | M3 |
| BR-15 | FR-17 | No invoice tables or generators exist (arch) | M3 |
| BR-16 | FR-29 | Weekends, country holidays, half days, weekend holiday no-op (unit) | M5 |
| BR-17 | FR-29 | First/last day parts; single-day one part (unit) | M5 |
| BR-18 | FR-32 | 1 Jan grant, proration, round 0.5, joiner grant, carry-over off by default (unit + feature) | M5 |
| BR-19 | FR-31 | Transition table; job approved→taken; reversal on cancel (feature) | M5 |
| BR-20 | FR-30 | Both stages needed; no self-approval; superadmin any stage; no manager skip (feature) | M5 |
| BR-21 | FR-30 | Rejection without note fails (feature) | M5 |
| BR-22 | FR-33 | Direct day off approved, deducted, balance override with note (feature) | M5 |
| BR-23 | FR-29 | Overlap rejected (feature) | M5 |
| BR-24 | FR-29 | Exceeding balance minus pending rejected (feature) | M5 |
| BR-25 | FR-08 | HR/superadmin create; feeds day counting; Monday entry manual (feature) | M1 (CRUD), M5 (counting) |
| BR-26 | FR-10 | Privacy suite (integration) | M2 |
| BR-27 | FR-15 | Superadmin, hr, direct manager read; non-manager admin 403 (policy) | M6 |
| BR-28 | FR-16 | Anonymisation after 2 months; keeps history; scrubs activity props; run twice (feature) | M6 |
| BR-29 | FR-14 | Update/delete refused; new row supersedes; visibility by permission (feature) | M2 |
| BR-30 | FR-19 | Non-admin manager rejected; role change blocked while managing (feature) | M3 |
| BR-31 | FR-17 | Deactivated customer blocks new project/task/log (feature) | M3 |
| BR-32 | FR-35 | Audit suite per model (integration) | M1 onward, each milestone adds its models |
| BR-33 | FR-28 | Export respects permissions and omits rate columns (feature, queue fake) | M4 |
| BR-34 | FR-37 | Country timezone vs UTC date boundary cases (unit) | M5 |
| BR-35 | FR-23 | Assignee, creator, admin allowed; others 403 (policy) | M3 |

Milestone scope for the reports: M0 none; M1 BR-12, BR-25 (CRUD), BR-32; M2 BR-26, BR-29, BR-32; M3 BR-09..15, 30, 31, 35; M4 BR-01..08, 33; M5 BR-16..25, 34. BR-27, BR-28 belong to M6, beyond the approved M0–M5 range, but permission tests for them land with M1's grid.

## 4a. M7 additions

- `tests/Feature/Reports`: the estimated-versus-logged report (SPEC 1.2, BR-06, BR-12, D-05, D-49).
- `tests/Feature/Security`: headers, HSTS, cookies, password rule, login throttle, no registration, guests redirected, default-deny on every resource, page and relation manager, no `$guarded = []`.
- `tests/Performance/CapacityTest.php` (NFR-08, NFR-09, NFR-10): `RUN_PERFORMANCE_TESTS=1 PERF_LOGS=500000 php artisan test tests/Performance`. Skipped in the normal run and in CI; run it before a release or after changing a hot path. Results are in docs/09-scalability.md.

## 5. Test data

Factories per model; seeders for roles/permissions, countries (AL, XK, DE), leave types, labels. Performance seeder separate and never run in CI by default.

## Open questions for the owner

1. Browser E2E tooling acceptable (Playwright via Pest)?
2. Coverage threshold 80% on `app/Domain` OK?
3. Real anonymised data for staging, or synthetic only?
