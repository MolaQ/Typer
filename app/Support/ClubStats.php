<?php

namespace App\Support;

use App\Enums\MatchdayStatus;
use App\Enums\PlayerPosition;
use App\Models\DesignatedPlayer;
use App\Models\LechPlayer;
use App\Models\Matchday;

/**
 * Zawodnicy klubu (gracz albo bot) i ich dorobek z kolejek, w których klub ich wystawił (desygnowanie, Support\Squad):
 * bieżący sezon, każdy wcześniejszy sezon osobno, cała kariera i lista meczów („Moja kadra” na /tips, profil bota).
 */
class ClubStats
{
    public const SUMS = ['fielded', 'played', 'play_time', 'goals', 'assists', 'own_goals', 'clean_sheets', 'yellow', 'red', 'attack', 'defense', 'experience', 'forfeited'];

    /**
     * Kadra klubu z dorobkiem: cała kadra bez niedostępnych plus niedostępni, którzy już coś mają.
     * Kolejność: doświadczenie w całej karierze w klubie malejąco, potem w bieżącym sezonie, potem pozycja i numer.
     * „seasons” to sezony od ostatniego (także bieżący) z sumą i meczami od pierwszej kolejki do ostatniej.
     *
     * @return array<int, array{player: LechPlayer, free_at: int|null, season: array<string, int|float>, career: array<string, int|float>, seasons: array<int, array{title: string, current: bool, sums: array<string, int|float>, matches: array<int, array<string, mixed>>}>}>
     */
    public static function players(?int $userId, ?int $botId, ?int $seasonId): array
    {
        if ($userId === null && $botId === null) {
            return [];
        }

        $picks = DesignatedPlayer::with(['matchday.season'])
            ->where($userId !== null ? 'user_id' : 'bot_id', $userId ?? $botId)
            ->whereNotNull('counted')
            // Tylko mecze, które się odbyły (np. Liga Legend z jednym zespołem nie ma pojedynku).
            ->where('matches', '>', 0)
            ->whereHas('matchday', fn ($q) => $q->where('status', MatchdayStatus::Played->value))
            ->get()
            ->sortBy(fn (DesignatedPlayer $p) => [-(int) $p->matchday?->season?->getAttribute('number'), (int) $p->matchday?->number])
            ->groupBy('lech_player_id');

        // Odpoczynek przed najbliższą kolejką bez wyniku w sezonie (do „wolny od”).
        $next = $seasonId ? Matchday::where('season_id', $seasonId)->where('status', '!=', MatchdayStatus::Played->value)->orderBy('number')->first() : null;
        $locks = $next ? Squad::locks($userId, $botId, $next) : [];

        $squad = Squad::available();
        $extra = LechPlayer::whereIn('id', $picks->keys()->diff($squad->pluck('id')))->get();
        $order = array_flip(PlayerPosition::values());
        $out = [];

        foreach ($squad->concat($extra) as $player) {
            $season = self::empty();
            $career = $season;
            $seasons = [];

            foreach ($picks->get($player->id, collect()) as $pick) {
                $row = self::sums($pick);
                $md = $pick->matchday;
                $current = $md?->season_id === $seasonId;
                $key = (int) $md?->season_id;
                $seasons[$key] ??= ['title' => (string) $md?->season?->title, 'current' => $current, 'sums' => self::empty(), 'matches' => []];

                foreach (self::SUMS as $sum) {
                    $career[$sum] += $row[$sum];
                    $seasons[$key]['sums'][$sum] += $row[$sum];
                    if ($current) {
                        $season[$sum] += $row[$sum];
                    }
                }

                $seasons[$key]['matches'][] = [
                    'matchday' => $md?->number,
                    'fixture' => $md ? $md->fixture.($md->lech_goals !== null ? ' '.$md->lech_goals.':'.$md->opponent_goals : '') : '',
                    'set' => $pick->score_set->scoreSetLabel(),
                    'counted' => (bool) $pick->counted,
                    'play_time' => $pick->play_time,
                    'goals' => $pick->goals,
                    'assists' => $pick->assists,
                    'own_goals' => $pick->own_goals,
                    'clean_sheet' => $pick->clean_sheet,
                    'yellow' => $pick->yellow,
                    'red' => $pick->red,
                    'experience' => $pick->experience * $pick->matches,
                    'times' => $pick->matches,
                    'rest_hours' => $pick->rest_hours,
                ];
            }

            $out[] = [
                'player' => $player,
                'free_at' => $locks[$player->id] ?? null,
                'season' => $season,
                'career' => $career,
                'seasons' => array_values($seasons),
            ];
        }

        usort($out, fn ($a, $b) => [$b['career']['experience'], $b['season']['experience'], $order[$a['player']->position->value], $a['player']->number ?? 999]
            <=> [$a['season']['experience'], $a['career']['experience'], $order[$b['player']->position->value], $b['player']->number ?? 999]);

        return $out;
    }

    /** @return array<string, int|float> */
    private static function empty(): array
    {
        return array_fill_keys(self::SUMS, 0);
    }

    /** @return array<string, int|float> */
    private static function sums(DesignatedPlayer $pick): array
    {
        $counted = (bool) $pick->counted;
        // Liga Legend: każdy pojedynek, który się odbył, to osobny występ (zwykle dwa, przy dwóch zespołach jeden).
        $times = $pick->matches;

        return [
            'fielded' => $counted ? $times : 0,
            'played' => $counted && $pick->play_time > 0 ? $times : 0,
            'play_time' => $pick->play_time * $times,
            'goals' => $pick->goals * $times,
            'assists' => $pick->assists * $times,
            'own_goals' => $pick->own_goals * $times,
            'clean_sheets' => $pick->clean_sheet === true ? $times : 0,
            'yellow' => $pick->yellow * $times,
            'red' => (int) $pick->red * $times,
            'attack' => $pick->attack * $times,
            'defense' => $pick->defense * $times,
            'experience' => $pick->experience * $times,
            'forfeited' => $counted ? 0 : $times,
        ];
    }

    /** Doświadczenie: zawsze jedno miejsce po przecinku (4,0; 3,5). */
    public static function experience(int|float $value): string
    {
        return number_format((float) $value, 1, ',', '');
    }

    /** Liczba do tabeli: całkowita bez przecinka, ułamek z jednym miejscem po przecinku (3,5). */
    public static function format(int|float $value): string
    {
        return fmod((float) $value, 1.0) === 0.0 ? (string) (int) $value : number_format((float) $value, 1, ',', '');
    }
}
