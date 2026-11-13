# 11. Testing strategy

Tooling: **Pest** (with the Laravel plugin, Pest browser testing on Playwright, architecture tests, type coverage, mutation testing). Plugin compatibility with Laravel 13 is to be confirmed at install (05, section 3.2). Tests run against a real **MySQL 8.4** and Redis in CI, never SQLite, because CHECK constraints, composite foreign keys and row locks are part of the design.

## 1. Test pyramid

| Level | Scope | Volume (target) | Runs |
|---|---|---|---|
| Unit | Value objects (Money, Minutes, DateRange), rate resolution, leave day counter, due-date calculator, budget colour, proration | many, milliseconds | every push |
| Feature / integration | Actions, policies, Filament resources/pages (Livewire testing), scheduled jobs, notifications (fake), MCP tools, exports | bulk of the suite | every push |
| Architecture | Module boundaries, policy coverage, Livewire authorisation, strict types, no debug calls | about 10 rules | every push |
| Browser / E2E | Critical journeys in a real browser (section 4) | 15-25 scenarios | nightly and release candidates |
| Performance | k6 and query-budget tests (10, section 7) | 6 scenarios | release candidates, migrations on `time_logs` |
| Security | Role-matrix tests, dependency audit, ZAP baseline scan on staging | | every push / release |
| Accessibility | axe-core inside browser tests | per key page | nightly |

Quality gates (CI): Pint clean, Larastan level 8, coverage >= 85% on TimeTracking/Leave/Billing and >= 70% overall, mutation score >= 70% on domain Actions (Pest `--mutate`, run nightly), type coverage >= 95%.

## 2. Test data

- Model factories per module with states (`inactive()`, `locked()`, `invoiced()`).
- A `Scenario` helper builds common worlds: company with 3 countries, manager chain of 4 levels, a client with two rate versions, a project with areas and tasks, assignees.
- Time is frozen (`Carbon::setTestNow`) in every date-sensitive test. **Timezone matrix** datasets: UTC-11, UTC, UTC+5:30, UTC+14, plus DST change days.
- No real personal data anywhere.

## 3. Business rules that MUST have tests

Each rule ID from 04 maps to at least the tests listed. "U" unit, "F" feature, "C" concurrency, "B" browser.

| Rule | Must-test cases | Level |
|---|---|---|
| BR-01 assignment | log on unassigned task rejected; after assignment accepted; after un-assignment old logs remain | F |
| BR-02 15-min steps | 15, 30, 1440 accepted; 0, 10, 20, 1445 rejected; stored as integer minutes | U, F |
| BR-03 24h cap | exactly 24h accepted; +15 min rejected; two parallel requests at 23h cannot both succeed | F, **C** |
| BR-04 no future dates | tomorrow in the user's timezone rejected; today accepted at UTC-11 and UTC+14 | F |
| BR-05 snapshots | log keeps rate after new rate version; keeps area after task area change; keeps multiplier after hour type edit | F |
| BR-06 no rate | rejected with `no_rate`; works after a rate dated on or before the log date is added | F |
| BR-07/08 lock | author can edit before lock, cannot after; employee cannot back-fill into locked month; Admin can correct; Admin cannot correct an invoiced log | F |
| BR-09 auto-lock | day 4: not locked; day 5: locked; run twice: one row | F |
| BR-10 unlock | blocked with an issued invoice; allowed after cancelling it | F |
| BR-11/12 area subsets | database rejects project area not on client and task area not on project (composite FKs), plus friendly UI validation | F |
| BR-13 rate history | no in-place update; resolves effective rate on boundary dates (same-day change counts for that day) | U, F |
| BR-14 leave approval | both approve in either order; one rejects; no manager means HR only; HR requester needs a different HR; same person cannot approve both steps | F |
| BR-15 rejection message | empty message rejected | F |
| BR-16 day counting | weekends/holidays/half-days; non-Mon-Fri country; range across year end; holiday added after submission | U, F |
| BR-17 negative PTO | HR approve without override fails; with override succeeds and is audited; sick over allowance flagged not blocked | F |
| BR-18 allowances | proration (join 1 July), carry-over cap, expiry on 1 April, consumption order, per-user override | U, F |
| BR-19 overlap | adjacent days allowed, overlapping rejected, rejected/cancelled requests ignored; concurrency | F, **C** |
| BR-20 colours | 79.99 green, 80.00 amber, 100.00 amber, 100.01 red, no estimate none | U |
| BR-21 overdue | past due and not Done flagged; Done never overdue; today not overdue | U |
| BR-22 numbering | sequential per year, no gaps after rollback, no reuse after cancel, 20 parallel issues give unique numbers, new year restarts | F, **C** |
| BR-23/24 invoice freeze | issued invoice rejects line edits; a log on an active invoice cannot join another; cancel releases logs | F |
| BR-25 due date | prepayment, end of month (incl. 31 Jan and leap-year Feb), N days boundaries | U |
| BR-26 money visibility | role matrix on every endpoint, Filament column and export (section 5) | F |
| BR-27 chain | cycle rejected; self-manager rejected; deactivating a manager keeps reports' approvals reassignable | F |
| BR-28 offboarding | day 29 not anonymised, day 30 anonymised; personal fields cleared; logs/leave kept; reactivation window | F |
| BR-29 warnings | log on holiday or leave day saves with warning | F |
| BR-30 closed states | cancelled task, draft/cancelled/completed project, inactive client each reject; Done task accepts | F |
| Rounding A-15 | invoice line rounding half-up, totals equal sum of lines; example 1000 -10% +20% VAT = 1080.00 | U |
| Idempotency (MCP) | same key returns the same log, a different key creates another, expired key reusable | F |

