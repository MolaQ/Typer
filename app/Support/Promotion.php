<?php

namespace App\Support;

/**
 * Awanse i spadki między sezonami (regulamin, punkt 9). Czysta funkcja bez bazy.
 *
 * Wejście: poziom ligi (1-10, 11 = Liga podwórkowa) => zespoły w kolejności tabeli końcowej,
 * każdy jako ['id' => int, 'human' => bool]. Wyjście: poziom => id w kolejności nowej listy.
 *
 * Zasady:
 *  - między sąsiednimi ligami 4 najgorsze spadają, 4 najlepsze z niższej awansują,
 *    z Ekstraklasy nikt nie awansuje, z podwórkowej nikt nie spada,
 *  - czyszczenie z botów: po podstawowej wymianie, dopóki najgorszy z pozostałych w wyższej lidze
 *    jest botem, a najlepszy z pozostałych w niższej człowiekiem, zamieniamy ich (do EXTRA_SWAPS razy).
 *    "Pozostali" to zespoły, które jeszcze nigdzie się nie przenoszą, więc każdy zmienia ligę najwyżej o jedną,
 *  - kolejność w nowej lidze: spadkowicze z wyższej, potem pozostali według tabeli, na końcu beniaminkowie.
 */
final class Promotion
{
    public const MOVES = 4;

    public const EXTRA_SWAPS = 6;

    /**
     * @param  array<int, array<int, array{id: int, human: bool}>>  $tiers
     * @return array<int, array<int, int>>
     */
    public static function apply(array $tiers, int $moves = self::MOVES, int $extraSwaps = self::EXTRA_SWAPS): array
    {
        ksort($tiers);
        $levels = array_keys($tiers);
        $lowest = max($levels);

        $down = []; // poziom => zespoły spadające z tego poziomu (do poziom + 1)
        $up = [];   // poziom => zespoły awansujące z tego poziomu (do poziom - 1)
        $moving = [];

        // Podstawowa wymiana po $moves zespołów.
        foreach ($levels as $level) {
            $teams = array_values($tiers[$level]);
            $up[$level] = $level > 1 ? array_slice($teams, 0, $moves) : [];
            $down[$level] = $level < $lowest ? array_slice($teams, -$moves) : [];

            // Mała liga (np. pusta podwórkowa): awans i spadek nie mogą dotyczyć tych samych zespołów.
            $upIds = array_column($up[$level], 'id');
            $down[$level] = array_values(array_filter($down[$level], fn ($t) => ! in_array($t['id'], $upIds, true)));

            foreach (array_merge($up[$level], $down[$level]) as $team) {
                $moving[$team['id']] = true;
            }
        }

        // Czyszczenie z botów, granica po granicy od góry.
        foreach ($levels as $level) {
            if ($level === $lowest) {
                break;
            }

            $upper = array_values(array_filter(array_reverse($tiers[$level]), fn ($t) => ! isset($moving[$t['id']])));
            $lower = array_values(array_filter($tiers[$level + 1], fn ($t) => ! isset($moving[$t['id']])));

            for ($swap = 0; $swap < $extraSwaps && $upper !== [] && $lower !== []; $swap++) {
                if ($upper[0]['human'] || ! $lower[0]['human']) {
                    break;
                }

                $bot = array_shift($upper);
                $human = array_shift($lower);
                $down[$level][] = $bot;
                $up[$level + 1][] = $human;
                $moving[$bot['id']] = true;
                $moving[$human['id']] = true;
            }
        }

        $result = [];

        foreach ($levels as $level) {
            $relegated = $level > 1 ? self::inTableOrder($down[$level - 1], $tiers[$level - 1]) : [];
            $stayers = array_filter($tiers[$level], fn ($t) => ! isset($moving[$t['id']]));
            $promoted = $level < $lowest ? self::inTableOrder($up[$level + 1], $tiers[$level + 1]) : [];

            $result[$level] = array_merge(
                array_column($relegated, 'id'),
                array_column(array_values($stayers), 'id'),
                array_column($promoted, 'id'),
            );
        }

        return $result;
    }

    /** Zespoły w kolejności tabeli końcowej ich dotychczasowej ligi. */
    private static function inTableOrder(array $teams, array $table): array
    {
        $order = array_flip(array_column($table, 'id'));
        usort($teams, fn ($a, $b) => $order[$a['id']] <=> $order[$b['id']]);

        return $teams;
    }
}
