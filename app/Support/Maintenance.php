<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Tryb konserwacji z panelu admina (dashboard/maintenance): strona jest niedostępna dla wszystkich poza adminami,
 * którzy widzą ją normalnie (z paskiem przypomnienia) i mogą sprawdzić zmiany przed ponownym otwarciem.
 * Stan trzymamy w pliku (storage/app/maintenance.json), a nie w bazie: odczyt nie kosztuje zapytania przy każdym
 * żądaniu i działa także w trakcie migracji. Na czas wgrywania kodu (composer, migracje) lepsze jest
 * `php artisan down --secret=...`, które blokuje też adminów, ale wpuszcza z sekretnym adresem.
 */
class Maintenance
{
    /** @var array{enabled: bool, message: string, until: ?string, since: ?string, by: ?string}|null */
    private static ?array $state = null;

    /** Trasy dostępne także w trakcie konserwacji: logowanie (żeby admin mógł wejść) i powiadomienia płatności. */
    public const ALLOWED_ROUTES = ['login', 'login.store', 'logout', 'password.*', 'two-factor.*', 'verification.*', 'przelewy24.status'];

    public static function path(): string
    {
        return storage_path('app/maintenance.json');
    }

    /** @return array{enabled: bool, message: string, until: ?string, since: ?string, by: ?string} */
    public static function state(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }

        $data = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
        $data = is_array($data) ? $data : [];

        return self::$state = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'message' => (string) ($data['message'] ?? ''),
            'until' => isset($data['until']) ? (string) $data['until'] : null,
            'since' => isset($data['since']) ? (string) $data['since'] : null,
            'by' => isset($data['by']) ? (string) $data['by'] : null,
        ];
    }

    public static function isOn(): bool
    {
        return self::state()['enabled'];
    }

    /** Planowany koniec (czas polski) albo null. */
    public static function until(): ?Carbon
    {
        $until = self::state()['until'];

        return $until ? Carbon::parse($until) : null;
    }

    /** Czy użytkownik widzi stronę mimo konserwacji (tylko admin). */
    public static function canBypass(?User $user): bool
    {
        return $user?->hasRole(RoleName::Admin->value) ?? false;
    }

    public static function enable(string $message, ?string $until, User $by): void
    {
        self::write([
            'enabled' => true,
            'message' => trim($message),
            'until' => $until ?: null,
            'since' => now()->toDateTimeString(),
            'by' => $by->name,
        ]);

        Audit::log('maintenance.enabled', null, [], ['message' => trim($message), 'until' => $until ?: null], __('Maintenance mode'));
    }

    /** Zmienia tylko komunikat i planowany koniec (bez wyłączania). */
    public static function update(string $message, ?string $until): void
    {
        self::write(['message' => trim($message), 'until' => $until ?: null] + self::state());
    }

    public static function disable(): void
    {
        $state = self::state();
        self::write(['enabled' => false, 'since' => null] + $state);

        Audit::log('maintenance.disabled', null, [], [], __('Maintenance mode'));
    }

    /** @param  array<string, mixed>  $state */
    private static function write(array $state): void
    {
        if (! is_dir(dirname(self::path()))) {
            mkdir(dirname(self::path()), 0775, true);
        }

        file_put_contents(self::path(), (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        self::$state = null;
    }
}
