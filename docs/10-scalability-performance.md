# 10. Scalability and performance

## 1. Load model (all numbers are **assumptions**, A-33)

| Input | Typical | Stress ceiling | Basis |
|---|---|---|---|
| Employees | 120 | 200 | D-49 (50-200) |
| Countries | 30 | 30 | D-49 |
| Logs per employee per working day | 3 | 20 | One log per task per day; ceiling covers bulk back-filling |
| Working days per year | 250 | 250 | |
| Logs per day | 360 (typical at 120 users) to 600 (200 users) | 4,000 | |
| Logs per year | about 150,000 (200 users x 3 x 250) | about 1,000,000 | |
| Logs after 10 years | about 1.5 million | | NFR-03 target table size |
| Leave requests per user per year | 8 | 20 | |
| Ledger rows per user per year | about 25 | | grants + deductions |
| Web requests per user per day | 100 (Filament/Livewire are chatty) | 300 | |
| Peak request rate | about 10 req/s (200 users x 100 / 8h, 3x peak factor) | 30 req/s | end-of-day logging bursts |
| Concurrent users at peak | 60 | 120 | about 30% of staff |
| MCP calls | 30 active agents x 30 calls/day = 900/day | 10,000/day | |
| Invoices | 50 clients x 12 = 600/year | | |
| Exports | 20/day | 100/day | up to 100,000 rows each |

Storage: `time_logs` row about 300 bytes plus indexes (about 6 indexes) is roughly 1 KB/row all-in, so 1.5M rows is about 1.5 GB. Activity log about 5 rows per business action: about 3-4M rows over 2 years (about 2 GB, pruned). Total database under 10 GB after 10 years. This is small for MySQL; no sharding or partitioning needed.

Conclusion: a single application server (4 vCPU, 8 GB RAM) with MySQL and Redis co-located handles the stress ceiling comfortably. The design priority is **correctness under concurrency** and **avoiding N+1 / unbounded queries**, not raw scale.

## 2. Likely bottlenecks and mitigations

| # | Bottleneck | Why | Mitigation |
|---|---|---|---|
| 1 | Billing report and invoice generation | Aggregates a month of a client's logs, joined to projects/areas/types | Covering index `(client_id, work_date, project_id, area_id, hour_type_id)`; aggregate in SQL (`SUM(minutes) GROUP BY`) not PHP; report runs as a query on demand and caches by `(client, month, lock-version)` for 10 minutes; target p95 < 2 s (NFR-PERF-03) |
| 2 | Budget colours on task and project lists | Needs logged minutes per task for each page of rows | One grouped query per page: `WHERE task_id IN (page ids) GROUP BY task_id` (indexed). Project-area: `GROUP BY project_id, area_id`. No per-row queries. If measurement shows trouble, add maintained counters (`logged_minutes` on tasks/project_areas updated in the log transaction) reconciled nightly: **not built in Phase 1** |
| 3 | Team calendar | 200 users x 30 days x leave/holidays/logs | Range-bound month query; leave by `(start_date, end_date)` index; holidays cached per `(country, year)` (changes rarely); daily hour totals in one grouped query limited to the visible users; no per-day queries |
| 4 | Manager subtree resolution | Recursive structure | MySQL recursive CTE (200 rows, microseconds), memoised per request; cached 5 min per user, invalidated when any `manager_id` changes |
| 5 | Permissions lookups | Every request | spatie cache in Redis; eager load roles with the auth user |
| 6 | Exports | Up to 100k rows | Queued on `exports` queue, chunked (`lazyById(2000)`), streamed write via Filament export (OpenSpout), memory target under 256 MB, file on private disk, 24h expiry, notification when ready |
| 7 | Notification bursts | Month lock, holiday added, reminders to 200 users | Queued on `notifications`, batched, mail rate limited to provider limits |
| 8 | Daily-cap race and numbering | Concurrent inserts | Row locks on the user row (cap) and `invoice_sequences` row (numbering); lock held for milliseconds. Verified by concurrency tests |
| 9 | Activity log growth | Many rows | Index `(subject_type, subject_id)`, prune job (2 years, except financial), no log of read events |
| 10 | Filament tables with many columns/relations | Easy N+1 | `preventLazyLoading`, explicit `with()`, `deferLoading`, paginate (25 default), search indexed columns only |
| 11 | Leave ledger sums | Frequent | Tiny volume; `(user_id, type, year)` index; no cache needed |
| 12 | Auto-lock/anonymise/overdue jobs | Daily | Cheap indexed queries; idempotent |

