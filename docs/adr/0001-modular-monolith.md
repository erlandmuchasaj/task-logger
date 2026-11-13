# ADR-0001: Modular monolith

- Status: Accepted (SPEC §10 item 5, `[ASSUMED]`)
- Context: Single team, one MySQL database, transactional rules (rate snapshot, ledger, month lock) spanning contexts. TALL + Filament points to one deployable.
- Decision: One Laravel application organised as modules under `app/Domain/{Identity,MasterData,People,Clients,Work,Leave,Expenses}`. Business rules in Actions. Modules call each other through Actions, never write each other's tables.
- Consequences: Simple deployment and transactions. Boundaries enforced by architecture tests, not the network. Extraction to services remains possible later via the Action contracts (06-api-design.md).
- Alternatives rejected: microservices (operational cost with no scale need, 09-scalability.md).
