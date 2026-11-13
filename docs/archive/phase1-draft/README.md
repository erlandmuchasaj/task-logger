# task-logger: Phase 1 design documents

Analysis and design only; no application code. Progress is tracked in [TODO.md](TODO.md). Assumptions are labelled A-nn and consolidated in [00-decisions.md](00-decisions.md).

| # | Document | Purpose |
|---|---|---|
| 00 | [Decisions](00-decisions.md) | Outcome of the requirements grilling plus the assumption register |
| 01 | [Business requirements](01-business-requirements.md) | Why, scope, stakeholders, risks |
| 02 | [Functional requirements](02-functional-requirements.md) | FR IDs with acceptance criteria |
| 03 | [Non-functional requirements](03-non-functional-requirements.md) | Measurable quality targets |
| 04 | [Domain analysis](04-domain-analysis.md) | Aggregates, workflows, business rules BR-01..30 |
| 05 | [Architecture](05-architecture.md) | Modular monolith, package verdicts |
| 06 | [Database design](06-database-design.md) | Tables, constraints, Mermaid ERD |
| 07 | [API / MCP design](07-api-mcp-design.md) | MCP tools, tokens, errors |
| 08 | [Security](08-security.md) | Permissions, roles, policies, audit |
| 09 | [Project organization](09-project-organization.md) | Layout, standards, CI/CD |
| 10 | [Scalability and performance](10-scalability-performance.md) | Load model, caching, queues |
| 11 | [Testing strategy](11-testing-strategy.md) | Test levels, must-test rules |
| 12 | [Implementation roadmap](12-implementation-roadmap.md) | Build slices and milestones |

Reading order: 00, 01, 02, 04, 05, 06, then the rest.
