# ADR-0003: SPEC.md is authoritative; conflicting sources are listed

- Status: Accepted, owner questions open
- Context: The repo also holds IDEA.md, PRD.md, TRD.md, README.md and an earlier Phase 1 draft (now `docs/archive/phase1-draft/`). They disagree with SPEC.md.
- Decision: SPEC.md wins. Conflicts are not silently resolved; each is listed in 00-business-requirements.md §7 and asked of the owner.
- Conflicts: multiple assignees (IDEA Q8 vs BR-09); invoicing in scope (IDEA Q1, archived S8 vs non-goal); Finance role and additive roles (archive vs D-09); MCP (IDEA Q10 vs D-22, see ADR-0004); 15-minute steps, cancelled/closed-task rules (IDEA/archive vs BR-04); TRD packages not in SPEC §2.3 (Shield, medialibrary, backup, Horizon, Boost).
- Consequences: The legacy draft was archived because it reuses D-xx/BR-xx IDs with different meanings. Nothing from IDEA/TRD is implemented unless the owner confirms.
