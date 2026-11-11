# task-logger — documentation

`SPEC.md` is the authoritative specification. The files below are the Phase 0 deliverables (SPEC §10). The earlier draft is in `archive/phase1-draft/` (superseded; its D-xx/BR-xx IDs differ).

| # | Document | Purpose |
|---|---|---|
| 00 | [Business requirements](00-business-requirements.md) | Goals, scope, non-goals, actors, source conflicts |
| 01 | [Functional requirements](01-functional-requirements.md) | FR-xx with acceptance criteria, linked to BR and workflows |
| 02 | [Non-functional requirements](02-non-functional-requirements.md) | Measurable targets |
| 03 | [Domain analysis](03-domain-analysis.md) | Entities, enums, state machines |
| 04 | [Architecture](04-architecture.md) | Modular monolith, package verification |
| 05 | [Database design](05-database-design.md) | ERD, constraints, indexes, migration order |
| 06 | [API design](06-api-design.md) | Internal contracts; no public API in v1 |
| 07 | [Security](07-security.md) | AuthN/AuthZ, permission grid, privacy, audit |
| 08 | [Project organisation](08-project-organization.md) | Standards, branching, CI/CD |
| 09 | [Scalability](09-scalability.md) | Load assumptions, bottlenecks |
| 10 | [Testing strategy](10-testing-strategy.md) | Test plans and BR traceability matrix |

ADRs: [adr/](adr/) (0001–0009). Decision log: SPEC §3 (D-01…D-30).
