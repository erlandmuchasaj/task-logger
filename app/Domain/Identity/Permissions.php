<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use ReflectionClass;
use ReflectionClassConstant;

/**
 * Every permission in the system, as `resource.action` string constants (SPEC 4.1).
 * Application code checks these through Policies and Gates, never role names.
 * Which role holds which permission lives in config/permission.php ('roles').
 */
final class Permissions
{
    /** Employees */
    public const string EMPLOYEES_CREATE = 'employees.create';

    public const string EMPLOYEES_UPDATE = 'employees.update';

    public const string EMPLOYEES_DEACTIVATE = 'employees.deactivate';

    public const string EMPLOYEES_VIEW_HR_RECORD = 'employees.view_hr_record';

    public const string EMPLOYEES_UPDATE_HR_RECORD = 'employees.update_hr_record';

    /** [OPTIONAL] Act as another user (D-47). Superadmin only. */
    public const string EMPLOYEES_IMPERSONATE = 'employees.impersonate';

    /** Salaries */
    public const string SALARIES_VIEW = 'salaries.view';

    public const string SALARIES_MANAGE = 'salaries.manage';

    /** Offboardings */
    public const string OFFBOARDINGS_VIEW = 'offboardings.view';

    public const string OFFBOARDINGS_MANAGE = 'offboardings.manage';

    /** Performances */
    public const string PERFORMANCES_CREATE = 'performances.create';

    public const string PERFORMANCES_VIEW = 'performances.view';

    /** Clients */
    public const string CLIENTS_VIEW = 'clients.view';

    public const string CLIENTS_CREATE = 'clients.create';

    public const string CLIENTS_UPDATE = 'clients.update';

    public const string CLIENTS_ACTIVATE = 'clients.activate';

    public const string CLIENTS_DEACTIVATE = 'clients.deactivate';

    public const string CLIENTS_EXPORT = 'clients.export';

    public const string CLIENTS_VIEW_RATES = 'clients.view_rates';

    /** Payment Plans */
    public const string PAYMENT_PLANS_MANAGE = 'payment_plans.manage';

    /** Projects */
    public const string PROJECTS_VIEW = 'projects.view';

    public const string PROJECTS_CREATE = 'projects.create';

    public const string PROJECTS_UPDATE = 'projects.update';

    public const string PROJECTS_DELETE = 'projects.delete';

    public const string PROJECTS_CHANGE_STATUS = 'projects.change_status';

    public const string PROJECTS_EXPORT = 'projects.export';

    public const string PROJECTS_MANAGE_MEMBERS = 'projects.manage_members';

    /** Fixed Costs */
    public const string FIXED_COSTS_MANAGE = 'fixed_costs.manage';

    /** Tasks */
    public const string TASKS_VIEW_ANY = 'tasks.view_any';

    public const string TASKS_VIEW_OWN = 'tasks.view_own';

    public const string TASKS_CREATE = 'tasks.create';

    public const string TASKS_UPDATE = 'tasks.update';

    public const string TASKS_DELETE = 'tasks.delete';

    public const string TASKS_CHANGE_STATUS = 'tasks.change_status';

    public const string TASKS_CHANGE_STATUS_OWN = 'tasks.change_status_own';

    public const string TASKS_ASSIGN = 'tasks.assign';

    public const string TASKS_EXPORT = 'tasks.export';

    /** Labels */
    public const string LABELS_MANAGE = 'labels.manage';

    /** Logs */
    public const string LOGS_VIEW_ANY = 'logs.view_any';

    public const string LOGS_VIEW_OWN = 'logs.view_own';

    public const string LOGS_CREATE_OWN = 'logs.create_own';

    public const string LOGS_UPDATE_OWN = 'logs.update_own';

    public const string LOGS_DELETE_OWN = 'logs.delete_own';

    public const string LOGS_EXPORT = 'logs.export';

    public const string LOGS_EDIT_LOCKED = 'logs.edit_locked';

    /** Timesheets */
    public const string TIMESHEETS_LOCK = 'timesheets.lock';

    /** Leave Requests */
    public const string LEAVE_REQUESTS_CREATE_OWN = 'leave_requests.create_own';

    public const string LEAVE_REQUESTS_VIEW_OWN = 'leave_requests.view_own';

    public const string LEAVE_REQUESTS_VIEW_ANY = 'leave_requests.view_any';

    public const string LEAVE_REQUESTS_APPROVE = 'leave_requests.approve';

    public const string LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS = 'leave_requests.approve_direct_reports';

    public const string LEAVE_REQUESTS_CREATE_FOR_OTHERS = 'leave_requests.create_for_others';

    /** Leave Types */
    public const string LEAVE_TYPES_MANAGE = 'leave_types.manage';

    /** Country Leave Policies */
    public const string COUNTRY_LEAVE_POLICIES_MANAGE = 'country_leave_policies.manage';

    /** Bank Holidays */
    public const string BANK_HOLIDAYS_MANAGE = 'bank_holidays.manage';

    public const string BANK_HOLIDAYS_VIEW = 'bank_holidays.view';

    /** Countries */
    public const string COUNTRIES_CREATE = 'countries.create';

    public const string COUNTRIES_MANAGE = 'countries.manage';

    /** Departments */
    public const string DEPARTMENTS_CREATE = 'departments.create';

    public const string DEPARTMENTS_MANAGE = 'departments.manage';

    /** Technologies */
    public const string TECHNOLOGIES_CREATE = 'technologies.create';

    public const string TECHNOLOGIES_MANAGE = 'technologies.manage';

    /** Activity Logs */
    public const string ACTIVITY_LOGS_VIEW = 'activity_logs.view';

    /** Settings */
    public const string SETTINGS_MANAGE = 'settings.manage';

    /** Two Factor */
    public const string TWO_FACTOR_MANAGE = 'two_factor.manage';

    /** Expenses [OPTIONAL] (D-51, D-52): finance and superadmin only. */
    public const string EXPENSES_VIEW = 'expenses.view';

    public const string EXPENSES_CREATE = 'expenses.create';

    public const string EXPENSES_UPDATE = 'expenses.update';

    public const string EXPENSES_DELETE = 'expenses.delete';

    public const string EXPENSES_APPROVE = 'expenses.approve';

    public const string EXPENSES_REIMBURSE = 'expenses.reimburse';

    /** System */
    public const string SYSTEM_ROOT = 'system.root';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $values = [];

        foreach (new ReflectionClass(self::class)->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $values[] = $constant->getValue();
        }

        return $values;
    }
}
