<?php

namespace Database\Seeders;

use App\Enums\League;
use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use App\Support\Roster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 30 graczy testowych z polskimi imionami i nazwiskami oraz zespołami nazwanymi jak popularne polskie kluby.
 * Dostają rolę User i potwierdzony e-mail (hasło: password). Jeśli istnieje niezakończony sezon,
 * trafiają do losowych lig 1-10 w miejsce botów (Roster::place). Ponowne uruchomienie nic nie dubluje.
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

    /**
     * Wstawia graczy do losowych lig 1-10 najnowszego niezakończonego sezonu tą samą drogą co panel
     * (App\Support\Roster::place): gracz zajmuje miejsce bota, bot wraca do puli, a jeśli Liga Legend
     * już istnieje, gracz do niej dołącza. Gdy w wylosowanej lidze nie ma bota, próbujemy kolejnych.
     */
    private function placeInLeagues(array $userIds): int
    {
        $season = Season::where('status', '!=', SeasonStatus::Finished->value)->orderByDesc('number')->first();

        if (!$season) {
            return 0;
        }

        $listed = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->pluck('user_id')->all();
        $leagues = array_values(array_filter(League::cases(), fn(League $l) => $l !== League::Podworkowa));
        $placed = 0;

        foreach (array_diff($userIds, $listed) as $userId) {
            foreach (collect($leagues)->shuffle() as $league) {
                if (Roster::place($season->id, $userId, $league)) {
                    $placed++;
                    break;
                }
            }
        }

        return $placed;
    }

    /** "Łukasz" -> "lukasz" (adres e-mail bez polskich znaków). */
    private static function slug(string $text): string
    {
        return strtolower(strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ł' => 'L', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z']));
    }
}
