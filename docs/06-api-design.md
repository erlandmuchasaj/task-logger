# 06 — API design

## 1. Position

v1 has **no public REST API and no MCP server** (D-22, D-25, ADR-0004). The UI is Livewire/Filament. What this document defines are **internal contracts** (Actions, DTOs, events, job payloads) that are transport-agnostic, so an API or MCP layer can wrap them later without rewriting rules.

## 2. Versioning and communication

- No external versioning in v1. Internal contracts are PHP types; breaking changes are caught by Larastan and tests.
- Communication is in-process. Async only for exports and scheduled jobs (Redis queue).
- If an API is added later: `/api/v1`, token auth, the same Actions, the same Policies. Requires an ADR.

## 3. Action contracts

Each Action is a single-method class `handle(...)` that authorises via Policy where a user is passed, validates, writes in one DB transaction, writes the activity log and returns a model or DTO. Money is `Money`; durations are `Minutes`.

| Action | Input DTO | Output | Rules |
|---|---|---|---|
| `CreateUser` | `UserData` (+ profile, contacts, skills) | `User` | D-09, FR-01 |
| `ComputeProjectBudget` | `Project` | `Money` + hours | BR-13, BR-14, D-04 |
| `ResolveClientRate` | customer, technology, date | `Money` or throws `RateNotFound` | BR-06 |
| `LogTime` | `LogData` {user, date, projectId, taskId?, technologyId?, minutes, type, description} | `Log` | BR-02..06, BR-07 |
| `UpdateLog` / `DeleteLog` | `Log`, data | `Log` | BR-07; before/after audit |
| `LockMonth` / `UnlockMonth` | month | `TimesheetLock` | D-13 |
| `ChangeTaskStatus` | task, `TaskStatus` | `Task` | BR-35 |
| `CountLeaveDays` | user, start, end, parts | decimal | BR-16, 17 |
| `RequestLeave` | `LeaveRequestData` | `LeaveRequest` | BR-16, 23, 24 |
| `DecideLeave` | request, stage, decision, note? | `LeaveRequest` | BR-20, 21, D-07 |
| `CancelLeave` | request | `LeaveRequest` | BR-19 reversal |
| `CreateDirectDayOff` | `DirectDayOffData` | `LeaveRequest` | BR-22 |
| `ApplyAnnualGrant` | year, country? | count | BR-18, idempotent |
| `CompleteOffboarding` | offboarding | `Offboarding` | ADR-0008 |
| `AnonymizeUser` | user | void | BR-28, D-15 |
| `ExportLogs` etc. | filters + actor | queued `ExportJob` | BR-33 |

### Errors

Domain exceptions extend `DomainException` with a stable `code` and a translatable message shown by Filament as a notification: `RateNotFound`, `MonthLocked`, `DailyCapExceeded`, `NotEligibleForDirectLog`, `LeaveOverlap`, `InsufficientBalance`, `ApproverIsRequester`, `InvalidStatusTransition`.

## 4. Domain events

`LogCreated`, `LogEdited(locked: bool)`, `LeaveRequested`, `LeaveDecided`, `LeaveApproved`, `MonthLocked`, `OffboardingCompleted`, `UserAnonymized`. Used for the `[OPTIONAL]` notifications and for audit enrichment. No external consumers in v1.

## 5. Export job contract (BR-33)

`ExportJob(actorId, resource, filters)`: re-checks permissions at run time, selects columns by the actor's permissions (rate/budget/payment-plan columns only with `clients.view_rates`), streams in chunks to a private disk file, stores a download record, activity-logged.

## Open questions for the owner

1. Confirm no API/MCP in v1 (ADR-0004). If MCP is wanted, in which milestone?
2. Are CSV only, or also XLSX, exports required?
