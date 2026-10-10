<?php

namespace App\Support;

/**
 * Stały terminarz ligi 10-zespołowej (regulamin: 9 kolejek, każdy z każdym raz).
 *
 * Liczby to numery rozstawienia (seed) w lidze: 1 = zespół najwyżej na liście
 * przedsezonowej. W parze gospodarzem jest zespół o niższym numerze (faworyt).
 * Schemat jest zapisany na sztywno, żeby wynik zawsze był taki sam i żeby
 * można go było sprawdzić testem (45 unikalnych par, w kolejce każdy gra raz).
 */
final class LeagueSchedule
{
    public const TEAMS = 10;

    public const ROUNDS = [
        1 => [[1, 9], [2, 7], [3, 10], [4, 6], [5, 8]],
        2 => [[1, 8], [2, 10], [3, 6], [4, 7], [5, 9]],
        3 => [[1, 10], [2, 5], [3, 8], [4, 9], [6, 7]],
        4 => [[1, 7], [2, 8], [3, 9], [4, 5], [6, 10]],
        5 => [[1, 6], [2, 9], [3, 5], [4, 8], [7, 10]],
        6 => [[1, 5], [2, 6], [3, 7], [4, 10], [8, 9]],
        7 => [[1, 3], [2, 4], [5, 10], [6, 9], [7, 8]],
        8 => [[1, 4], [2, 3], [5, 6], [7, 9], [8, 10]],
        9 => [[1, 2], [3, 4], [5, 7], [6, 8], [9, 10]],
    ];

    /**
     * Płaska lista meczów: [['round' => 1, 'home' => 1, 'away' => 9], ...].
     *
     * @return array<int, array{round: int, home: int, away: int}>
     */
    public static function fixtures(): array
    {
        $list = [];

        foreach (self::ROUNDS as $round => $pairs) {
            foreach ($pairs as [$home, $away]) {
                $list[] = ['round' => $round, 'home' => $home, 'away' => $away];
            }
        }

        return $list;
    }
}
