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

    /** Usuwa zbędne spacje. Wywołaj przed walidacją i zapisem. */
    public static function normalize(array $input): array
    {
        foreach (['team_name'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = Str::squish((string) $input[$key]);
            }
        }

        return $input;
    }
}
