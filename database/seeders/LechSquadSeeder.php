<?php

namespace Database\Seeders;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechPlayer;
use Illuminate\Database\Seeder;

/**
 * Kadra Lecha na start (do desygnowania zawodników): pierwsza drużyna ze strony lechpoznan.pl (październik 2026),
 * z numerami i pozycjami. Statusy i wychowankowie są wstępne, do poprawy w panelu `dashboard/lech-squad`.
 * Można uruchamiać wielokrotnie: istniejący zawodnicy (po nazwisku) nie są zmieniani.
 *
 *   php artisan db:seed --class=LechSquadSeeder
 */
class LechSquadSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::squad() as [$number, $name, $position, $academy, $status]) {
            LechPlayer::firstOrCreate(['name' => $name], [
                'number' => $number,
                'position' => $position,
                'is_academy' => $academy,
                'status' => $status,
            ]);
        }
    }

    /** @return array<int, array{0: int, 1: string, 2: PlayerPosition, 3: bool, 4: PlayerStatus}> */
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
            [1, 'Mateusz Lis', $gk, false, $regular],
            [31, 'Wiktor Obremski', $gk, true, $reserve],
            [33, 'Mateusz Pruchniewski', $gk, true, $reserve],
            [2, 'Joel Pereira', $df, false, $regular],
            [3, 'Alex Douglas', $df, false, $regular],
            [4, 'João Moutinho', $df, false, $regular],
            [15, 'Michał Gurgul', $df, true, $regular],
            [20, 'Robert Gumny', $df, true, $rotation],
            [27, 'Wojciech Mońka', $df, false, $rotation],
            [59, 'Terry Yegbe', $df, false, $rotation],
            [72, 'Mateusz Skrzypczak', $df, true, $regular],
            [90, 'Hubert Janyszka', $df, true, $reserve],
            [5, 'Gustav Berggren', $mf, false, $regular],
            [8, 'Ali Gholizadeh', $mf, false, $rotation],
            [10, 'Patrik Wålemark', $mf, false, $regular],
            [11, 'Daniel Håkans', $mf, false, $rotation],
            [14, 'Leo Bengtsson', $mf, false, $rotation],
            [17, 'Allahyar Sayyadmanesh', $mf, false, $rotation],
            [21, 'Pablo Rodríguez', $mf, false, $rotation],
            [22, 'Radosław Murawski', $mf, false, $regular],
            [24, 'Filip Jagiełło', $mf, false, $rotation],
            [43, 'Antoni Kozubal', $mf, true, $regular],
            [53, 'Karol Delikat', $mf, true, $reserve],
            [77, 'Luis Palma', $mf, false, $rotation],
            [7, 'Yannick Agnero', $fw, false, $rotation],
            [9, 'Mikael Ishak', $fw, false, $regular],
            [54, 'Kamil Jakóbczyk', $fw, true, $reserve],
            [56, 'Wojciech Szymczak', $fw, true, $reserve],
        ];
    }
}
