# ADR-0010: A Finance role manages expenses

- Status: Accepted (owner decision, 2026-11-12)
- Context: SPEC defines five roles (D-09). ADR-0003 listed a Finance role from the earlier drafts as a conflict. D-17 left expense access open, and the owner answered: "finance and superadmin only manage expenses". That replaces the earlier answer that gave expenses to hr, admin and superadmin.
- Decision: add `RoleName::Finance`. It holds the employee baseline plus the six `expenses.*` permissions; the superadmin holds them too. hr, admin, assistant and employee hold none. Authorisation stays permission-first: policies check `expenses.*`, never the role name. One role per user (D-09) is unchanged, so a person who is both an employee-level worker and the finance contact simply has the finance role, which includes the baseline.
- Consequences: the seeder and `PermissionCatalog` pick the role up from `RoleName::cases()` and the config map; the Employees form offers it; every test that iterates all roles now covers it. Finance gets no access to clients, rates, logs or HR data, because nothing asked for it. The superadmin's Gate::before root access (D-41) is unchanged.
- Rejected: giving expenses to hr and admin as well (the owner said finance and superadmin only); additive multiple roles (D-09).
