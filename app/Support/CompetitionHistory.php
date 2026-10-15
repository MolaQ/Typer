<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historia rozgrywek i rekordy (strona /history): triumfatorzy sezon po sezonie i rekordy wszech czasów
 * dla każdych rozgrywek (klucz trofeum: league_1…league_11, cup, champions, europa, conference, legends)
 * oraz nagród indywidualnych (mvp, golden_ball, golden_gloves). Tylko zakończone sezony.
 * Zespół w rekordach to właściciel miejsca na koniec sezonu (gracz albo bot, final_standings.user_id/bot_id).
 */
final class CompetitionHistory
{
    public const AWARDS = ['mvp', 'golden_ball', 'golden_gloves'];

    /** Rozgrywki pokazywane w „Ostatnich mistrzach” na stronie głównej (w kolejności ważności). */
    public const HIGHLIGHTS = ['legends', 'league_1', 'cup', 'champions', 'europa', 'conference', 'mvp', 'golden_ball', 'golden_gloves'];

    /** @var array<string, array{name: string, url: string|null, bot: bool}> */
    private static array $owners = [];

    /** @return array<string, string> klucz => nazwa (rozgrywki w kolejności strony wyników, potem nagrody) */
    public static function keys(): array
    {
        $trophies = HallOfFame::trophies();

        return CompetitionColors::labels() + collect(self::AWARDS)->mapWithKeys(fn ($k) => [$k => $trophies[$k] ?? $k])->all();
    }

    public static function slug(string $key): string
    {
        return Str::slug(self::keys()[$key] ?? $key);
    }

    public static function fromSlug(?string $slug): ?string
    {
        foreach (array_keys(self::keys()) as $key) {
            if (self::slug($key) === $slug) {
                return $key;
            }
        }

        return null;
    }

    public static function isAward(string $key): bool
    {
        return in_array($key, self::AWARDS, true);
    }

    /** Barwy strony (nagrody indywidualne w barwach Ekstraklasy). */
    public static function colorKey(string $key): string
    {
        return self::isAward($key) ? 'league_1' : $key;
    }

    /**
     * Triumfatorzy sezon po sezonie (od ostatniego): podium z dorobkiem albo zwycięzca nagrody z wartością.
     *
     * @return array<int, array{season: Season, podium: array<int, array{place: int, team: array{name: string, url: string|null, bot: bool}, line: string}>, extra: array<int, array{label: string, team: array{name: string, url: string|null, bot: bool}, line: string}>}>
     */
    public static function honours(string $key): array
    {
        $seasons = self::seasons();
        $out = [];

        if (self::isAward($key)) {
            $awards = HallOfFameAward::where('kind', $key)->whereIn('season_id', $seasons->keys())->get()->groupBy('season_id');

            foreach ($seasons as $season) {
                $rows = ($awards[$season->id] ?? collect())->sortBy(fn ($a) => $a->meta['place'] ?? 1)->values();
                if ($rows->isEmpty()) {
                    continue;
                }

                $out[] = [
                    'season' => $season,
                    'podium' => $rows->map(fn ($a, $i) => ['place' => $i + 1, 'team' => self::owner($a->user_id, $a->bot_id), 'line' => self::awardLine($key, $a->meta ?? [])])->all(),
                    'extra' => [],
                ];
            }

            return $out;
        }

        $competitions = self::competitions($key, $seasons);
        $standings = FinalStanding::whereIn('competition_id', $competitions->keys())->where('place', '<=', 3)->orderBy('place')->get()->groupBy('competition_id');
        // Wynik finału pucharów europejskich (w tabeli końcowej Pucharu Polski jest już w stats.score).
        $finals = Fixture::whereIn('competition_id', $competitions->keys())->where('round', EuropeanCup::ROUNDS)->whereNotNull('winner_entry_id')->get()->keyBy('competition_id');
        $scorers = HallOfFameAward::where('kind', 'top_scorer')->whereIn('competition_id', $competitions->keys())->get()->keyBy('competition_id');

        foreach ($competitions->sortByDesc(fn ($c) => $seasons[$c->season_id]->number) as $competition) {
            $rows = $standings[$competition->id] ?? collect();
            if ($rows->isEmpty()) {
                continue;
            }

            $knockout = $competition->type === CompetitionType::Cup || $competition->type->isEuropean();
            $podium = $rows->when($knockout, fn ($r) => $r->where('place', '<=', 2))
                ->map(fn ($s) => ['place' => $s->place, 'team' => self::owner($s->user_id, $s->bot_id), 'line' => $competition->type->isEuropean() && $finals->has($competition->id)
                    ? __('Final :score', ['score' => $finals[$competition->id]->score()])
                    : self::standingLine($s)])
                ->values()->all();

            $extra = [];
            if ($scorer = $scorers->get($competition->id)) {
                $extra[] = ['label' => __('Top scorer'), 'team' => self::owner($scorer->user_id, $scorer->bot_id), 'line' => __(':count goals', ['count' => $scorer->meta['goals'] ?? 0])];
            }

            $out[] = ['season' => $seasons[$competition->season_id], 'podium' => $podium, 'extra' => $extra];
        }

        return $out;
    }

