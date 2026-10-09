<?php

namespace App\Support;

use App\Rules\UniqueTeamValue;
use Illuminate\Support\Str;

class TeamRules
{
    /** Pełna nazwa: 3-40 znaków, unikalna bez względu na wielkość liter. */
    public static function name(?int $ignoreUserId = null): array
    {
        return ['required', 'string', 'min:3', 'max:40', new UniqueTeamValue('team_name', $ignoreUserId)];
    }

    /** Krótka nazwa: 3-20 znaków, unikalna bez względu na wielkość liter. */
    public static function shortName(?int $ignoreUserId = null): array
    {
        return ['required', 'string', 'min:3', 'max:20', new UniqueTeamValue('team_short_name', $ignoreUserId)];
    }

    /** Skrót: 3-6 liter (także polskich), unikalny bez względu na wielkość liter. */
    public static function abbr(?int $ignoreUserId = null): array
    {
        return ['required', 'string', 'regex:/^\p{L}{3,6}$/u', new UniqueTeamValue('team_abbr', $ignoreUserId)];
    }

    /** Usuwa zbędne spacje i zamienia skrót na wielkie litery. Wywołaj przed walidacją i zapisem. */
    public static function normalize(array $input): array
    {
        foreach (['team_name', 'team_short_name', 'team_abbr'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = Str::squish((string) $input[$key]);
            }
        }

        if (isset($input['team_abbr'])) {
            $input['team_abbr'] = mb_strtoupper($input['team_abbr']);
        }

        return $input;
    }
}
