<?php

declare(strict_types=1);

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Permissions;

// Employee baseline: every role inherits it (D-09).
$employee = [
    Permissions::EMPLOYEES_VIEW_DIRECTORY,

    Permissions::CLIENTS_VIEW,

    Permissions::PROJECTS_VIEW,

    Permissions::TASKS_VIEW_OWN,
    Permissions::TASKS_CHANGE_STATUS_OWN,

    Permissions::LOGS_VIEW_OWN,
    Permissions::LOGS_CREATE_OWN,
    Permissions::LOGS_UPDATE_OWN,
    Permissions::LOGS_DELETE_OWN,

    Permissions::LEAVE_REQUESTS_CREATE_OWN,
    Permissions::LEAVE_REQUESTS_VIEW_OWN,

    Permissions::BANK_HOLIDAYS_VIEW,
];

return [
    /**
     * Role => permissions. Permissions come first; roles are named bundles (SPEC 4.1).
     * Superadmin holds every permission explicitly (D-10: no Gate::before bypass).
     * Every role inherits the employee baseline (D-09). Unspecified means denied.
     * performances.view is not granted to admin: the direct-manager read is a Policy rule (D-21).
     */
    'roles' => [
        RoleName::Superadmin->value => [
            Permissions::EMPLOYEES_VIEW_DIRECTORY,
            Permissions::EMPLOYEES_CREATE,
            Permissions::EMPLOYEES_UPDATE,
            Permissions::EMPLOYEES_DEACTIVATE,
            Permissions::EMPLOYEES_VIEW_HR_RECORD,
            Permissions::EMPLOYEES_UPDATE_HR_RECORD,

            Permissions::SALARIES_VIEW,
            Permissions::SALARIES_MANAGE,

            Permissions::OFFBOARDINGS_VIEW,
            Permissions::OFFBOARDINGS_MANAGE,

            Permissions::PERFORMANCES_CREATE,
            Permissions::PERFORMANCES_VIEW,

            Permissions::CLIENTS_VIEW,
            Permissions::CLIENTS_CREATE,
            Permissions::CLIENTS_UPDATE,
            Permissions::CLIENTS_ACTIVATE,
            Permissions::CLIENTS_DEACTIVATE,
            Permissions::CLIENTS_EXPORT,
            Permissions::CLIENTS_VIEW_RATES,

            Permissions::PAYMENT_PLANS_MANAGE,

            Permissions::PROJECTS_VIEW,
            Permissions::PROJECTS_CREATE,
            Permissions::PROJECTS_UPDATE,
            Permissions::PROJECTS_DELETE,
            Permissions::PROJECTS_CHANGE_STATUS,
            Permissions::PROJECTS_EXPORT,
            Permissions::PROJECTS_MANAGE_MEMBERS,

            Permissions::FIXED_COSTS_MANAGE,

            Permissions::TASKS_VIEW_ANY,
            Permissions::TASKS_VIEW_OWN,
            Permissions::TASKS_CREATE,
            Permissions::TASKS_UPDATE,
            Permissions::TASKS_DELETE,
            Permissions::TASKS_CHANGE_STATUS,
            Permissions::TASKS_CHANGE_STATUS_OWN,
            Permissions::TASKS_ASSIGN,
            Permissions::TASKS_EXPORT,

            Permissions::LABELS_MANAGE,

            Permissions::LOGS_VIEW_ANY,
            Permissions::LOGS_VIEW_OWN,
            Permissions::LOGS_CREATE_OWN,
            Permissions::LOGS_UPDATE_OWN,
            Permissions::LOGS_DELETE_OWN,
            Permissions::LOGS_EXPORT,
            Permissions::LOGS_EDIT_LOCKED,

            Permissions::TIMESHEETS_LOCK,

            Permissions::LEAVE_REQUESTS_CREATE_OWN,
            Permissions::LEAVE_REQUESTS_VIEW_OWN,
            Permissions::LEAVE_REQUESTS_VIEW_ANY,
            Permissions::LEAVE_REQUESTS_APPROVE,
            Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS,
            Permissions::LEAVE_REQUESTS_CREATE_FOR_OTHERS,

            Permissions::LEAVE_TYPES_MANAGE,

            Permissions::COUNTRY_LEAVE_POLICIES_MANAGE,

            Permissions::BANK_HOLIDAYS_MANAGE,
            Permissions::BANK_HOLIDAYS_VIEW,

            Permissions::COUNTRIES_CREATE,
            Permissions::COUNTRIES_MANAGE,

            Permissions::DEPARTMENTS_CREATE,
            Permissions::DEPARTMENTS_MANAGE,

            Permissions::TECHNOLOGIES_CREATE,
            Permissions::TECHNOLOGIES_MANAGE,

            Permissions::ACTIVITY_LOGS_VIEW,

            Permissions::SETTINGS_MANAGE,

            Permissions::TWO_FACTOR_MANAGE,
        ],
        RoleName::Admin->value => [
            ...$employee,

            Permissions::EMPLOYEES_VIEW_HR_RECORD,
            Permissions::EMPLOYEES_UPDATE_HR_RECORD,

            Permissions::SALARIES_VIEW,
            Permissions::SALARIES_MANAGE,

            Permissions::OFFBOARDINGS_VIEW,
            Permissions::OFFBOARDINGS_MANAGE,

            Permissions::PERFORMANCES_CREATE,

            Permissions::CLIENTS_CREATE,
            Permissions::CLIENTS_UPDATE,
            Permissions::CLIENTS_ACTIVATE,
            Permissions::CLIENTS_DEACTIVATE,
            Permissions::CLIENTS_VIEW_RATES,

            Permissions::PROJECTS_CREATE,
            Permissions::PROJECTS_UPDATE,
            Permissions::PROJECTS_DELETE,
            Permissions::PROJECTS_CHANGE_STATUS,
            Permissions::PROJECTS_MANAGE_MEMBERS,

            Permissions::TASKS_VIEW_ANY,
            Permissions::TASKS_CREATE,
            Permissions::TASKS_UPDATE,
            Permissions::TASKS_DELETE,
            Permissions::TASKS_CHANGE_STATUS,
            Permissions::TASKS_ASSIGN,

            Permissions::LOGS_VIEW_ANY,
            Permissions::LOGS_EDIT_LOCKED,

            Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS,

            Permissions::SETTINGS_MANAGE,
        ],
        RoleName::Hr->value => [
            ...$employee,

            Permissions::EMPLOYEES_VIEW_HR_RECORD,
            Permissions::EMPLOYEES_UPDATE_HR_RECORD,

            Permissions::SALARIES_VIEW,
            Permissions::SALARIES_MANAGE,

            Permissions::OFFBOARDINGS_VIEW,
            Permissions::OFFBOARDINGS_MANAGE,

            Permissions::PERFORMANCES_VIEW,

            Permissions::LEAVE_REQUESTS_VIEW_ANY,
            Permissions::LEAVE_REQUESTS_APPROVE,
            Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS,

            Permissions::BANK_HOLIDAYS_MANAGE,

            Permissions::SETTINGS_MANAGE,
        ],
        RoleName::Assistant->value => [
            ...$employee,

            Permissions::CLIENTS_EXPORT,

            Permissions::PROJECTS_EXPORT,

            Permissions::TASKS_VIEW_ANY,
            Permissions::TASKS_EXPORT,

            Permissions::LOGS_VIEW_ANY,
            Permissions::LOGS_EXPORT,

            Permissions::COUNTRIES_CREATE,

            Permissions::DEPARTMENTS_CREATE,

            Permissions::TECHNOLOGIES_CREATE,
        ],
        RoleName::Employee->value => $employee,
    ],

    'models' => [

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your permissions. Of course, it
         * is often just the "Permission" model but you may use whatever you like.
         *
         * The model you want to use as a Permission model needs to implement the
         * `Spatie\Permission\Contracts\Permission` contract.
         */

        'permission' => Permission::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your roles. Of course, it
         * is often just the "Role" model but you may use whatever you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Spatie\Permission\Contracts\Role` contract.
         */

        'role' => Role::class,

        /*
         * When using the "Teams" feature from this package, we need to know which
         * Eloquent model should be used to retrieve your teams. Of course, it
         * is often just the "Team" model but you may use whatever you like.
         */
        'team' => null,

        /*
         * When using the "HasModels" trait and passing raw IDs to syncModels,
         * attachModels, or detachModels, this model class will be used to
         * resolve those IDs. If null, defaults to the guard's model.
         */
        'default_model' => null,
    ],

    'table_names' => [

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'roles' => 'roles',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your permissions. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'permissions' => 'permissions',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your models permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'model_has_permissions' => 'model_has_permissions',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your models roles. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'model_has_roles' => 'model_has_roles',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        /*
         * Change this if you want to name the related pivots other than defaults
         */
        'role_pivot_key' => null, // default 'role_id',
        'permission_pivot_key' => null, // default 'permission_id',

        /*
         * Change this if you want to name the related model primary key other than
         * `model_id`.
         *
         * For example, this would be nice if your primary keys are all UUIDs. In
         * that case, name this `model_uuid`.
         */

        'model_morph_key' => 'model_id',

        /*
         * Change this if you want to use the teams feature and your related model's
         * foreign key is other than `team_id`.
         */

        'team_foreign_key' => 'team_id',
    ],

    /*
     * When set to true, the method for checking permissions will be registered on the gate.
     * Set this to false if you want to implement custom logic for checking permissions.
     */

    'register_permission_check_method' => true,

    /*
     * When set to true, Laravel\Octane\Events\OperationTerminated event listener will be registered
     * this will refresh permissions on every TickTerminated, TaskTerminated and RequestTerminated
     * NOTE: This should not be needed in most cases, but an Octane/Vapor combination benefited from it.
     */
    'register_octane_reset_listener' => false,

    /*
     * Events will fire when a role or permission is assigned/unassigned:
     * \Spatie\Permission\Events\RoleAttachedEvent
     * \Spatie\Permission\Events\RoleDetachedEvent
     * \Spatie\Permission\Events\PermissionAttachedEvent
     * \Spatie\Permission\Events\PermissionDetachedEvent
     *
     * To enable, set to true, and then create listeners to watch these events.
     */
    'events_enabled' => false,

    /*
     * Teams Feature.
     * When set to true the package implements teams using the 'team_foreign_key'.
     * If you want the migrations to register the 'team_foreign_key', you must
     * set this to true before doing the migration.
     * If you already did the migration then you must make a new migration to also
     * add 'team_foreign_key' to 'roles', 'model_has_roles', and 'model_has_permissions'
     * (view the latest version of this package's migration file)
     */

    'teams' => false,

    /*
     * The class to use to resolve the permissions team id
     */
    'team_resolver' => DefaultTeamResolver::class,

    /*
     * Passport Client Credentials Grant
     * When set to true the package will use Passports Client to check permissions
     */

    'use_passport_client_credentials' => false,

    /*
     * When set to true, the required permission names are added to exception messages.
     * This could be considered an information leak in some contexts, so the default
     * setting is false here for optimum safety.
     */

    'display_permission_in_exception' => false,

    /*
     * When set to true, the required role names are added to exception messages.
     * This could be considered an information leak in some contexts, so the default
     * setting is false here for optimum safety.
     */

    'display_role_in_exception' => false,

    /*
     * By default wildcard permission lookups are disabled.
     * See documentation to understand supported syntax.
     */

    'enable_wildcard_permission' => false,

    /*
     * The class to use for interpreting wildcard permissions.
     * If you need to modify delimiters, override the class and specify its name here.
     */
    // 'wildcard_permission' => Spatie\Permission\WildcardPermission::class,

    /* Cache-specific settings */

    'cache' => [

        /*
         * By default all permissions are cached for 24 hours to speed up performance.
         * When permissions or roles are updated the cache is flushed automatically.
         */

        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        /*
         * The cache key used to store all permissions.
         */

        'key' => 'spatie.permission.cache',

        /*
         * You may optionally indicate a specific cache driver to use for permission and
         * role caching using any of the `store` drivers listed in the cache.php config
         * file. Using 'default' here means to use the `default` set in cache.php.
         */

        'store' => 'default',
    ],
];
