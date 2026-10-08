<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\SeasonTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kto może grać w sezonie?
 * Gracz to użytkownik z co najmniej jedną rolą (admin nadaje rolę po rejestracji,
 * to jest "zatwierdzenie" konta), który NIE ma roli "Banned" ani "Inactive". Zbanowany użytkownik
 * nie bierze udziału w żadnych rozgrywkach, więc każdy kod wybierający uczestników
 * (lista, ligi, puchar, typowanie) powinien korzystać z Players::eligible().
 * Jeśli gracz nie ma nazwy zespołu, na liście pokazuje się jego nazwa wyświetlana
 * (patrz SeasonTeam::name).
 */
class Players
{
    /** Nazwa roli blokującej udział w zabawie. Rolę tworzysz w "Rolach i uprawnieniach". */
    public const BANNED_ROLE = RoleName::Banned->value;

    /** Role wykluczające z gry: ban i nieaktywność (5 kolejek z rzędu bez typu). */
    public const BLOCKING_ROLES = [RoleName::Banned->value, RoleName::Inactive->value];

    /** Użytkownicy z rolą i bez bana, czyli ci, którzy mogą być na liście. */
    public static function eligible(): Builder
    {
        return User::query()
            ->whereHas('roles')
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::BLOCKING_ROLES));
    }

    /** Użytkownicy bez żadnej roli (jeszcze niezatwierdzeni). Zbanowani mają rolę, więc tu ich nie ma. */
    public static function withoutRole(): Builder
    {
        return User::query()->whereDoesntHave('roles');
    }

    /** Gracze z rolą (bez zbanowanych), których nie ma jeszcze na liście danego sezonu. */
    public static function unlisted(int $seasonId): Builder
    {
        return self::eligible()->whereNotIn(
            'id',
            SeasonTeam::where('season_id', $seasonId)->whereNotNull('user_id')->select('user_id'),
        );
    }

    /** Czy ten użytkownik może grać (rola jest, bana nie ma)? */
    public static function canPlay(User $user): bool
    {
        return $user->roles->isNotEmpty() && ! $user->hasAnyRole(self::BLOCKING_ROLES);
    }
}
