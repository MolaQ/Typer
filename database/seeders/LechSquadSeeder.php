<?php

namespace Database\Seeders;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechPlayer;
use Illuminate\Database\Seeder;

/**
 * Kadra Lecha na start (do desygnowania zawodników). Lista z wiedzy o składzie z sezonu 2025/26, bez numerów:
 * do sprawdzenia i poprawy w panelu `dashboard/lech-squad` (statusy, wychowankowie, narodowości, odejścia).
 * Można uruchamiać wielokrotnie: istniejący zawodnicy (po nazwisku) nie są zmieniani.
 *
 *   php artisan db:seed --class=LechSquadSeeder
 */
class LechSquadSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::squad() as [$name, $position, $nationality, $academy, $status]) {
            LechPlayer::firstOrCreate(['name' => $name], [
                'position' => $position,
                'nationality' => $nationality,
                'is_academy' => $academy,
                'status' => $status,
            ]);
        }
    }

    /** @return array<int, array{0: string, 1: PlayerPosition, 2: string, 3: bool, 4: PlayerStatus}> */
    public static function squad(): array
    {
        $gk = PlayerPosition::Goalkeeper;
        $df = PlayerPosition::Defender;
        $mf = PlayerPosition::Midfielder;
        $fw = PlayerPosition::Forward;
        $regular = PlayerStatus::Regular;
        $rotation = PlayerStatus::Rotation;
        $reserve = PlayerStatus::Reserve;

        return [
            ['Bartosz Mrozek', $gk, 'Polska', false, $regular],
            ['Filip Bednarek', $gk, 'Polska', false, $reserve],
            ['Joel Pereira', $df, 'Portugalia', false, $regular],
            ['Mateusz Skrzypczak', $df, 'Polska', true, $regular],
            ['Antonio Milić', $df, 'Chorwacja', false, $regular],
            ['Alex Douglas', $df, 'Szwecja', false, $regular],
            ['Michał Gurgul', $df, 'Polska', true, $regular],
            ['Wojciech Mońka', $df, 'Polska', false, $rotation],
            ['Robert Gumny', $df, 'Polska', true, $rotation],
            ['Rasmus Carstensen', $df, 'Dania', false, $rotation],
            ['Elias Andersson', $df, 'Szwecja', false, $rotation],
            ['Antoni Kozubal', $mf, 'Polska', true, $regular],
            ['Radosław Murawski', $mf, 'Polska', false, $regular],
            ['Afonso Sousa', $mf, 'Portugalia', false, $regular],
            ['Patrik Wålemark', $mf, 'Szwecja', false, $regular],
            ['Ali Gholizadeh', $mf, 'Iran', false, $rotation],
            ['Daniel Håkans', $mf, 'Finlandia', false, $rotation],
            ['Pablo Rodríguez', $mf, 'Hiszpania', false, $rotation],
            ['Taofeek Ismaheel', $mf, 'Nigeria', false, $rotation],
            ['Timothy Ouma', $mf, 'Kenia', false, $rotation],
            ['Gísli Thórdarson', $mf, 'Islandia', false, $rotation],
            ['Leo Bengtsson', $mf, 'Szwecja', false, $rotation],
            ['Kornel Lisman', $mf, 'Polska', true, $reserve],
            ['Mikael Ishak', $fw, 'Szwecja', false, $regular],
            ['Yannick Agnero', $fw, 'Wybrzeże Kości Słoniowej', false, $rotation],
            ['Bryan Fiabema', $fw, 'Norwegia', false, $rotation],
            ['Luis Palma', $fw, 'Honduras', false, $rotation],
        ];
    }
}
