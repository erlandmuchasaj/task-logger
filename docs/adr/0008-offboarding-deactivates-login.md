# ADR-0008: Offboarding completion deactivates login as a system effect

- Status: Accepted
- Context: W-10 and BR-28 say completing an offboarding deactivates login. `employees.deactivate` is superadmin only, but `offboardings.manage` is also held by admin and hr.
- Decision: `CompleteOffboarding` bans the user as a side effect of the permitted action (authorised by `offboardings.manage`), not by checking `employees.deactivate`. The ban is activity-logged with the offboarding as cause.
- Consequences: Manual deactivation outside offboarding remains superadmin-only. Reactivation is superadmin-only.