    /**
     * Rekordy wszech czasów: tytuł => najlepsze 3 zespoły z wartością.
     *
     * @return array<int, array{title: string, icon: string, rows: array<int, array{team: array{name: string, url: string|null, bot: bool}, value: string, season: string|null}>}>
     */
    public static function records(string $key): array
    {
        $seasons = self::seasons();

        if (self::isAward($key)) {
            $awards = HallOfFameAward::where('kind', $key)->whereIn('season_id', $seasons->keys())->get();
            $winners = $awards->filter(fn ($a) => ($a->meta['place'] ?? 1) === 1);
            $metric = ['mvp' => 'exact', 'golden_ball' => 'goals', 'golden_gloves' => 'gloves'][$key];

            return array_values(array_filter([
                self::top(__('Most wins of the award'), 'trophy', $winners, fn ($rows) => $rows->count()),
                self::best(__('Best season'), 'star', $winners, fn ($a) => (int) ($a->meta[$metric] ?? 0), $seasons),
            ]));
        }

        $competitions = self::competitions($key, $seasons);
        $standings = FinalStanding::whereIn('competition_id', $competitions->keys())->get();
        $first = $standings->where('place', 1);
        $knockout = in_array($key, ['cup', 'champions', 'europa', 'conference'], true);

        if ($knockout) {
            $entries = $competitions->keys();
            $won = DB::table('fixtures')
                ->join('competition_entries', 'competition_entries.id', '=', 'fixtures.winner_entry_id')
                ->join('season_teams', 'season_teams.id', '=', 'competition_entries.season_team_id')
                ->whereIn('fixtures.competition_id', $entries)
                ->get(['season_teams.user_id', 'season_teams.bot_id']);

            return array_values(array_filter([
                self::top(__('Most titles'), 'trophy', $first, fn ($rows) => $rows->count()),
                self::top(__('Most finals'), 'flag', $standings->where('place', '<=', 2), fn ($rows) => $rows->count()),
                self::top(__('Most matches won'), 'check-badge', $won, fn ($rows) => $rows->count()),
            ]));
        }

        $stat = fn (string $name) => fn ($rows) => (int) $rows->sum(fn ($s) => $s->stats[$name] ?? 0);

        return array_values(array_filter([
            self::top(__('Most titles'), 'trophy', $first, fn ($rows) => $rows->count()),
            self::top(__('Most goals'), 'fire', $standings, $stat('for')),
            self::top(__('Most wins'), 'check-badge', $standings, $stat('won')),
            self::top(__('Most points'), 'chart-bar', $standings, $stat('points')),
            self::top(__('Most exact tips'), 'sparkles', $standings, $stat('exact')),
            self::top(__('Most seasons played'), 'calendar', $standings, fn ($rows) => $rows->count()),
            self::best(__('Most points in a season'), 'star', $standings, fn ($s) => (int) ($s->stats['points'] ?? 0), $seasons, fn ($s) => $competitions[$s->competition_id]->season_id ?? null),
            self::best(__('Most goals in a season'), 'bolt', $standings, fn ($s) => (int) ($s->stats['for'] ?? 0), $seasons, fn ($s) => $competitions[$s->competition_id]->season_id ?? null),
        ]));
    }

    /**
     * Ostatni mistrzowie najważniejszych rozgrywek (strona główna).
     *
     * @return array{season: Season|null, rows: array<int, array{key: string, label: string, team: array{name: string, url: string|null, bot: bool}, line: string}>}
     */
    public static function latestChampions(): array
    {
        $season = self::seasons()->first();
        if (! $season) {
            return ['season' => null, 'rows' => []];
        }

        $labels = self::keys();
        $rows = [];
        foreach (self::HIGHLIGHTS as $key) {
            $honour = collect(self::honours($key))->first(fn ($h) => $h['season']->id === $season->id);
            $winner = $honour['podium'][0] ?? null;
            if ($winner) {
                $rows[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'team' => $winner['team'], 'line' => $winner['line']];
            }
        }

        return ['season' => $season, 'rows' => $rows];
    }

    /** @return Collection<int, Season> zakończone sezony od ostatniego, po id */
    private static function seasons(): Collection
    {
        return Season::where('status', SeasonStatus::Finished->value)->orderByDesc('number')->get()->keyBy('id');
    }

