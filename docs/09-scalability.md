# 09 — Scalability and performance

SPEC D-22: no numbers were provided. The figures below are **assumptions for the owner to confirm**.

## 1. Load assumptions

| Metric | Assumption | Basis |
|---|---|---|
| Employees | 100 (design ceiling 500) | Internal company tool |
| Active clients / projects | 50 / 150 | Estimate |
| Open tasks | 2,000 | ~15 per project |
| Logs per employee per working day | 3 | Manual entries |
| Logs per day | 300 (ceiling 1,500) | 100 × 3 |
| Logs per year | ~75,000 (ceiling 375,000) | 250 days |
| 5-year log volume | ~375k (ceiling ~1.9M) | |
| Leave requests per year | ~1,000 | 10 per head |
| Concurrent users | ≤ 50 peak, mostly end of day and Friday | |
| Activity rows per year | ~500k | ~5 per business write |

This is a small dataset for MySQL; correctness matters more than scale.

## 2. Hot paths and mitigations

| Path | Risk | Mitigation |
|---|---|---|
| Log lists, exports | Large scans | Indexes (user_id,date), (project_id,date); paginate; chunked queued exports (NFR-10) |
| My Day | Per-load queries | (assignee_id,status); eager loading; pinned tasks single query |
| Project reports (estimated vs logged) | Aggregation | Grouped SQL on indexed columns; cache per project invalidated on log write `[PROPOSED]` |
| Budget recompute | Triggered by rate/hour changes | Recompute in a queued job per affected project |
| Leave balance | SUM over ledger | Index; ledger small (a few entries per user/year) |
| Directory | Joins | Public-column select, eager load skills |
| Activity log | Growth | Index on subject; never prune; partition later if > 10M rows |
| Annual grant job | Burst at 1 Jan | Chunk per country, idempotent |

## 3. Bottlenecks to watch

1. N+1 in Filament tables (use `preventLazyLoading` outside production).
2. Rate lookup per log creation (single indexed query; fine).
3. Redis single instance for cache/queue/session `[ASSUMED]`; separate DB indexes.
4. Export memory use; use `lazy()`/cursor and streaming writer.

## 4. Caching and queues

Cache: country holiday sets per year, active policies, permission cache (spatie). Queue: exports, budget recompute, notifications `[OPTIONAL]`. Workers: 2 under Supervisor.

## 5. Capacity test plan

Seed 100 users, 1.9M logs, 500k activity rows; verify NFR-08..10 in `tests/Performance` and record in the milestone report (M7).

## 6. Measured results (M7, 2026-11-12)

`tests/Performance/CapacityTest.php`, 100 employees, 20 clients, 60 projects with 3 technologies each, logs spread over three years, MySQL 8.4 and PHP 8.5 on a development laptop with debug on (a conservative upper bound). p95 of 15 requests after a warm-up; queries are the most any one request ran.

| Path | NFR | 200k logs | 500k logs | 1.9M logs (ceiling) |
|---|---|---|---|---|
| Log list, all people (admin) | p95 ≤ 500 ms, ≤ 15 queries | 145 ms, 11 q | 281 ms, 11 q | **66 ms**, 10 q |
| My Day | same | 29 ms, 15 q | 33 ms, 15 q | 52 ms, 15 q |
| Own log list (employee) | same | 69 ms, 10 q | 80 ms, 10 q | 58 ms, 9 q |
| Estimated vs logged (all active projects) | same | 258 ms, 14 q | **253 ms**, 13 q | **839 ms**, 13 q |
| Full logs export | ≤ 5 min, ≤ 256 MB | 25.6 s, +7.4 MB | 65.3 s, +7.4 MB | 255 s, +7.4 MB |

Reading it: every NFR-08, NFR-09 and NFR-10 target holds at the 500k volume the targets were written for. At the 1.9M design ceiling everything holds except the report, which takes 839 ms because it must read every log of every matching project once. The export is linear (about 7.5k rows a second) and stays within the 5-minute limit up to roughly 2 million logs, so plan for a second worker or a per-month export beyond that. Memory is flat because the export streams in chunks of 500.

What the measurements changed:

1. The log table asked "is this month locked?" once per row: 136 queries per page. Now one query per request (`LockedMonths`).
2. The report scanned the logs twice (page, then totals): 604 ms at 500k. Now one grouped pass: 253 ms.
3. The all-people log list sorted 1.9M rows by date without an index and counted them: 893 ms. An index on `logs(date)` and simple pagination: 66 ms.
4. A wide covering index for the report was tried and removed: MySQL chose a worse plan and the report took 5.4 s.

If the report must stay under 500 ms beyond about 1M logs, the next step is a summary table of minutes and value per project, technology and month, updated by the log actions (or a nightly job) and read by the report. It is not built because the 500k target is met and the table would add write-path complexity.

Run it again after changing a hot path: `RUN_PERFORMANCE_TESTS=1 PERF_LOGS=500000 php artisan test tests/Performance`.

## Open questions for the owner

1. Confirm headcount, client count and logs per day.
2. Is growth beyond 500 employees plausible within 3 years?
3. Is there a month-end peak (everyone logging at once) to size workers for?
