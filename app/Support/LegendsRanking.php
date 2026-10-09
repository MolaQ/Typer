<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use Illuminate\Support\Collection;

/**
 * Liga Legend (regulamin, punkt 10): wszystkie zespoły ludzi, ranking narastająco od kolejki 1.
 * Po kolejkach 1-8 zostaje najwyżej 256, 128, 64, 32, 16, 8, 4 i 2 zespoły; kolejka 9 to finał.
 * Kryteria: punkty Legend, dokładne typy, trafione różnice, trafione rozstrzygnięcia,
 * punkty Hall of Fame (stan na początku sezonu), czas typu w ostatniej kolejce, pozycja na liście.
 *
 * Punkty Legend w kolejce (najwyżej 100): po 12 za dokładny wynik, rozstrzygnięcie i różnicę goli (36)
 * oraz za każdy zestaw pytań Legend (ofensywny i defensywny osobno) 2^n przy n poprawnych odpowiedziach:
 * 1 = 2, 2 = 4, 3 = 8, 4 = 16, 5 = 32 (zła odpowiedź zeruje zestaw, więc 0). W Lidze Legend nie ma rywala,
 * więc bonus defensywny się dodaje.
 */
final class LegendsRanking
{
    public const ROUNDS = 9;

    /** Punkty Legend za trafienie dokładnego wyniku, rozstrzygnięcia i różnicy goli. */
    public const HIT_POINTS = 12;

    /** Punkty za zestaw pytań z $correct poprawnymi odpowiedziami: 0, 2, 4, 8, 16, 32. */
    public static function bonusPoints(int $correct): int
    {
        return $correct > 0 ? 2 ** $correct : 0;
    }

    /** Punkty Legend zespołu w jednej kolejce (0-100). */
    public static function matchdayPoints(TeamScore $score): int
    {
        return self::HIT_POINTS * ((int) $score->exact_hit + (int) $score->outcome_hit + (int) $score->diff_hit)
            + self::bonusPoints($score->offense_bonus)
            + self::bonusPoints($score->defense_bonus);
    }

    /** Ile zespołów może zostać po danej kolejce (1-8), np. po 1. kolejce 256. */
    public static function limitAfter(int $round): int
    {
        return intdiv(512, 2 ** $round);
    }

    /**
     * Ranking uczestników (od najlepszego) z kolejek 1..$upTo. $onlyAlive: tylko ci, którzy nie odpadli
     * przed kolejką $upTo (odpadnięci w samej kolejce $upTo zostają).
     *
     * @return Collection<int, array{entry_id: int, team: SeasonTeam, eliminated_round: ?int, points: int, last_points: ?int, hit_points: int, bonus_points: int, tip_points: int, bonus: int, exact: int, diff_hits: int, outcome_hits: int, hof: float, tipped_at: ?string}>
     */
    public static function for(Competition $competition, int $upTo, bool $onlyAlive = false): Collection
    {
        $entries = $competition->entries()->with(['seasonTeam.user:id,name,team_name'])->get();

        if ($onlyAlive) {
            $entries = $entries->filter(fn ($e) => $e->eliminated_round === null || $e->eliminated_round >= $upTo);
        }

        $matchdays = Matchday::where('season_id', $competition->season_id)->where('number', '<=', $upTo)->pluck('number', 'id');
        $last = $matchdays->search($upTo);

        $scores = TeamScore::whereIn('matchday_id', $matchdays->keys())
            ->whereIn('season_team_id', $entries->pluck('season_team_id'))
            ->where('question_set', CompetitionType::Legends->value)
            ->get()
            ->groupBy('season_team_id');

        $season = $competition->season;

        $rows = $entries->map(function ($entry) use ($scores, $last, $season) {
            $own = $scores->get($entry->season_team_id, collect());
            $lastScore = $own->firstWhere('matchday_id', $last);

            $points = (int) $own->sum(fn (TeamScore $s) => self::matchdayPoints($s));
            $bonusPoints = (int) $own->sum(fn (TeamScore $s) => self::bonusPoints($s->offense_bonus) + self::bonusPoints($s->defense_bonus));

            return [
                'entry_id' => $entry->id,
                'team' => $entry->seasonTeam,
                'eliminated_round' => $entry->eliminated_round,
                'points' => $points,
                'last_points' => $lastScore ? self::matchdayPoints($lastScore) : null,
                'hit_points' => $points - $bonusPoints,
                'bonus_points' => $bonusPoints,
                'tip_points' => (int) $own->sum('tip_points'),
                'bonus' => (int) $own->sum(fn ($s) => $s->bonus()),
                'exact' => $own->where('exact_hit', true)->count(),
                'diff_hits' => $own->where('diff_hit', true)->count(),
                'outcome_hits' => $own->where('outcome_hit', true)->count(),
                'hof' => $season ? HallOfFame::teamPointsBefore($season, $entry->seasonTeam?->user_id, $entry->seasonTeam?->bot_id) : 0.0,
                'tipped_at' => $lastScore?->tipped_at?->format('Y-m-d H:i:s.u'),
            ];
        });

        return $rows->sort(function (array $a, array $b) {
            foreach (['points', 'exact', 'diff_hits', 'outcome_hits', 'hof'] as $key) {
                if ($a[$key] !== $b[$key]) {
                    return $b[$key] <=> $a[$key];
                }
            }

            // Wcześniejszy typ wygrywa; brak typu na końcu, potem pozycja na liście.
            if ($a['tipped_at'] !== $b['tipped_at']) {
                return $a['tipped_at'] === null ? 1 : ($b['tipped_at'] === null ? -1 : $a['tipped_at'] <=> $b['tipped_at']);
            }

            return $a['team']->position <=> $b['team']->position;
        })->values();
    }

    /**
     * Odcięcie po kolejce: zespoły poza limitem dostają eliminated_round = $round.
     * Wywoływane przez ScoreMatchday po przeliczeniu kolejki (wcześniej czyści odpadnięcia z tej kolejki).
     */
    public static function eliminate(Competition $competition, int $round): int
    {
        if ($round >= self::ROUNDS) {
            return 0; // finał: nikt już nie odpada, zwycięzca to pierwszy w rankingu
        }

        $alive = self::for($competition, $round, onlyAlive: true);
        $out = $alive->slice(self::limitAfter($round))->pluck('entry_id');

        if ($out->isNotEmpty()) {
            CompetitionEntry::whereIn('id', $out)->update(['eliminated_round' => $round]);
        }

        return $out->count();
    }
}
