# ADR-0005: Authentication, 2FA and SSO

- Status: Proposed (SPEC §9 requires this ADR; no owner decision)
- Decision `[PROPOSED]`: Filament email + password login, no self-registration, password reset by email. Enable Filament's built-in TOTP 2FA, required for superadmin, admin and hr, optional for others, decided in M1 after verifying support in the installed Filament version. SSO is out of v1.
- Consequences: If the owner rejects 2FA, nothing else changes. Recovery codes and reset procedure need a runbook.
- Open question: 2FA mandatory? Is SSO (Google/Microsoft) expected later?
