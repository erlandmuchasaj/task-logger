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

## Open questions for the owner

1. Confirm headcount, client count and logs per day.
2. Is growth beyond 500 employees plausible within 3 years?
3. Is there a month-end peak (everyone logging at once) to size workers for?
