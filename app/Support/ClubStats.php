<?php

namespace App\Support;

use App\Enums\MatchdayStatus;
use App\Models\DesignatedPlayer;
use App\Models\LechPlayer;
use App\Models\Matchday;

/**
 * Zawodnicy klubu (gracz albo bot) i ich dorobek z kolejek, w których klub ich wystawił (desygnowanie, Support\Squad):
 * sumy w sezonie i w karierze oraz lista meczów do szczegółów („Mój klub” na /tips, profil bota).
 */
class ClubStats
{
    private const SUMS = ['fielded', 'played', 'minutes', 'goals', 'assists', 'own_goals', 'clean_sheets', 'yellow', 'red', 'attack', 'defense', 'experience', 'forfeited'];

    /**
     * Kadra klubu z dorobkiem: cała kadra bez niedostępnych plus niedostępni, którzy już coś mają.
     *
     * @return array<int, array{player: LechPlayer, lock: int, next: int|null, season: array<string, int>, career: array<string, int>, matches: array<int, array<string, mixed>>}>
     */
    public static function players(?int $userId, ?int $botId, ?int $seasonId): array
    {
        if ($userId === null && $botId === null) {
            return [];
        }

        $picks = DesignatedPlayer::with(['matchday.season'])
            ->where($userId !== null ? 'user_id' : 'bot_id', $userId ?? $botId)
            ->whereNotNull('counted')
            ->whereHas('matchday', fn ($q) => $q->where('status', MatchdayStatus::Played->value))
            ->get()
            ->sortByDesc(fn (DesignatedPlayer $p) => (int) $p->matchday?->kickoff_at?->getTimestamp())
            ->groupBy('lech_player_id');

        // Blokady na najbliższą kolejkę bez wyniku w sezonie (do „wolny od kolejki N”).
        $next = $seasonId ? Matchday::where('season_id', $seasonId)->where('status', '!=', MatchdayStatus::Played->value)->orderBy('number')->first() : null;
        $locks = $next ? Squad::locks($userId, $botId, $next) : [];

        $squad = Squad::available();
        $extra = LechPlayer::whereIn('id', $picks->keys()->diff($squad->pluck('id')))->get();
        $out = [];

        foreach ($squad->concat($extra) as $player) {
            $season = array_fill_keys(self::SUMS, 0);
            $career = $season;
            $matches = [];

            foreach ($picks->get($player->id, collect()) as $pick) {
                $row = self::sums($pick);

                foreach (self::SUMS as $key) {
                    $career[$key] += $row[$key];
                    if ($pick->matchday?->season_id === $seasonId) {
                        $season[$key] += $row[$key];
                    }
                }

                $md = $pick->matchday;
                $matches[] = [
                    'this_season' => $md?->season_id === $seasonId,
                    'season' => $md?->season?->title,
                    'matchday' => $md?->number,
                    'fixture' => $md ? $md->fixture.($md->lech_goals !== null ? ' '.$md->lech_goals.':'.$md->opponent_goals : '') : '',
                    'set' => $pick->score_set->scoreSetLabel(),
                    'counted' => (bool) $pick->counted,
                    'minutes' => $pick->minutes,
                    'goals' => $pick->goals,
                    'assists' => $pick->assists,
                    'own_goals' => $pick->own_goals,
                    'clean_sheet' => $pick->clean_sheet,
                    'yellow' => $pick->yellow,
                    'red' => $pick->red,
                    'experience' => $pick->experience,
                    'lock' => $pick->lock,
                ];
            }

            $out[] = ['player' => $player, 'lock' => $locks[$player->id] ?? 0, 'next' => $next?->number, 'season' => $season, 'career' => $career, 'matches' => $matches];
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function sums(DesignatedPlayer $pick): array
    {
        $counted = (bool) $pick->counted;

        return [
            'fielded' => $counted ? 1 : 0,
            'played' => $counted && $pick->minutes > 0 ? 1 : 0,
            'minutes' => $pick->minutes,
            'goals' => $pick->goals,
            'assists' => $pick->assists,
            'own_goals' => $pick->own_goals,
            'clean_sheets' => $pick->clean_sheet === true ? 1 : 0,
            'yellow' => (int) $pick->yellow,
            'red' => (int) $pick->red,
            'attack' => $pick->attack,
            'defense' => $pick->defense,
            'experience' => $pick->experience,
            'forfeited' => $counted ? 0 : 1,
        ];
    }
}