## 3. Caching strategy

| Cache | Key | TTL | Invalidation |
|---|---|---|---|
| Permissions | spatie default | until change | on role/permission change |
| Holidays per country-year | `holidays:{country}:{year}` | 24h | on holiday create/update/delete |
| Manager subtree ids | `subtree:{user}` | 5 min | on any manager_id/is_active change |
| Billing report | `billing:{client}:{ym}:{lockver}` | 10 min | on month lock/unlock, log correction in that month |
| Project budget summary | `budget:project:{id}` | 60 s | on `TimeLogged/Changed` for that project |
| Settings | spatie settings | until change | on save |
| Lookups (areas, hour types, departments) | `lookup:{name}` | 1 h | on save |

Rules: cache only read models; never cache authorisation results across users; every cache has an explicit invalidation event; correctness first: if in doubt, don't cache. Redis `cache` store with a key prefix per environment.

## 4. Queue strategy

| Queue | Jobs | Workers | Retry |
|---|---|---|---|
| `default` | Event listeners (audit, budget recalculation), PDF generation | 2 | 3 attempts, backoff 10s/60s/300s |
| `notifications` | Mail and database notifications | 1-2 | 5 attempts, backoff, then failed |
| `exports` | Filament exports, billing exports | 1 | 2 attempts, timeout 10 min |

- Supervisor keeps workers alive; Horizon (if compatible) supervises balancing and shows metrics.
- Jobs are idempotent and carry ids, not models.
- `queue:restart` on every deploy.
- Failed jobs retained 14 days; alert on any failure for money-related jobs.

## 5. Scheduler

| Schedule | Job | Notes |
|---|---|---|
| Daily 00:05 (company tz) | Auto-lock previous month if today is day 5 or later and not yet locked | idempotent; catches missed days |
| Jan 1 00:10 | Grant yearly allowances, carry-over | idempotent per (user, year) |
| Apr 1 00:10 | Expire carry-over | ledger entry |
| Daily | Anonymise users deactivated > 30 days | |
| Daily | Mark invoices overdue | |
| Daily 08:00 | Approval reminders | A-13 |
| Daily | Prune: activity log (2y, excl. financial), notifications, expired idempotency keys, expired export files, invitations | |
| Nightly | `backup:run`, `backup:clean`, `backup:monitor` | |
| Every minute | Heartbeat for the scheduler monitor | |

`->onOneServer()` and `withoutOverlapping()` on all jobs so scale-out never double-runs.

## 6. Scale-out path (not needed now)

1. Move MySQL and Redis to managed services.
2. Run web on 2+ nodes behind a load balancer (sessions already in Redis, files on S3-compatible disk).
3. Dedicated worker node for queues and the scheduler (`onOneServer`).
4. Read replica for reports (billing, exports) with a `reports` connection.
5. If `time_logs` ever exceeds tens of millions of rows: partition by year on `work_date` (requires including `work_date` in the PK; plan only if needed).

## 7. Performance verification

| Test | Tool | Pass criteria |
|---|---|---|
| Seed production-sized data | `PerformanceSeeder`: 200 users, 1.5M logs, 30 countries, 600 invoices | completes in < 15 min |
| Load (web) | k6: 60 users, 15 min, mix: dashboard, my logs, save log, task list, calendar, leave request | NFR-PERF-01/02, error rate < 0.5% |
| Spike (end-of-day) | k6: 120 users saving logs within 5 min | no lost writes, p95 < 600 ms, cap rule never violated |
| Report | scripted calls | NFR-PERF-03 |
| Query budget | Pest feature tests assert query counts (e.g. save log <= 12, task list page <= 10) | |
| EXPLAIN review | CI script checks listed access patterns use their index (06 section 3) | no full scans on `time_logs` |
| Soak | 8 h at 30% load | memory stable, queue depth returns to 0 |

Run on staging at each release candidate and whenever a migration touches `time_logs`.
