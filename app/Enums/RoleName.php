<?php

namespace App\Enums;

/**
 * Role stałe aplikacji (jedno źródło prawdy, tak jak Permission dla uprawnień).
 * Nazwa RoleName, żeby nie mylić z modelem Spatie\Permission\Models\Role.
 * Po dopisaniu pozycji uruchom: php artisan db:seed --class=RolesAndPermissionsSeeder
 */
enum RoleName: string
{
    /** Pełny dostęp (Gate::before przepuszcza go przez wszystkie sprawdzenia). */
    case Admin = 'Admin';

    /** Zwykły gracz: konto zatwierdzone przez admina, gra w sezonach. */
    case User = 'User';

    /** Gracz premium (dostęp na czas określony, nie wpływa na punkty). */
    case Premium = 'Premium';

    /** Zbanowany: nie bierze udziału w żadnych rozgrywkach (patrz App\Support\Players). */
    case Banned = 'Banned';

    /** Wszystkie nazwy jako tablica tekstów, np. do seedera. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Kolor plakietki flux:badge w tabeli użytkowników. */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'amber',
            self::User => 'blue',
            self::Premium => 'purple',
            self::Banned => 'red',
        };
    }
}
