<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 30 graczy testowych z polskimi imionami i nazwiskami oraz zespołami nazwanymi jak popularne polskie kluby.
 * Dostają rolę User i potwierdzony e-mail (hasło: password). Jeśli istnieje niezakończony sezon,
 * zajmują losowe miejsca botów w ligach (pozycje 1-100). Ponowne uruchomienie nic nie dubluje.
 */
class PlayersSeeder extends Seeder
{
    /** [imię, nazwisko, domena, nazwa zespołu, skrót nazwy, skrót 3-literowy] */
    private const PLAYERS = [
        ['Jan', 'Kowalski', 'gmail.com', 'Legia Warszawa', 'Legia', 'LEG'],
        ['Anna', 'Nowak', 'wp.pl', 'Wisła Kraków', 'Wisła K.', 'WIS'],
        ['Piotr', 'Wiśniewski', 'onet.pl', 'Górnik Zabrze', 'Górnik', 'GÓR'],
        ['Katarzyna', 'Wójcik', 'interia.pl', 'Śląsk Wrocław', 'Śląsk', 'ŚLĄ'],
        ['Tomasz', 'Kowalczyk', 'o2.pl', 'Pogoń Szczecin', 'Pogoń', 'POG'],
        ['Agnieszka', 'Kamińska', 'gmail.com', 'Jagiellonia Białystok', 'Jagiellonia', 'JAG'],
        ['Krzysztof', 'Lewandowski', 'wp.pl', 'Raków Częstochowa', 'Raków', 'RAK'],
        ['Magdalena', 'Zielińska', 'gmail.com', 'Cracovia', 'Cracovia', 'CRA'],
        ['Paweł', 'Szymański', 'onet.pl', 'Widzew Łódź', 'Widzew', 'WID'],
        ['Monika', 'Woźniak', 'interia.pl', 'ŁKS Łódź', 'ŁKS', 'ŁKS'],
        ['Michał', 'Dąbrowski', 'gmail.com', 'Zagłębie Lubin', 'Zagłębie L.', 'ZAL'],
        ['Joanna', 'Kozłowska', 'o2.pl', 'Ruch Chorzów', 'Ruch', 'RUC'],
        ['Marcin', 'Jankowski', 'wp.pl', 'Korona Kielce', 'Korona', 'KOR'],
        ['Ewa', 'Mazur', 'gmail.com', 'Piast Gliwice', 'Piast', 'PIA'],
        ['Grzegorz', 'Kwiatkowski', 'onet.pl', 'Wisła Płock', 'Wisła P.', 'WPŁ'],
        ['Aleksandra', 'Krawczyk', 'gmail.com', 'Stal Mielec', 'Stal M.', 'STM'],
        ['Łukasz', 'Piotrowski', 'interia.pl', 'Motor Lublin', 'Motor', 'MOT'],
        ['Natalia', 'Grabowska', 'wp.pl', 'Radomiak Radom', 'Radomiak', 'RAD'],
        ['Adam', 'Nowakowski', 'gmail.com', 'GKS Katowice', 'GKS Katowice', 'GKS'],
        ['Karolina', 'Pawłowska', 'o2.pl', 'Arka Gdynia', 'Arka', 'ARK'],
        ['Dariusz', 'Michalski', 'onet.pl', 'Lechia Gdańsk', 'Lechia', 'LGD'],
        ['Beata', 'Król', 'gmail.com', 'Warta Poznań', 'Warta', 'WAR'],
        ['Rafał', 'Wieczorek', 'wp.pl', 'Puszcza Niepołomice', 'Puszcza', 'PUS'],
        ['Dorota', 'Jabłońska', 'interia.pl', 'Odra Opole', 'Odra', 'ODR'],
        ['Jakub', 'Wróbel', 'gmail.com', 'Polonia Warszawa', 'Polonia', 'POL'],
        ['Izabela', 'Majewska', 'o2.pl', 'Zagłębie Sosnowiec', 'Zagłębie S.', 'ZSO'],
        ['Mateusz', 'Olszewski', 'gmail.com', 'Bruk-Bet Termalica', 'Termalica', 'TER'],
        ['Justyna', 'Stępień', 'wp.pl', 'Miedź Legnica', 'Miedź', 'MIE'],
        ['Kamil', 'Malinowski', 'onet.pl', 'Stal Rzeszów', 'Stal R.', 'STR'],
        ['Sylwia', 'Jaworska', 'gmail.com', 'Znicz Pruszków', 'Znicz', 'ZNI'],
    ];

    public function run(): void
    {
        $created = [];

        foreach (self::PLAYERS as $index => [$first, $last, $domain, $team, $short, $abbr]) {
            $email = self::slug($first) . '.' . self::slug($last) . ($index % 3 === 0 ? '' : ($index + 71)) . '@' . $domain;

            $user = User::firstOrCreate(['email' => $email], [
                'name' => $first . ' ' . $last,
                'password' => Hash::make('password'),
                'team_name' => User::where('team_name', $team)->exists() ? null : $team,
                'team_short_name' => User::where('team_short_name', $short)->exists() ? null : $short,
                'team_abbr' => User::where('team_abbr', $abbr)->exists() ? null : $abbr,
            ]);

            if (!$user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if (!$user->roles()->exists()) {
                $user->assignRole(RoleName::User->value);
            }

            $created[] = $user->id;
        }

        $placed = $this->placeInLeagues($created);

        $this->command?->info('Gracze testowi: ' . count($created) . ', dodani do lig: ' . $placed . '.');
    }

    /** Wstawia graczy na losowe miejsca botów w ligach 1-10 najnowszego niezakończonego sezonu. */
    private function placeInLeagues(array $userIds): int
    {
        $season = Season::where('status', '!=', SeasonStatus::Finished->value)->orderByDesc('number')->first();

        if (!$season) {
            return 0;
        }

        $listed = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->pluck('user_id')->all();
        $waiting = array_values(array_diff($userIds, $listed));

        $botSeats = SeasonTeam::where('season_id', $season->id)
            ->whereNull('user_id')
            ->whereBetween('position', [1, 100])
            ->inRandomOrder()
            ->limit(count($waiting))
            ->get();

        foreach ($botSeats as $i => $seat) {
            $seat->update(['user_id' => $waiting[$i], 'bot_id' => null]);
        }

        return $botSeats->count();
    }

    /** "Łukasz" -> "lukasz" (adres e-mail bez polskich znaków). */
    private static function slug(string $text): string
    {
        return strtolower(strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ł' => 'L', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z']));
    }
}
