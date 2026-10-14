<?php

namespace App\Support;

use App\Enums\PlayerPosition;
use App\Models\LechMatch;
use App\Models\MatchAppearance;

/**
 * Rozliczenie meczu Lecha z raportu (dashboard/results, kalendarz Lecha). Admin klika fakty: skład, wejście i zejście,
 * gole, asysty, samobóje, kartki. Resztę liczymy tutaj: czas gry, blokadę (R6), czyste konto (R4) i doświadczenie.
 *
 * Bez minut: wejście i zejście to punkty osi meczu (SLOTS). Parzyste punkty to granice połów (0, 45, 90, 105, 120),
 * nieparzyste to „w trakcie” połowy. Połowa k (0-3) zajmuje punkty 2k…2k+2: cała, gdy zawodnik był od 2k do 2k+2,
 * część, gdy był w niej tylko przez chwilę.
 *
 * Doświadczenie (ustawienia Support\Designation): powołanie do wyjściowej jedenastki albo na ławkę (także bez wejścia), cała połowa i część połowy,
 * czyste konto (bramkarz, obrońca), gole (także w serii karnych) i asysty.
 */
class MatchReport
{
    /** Koniec 90 minut i koniec dogrywki na osi meczu. */
    public const END_REGULAR = 4;

    public const END_EXTRA = 8;

    /** Przyciski wejścia z ławki (punkt osi => etykieta), 0 = od początku (wyjściowa jedenastka). */
    public const ON_LABELS = [1 => '>0', 2 => '45', 3 => '>45', 4 => '90', 5 => '>90', 6 => '105', 7 => '>105'];

    /** Przyciski zejścia (punkt osi => etykieta). */
    public const OFF_LABELS = [1 => '<45', 2 => '45', 3 => '<90', 4 => '90', 5 => '<105', 6 => '105', 7 => '<120', 8 => '120'];

    /**
     * Poprawne wejście i zejście: wyjściowa jedenastka od 0, rezerwowy wchodzi przed końcem meczu,
     * zejście nie wcześniej niż wejście (w tej samej połowie można wejść i zejść).
     *
     * @return array{0: int, 1: int}
     */
    public static function normalize(bool $started, int $on, int $off, bool $extraTime): array
    {
        $end = $extraTime ? self::END_EXTRA : self::END_REGULAR;
        $on = $started ? 0 : max(1, min($end - 1, $on));
        $off = max(1, min($end, $off));

        if ($off < $on || ($off === $on && $on % 2 === 0)) {
            $off = $on % 2 === 0 ? $on + 1 : $on;
        }

        return [$on, $off];
    }

    /**
     * Całe i niecałe połowy między wejściem a zejściem.
     *
     * @return array{0: int, 1: int} [całe, części]
     */
    public static function halves(int $on, int $off): array
    {
        $full = 0;
        $partial = 0;

        for ($k = 0; $k < 4; $k++) {
            $start = 2 * $k;
            $stop = $start + 2;

            if ($on <= $start && $off >= $stop) {
                $full++;
            } elseif ($on < $stop && $off > $start) {
                $partial++;
            }
        }

        return [$full, $partial];
    }

    /**
     * Czyste rozliczenie (bez bazy), klucz = id zawodnika.
     *
     * @param  array<int, array{id: int, position: PlayerPosition, started: bool, on: int|null, off: int, goals: int, assists: int, own_goals: int, yellow: int, red: bool}>  $appearances
     * @param  int  $conceded  gole stracone przez Lecha w całym meczu (z dogrywką)
     * @param  array<string, float>  $settings  Designation::values()
     * @return array<int, array{played: bool, full_halves: int, partial_halves: int, play_time: float, lock: int, goals: int, assists: int, own_goals: int, clean_sheet: bool|null, experience: float, yellow: int, red: bool}>
     */
    public static function compute(array $appearances, int $conceded, bool $extraTime, array $settings): array
    {
        $result = [];

        foreach ($appearances as $a) {
            // Ławka bez wejścia: tylko premia za powołanie przez trenera, bez czasu gry, blokady i czystego konta.
            if (! $a['started'] && $a['on'] === null) {
                $result[$a['id']] = [
                    'played' => false, 'full_halves' => 0, 'partial_halves' => 0, 'play_time' => 0.0, 'lock' => 0,
                    'goals' => 0, 'assists' => 0, 'own_goals' => 0, 'clean_sheet' => null,
                    'experience' => round((float) ($settings['exp_sub'] ?? 0.5), 1), 'yellow' => $a['yellow'], 'red' => $a['red'],
                ];

                continue;
            }

            [$on, $off] = self::normalize($a['started'], (int) $a['on'], $a['off'], $extraTime);
            [$full, $partial] = self::halves($on, $off);

            $playTime = $full * ($settings['exp_full_half'] ?? 1) + $partial * ($settings['exp_partial_half'] ?? 0.5);

            // R4: czyste konto bramkarza i obrońcy, który zagrał: Lech nie stracił gola w całym meczu.
            $defensive = in_array($a['position'], [PlayerPosition::Goalkeeper, PlayerPosition::Defender], true);
            $cleanSheet = $defensive ? $conceded === 0 : null;

            $experience = ($a['started'] ? ($settings['exp_start'] ?? 1) : ($settings['exp_sub'] ?? 0.5))
                + $playTime
                + ($cleanSheet ? ($settings['exp_clean_sheet'] ?? 1) : 0)
                + $a['goals'] * ($settings['exp_goal'] ?? 1)
                + $a['assists'] * ($settings['exp_assist'] ?? 1);

            $result[$a['id']] = [
                'played' => true,
                'full_halves' => $full,
                'partial_halves' => $partial,
                'play_time' => round($playTime, 1),
                // R6: każda cała połowa = jedna kolejka blokady (część połowy według ustawienia).
                'lock' => $full + (($settings['partial_half_lock'] ?? 0) ? $partial : 0),
                'goals' => $a['goals'],
                'assists' => $a['assists'],
                'own_goals' => $a['own_goals'],
                'clean_sheet' => $cleanSheet,
                'experience' => round($experience, 1),
                'yellow' => $a['yellow'],
                'red' => $a['red'],
            ];
        }

        return $result;
    }

