# 02 — Non-functional requirements

Turns SPEC §9 into measurable targets. Numbers are `[PROPOSED]` until the owner confirms load (D-22, see 09-scalability.md).

| ID | Area | Target | How measured |
|---|---|---|---|
| NFR-01 | Auth | Login via Filament; no self-registration route exists; password reset by email token; passwords hashed (bcrypt/argon per Laravel default) | Feature test: registration URL 404 |
| NFR-02 | Auth | TOTP 2FA, superadmin-controlled: global on/off, required-for-all, grace period default 14 days (ADR-0005, D-26) | Feature tests: login challenge, grace period, redirect |
| NFR-03 | Authz | 100% of Filament resources, pages and actions guarded by a Policy or Gate on a permission; zero role-name checks in `app/` | CI grep + policy tests |
| NFR-04 | Privacy | Zero private-profile fields reachable by users without `employees.view_hr_record` (BR-26) | Dedicated test over directory query, resource, export |
| NFR-05 | Data protection | HTTPS only in non-local env; HSTS; receipts on private disk; evaluate encrypted casts for DOB, address, phone `[PROPOSED]` | Config test, ADR if encryption adopted |
| NFR-06 | Audit | 100% of business-model writes produce an activity row (BR-32) | One test per model |
| NFR-07 | Retention | Anonymisation job runs daily; processes users whose `end_date + anonymize_after_months <= today`; idempotent | Job test, run twice |
| NFR-08 | Performance | p95 server response ≤ 500 ms for log list (50 rows) and My Day at 500k log rows `[PROPOSED]` | Seeded perf test, Debugbar/`insights` |
| NFR-09 | Performance | No N+1 on any list page (query count ≤ 15 per page) | `preventLazyLoading()` in non-prod + test |
| NFR-10 | Exports | 100k-row logs export finishes ≤ 5 min on queue, memory ≤ 256 MB (chunked) | Perf test |
| NFR-11 | Pagination | All lists paginated; default 25, max 100 | Test |
| NFR-12 | Reliability | Queue workers and scheduler under Supervisor with auto-restart; failed jobs table and retry | Runbook check |
| NFR-13 | Reliability | Scheduled jobs idempotent: leave grant, approved→taken, anonymisation | Run-twice tests |
| NFR-14 | Backup | Daily DB backup, 30-day retention, quarterly restore test; RPO ≤ 24 h, RTO ≤ 4 h `[PROPOSED]` | Runbook |
| NFR-15 | Maintainability | Larastan at level 6 at M0, raised to 8 by M3 `[PROPOSED]`; Pint clean; CI blocks merge on failure | CI |
| NFR-16 | Testing | Every BR-xx has ≥ 1 test citing its ID (`@covers`/test name `BR-xx`); line coverage ≥ 80% on `app/Domain` `[PROPOSED]` | Traceability matrix in 10-testing-strategy.md |
| NFR-17 | Localisation | All user-facing strings via `__()`; English only (SPEC §1.3) | Pint/grep rule |
| NFR-18 | Money | No float type for money anywhere; `*_minor` unsigned bigint + `char(3)` | Larastan + migration review |
| NFR-19 | Time | UTC storage; `date` columns interpreted in country timezone (BR-34) | Unit tests across Europe/Tirane and UTC |
| NFR-20 | Accessibility | Filament defaults; keyboard-operable forms `[PROPOSED]` | Manual check at M7 |
| NFR-21 | Compatibility | Latest two evergreen browsers; responsive to tablet width | Manual |
| NFR-22 | Observability | Laravel logs to daily files; Herd `logs`/`insights` locally; failed-job alerting `[PROPOSED]` | Runbook |

## Open questions for the owner

1. Is SSO ever expected?
2. Confirm RPO/RTO and backup retention.
3. Confirm the performance targets once headcount and volume are known (09-scalability.md).
4. Is encryption of private profile columns at rest required?
