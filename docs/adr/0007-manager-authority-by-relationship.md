# ADR-0007: Direct-manager authority follows the relationship

- Status: Accepted with open owner question
- Context: `users.manager_id` has no role constraint. The grid grants `leave_requests.approve_direct_reports` and the manager branch of `performances.view` only to superadmin, admin and hr. An employee-role user can still be someone's manager.
- Decision: The Policy requires both the permission and `requester.manager_id == actor.id`. A manager lacking the permission cannot act. For performances, the direct manager reads via a relationship rule (D-21), as the source states, independent of the permission.
- Consequence: If an employee-role user manages others, the manager stage cannot be completed by them; superadmin may decide any stage (BR-20). Operational remedy: assign managers with role admin or hr.
- Open question: Grant `approve_direct_reports` to employee, or require managers to be admin/hr (like project managers, BR-30)?
