<?php

namespace App\Support;

use App\Enums\PlayerPosition;
use App\Models\LechMatch;
use App\Models\MatchAppearance;
use App\Models\MatchEvent;
use Illuminate\Support\Collection;

/**
 * Rozliczenie meczu Lecha z raportu (dashboard/results, kalendarz Lecha). Admin klika skład (jedenastka, ławka) i protokół
 * (gol z asystą, samobój, kartka, zmiana; match_events). Resztę liczymy tutaj: wejścia i zejścia, gole, asysty, kartki,
 * czas gry, godziny odpoczynku (R6), czyste konto (R4) i doświadczenie.
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
     * Etap meczu dla punktu osi (zamiast minut, 15.10.2026): ten sam indeks dla wejścia i zejścia.
     * Zejście w 90+7 to „koniec 2. połowy”, więc nie ma pytań, czemu w protokole jest „<90”.
     *
     * @param  bool  $on  wejście (od 2. połowy…), inaczej zejście albo zdarzenie (koniec 1. połowy…)
     */
    public static function phase(int $slot, bool $on = false, bool $long = false): string
    {
        if ($long) {
            return match ($slot) {
                0 => __('From the start'),
                1 => __('During the 1st half'),
                2 => $on ? __('At half-time (from the 2nd half)') : __('End of the 1st half (stoppage time or half-time)'),
                3 => __('During the 2nd half'),
                4 => $on ? __('Before extra time') : __('End of the 2nd half (stoppage time, e.g. 90+7)'),
                5 => __('During the 1st half of extra time'),
                6 => $on ? __('From the 2nd half of extra time') : __('End of the 1st half of extra time'),
                7 => __('During the 2nd half of extra time'),
                default => __('End of extra time'),
            };
        }

        return match ($slot) {
            0 => __('start'),
            1 => __('1st half'),
            2 => $on ? __('from 2nd half') : __('end of 1st half'),
            3 => __('2nd half'),
            4 => $on ? __('from extra time') : __('end of 2nd half'),
            5 => __('ET 1st half'),
            6 => $on ? __('from ET 2nd half') : __('end of ET 1st half'),
            7 => __('ET 2nd half'),
            default => __('end of ET'),
        };
    }

    /** Rodzaje wpisów protokołu. */
    public const EVENT_GOAL = 'goal';

    public const EVENT_OWN_GOAL = 'own_goal';

    public const EVENT_CARD = 'card';

    public const EVENT_SUB = 'sub';

    public const EVENTS = [self::EVENT_GOAL, self::EVENT_CARD, self::EVENT_SUB, self::EVENT_OWN_GOAL];

    /** Kolejność grup w wyświetlanym protokole: gole z asystami, samobóje, kartki, zmiany. */
    public const EVENT_ORDER = [self::EVENT_GOAL, self::EVENT_OWN_GOAL, self::EVENT_CARD, self::EVENT_SUB];

    /**
     * Protokół do wyświetlenia: grupy według EVENT_ORDER, w grupie według czasu (bez czasu na początku) i kolejności wpisu.
     *
     * @param  Collection<int, MatchEvent>  $events
     * @return Collection<int, MatchEvent>
     */
    public static function ordered(Collection $events): Collection
    {
        $groups = array_flip(self::EVENT_ORDER);

        return $events->sortBy(fn (MatchEvent $e) => [$groups[$e->type] ?? 9, $e->slot ?? 0, $e->id])->values();
    }

    /** Kartki w protokole: druga żółta i czerwona oznaczają zejście z boiska (z czasem). */
    public const CARDS = ['yellow', 'second_yellow', 'red'];

    /**
     * Fakty występów z protokołu (bez bazy): jedenastka gra od 0, ławka nie gra, dopóki nie wejdzie zmianą.
     * Zmiana: schodzący kończy w danym punkcie osi, wchodzący (opcjonalny, np. przy kontuzji bez zmiany) od niego zaczyna.
     * Gol daje bramkę strzelcowi i asystę asystentowi, samobój liczy się autorowi, druga żółta (dwie żółte)
     * i czerwona kończą grę zawodnika. Wpisy zawodników spoza składu pomijamy.
     *
     * @param  array<int, bool>  $squad  id zawodnika => wyjściowa jedenastka
     * @param  array<int, array{type: string, player: int, other: int|null, card: string|null, slot: int|null}>  $events  w kolejności wpisu
     * @param  int  $end  koniec meczu na osi (END_REGULAR albo END_EXTRA)
     * @return array<int, array{on: int|null, off: int, goals: int, assists: int, own_goals: int, yellow: int, red: bool}>
     */
    public static function protocol(array $squad, array $events, int $end): array
    {
        $out = [];
        foreach ($squad as $id => $started) {
            $out[$id] = ['on' => $started ? 0 : null, 'off' => $end, 'goals' => 0, 'assists' => 0, 'own_goals' => 0, 'yellow' => 0, 'red' => false];
        }

        foreach ($events as $e) {
            $id = $e['player'];
            $other = $e['other'];

            if (! isset($out[$id])) {
                continue;
            }

            $slot = $e['slot'] === null ? $end : max(1, min($end, $e['slot']));

            switch ($e['type']) {
                case self::EVENT_GOAL:
                    $out[$id]['goals']++;
                    if ($other !== null && $other !== $id && isset($out[$other])) {
                        $out[$other]['assists']++;
                    }
                    break;
                case self::EVENT_OWN_GOAL:
                    $out[$id]['own_goals']++;
                    break;
                case self::EVENT_CARD:
                    if ($e['card'] === 'yellow') {
                        $out[$id]['yellow'] = max(1, $out[$id]['yellow']);
                    } elseif ($e['card'] === 'second_yellow') {
                        $out[$id]['yellow'] = 2;
                        $out[$id]['off'] = $slot;
                    } elseif ($e['card'] === 'red') {
                        $out[$id]['red'] = true;
                        $out[$id]['off'] = $slot;
                    }
                    break;
                case self::EVENT_SUB:
                    $out[$id]['off'] = $slot;
                    // Wchodzący z ławki: ten sam punkt osi (np. zmiana w przerwie: zejście 45, wejście 45).
                    if ($other !== null && isset($out[$other]) && ! $squad[$other] && $out[$other]['on'] === null) {
                        $out[$other]['on'] = min($slot, $end - 1);
                    }
                    break;
            }
        }

        return $out;
    }

    /** Czas na boisku do tabeli etapami meczu („start – koniec 2. poł.”), null = nie zagrał. */
    public static function spanLabel(?int $on, int $off): ?string
    {
        if ($on === null) {
            return null;
        }

        return self::phase($on, true).' – '.self::phase($off);
    }

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
     * @return array<int, array{played: bool, full_halves: int, partial_halves: int, play_time: float, rest_hours: int, goals: int, assists: int, own_goals: int, clean_sheet: bool|null, experience: float, yellow: int, red: bool}>
     */
    public static function compute(array $appearances, int $conceded, bool $extraTime, array $settings): array
    {
        $result = [];

        foreach ($appearances as $a) {
            // Ławka bez wejścia: tylko premia za powołanie przez trenera, bez czasu gry, odpoczynku i czystego konta.
            if (! $a['started'] && $a['on'] === null) {
                $result[$a['id']] = [
                    'played' => false, 'full_halves' => 0, 'partial_halves' => 0, 'play_time' => 0.0, 'rest_hours' => 0,
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
                // R6: godziny odpoczynku od gwizdka (część połowy liczy się jako pół połowy).
                'rest_hours' => self::restHours($full, $partial, $settings),
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
     * Godziny odpoczynku po występie (R6): podstawa + godziny za każdą połowę, część połowy jako pół.
     *
     * @param  array<string, float>  $settings
     */
    public static function restHours(int $full, int $partial, array $settings): int
    {
        if ($full + $partial === 0) {
            return 0;
        }

        return (int) round(($settings['rest_base_hours'] ?? 10) + ($full + $partial / 2) * ($settings['rest_hours_per_half'] ?? 60));
    }

    /**
     * Przelicza i zapisuje występy meczu z protokołu (po każdej zmianie składu, protokołu, wyniku albo ustawień):
     * wejście, zejście, gole, asysty, samobóje, kartki i kolumny wyliczane.
     */
    public static function store(LechMatch $match): void
    {
        $appearances = MatchAppearance::with('player:id,position')->where('lech_match_id', $match->id)->get()
            ->filter(fn (MatchAppearance $a) => $a->player !== null);
        $end = $match->extra_time ? self::END_EXTRA : self::END_REGULAR;

        $facts = self::protocol(
            $appearances->mapWithKeys(fn (MatchAppearance $a) => [$a->lech_player_id => $a->started])->all(),
            MatchEvent::where('lech_match_id', $match->id)->orderBy('id')->get()
                ->map(fn (MatchEvent $e) => ['type' => $e->type, 'player' => $e->lech_player_id, 'other' => $e->other_player_id, 'card' => $e->card, 'slot' => $e->slot])
                ->values()->all(),
            $end,
        );

        foreach ($appearances as $a) {
            $f = $facts[$a->lech_player_id];
            $a->fill(['on_slot' => $f['on'], 'off_slot' => $f['off'], 'goals' => $f['goals'], 'assists' => $f['assists'],
                'own_goals' => $f['own_goals'], 'yellow' => $f['yellow'], 'red' => $f['red']]);
        }

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
            $a->fill([
                'on_slot' => $on,
                'off_slot' => $off,
                'clean_sheet' => $r['clean_sheet'],
                'play_time' => $r['play_time'],
                'rest_hours' => $r['rest_hours'],
                'experience' => $r['experience'],
            ])->save();
        }

        Squad::forget();
    }

    /**
     * Rozliczenie zapisanego meczu, klucz = id zawodnika (kolumny wyliczane z bazy).
     *
     * @return array<int, array{played: bool, play_time: float, rest_hours: int, goals: int, assists: int, own_goals: int, clean_sheet: bool|null, experience: float, yellow: int, red: bool}>
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
                'rest_hours' => $a->rest_hours,
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
