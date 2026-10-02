<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\TeamRules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input = TeamRules::normalize($input);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'team_name' => TeamRules::name(),
            'team_short_name' => TeamRules::shortName(),
            'team_abbr' => TeamRules::abbr(),
        ], [], [
            'team_name' => __('Team name'),
            'team_short_name' => __('Team short name'),
            'team_abbr' => __('Team abbreviation'),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'team_name' => $input['team_name'],
            'team_short_name' => $input['team_short_name'],
            'team_abbr' => $input['team_abbr'],
        ]);
    }
}