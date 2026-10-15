<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use Illuminate\Support\Facades\DB;

/**
 * Gwiazdy gry (strona /stars): zawodnicy Lecha w klubach wszystkich graczy i botów, każdy wiersz = para klub + zawodnik
 * (dorobek liczy się osobno w każdym klubie, jak w „Mojej kadrze”). Liczby z rozliczonych wyborów (designated_players,
 * counted, mecze, które się odbyły), procent wygranych z meczów klubu w typie rozgrywek, w którym zawodnik był wystawiony
 * (Liga Legend: oba pojedynki; remis rozstrzygnięty czasem typu w pucharze = wygrana albo porażka).
 * Wiersze zawierają tylko liczby i id (cache bez obiektów), nazwy dokłada strona.
 */
class GameStars
{
    /**
     * @return array<int, array{user_id: int|null, bot_id: int|null, player_id: int, fielded: int, played: int, goals: int, assists: int, own_goals: int, clean_sheets: int, attack: float, defense: float, contribution: float, experience: float, yellow: int, red: int, won: int, games: int, win_pct: float|null}>
     */
    public static function rows(?int $seasonId): array
    {
        return PageCache::remember('stars:'.($seasonId ?? 'all'), fn () => self::compute($seasonId));
    }

    /**
     * @return array<int, array{user_id: int|null, bot_id: int|null, player_id: int, fielded: int, played: int, goals: int, assists: int, own_goals: int, clean_sheets: int, attack: float, defense: float, contribution: float, experience: float, yellow: int, red: int, won: int, games: int, win_pct: float|null}>
     */
    private static function compute(?int $seasonId): array
    {
        $picks = DB::table('designated_players')
            ->join('matchdays', 'matchdays.id', '=', 'designated_players.matchday_id')
            ->where('matchdays.status', MatchdayStatus::Played->value)
            ->where('designated_players.counted', true)
            ->where('designated_players.matches', '>', 0)
            ->when($seasonId, fn ($q) => $q->where('matchdays.season_id', $seasonId))
            ->get([
                'designated_players.matchday_id', 'designated_players.user_id', 'designated_players.bot_id', 'designated_players.lech_player_id',
                'designated_players.score_set', 'designated_players.matches', 'designated_players.play_time', 'designated_players.goals',
                'designated_players.assists', 'designated_players.own_goals', 'designated_players.clean_sheet', 'designated_players.yellow',
                'designated_players.red', 'designated_players.attack', 'designated_players.defense', 'designated_players.experience',
            ]);

        $outcomes = self::outcomes($seasonId);
        $rows = [];

        foreach ($picks as $pick) {
            $club = Squad::clubKey($pick->user_id !== null ? (int) $pick->user_id : null, $pick->bot_id !== null ? (int) $pick->bot_id : null);
            $key = $club.'|'.$pick->lech_player_id;
            $times = (int) $pick->matches;
            $rows[$key] ??= [
                'user_id' => $pick->user_id !== null ? (int) $pick->user_id : null,
                'bot_id' => $pick->bot_id !== null ? (int) $pick->bot_id : null,
                'player_id' => (int) $pick->lech_player_id,
                'fielded' => 0, 'played' => 0, 'goals' => 0, 'assists' => 0, 'own_goals' => 0, 'clean_sheets' => 0,
                'attack' => 0.0, 'defense' => 0.0, 'contribution' => 0.0, 'experience' => 0.0, 'yellow' => 0, 'red' => 0,
                'won' => 0, 'games' => 0, 'win_pct' => null,
            ];
            $row = &$rows[$key];
            $row['fielded'] += $times;
            $row['played'] += (float) $pick->play_time > 0 ? $times : 0;
            $row['goals'] += (int) $pick->goals * $times;
            $row['assists'] += (int) $pick->assists * $times;
            $row['own_goals'] += (int) $pick->own_goals * $times;
            $row['clean_sheets'] += (int) $pick->clean_sheet === 1 ? $times : 0;
            $row['attack'] += (float) $pick->attack * $times;
            $row['defense'] += (float) $pick->defense * $times;
            $row['experience'] += (float) $pick->experience * $times;
            $row['yellow'] += (int) $pick->yellow * $times;
            $row['red'] += (int) $pick->red * $times;

            // Wynik meczów klubu w tym typie rozgrywek w tej kolejce (zawodnik był w nich wystawiony).
            [$won, $games] = $outcomes[$pick->matchday_id.'|'.$club.'|'.$pick->score_set] ?? [0, 0];
            $row['won'] += $won;
            $row['games'] += $games;
            unset($row);
        }

        foreach ($rows as &$row) {
            $row['contribution'] = $row['attack'] + $row['defense'];
            $row['win_pct'] = $row['games'] > 0 ? round($row['won'] / $row['games'] * 100, 1) : null;
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * Wygrane i rozegrane mecze klubu w kolejce w typie rozgrywek (kto grał miejscem: team_scores.user_id / bot_id).
     *
     * @return array<string, array{0: int, 1: int}> „matchday_id|klucz klubu|typ rozgrywek” => [wygrane, mecze]
     */
    private static function outcomes(?int $seasonId): array
    {
        $fixtures = DB::table('fixtures')
            ->join('competitions', 'competitions.id', '=', 'fixtures.competition_id')
            ->whereNotNull('fixtures.home_goals')
            ->when($seasonId, fn ($q) => $q->where('competitions.season_id', $seasonId))
            ->get(['fixtures.round', 'fixtures.home_entry_id', 'fixtures.away_entry_id', 'fixtures.home_goals', 'fixtures.away_goals',
                'fixtures.winner_entry_id', 'competitions.season_id', 'competitions.type']);

        $entryIds = $fixtures->pluck('home_entry_id')->merge($fixtures->pluck('away_entry_id'))->filter()->unique()->values();
        $teamOfEntry = DB::table('competition_entries')->whereIn('id', $entryIds)->pluck('season_team_id', 'id');
        $matchdays = DB::table('matchdays')->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))->get(['id', 'season_id', 'number'])
            ->mapWithKeys(fn ($md) => [$md->season_id.':'.$md->number => (int) $md->id]);
        $owners = DB::table('team_scores')
            ->when($seasonId, fn ($q) => $q->whereIn('matchday_id', $matchdays->values()))
            ->get(['matchday_id', 'season_team_id', 'score_set', 'user_id', 'bot_id'])
            ->mapWithKeys(fn ($s) => [$s->matchday_id.'|'.$s->season_team_id.'|'.$s->score_set => Squad::clubKey(
                $s->user_id !== null ? (int) $s->user_id : null,
                $s->bot_id !== null ? (int) $s->bot_id : null,
            )]);

        $out = [];
        foreach ($fixtures as $fixture) {
            $matchdayId = $matchdays[$fixture->season_id.':'.$fixture->round] ?? null;
            if ($matchdayId === null) {
                continue;
            }
            $set = CompetitionType::from($fixture->type)->scoreSet()->value;

            foreach ([true, false] as $home) {
                $entry = $home ? $fixture->home_entry_id : $fixture->away_entry_id;
                $team = $entry !== null ? ($teamOfEntry[$entry] ?? null) : null;
                $club = $team !== null ? ($owners[$matchdayId.'|'.$team.'|'.$set] ?? null) : null;
                if ($club === null) {
                    continue;
                }

                $mine = (int) ($home ? $fixture->home_goals : $fixture->away_goals);
                $theirs = (int) ($home ? $fixture->away_goals : $fixture->home_goals);
                // Remis rozstrzygnięty czasem typu (puchar) to wygrana albo porażka.
                $won = $mine === $theirs && $fixture->winner_entry_id ? (int) $fixture->winner_entry_id === (int) $entry : $mine > $theirs;

                $key = $matchdayId.'|'.$club.'|'.$set;
                $out[$key] ??= [0, 0];
                $out[$key][0] += $won ? 1 : 0;
                $out[$key][1]++;
            }
        }

        return $out;
    }
}