## 4. Browser / E2E journeys

| # | Journey |
|---|---|
| E1 | Invitation: receive link, set password, prompted for 2FA, enable 2FA |
| E2 | Employee logs time from the quick-add, sees totals and budget colour change |
| E3 | Employee edits log, then loses ability after month lock |
| E4 | Admin creates client with areas and rates, project, task, assigns; employee sees only that task |
| E5 | Leave request, manager approves, HR approves, balance and calendars update |
| E6 | HR rejects with a message; requester notified |
| E7 | PTO over balance: HR override flow |
| E8 | Finance locks month, builds draft invoice, edits a line, issues, downloads PDF |
| E9 | Superadmin impersonates, blocked actions are refused, banner visible, returns |
| E10 | Superadmin deactivates user; session ends; MCP token dies |
| E11 | Team calendar shows other country's holiday; manager sees subtree only |
| E12 | Responsive: log entry and leave request at 360 px |
| E13 | Keyboard-only log entry; axe scan has no serious violations |
| E14 | Export billing report, download file, role without money sees no money columns |

## 5. Security and permission tests

1. **Role matrix test**: a dataset generated from the grid in 08 (role x permission). One test asserts each cell via policies; another hits each Filament resource/page URL as each role and asserts 200/403/404 as specified.
2. **Row-level isolation**: for each model scoped by `visibleTo`, create data for two unrelated users and assert none leaks (list, detail, export, MCP, calendar).
3. **Attribute-level**: rates and amounts absent in JSON, HTML, CSV, and Livewire payloads for roles without `view-money`.
4. **Mass assignment / tampering**: Livewire property tampering for ids and roles.
5. **Auth flows**: throttling, expired/reused invitation links, 2FA enforcement by setting, deactivation revocation.
6. **Impersonation**: blocked-action list, audit rows with `impersonator_id`, 60-minute expiry.
7. **CSV injection**: export cells starting with `= + - @` are escaped.
8. **Dependency and secrets**: `composer audit`, `npm audit`, secret scan; ZAP baseline on staging.

## 6. Integration, jobs and notifications

- Each scheduled job: idempotency (run twice), boundary dates, and time-zone cases.
- Notifications faked and asserted per event (FR-NOTIF-01): recipients, channels, content, queueing; mail failure does not roll back the business transaction.
- Exports: chunking, memory budget on a seeded 100k-row table, access control of the download link.
- Backups: `backup:run` on CI against a test disk; quarterly manual restore drill (NFR-AV-03).

## 7. MCP tests

Per tool: scope enforcement, cross-user isolation (`not_found`), every error code in 07 section 4, rate limit (61st call), audit row with `source = mcp`, parity with UI (same action result). One contract test validates each tool's JSON schema.

## 8. Performance tests

See 10 section 7. In-suite: query-count assertions (save log <= 12 queries; task list page of 25 <= 10; my calendar month <= 8) and `EXPLAIN` checks that the access patterns in 06 section 3 use their intended index.

## 9. Regression and release

- Every bug gets a failing test first.
- Release candidate on staging: full suite, browser suite, performance suite, manual exploratory checklist (leave edge cases, invoice PDF visual check).
- Test reports and coverage published as CI artefacts.

## 10. Risks to testing

| Risk | Handling |
|---|---|
| Concurrency tests flaky in a single-process runner | Use two real DB connections and explicit `lockForUpdate` waits; mark with a `concurrency` group run serially |
| Pest plugin lagging Laravel 13 | Pin versions, verify before slice 0; fall back to PHPUnit syntax if required (tests are portable) |
| Filament upgrades break UI tests | Prefer testing actions/policies directly; keep Filament UI tests few and high-value |
