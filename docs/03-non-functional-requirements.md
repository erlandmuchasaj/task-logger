# 03. Non-functional requirements

Every target is measurable. Load assumptions come from [10-scalability-performance.md](10-scalability-performance.md): up to 200 users, about 30 countries, about 4,000 logs/day at peak (200 users x 20 logs is a deliberately high ceiling; the realistic figure is 200 x 3 = 600/day), about 1.5M logs after 10 years.

## Performance

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-PERF-01 | Interactive page loads (task list, my logs, calendar month) | p95 server time < 400 ms, p99 < 1 s at 200 concurrent-user profile | k6 load test on staging with production-sized data |
| NFR-PERF-02 | Saving a time log | p95 < 300 ms | k6, plus feature test counting queries (<= 12) |
| NFR-PERF-03 | Billing report for one client and month | p95 < 2 s on 1.5M-row logs table | Seeded benchmark; index usage checked with EXPLAIN |
| NFR-PERF-04 | Exports (CSV/XLSX) of up to 100,000 rows | Complete in < 2 min, run on the queue, memory < 256 MB per job | Queue job test with chunked reads |
| NFR-PERF-05 | Team calendar (month, 200 users) | p95 < 800 ms | Seeded benchmark |
| NFR-PERF-06 | N+1 queries | 0 in tested list pages (Filament resources and Livewire pages) | `preventLazyLoading()` enabled in non-production and in tests |
| NFR-PERF-07 | MCP tool calls | p95 < 500 ms | Pest feature tests with timing budget, load test |

## Availability and recovery

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-AV-01 | Availability during business hours (**Assumption**: internal tool, 07:00-20:00 UTC weekdays) | 99.5% monthly | Uptime monitor |
| NFR-AV-02 | Planned deploys | Zero-downtime (maintenance < 30 s on migrations) | Deploy runbook, dry run on staging |
| NFR-AV-03 | RPO / RTO | RPO <= 24 h (nightly backup; binlog gives point-in-time within 15 min if enabled), RTO <= 4 h | Quarterly restore drill, recorded |
| NFR-AV-04 | Queue resilience | Failed jobs retried with backoff (3 attempts), then kept in `failed_jobs`, alert raised | Test with a forced failure |
| NFR-AV-05 | Scheduled jobs (lock, carry-over, anonymise, overdue) idempotent | Running twice yields the same state | Pest tests |

## Security

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-SEC-01 | Transport | HTTPS only, HSTS 1 year, TLS 1.2+ | SSL Labs grade A |
| NFR-SEC-02 | Authentication | Password min 12 chars, breach check, throttling 5/min, TOTP 2FA per D-42, session idle timeout 120 min (configurable) | Feature tests |
| NFR-SEC-03 | Authorisation | Deny by default. Every Filament resource/page and Livewire action backed by a policy or gate; scoped queries for row-level data | Architecture test that every Policy exists and every resource uses one |
| NFR-SEC-04 | Sensitive data | Rates, money, invoices, balances never in responses to unauthorised roles | Role-matrix feature tests (08) |
| NFR-SEC-05 | Secrets | Tokens hashed at rest; 2FA secrets encrypted; `.env` not in repo; rotation runbook | Review plus CI secret scan |
| NFR-SEC-06 | Dependencies | `composer audit` and `npm audit` in CI, no high/critical findings at release | CI gate |
| NFR-SEC-07 | OWASP Top 10 | CSRF, XSS (escaped output), mass-assignment protection, SQL injection (parameterised only), security headers (CSP report-only first) | Checklist per release |
| NFR-SEC-08 | MCP | Token scopes enforced, 60 req/min/token, all calls audited | Feature tests |

## Privacy and compliance

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-PRIV-01 | Anonymisation | Within 31 days of deactivation, 100% of personal fields cleared per FR-USR-06 | Pest test and monthly job report |
| NFR-PRIV-02 | Data minimisation | Only data required by the domain model is stored. No tracking, no third-party analytics | Review |
| NFR-PRIV-03 | Retention | Business data kept indefinitely, activity log 2 years (except financial events), failed jobs 14 days | Prune job test |
| NFR-PRIV-04 | Data subject request | Export of one user's personal data in < 1 working day (admin tool or documented query) | Runbook |

## Internationalisation and localisation

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-I18N-01 | UI language | English; 100% of user-facing strings via `__()` or Filament translation keys | Automated check (Pint rule/grep in CI) on `resources/views`, Filament classes |
| NFR-I18N-02 | Timezones | Timestamps stored in UTC, rendered in the user's timezone; calendar dates stored as plain dates (A-01) | Tests at UTC-11, UTC+0, UTC+14 including DST changes |
| NFR-I18N-03 | Formats | Dates, numbers and currency formatted per the user's locale, 2 decimals for money | Snapshot tests |
| NFR-I18N-04 | Adding a language later | Requires only translation files, no code changes | Add a dummy locale in CI test |

## Accessibility

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-A11Y-01 | Conformance | WCAG 2.2 AA for custom Livewire pages (Filament components are accepted as shipped, gaps tracked) | axe-core scan in browser tests, manual keyboard pass per release |
| NFR-A11Y-02 | Colour meaning | Budget and overdue states never rely on colour alone (icon plus text label) | Browser test |
| NFR-A11Y-03 | Keyboard | Time-log quick-add and calendar usable with keyboard only | Manual check |
| NFR-A11Y-04 | Contrast | Text 4.5:1 minimum, including dark mode if enabled | axe-core |

## Auditability

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-AUD-01 | Coverage | 100% of events listed in FR-AUD-01 produce an activity row with actor, old/new values, UTC time, source | Feature tests per model |
| NFR-AUD-02 | Immutability | No UI or application path edits or deletes audit rows | Test; DB user for the app has no DELETE on the audit table in production (**Assumption**: acceptable to implement with a MySQL grant) |
| NFR-AUD-03 | Traceability of money | Any invoice amount can be reconstructed from its frozen lines and the logs referenced | Reconciliation test |

## Maintainability and quality

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-MNT-01 | Static analysis | Larastan level 8 (start at 6 if legacy noise, never below 6), 0 errors in CI | CI |
| NFR-MNT-02 | Code style | Laravel Pint (PSR-12 preset), 0 diffs in CI | CI |
| NFR-MNT-03 | Test coverage | >= 85% line coverage on domain modules (Leave, TimeTracking, Billing), >= 70% overall | Pest coverage in CI |
| NFR-MNT-04 | Module boundaries | No module touches another module's tables directly; checked by architecture tests | Pest architecture tests |
| NFR-MNT-05 | Dependency freshness | Security updates within 7 days; majors reviewed quarterly | Renovate/Dependabot |

## Observability

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-OBS-01 | Error tracking | All unhandled exceptions reported with user id and request id (**Assumption**: Sentry or Flare) | Forced exception on staging |
| NFR-OBS-02 | Logs | Structured JSON logs, 30 days retention, no secrets or personal data in logs | Review |
| NFR-OBS-03 | Health | `/up` health check, queue depth and failed-job alerts, scheduler heartbeat | Alert test |

## Usability

| ID | Requirement | Target | How verified |
|---|---|---|---|
| NFR-UX-01 | Time entry speed | A new log takes <= 3 interactions from the dashboard (select task, hours, description) | Browser test + usability check |
| NFR-UX-02 | Responsive | Usable at 360 px width for log entry, my tasks, leave request | Browser test with mobile viewport |
| NFR-UX-03 | Errors | Validation messages say what to do next (e.g. remaining hours today) | Review |
