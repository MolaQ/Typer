<?php

namespace App\Support;

use App\Enums\PlayerPosition;
use App\Models\MatchAppearance;
use App\Models\Matchday;
use App\Models\MatchGoal;

/**
 * Rozliczenie meczu Lecha z raportu (występy i gole, dashboard/results). Admin wpisuje fakty,
 * resztę liczymy tutaj: minuty, połowy, blokada (R6), gole, asysty, samobóje, czyste konto (R4),
 * „mecz zawodnika” i doświadczenie (rozstrzyganie remisów w pucharach).
 *
 * Minuty: zmiana w minucie m dzieli mecz w chwili m-1 (zmiana „46” = od przerwy), gol w minucie m liczy się w chwili m-0,5.
 * Połowy: 0-45, 45-90 i w dogrywce 90-105, 105-120.
 */
class MatchReport
{
    private const HALVES = [[0, 45], [45, 90], [90, 105], [105, 120]];

    /**
     * Rozliczenie meczu kolejki z bazy, klucz = id zawodnika.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forMatchday(Matchday $matchday): array
    {
        $appearances = MatchAppearance::with('player')->where('matchday_id', $matchday->id)->get()
            ->filter(fn (MatchAppearance $a) => $a->player !== null)
            ->map(fn (MatchAppearance $a) => [
                'id' => $a->lech_player_id,
                'position' => $a->player->position,
                'started' => $a->started,
                'on' => $a->on_minute,
                'off' => $a->off_minute,
                'yellow' => $a->yellow,
                'red' => $a->red,
            ])->values()->all();

        $goals = MatchGoal::where('matchday_id', $matchday->id)->get()
            ->map(fn (MatchGoal $g) => [
                'side' => $g->side,
                'minute' => $g->minute,
                'scorer' => $g->scorer_id,
                'assist' => $g->assist_id,
            ])->all();

        return self::compute($appearances, $goals, $matchday->extra_time, Designation::values());
    }

    /**
     * Czyste rozliczenie (bez bazy).
     *
     * @param  array<int, array{id: int, position: PlayerPosition, started: bool, on: int|null, off: int|null, yellow: bool, red: bool}>  $appearances
     * @param  array<int, array{side: string, minute: int|null, scorer: int|null, assist: int|null}>  $goals
     * @param  array<string, int>  $settings  Designation::values()
     * @return array<int, array<string, mixed>>
     */
    public static function compute(array $appearances, array $goals, bool $extraTime, array $settings): array
    {
        $end = $extraTime ? 120 : 90;
        $result = [];

        foreach ($appearances as $a) {
            $from = $a['started'] ? 0 : max(0, min($end, (int) $a['on'] - 1));
            $to = $a['off'] !== null ? max($from, min($end, $a['off'] - 1)) : $end;

            $full = 0;
            $partial = 0;

            foreach (self::HALVES as [$start, $stop]) {
                if ($stop > $end) {
                    break;
                }

                $overlap = min($to, $stop) - max($from, $start);

                if ($overlap >= $stop - $start) {
                    $full++;
                } elseif ($overlap > 0) {
                    $partial++;
                }
            }

            $played = $to > $from;
            $for = 0;
            $against = 0;
            $concededBefore = 0;
            $concededAfter = 0;
            $concededAll = 0;
            $scored = 0;
            $assists = 0;
            $ownGoals = 0;

            foreach ($goals as $g) {
                // Gol bez minuty liczymy tak, jakby zawodnik był wtedy na boisku.
                $t = $g['minute'] !== null ? $g['minute'] - 0.5 : null;
                $onPitch = $played && ($t === null || ($t > $from && $t < $to));

                if ($g['side'] === MatchGoal::LECH) {
                    $for += $onPitch ? 1 : 0;
                    $scored += $g['scorer'] === $a['id'] ? 1 : 0;
                    $assists += $g['assist'] === $a['id'] ? 1 : 0;

                    continue;
                }

                $concededAll++;
                $against += $onPitch ? 1 : 0;
                $concededBefore += ($t === null || $t < $to) ? 1 : 0;
                $concededAfter += ($t === null || $t > $from) ? 1 : 0;
                $ownGoals += $g['scorer'] === $a['id'] ? 1 : 0;
            }

            // R4: czyste konto tylko bramkarz i obrońca, który zagrał.
            $defensive = in_array($a['position'], [PlayerPosition::Goalkeeper, PlayerPosition::Defender], true);
            $cleanSheet = null;

            if ($defensive && $played) {
                $cleanSheet = match ($settings['clean_sheet_mode'] ?? Designation::CLEAN_MATCH) {
                    Designation::CLEAN_UNTIL_OFF => $concededBefore === 0,
                    Designation::CLEAN_FROM_ON => $concededAfter === 0,
                    default => $concededAll === 0,
                };
            }

            // „Mecz zawodnika”: bramki Lecha i rywala w czasie jego gry, ocenione jak mecz.
            $matchPoints = match (true) {
                ! $played => 0,
                $for > $against => $settings['exp_win'] ?? 3,
                $for === $against => $settings['exp_draw'] ?? 1,
                default => $settings['exp_loss'] ?? 0,
            };

            $experience = $played
                ? $full * ($settings['exp_full_half'] ?? 2)
                    + $partial * ($settings['exp_partial_half'] ?? 1)
                    + ($a['started'] ? ($settings['exp_start'] ?? 1) : 0)
                    + $matchPoints
                : 0;

            $result[$a['id']] = [
                'played' => $played,
                'from' => $from,
                'to' => $to,
                'minutes' => $to - $from,
                'full_halves' => $full,
                'partial_halves' => $partial,
                // R6: każda cała połowa = jedna kolejka blokady (niecała według ustawienia).
                'lock' => $full + (($settings['partial_half_lock'] ?? 0) ? $partial : 0),
                'goals' => $scored,
                'assists' => $assists,
                'own_goals' => $ownGoals,
                'clean_sheet' => $cleanSheet,
                'for' => $for,
                'against' => $against,
                'match_points' => $matchPoints,
                'experience' => $experience,
                'yellow' => $a['yellow'],
                'red' => $a['red'],
            ];
        }

        return $result;
    }
}
