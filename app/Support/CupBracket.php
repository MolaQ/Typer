<?php

namespace App\Support;

/**
 * Stała drabinka Pucharu Polski (regulamin, punkt 7.3): 512 zespołów, 9 rund.
 *
 * Numer to MIEJSCE w drabince (rozstawienie), nie konkretny zespół. W rundzie z M miejscami
 * najlepsze miejsce gra z najgorszym: 1 z M, 2 z M-1 itd. Zwycięzca meczu zajmuje lepsze
 * miejsce z pary (outsider, który pokona faworyta, przejmuje jego rozstawienie), więc w
 * kolejnej rundzie jest dokładnie M/2 miejsc i schemat nie zależy od wyników:
 *   runda 1: 1-512, 2-511, ... 256-257   (256 meczów)
 *   runda 2: 1-256, 2-255, ... 128-129   (128 meczów)
 *   ...
 *   runda 9: 1-2                         (finał)
 * Faworyt (niższy numer) jest po lewej. Kto stoi na miejscu w rundach 2-9, wiadomo dopiero
 * po wynikach, dlatego terminarz trzyma numery miejsc, a zespoły dopisują się później.
 */
final class CupBracket
{
    public const TEAMS = 512;

    public const ROUNDS = 9;

    /** Ile miejsc (zespołów) gra w danej rundzie: 512, 256, ... 2. */
    public static function seatsInRound(int $round): int
    {
        return intdiv(self::TEAMS, 2 ** ($round - 1));
    }

    /**
     * Pary miejsc w rundzie: [[1, 512], [2, 511], ...].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function pairs(int $round): array
    {
        $seats = self::seatsInRound($round);
        $pairs = [];

        for ($i = 1; $i <= intdiv($seats, 2); $i++) {
            $pairs[] = [$i, $seats + 1 - $i];
        }

        return $pairs;
    }

    /**
     * Wszystkie mecze pucharu: [['round' => 1, 'home' => 1, 'away' => 512], ...] (511 meczów).
     *
     * @return array<int, array{round: int, home: int, away: int}>
     */
    public static function fixtures(): array
    {
        $list = [];

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            foreach (self::pairs($round) as [$home, $away]) {
                $list[] = ['round' => $round, 'home' => $home, 'away' => $away];
            }
        }

        return $list;
    }

    /** Nazwa rundy do wyświetlenia, np. 1/256 finału, ćwierćfinał, finał. */
    public static function roundName(int $round): string
    {
        return match ($round) {
            self::ROUNDS => __('Final'),
            self::ROUNDS - 1 => __('Semi-final'),
            self::ROUNDS - 2 => __('Quarter-final'),
            default => __('Round of :count', ['count' => self::seatsInRound($round)]),
        };
    }

    /** Nazwa rundy z liczbą zespołów (kafelki rund po odpadnięciu), np. „Ćwierćfinał – 8 zespołów”. */
    public static function stageLabel(int $round): string
    {
        return match ($round) {
            self::ROUNDS => __('Final'),
            self::ROUNDS - 1 => __('Semi-final – :count teams', ['count' => self::seatsInRound($round)]),
            self::ROUNDS - 2 => __('Quarter-final – :count teams', ['count' => self::seatsInRound($round)]),
            default => self::roundName($round),
        };
    }
}