    /**
     * @param  Collection<int, Season>  $seasons
     * @return Collection<int, Competition>
     */
    private static function competitions(string $key, Collection $seasons): Collection
    {
        return Competition::whereIn('season_id', $seasons->keys())->get()
            ->filter(fn ($c) => $c->trophyKey() === $key)
            ->keyBy('id');
    }

    /** Dorobek w tabeli końcowej: liga „23 pkt · 7-2-0 · 22:6”, puchar wynik finału. */
    private static function standingLine(FinalStanding $s): string
    {
        $stats = $s->stats ?? [];

        if (isset($stats['score'])) {
            return __('Final :score', ['score' => $stats['score']]);
        }

        if (! isset($stats['points'])) {
            return '';
        }

        return __(':points pts', ['points' => $stats['points']]).' · '.($stats['won'] ?? 0).'-'.($stats['drawn'] ?? 0).'-'.($stats['lost'] ?? 0)
            .' · '.($stats['for'] ?? 0).':'.($stats['against'] ?? 0);
    }

    /** @param array<string, mixed> $meta */
    private static function awardLine(string $key, array $meta): string
    {
        return match ($key) {
            'golden_ball' => __(':count goals', ['count' => $meta['goals'] ?? 0]),
            'golden_gloves' => __('Clean sheets: :count', ['count' => $meta['gloves'] ?? 0]),
            default => __('Exact tips: :count', ['count' => $meta['exact'] ?? 0]),
        };
    }

    /**
     * Ranking sumaryczny według właściciela (gracz albo bot), najlepsze 3.
     *
     * @param  Collection<int, mixed>  $rows
     * @return array{title: string, icon: string, rows: array<int, array{team: array{name: string, url: string|null, bot: bool}, value: string, season: string|null}>}|null
     */
    private static function top(string $title, string $icon, Collection $rows, callable $value): ?array
    {
        $ranked = $rows->filter(fn ($r) => $r->user_id || $r->bot_id)
            ->groupBy(fn ($r) => $r->user_id ? 'u'.$r->user_id : 'b'.$r->bot_id)
            ->map(fn ($group) => ['first' => $group->first(), 'value' => $value($group)])
            ->filter(fn ($r) => $r['value'] > 0)
            ->sortByDesc('value')
            ->take(3);

        if ($ranked->isEmpty()) {
            return null;
        }

        return [
            'title' => $title,
            'icon' => $icon,
            'rows' => $ranked->map(fn ($r) => ['team' => self::owner($r['first']->user_id, $r['first']->bot_id), 'value' => (string) $r['value'], 'season' => null])->values()->all(),
        ];
    }

    /**
     * Najlepszy pojedynczy sezon, najlepsze 3.
     *
     * @param  Collection<int, mixed>  $rows
     * @param  Collection<int, Season>  $seasons
     * @return array{title: string, icon: string, rows: array<int, array{team: array{name: string, url: string|null, bot: bool}, value: string, season: string|null}>}|null
     */
    private static function best(string $title, string $icon, Collection $rows, callable $value, Collection $seasons, ?callable $seasonOf = null): ?array
    {
        $ranked = $rows->filter(fn ($r) => $r->user_id || $r->bot_id)
            ->map(fn ($r) => ['row' => $r, 'value' => $value($r)])
            ->filter(fn ($r) => $r['value'] > 0)
            ->sortByDesc('value')
            ->take(3);

        if ($ranked->isEmpty()) {
            return null;
        }

        return [
            'title' => $title,
            'icon' => $icon,
            'rows' => $ranked->map(function ($r) use ($seasons, $seasonOf) {
                $seasonId = $seasonOf ? $seasonOf($r['row']) : $r['row']->season_id;

                return [
                    'team' => self::owner($r['row']->user_id, $r['row']->bot_id),
                    'value' => (string) $r['value'],
                    'season' => $seasonId ? $seasons[$seasonId]?->title : null,
                ];
            })->values()->all(),
        ];
    }

    /** @return array{name: string, url: string|null, bot: bool} */
    private static function owner(?int $userId, ?int $botId): array
    {
        $key = $userId ? 'u'.$userId : 'b'.$botId;

        if (! isset(self::$owners[$key])) {
            if ($userId) {
                $user = User::find($userId);
                self::$owners[$key] = ['name' => $user ? ($user->team_name ?: $user->name) : '—', 'url' => $user ? route('team.show', $user) : null, 'bot' => false];
            } else {
                $bot = $botId ? Bot::find($botId) : null;
                self::$owners[$key] = ['name' => $bot ? $bot->name : '—', 'url' => $bot ? route('bot.show', $bot) : null, 'bot' => true];
            }
        }

        return self::$owners[$key];
    }
}
