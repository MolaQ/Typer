<?php

namespace App\Support;

/**
 * Awanse i spadki między sezonami (regulamin, punkt 9). Czysta funkcja bez bazy.
 *
 * Wejście: poziom ligi (1-10, 11 = Liga podwórkowa) => zespoły w kolejności tabeli końcowej,
 * każdy jako ['id' => int, 'human' => bool]. Wyjście: poziom => id w kolejności nowej listy.
 * Nagrody (tytuły, miejsca, MVP, Złota Piłka, Rękawice) liczą się wcześniej z prawdziwych tabel, także dla botów.
 *
 * Zasady (wersja z 15.10.2026, symulacja: notes/symulacja-awansow.md):
 *  1. w każdej lidze boty przesuwamy na koniec tabeli (ludzie i boty zachowują swoją kolejność),
 *  2. między sąsiednimi ligami 4 ostatnie spadają, 4 pierwsze z niższej awansują,
 *     z Ekstraklasy nikt nie awansuje, z podwórkowej nikt nie spada;
 *     kolejność w nowej lidze: spadkowicze z wyższej, pozostali, beniaminkowie,
 *  3. wypychanie botów: każdy bot zamienia się z najwyżej sklasyfikowanym człowiekiem pod nim (bez limitu lig).
 *     Wynik: najpierw wszyscy ludzie, potem wszystkie boty, każda grupa w kolejności listy po kroku 2,
 *     więc najlepszy bot otwiera listę botów pod ludźmi. Ligi zachowują swoje rozmiary.
 *     Człowiek, który wskoczył za bota, staje w nowej lidze za beniaminkami (rozstawienie w pucharze).
 */
final class Promotion
{
    public const MOVES = 4;

    /**
     * @param  array<int, array<int, array{id: int, human: bool}>>  $tiers
     * @return array<int, array<int, int>>
     */
    public static function apply(array $tiers, int $moves = self::MOVES): array
    {
        ksort($tiers);
        $levels = array_keys($tiers);
        $lowest = max($levels);

        // 1. Boty na koniec każdej tabeli.
        $sorted = [];
        foreach ($tiers as $level => $teams) {
            $sorted[$level] = array_merge(
                array_values(array_filter($teams, fn ($t) => $t['human'])),
                array_values(array_filter($teams, fn ($t) => ! $t['human'])),
            );
        }

        // 2. Wymiana po $moves zespołów na posortowanych tabelach.
        $up = [];
        $down = [];
        foreach ($levels as $level) {
            $teams = $sorted[$level];
            $up[$level] = $level > 1 ? array_slice($teams, 0, $moves) : [];
            $down[$level] = $level < $lowest ? array_slice($teams, -$moves) : [];

            // Mała liga (np. pusta podwórkowa): awans i spadek nie mogą dotyczyć tych samych zespołów.
            $upIds = array_column($up[$level], 'id');
            $down[$level] = array_values(array_filter($down[$level], fn ($t) => ! in_array($t['id'], $upIds, true)));
        }

        $flat = [];
        $sizes = [];
        foreach ($levels as $level) {
            $moving = array_column(array_merge($up[$level], $down[$level]), 'id');
            $stayers = array_filter($sorted[$level], fn ($t) => ! in_array($t['id'], $moving, true));
            $league = array_merge($level > 1 ? $down[$level - 1] : [], array_values($stayers), $level < $lowest ? $up[$level + 1] : []);
            $sizes[$level] = count($league);
            array_push($flat, ...$league);
        }

        // 3. Wypychanie botów: ludzie przed botami, kolejność w obu grupach jak po kroku 2.
        $order = array_merge(
            array_values(array_filter($flat, fn ($t) => $t['human'])),
            array_values(array_filter($flat, fn ($t) => ! $t['human'])),
        );

        // Kolejność w lidze: najpierw zespoły, które były tu po kroku 2 (spadkowicze, pozostali, beniaminkowie),
        // za nimi ludzie, którzy wskoczyli za boty; boty zawsze na końcu, w kolejności listy (najlepszy pierwszy).
        $levelAfterStep2 = [];
        $index = [];
        $position = 0;
        foreach ($levels as $level) {
            foreach (array_splice($flat, 0, $sizes[$level]) as $team) {
                $levelAfterStep2[$team['id']] = $level;
                $index[$team['id']] = $position++;
            }
        }

        $result = [];
        foreach ($levels as $level) {
            $members = array_splice($order, 0, $sizes[$level]);
            $key = fn ($t) => [! $t['human'], $t['human'] && $levelAfterStep2[$t['id']] !== $level, $index[$t['id']]];
            usort($members, fn ($a, $b) => $key($a) <=> $key($b));
            $result[$level] = array_column($members, 'id');
        }

        return $result;
    }

    /**
     * Kierunek zmiany ligi każdego przenoszonego zespołu (do oznaczeń w tabelach ostatniej kolejki):
     * id => 'up' (awans) albo 'down' (spadek), razem z zamianami z czyszczenia z botów.
     *
     * @param  array<int, array<int, array{id: int, human: bool}>>  $tiers
     * @return array<int, string>
     */
    public static function moves(array $tiers): array
    {
        $from = [];
        foreach ($tiers as $level => $teams) {
            foreach ($teams as $team) {
                $from[$team['id']] = $level;
            }
        }

        $moves = [];
        foreach (self::apply($tiers) as $level => $ids) {
            foreach ($ids as $id) {
                if ($from[$id] !== $level) {
                    $moves[$id] = $from[$id] > $level ? 'up' : 'down';
                }
            }
        }

        return $moves;
    }
}
