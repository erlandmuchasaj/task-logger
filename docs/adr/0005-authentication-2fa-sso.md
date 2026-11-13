# ADR-0005: Authentication, 2FA and SSO

- Status: Accepted (owner decision, 2026-11-11). Supersedes the earlier proposal.
- Context: SPEC section 9 requires a decision on 2FA and SSO. The owner asked for two-factor authentication that the superadmin controls from the panel.
- Decision:
  - Login is Filament email and password. No self-registration; password reset by email.
  - 2FA is time-based one-time codes from an authenticator app (Filament's built-in `AppAuthentication`, with recovery codes). Users enrol on their own Profile page.
  - **Global switch.** The superadmin turns 2FA on or off for the whole application. When off, nobody is challenged and nobody can enrol; existing enrolments are kept.
  - **Required for everyone.** The superadmin can require 2FA for all users. This only applies while 2FA is on.
  - **Grace period.** Once required, users without 2FA have a grace period, 14 days by default. The default is in `config/task-logger.php` (`two_factor.grace_days`); the superadmin changes it on the settings page. After it ends, every panel page redirects to the Profile page until the user enrols.
  - Settings live in `SecuritySettings` (spatie/laravel-settings), edited on the Two-factor authentication page. Permission `two_factor.manage`, held by the superadmin only.
  - SSO is out of v1.
- Assumptions to confirm:
  - The grace period counts from the moment the requirement is switched on, or from the day the user was created if that is later, so new hires get a full period.
  - The end date is computed from the current grace setting, so extending or shortening it applies immediately.
  - Email codes are not offered; only the authenticator app.
- Consequences:
  - Filament's own "required" flag is all-or-nothing, so enforcement uses `EnforceTwoFactorGracePeriod` middleware; the TOTP secret is encrypted at rest and never written to the activity log (only `two_factor_enabled` / `two_factor_disabled` events).
  - There is no superadmin action yet to reset a user who lost both device and recovery codes. Until one exists, that needs a database change. Candidate for M2 (people management).
