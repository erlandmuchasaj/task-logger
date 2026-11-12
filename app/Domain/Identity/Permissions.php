<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use ReflectionClassConstant;

/**
 * Every permission in the system, as `resource.action` string constants (SPEC 4.1).
 * Application code checks these through Policies and Gates, never role names.
 * Which role holds which permission lives in config/permission.php ('roles').
 */
final class Permissions
{
    /** Employees */
    public const EMPLOYEES_VIEW_DIRECTORY = 'employees.view_directory';

    public const EMPLOYEES_CREATE = 'employees.create';

    public const EMPLOYEES_UPDATE = 'employees.update';

    public const EMPLOYEES_DEACTIVATE = 'employees.deactivate';

    public const EMPLOYEES_VIEW_HR_RECORD = 'employees.view_hr_record';

    public const EMPLOYEES_UPDATE_HR_RECORD = 'employees.update_hr_record';

    /** [OPTIONAL] Act as another user (D-47). Superadmin only. */
    public const EMPLOYEES_IMPERSONATE = 'employees.impersonate';

    /** Salaries */
    public const SALARIES_VIEW = 'salaries.view';

    public const SALARIES_MANAGE = 'salaries.manage';

    /** Offboardings */
    public const OFFBOARDINGS_VIEW = 'offboardings.view';

    public const OFFBOARDINGS_MANAGE = 'offboardings.manage';

    /** Performances */
    public const PERFORMANCES_CREATE = 'performances.create';

    public const PERFORMANCES_VIEW = 'performances.view';

    /** Clients */
    public const CLIENTS_VIEW = 'clients.view';

    public const CLIENTS_CREATE = 'clients.create';

    public const CLIENTS_UPDATE = 'clients.update';

    public const CLIENTS_ACTIVATE = 'clients.activate';

    public const CLIENTS_DEACTIVATE = 'clients.deactivate';

    public const CLIENTS_EXPORT = 'clients.export';

    public const CLIENTS_VIEW_RATES = 'clients.view_rates';

    /** Payment Plans */
    public const PAYMENT_PLANS_MANAGE = 'payment_plans.manage';

    /** Projects */
    public const PROJECTS_VIEW = 'projects.view';

    public const PROJECTS_CREATE = 'projects.create';

    public const PROJECTS_UPDATE = 'projects.update';

    public const PROJECTS_DELETE = 'projects.delete';

    public const PROJECTS_CHANGE_STATUS = 'projects.change_status';

    public const PROJECTS_EXPORT = 'projects.export';

    public const PROJECTS_MANAGE_MEMBERS = 'projects.manage_members';

    /** Fixed Costs */
    public const FIXED_COSTS_MANAGE = 'fixed_costs.manage';

    /** Tasks */
    public const TASKS_VIEW_ANY = 'tasks.view_any';

    public const TASKS_VIEW_OWN = 'tasks.view_own';

    public const TASKS_CREATE = 'tasks.create';

    public const TASKS_UPDATE = 'tasks.update';

    public const TASKS_DELETE = 'tasks.delete';

    public const TASKS_CHANGE_STATUS = 'tasks.change_status';

    public const TASKS_CHANGE_STATUS_OWN = 'tasks.change_status_own';

    public const TASKS_ASSIGN = 'tasks.assign';

    public const TASKS_EXPORT = 'tasks.export';

    /** Labels */
    public const LABELS_MANAGE = 'labels.manage';

    /** Logs */
    public const LOGS_VIEW_ANY = 'logs.view_any';

    public const LOGS_VIEW_OWN = 'logs.view_own';

    public const LOGS_CREATE_OWN = 'logs.create_own';

    public const LOGS_UPDATE_OWN = 'logs.update_own';

    public const LOGS_DELETE_OWN = 'logs.delete_own';

    public const LOGS_EXPORT = 'logs.export';

    public const LOGS_EDIT_LOCKED = 'logs.edit_locked';

    /** Timesheets */
    public const TIMESHEETS_LOCK = 'timesheets.lock';

    /** Leave Requests */
    public const LEAVE_REQUESTS_CREATE_OWN = 'leave_requests.create_own';

    public const LEAVE_REQUESTS_VIEW_OWN = 'leave_requests.view_own';

    public const LEAVE_REQUESTS_VIEW_ANY = 'leave_requests.view_any';

    public const LEAVE_REQUESTS_APPROVE = 'leave_requests.approve';

    public const LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS = 'leave_requests.approve_direct_reports';

    public const LEAVE_REQUESTS_CREATE_FOR_OTHERS = 'leave_requests.create_for_others';

    /** Leave Types */
    public const LEAVE_TYPES_MANAGE = 'leave_types.manage';

    /** Country Leave Policies */
    public const COUNTRY_LEAVE_POLICIES_MANAGE = 'country_leave_policies.manage';

    /** Bank Holidays */
    public const BANK_HOLIDAYS_MANAGE = 'bank_holidays.manage';

    public const BANK_HOLIDAYS_VIEW = 'bank_holidays.view';

    /** Countries */
    public const COUNTRIES_CREATE = 'countries.create';

    public const COUNTRIES_MANAGE = 'countries.manage';

    /** Departments */
    public const DEPARTMENTS_CREATE = 'departments.create';

    public const DEPARTMENTS_MANAGE = 'departments.manage';

    /** Technologies */
    public const TECHNOLOGIES_CREATE = 'technologies.create';

    public const TECHNOLOGIES_MANAGE = 'technologies.manage';

    /** Activity Logs */
    public const ACTIVITY_LOGS_VIEW = 'activity_logs.view';

    /** Settings */
    public const SETTINGS_MANAGE = 'settings.manage';

    /** Two Factor */
    public const TWO_FACTOR_MANAGE = 'two_factor.manage';

    /** Expenses [OPTIONAL] (D-51, D-52): finance and superadmin only. */
    public const EXPENSES_VIEW = 'expenses.view';

    public const EXPENSES_CREATE = 'expenses.create';

    public const EXPENSES_UPDATE = 'expenses.update';

    public const EXPENSES_DELETE = 'expenses.delete';

    public const EXPENSES_APPROVE = 'expenses.approve';

    public const EXPENSES_REIMBURSE = 'expenses.reimburse';

    /** System */
    public const SYSTEM_ROOT = 'system.root';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $values = [];

        foreach ((new \ReflectionClass(self::class))->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $values[] = $constant->getValue();
        }

        return $values;
    }
}
