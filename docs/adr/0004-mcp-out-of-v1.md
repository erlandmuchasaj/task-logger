# ADR-0004: MCP / agent API out of v1

- Status: Accepted for v1, owner confirmation requested
- Context: IDEA.md Q10 says no external API but an MCP for coding agents is IMPORTANT. SPEC D-22 says v1 has no public API.
- Decision: v1 ships no REST API and no MCP. Actions and DTOs (06-api-design.md) are written transport-agnostic and Policy-guarded so an MCP layer can wrap them later.
- Consequences: Adding MCP later needs token auth, scopes, rate limits and its own ADR. Candidate slot: after M7.
- Open question: Should MCP be scheduled, and in which milestone?
