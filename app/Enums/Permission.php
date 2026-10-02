<?php

namespace App\Enums;

enum Permission: string
{
    case DashboardAccess = 'dashboard-access';

    case UserList = 'user-list';
    case UserCreate = 'user-create';
    case UserEdit = 'user-edit';
    case UserDelete = 'user-delete';

    case RoleList = 'role-list';
    case RoleCreate = 'role-create';
    case RoleEdit = 'role-edit';
    case RoleDelete = 'role-delete';

    case PermissionList = 'permission-list';
    case PermissionCreate = 'permission-create';
    case PermissionEdit = 'permission-edit';
    case PermissionDelete = 'permission-delete';

    // Zatwierdzanie próśb o zmianę nazwy drużyny
    case TeamChangeName = 'team-changename';

    // Przeglądanie dziennika zmian
    case LogView = 'log-view';

    /** Wszystkie nazwy jako tablica tekstów, np. do seedera. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}