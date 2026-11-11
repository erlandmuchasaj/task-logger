# ADR-0009: HR record visibility follows the grid

- Status: Accepted
- Context: SPEC §8 says "HR record tabs for superadmin"; D-14, §4.3 owner clarifications and the grid give superadmin, admin and hr access.
- Decision: Follow the grid and D-14: `employees.view_hr_record` / `update_hr_record` for superadmin, admin, hr. §8 wording is treated as an outdated summary.
- Consequences: The privacy test (BR-26) treats exactly these three roles as allowed. Employees reading their own record stays denied until D-14 is confirmed.
