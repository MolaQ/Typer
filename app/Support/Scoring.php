<?php

namespace App\Support;

/**
 * Punktacja (regulamin, punkt 3). Czyste funkcje bez bazy, łatwe do przetestowania.
 *
 *  - Typ: 1 pkt za rozstrzygnięcie, 1 za różnicę goli, 1 za dokładny wynik (0-3).
 *  - Zestaw pytań: 1 pkt za poprawną odpowiedź, brak odpowiedzi nic nie daje,
 *    jedna zła odpowiedź zeruje cały zestaw tej strony.
 *  - Wynik zespołu w meczu = max(0, własny wynik ofensywny − defensywa rywala).
 *  - Mecz ligowy: wygrana 3, remis 1, porażka 0.
 */
final class Scoring
{
    public const WIN_POINTS = 3;

    public const DRAW_POINTS = 1;

    /**
     * Punkty za typ.
     *
     * @return array{points: int, outcome: bool, diff: bool, exact: bool}
     */
    public static function tip(int $tipLech, int $tipOpponent, int $realLech, int $realOpponent): array
    {
        $outcome = self::sign($tipLech - $tipOpponent) === self::sign($realLech - $realOpponent);
        $diff = ($tipLech - $tipOpponent) === ($realLech - $realOpponent);
        $exact = $tipLech === $realLech && $tipOpponent === $realOpponent;

        return [
            'points' => (int) $outcome + (int) $diff + (int) $exact,
            'outcome' => $outcome,
            'diff' => $diff,
            'exact' => $exact,
        ];
    }

    /**
     * Punkty zestawu pytań jednej strony.
     *
     * @param  array<int, bool>  $answers  id miejsca => odpowiedź gracza (brak klucza = brak odpowiedzi)
     * @param  array<int, bool|null>  $correct  id miejsca => poprawna odpowiedź (null = pytanie bez rozstrzygnięcia, nie liczy się)
     * @return array{points: int, zeroed: bool}
     */
    public static function set(array $answers, array $correct): array
    {
        $points = 0;

        foreach ($correct as $slotId => $right) {
            if ($right === null || !array_key_exists($slotId, $answers)) {
                continue;
            }

            if ($answers[$slotId] !== $right) {
                return ['points' => 0, 'zeroed' => true];
            }

            $points++;
        }

        return ['points' => $points, 'zeroed' => false];
    }

    /** Bramki zespołu w meczu. */
    public static function goals(int $offense, int $opponentDefense): int
    {
        return max(0, $offense - $opponentDefense);
    }

    /** Punkty meczowe w lidze za wynik $for : $against. */
    public static function matchPoints(int $for, int $against): int
    {
        return match (true) {
            $for > $against => self::WIN_POINTS,
            $for === $against => self::DRAW_POINTS,
            default => 0,
        };
    }

    /**
     * Kto awansuje w pucharze przy remisie? Wcześniejszy czas typu; przy równym czasie gospodarz
     * (wyższe rozstawienie). Czasy jako napisy "Y-m-d H:i:s.u" porównują się poprawnie.
     */
    public static function homeWinsOnTime(?string $homeTippedAt, ?string $awayTippedAt): bool
    {
        if ($homeTippedAt === null || $awayTippedAt === null) {
            return $awayTippedAt === null;
        }

        return $homeTippedAt <= $awayTippedAt;
    }

    private static function sign(int $value): int
    {
        return $value <=> 0;
    }
}
