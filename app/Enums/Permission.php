<?php

namespace App\Enums;

/**
 * Lista wszystkich uprawnień aplikacji (jedno źródło prawdy).
 * Po dopisaniu pozycji uruchom: php artisan db:seed --class=RolesAndPermissionsSeeder
 */
enum Permission: string
{
    // Dostęp do panelu administracyjnego
    case DashboardAccess = 'dashboard-access';

    // Użytkownicy
    case UserList = 'user-list';
    case UserCreate = 'user-create';
    case UserEdit = 'user-edit';
    case UserDelete = 'user-delete';

    // Role
    case RoleList = 'role-list';
    case RoleCreate = 'role-create';
    case RoleEdit = 'role-edit';
    case RoleDelete = 'role-delete';

    // Uprawnienia
    case PermissionList = 'permission-list';
    case PermissionCreate = 'permission-create';
    case PermissionEdit = 'permission-edit';
    case PermissionDelete = 'permission-delete';

    // Zatwierdzanie próśb o zmianę nazwy drużyny
    case TeamChangeName = 'team-changename';

    // Przeglądanie dziennika zmian
    case LogView = 'log-view';

    // Konfiguracja sezonów (NOWE)
    case SeasonList = 'season-list';
    case SeasonCreate = 'season-create';
    case SeasonEdit = 'season-edit';
    case SeasonDelete = 'season-delete';

    // Pisanie newsów na stronę główną
    case NewsCreate = 'news-create';

    /** Wszystkie wartości jako tablica tekstów, np. do seedera. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}