<?php

namespace App\Support;

use App\Enums\PlayerPosition;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\DesignatedPlayer;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\User;

/**
 * Protokoły meczów gracza w rozliczonej kolejce (/tips, pod kartami typów rozgrywek): wynik i „strzelcy” obu stron.
 * Pierwszy strzelec to zespół (tyle piłek, ile punktów za typ), dalej wystawieni zawodnicy: piłka za każdy punkt ataku,
 * czerwona piłka za samobója (punkt dla rywala), pięść za czyste konto (zabiera rywalowi punkt ataku), kartki
 * i doświadczenie zawodnika w klubie po meczu. Zawodnicy, którzy nie zagrali w meczu Lecha, są wyszarzeni.
 * Po rozliczeniu wszystko jest jawne (także skład rywala).
 */
class MatchSheet
{
    /**
     * @return array<string, array<int, array<string, mixed>>> typ rozgrywek (wartość) => protokoły meczów
     */
    public static function forUser(User $user, Matchday $matchday): array
    {
        if (Rivals::phase($matchday) !== Rivals::PLAYED) {
            return [];
        }

        $team = SeasonTeam::where('season_id', $matchday->season_id)->where('user_id', $user->id)->first();

        if (! $team) {
            return [];
        }

        $round = $matchday->number;
        $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
        $mine = CompetitionEntry::whereIn('competition_id', $competitions->keys())
            ->where('season_team_id', $team->id)
            ->where(fn ($q) => $q->whereNull('eliminated_round')->orWhere('eliminated_round', '>=', $round))
            ->pluck('id');

        $fixtures = Fixture::with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('round', $round)
            ->whereIn('competition_id', $competitions->keys())
            ->where(fn ($q) => $q->whereIn('home_entry_id', $mine)->orWhereIn('away_entry_id', $mine))
            ->orderBy('id')
            ->get()
            ->filter(fn (Fixture $f) => $f->isPlayed());

        if ($fixtures->isEmpty()) {
            return [];
        }

        $teamIds = $fixtures->flatMap(fn (Fixture $f) => [$f->home?->season_team_id, $f->away?->season_team_id])->filter()->unique();
        $scores = TeamScore::where('matchday_id', $matchday->id)->whereIn('season_team_id', $teamIds)->get()
            ->groupBy('season_team_id');

        // Kto grał miejscem w tej kolejce (Ownership): klub gracza albo bota.
        $userIds = $scores->flatten()->pluck('user_id')->filter()->unique()->values()->all();
        $botIds = $scores->flatten()->pluck('bot_id')->filter()->unique()->values()->all();

        $picks = DesignatedPlayer::with('player')->where('matchday_id', $matchday->id)
            ->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('bot_id', $botIds))
            ->get()
            ->groupBy(fn (DesignatedPlayer $p) => Squad::clubKey($p->user_id, $p->bot_id).'|'.$p->score_set->value);
        $before = Squad::experienceBefore($matchday, $userIds, $botIds);
        $order = array_flip(PlayerPosition::values());

        $out = [];
        foreach ($fixtures as $fixture) {
            $competition = $competitions[$fixture->competition_id];
            $set = $competition->type->scoreSet()->value;
            $sides = [];

            foreach ([[$fixture->home, $fixture->home_goals, $fixture->home_entry_id], [$fixture->away, $fixture->away_goals, $fixture->away_entry_id]] as $index => [$entry, $goals, $entryId]) {
                $seasonTeam = $entry?->seasonTeam;
                $virtual = $index === 1 && $fixture->isBye();
                $score = $seasonTeam ? $scores->get($seasonTeam->id)?->first(fn (TeamScore $s) => $s->score_set->value === $set) : null;
                $club = $score ? Squad::clubKey($score->user_id, $score->bot_id) : null;

                $rows = [];
                foreach ($club ? $picks->get($club.'|'.$set, collect()) : [] as $pick) {
                    if (! $pick->player) {
                        continue;
                    }
                    $rows[] = [
                        'name' => $pick->player->name,
                        // W protokole samo nazwisko (jak w relacjach meczowych), pełne w podpowiedzi.
                        'short' => self::surname($pick->player->name),
                        'position' => $pick->player->position,
                        'attack' => (int) $pick->attack,
                        'own_goals' => (int) $pick->own_goals,
                        'defense' => (int) $pick->defense,
                        'yellow' => (int) $pick->yellow,
                        'red' => (bool) $pick->red,
                        'played' => (bool) $pick->counted && $pick->play_time > 0,
                        'counted' => (bool) $pick->counted,
                        // Doświadczenie zawodnika w klubie po tym meczu (Liga Legend liczy się jak dwa występy).
                        'experience' => round(($before[$club][$pick->lech_player_id] ?? 0) + $pick->experience * Squad::appearances($set), 1),
                    ];
                }
                usort($rows, fn ($a, $b) => [$b['played'], $order[$a['position']->value]] <=> [$a['played'], $order[$b['position']->value]]);

                $sides[] = [
                    'name' => $seasonTeam?->name ?? Fixture::VIRTUAL_OPPONENT,
                    'profile' => $seasonTeam?->profileUrl(),
                    'bot' => (bool) $seasonTeam?->is_bot,
                    'virtual' => $virtual,
                    'mine' => $entryId !== null && $mine->contains($entryId),
                    'goals' => (int) $goals,
                    'winner' => $fixture->winner_entry_id !== null && (int) $fixture->winner_entry_id === (int) $entryId,
                    'has_tip' => (bool) $score?->has_tip,
                    'tip' => $score?->has_tip ? $score->tip_lech.':'.$score->tip_opponent : null,
                    'tip_points' => (int) $score?->tip_points,
                    'rows' => $rows,
                ];
            }

            $out[$set][] = [
                'fixture_id' => $fixture->id,
                'competition' => $competition->name ?: $competition->type->label(),
                'by_time' => (bool) $fixture->decided_by_time,
                'sides' => $sides,
            ];
        }

        return $out;
    }

    /** Nazwisko z pełnego imienia i nazwiska („Mateusz Lis” → „Lis”, „João Moutinho” → „Moutinho”). */
    public static function surname(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : trim($name);
    }
}