    /**
     * Przelicza i zapisuje kolumny wyliczane występów meczu (po każdej zmianie raportu, wyniku albo ustawień).
     */
    public static function store(LechMatch $match): void
    {
        $appearances = MatchAppearance::with('player:id,position')->where('lech_match_id', $match->id)->get()
            ->filter(fn (MatchAppearance $a) => $a->player !== null);

        $computed = self::compute($appearances->map(fn (MatchAppearance $a) => [
            'id' => $a->lech_player_id,
            'position' => $a->player->position,
            'started' => $a->started,
            'on' => $a->on_slot,
            'off' => $a->off_slot,
            'goals' => $a->goals,
            'assists' => $a->assists,
            'own_goals' => $a->own_goals,
            'yellow' => $a->yellow,
            'red' => $a->red,
        ])->values()->all(), $match->conceded(), $match->extra_time, Designation::values());

        foreach ($appearances as $a) {
            $r = $computed[$a->lech_player_id];
            [$on, $off] = ! $a->started && $a->on_slot === null
                ? [null, $a->off_slot]
                : self::normalize($a->started, (int) $a->on_slot, $a->off_slot, $match->extra_time);
            $a->update([
                'on_slot' => $on,
                'off_slot' => $off,
                'clean_sheet' => $r['clean_sheet'],
                'play_time' => $r['play_time'],
                'lock' => $r['lock'],
                'experience' => $r['experience'],
            ]);
        }

        Squad::forget();
    }

    /**
     * Rozliczenie zapisanego meczu, klucz = id zawodnika (kolumny wyliczane z bazy).
     *
     * @return array<int, array{played: bool, play_time: float, lock: int, goals: int, assists: int, own_goals: int, clean_sheet: bool|null, experience: float, yellow: int, red: bool}>
     */
    public static function forMatch(?int $lechMatchId): array
    {
        if ($lechMatchId === null) {
            return [];
        }

        return MatchAppearance::where('lech_match_id', $lechMatchId)->get()
            ->mapWithKeys(fn (MatchAppearance $a) => [$a->lech_player_id => [
                'played' => $a->started || $a->on_slot !== null,
                'play_time' => $a->play_time,
                'lock' => $a->lock,
                'goals' => $a->goals,
                'assists' => $a->assists,
                'own_goals' => $a->own_goals,
                'clean_sheet' => $a->clean_sheet,
                'experience' => $a->experience,
                'yellow' => $a->yellow,
                'red' => $a->red,
            ]])->all();
    }

    /**
     * Doświadczenie zawodników Lecha ze wszystkich raportów (także archiwalnych): do kolejności w panelu wyniku.
     *
     * @return array<int, float> lech_player_id => suma
     */
    public static function totalExperience(): array
    {
        return MatchAppearance::query()->groupBy('lech_player_id')
            ->selectRaw('lech_player_id, SUM(experience) as total')
            ->pluck('total', 'lech_player_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * Sumy zawodników Lecha ze wszystkich raportów (także archiwalnych): panel „Kadra Lecha”.
     *
     * @return array<int, array{matches: int, starts: int, subs: int, play_time: float, goals: int, assists: int, own_goals: int, clean_sheets: int, yellow: int, red: int, experience: float}>
     */
    public static function playerTotals(): array
    {
        return MatchAppearance::query()->groupBy('lech_player_id')
            ->selectRaw('lech_player_id, COUNT(*) as matches, SUM(CASE WHEN started THEN 1 ELSE 0 END) as starts,
                SUM(play_time) as play_time, SUM(goals) as goals, SUM(assists) as assists, SUM(own_goals) as own_goals,
                SUM(CASE WHEN clean_sheet THEN 1 ELSE 0 END) as clean_sheets, SUM(yellow) as yellow,
                SUM(CASE WHEN red THEN 1 ELSE 0 END) as red, SUM(experience) as experience')
            ->get()
            ->mapWithKeys(fn (MatchAppearance $row) => [(int) $row->lech_player_id => [
                'matches' => (int) $row->getAttribute('matches'),
                'starts' => (int) $row->getAttribute('starts'),
                'subs' => (int) $row->getAttribute('matches') - (int) $row->getAttribute('starts'),
                'play_time' => (float) $row->getAttribute('play_time'),
                'goals' => (int) $row->getAttribute('goals'),
                'assists' => (int) $row->getAttribute('assists'),
                'own_goals' => (int) $row->getAttribute('own_goals'),
                'clean_sheets' => (int) $row->getAttribute('clean_sheets'),
                'yellow' => (int) $row->getAttribute('yellow'),
                'red' => (int) $row->getAttribute('red'),
                'experience' => (float) $row->getAttribute('experience'),
            ]])->all();
    }
}
