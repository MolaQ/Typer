<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\MatchdayStatus;
use App\Enums\RoleName;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statystyki gracza do zakładek strony „Moje typy”: kolejki (typy i braki), postęp w rozgrywkach,
 * statystyki sezonu (typy, mecze, serie, miejsce wśród graczy) i historia z rekordami osobistymi.
 * Liczymy z gotowych danych: team_scores (rozliczone kolejki), fixtures (mecze) i final_standings (tabele końcowe).
 */
final class PlayerStats
{
    /**
     * Kolejki sezonu z typem gracza i punktami (rozliczone) albo informacją o braku typu.
     *
     * @return array<int, array{matchday: Matchday, tip: ?string, points: ?int, exact: bool, missing: bool, open: bool}>
     */
    public static function matchdays(User $user, Season $season): array
    {
        $matchdays = Matchday::where('season_id', $season->id)->orderBy('number')->get();
        $tips = Tip::whereIn('matchday_id', $matchdays->pluck('id'))->where('user_id', $user->id)->get()->keyBy('matchday_id');
        $scores = self::tipRows($user, $season)->keyBy('matchday_id');

        return $matchdays->map(function (Matchday $matchday) use ($tips, $scores) {
            $tip = $tips->get($matchday->id);
            $score = $scores->get($matchday->id);
            $played = $matchday->status === MatchdayStatus::Played;

            return [
                'matchday' => $matchday,
                'tip' => $tip?->score(),
                'points' => $played && $score ? (int) $score->tip_points : null,
                'exact' => (bool) $score?->exact_hit,
                // Brak typu: kolejka rozegrana bez typu albo jeszcze otwarta i nie wytypowana.
                'missing' => $tip === null && ($played || $matchday->isOpenForTips()),
                'open' => $matchday->isOpenForTips(),
            ];
        })->all();
    }

    /**
     * Postęp w rozgrywkach sezonu: miejsce w tabeli albo etap (puchar, Liga Legend).
     *
     * @return array<int, array{name: string, type: CompetitionType, status: string, played: int, won: int, drawn: int, lost: int, points: ?int}>
     */
    public static function competitions(User $user, Season $season): array
    {
        $team = self::team($user, $season);

        if (! $team) {
            return [];
        }

        $entries = CompetitionEntry::with('competition')->where('season_team_id', $team->id)->get();
        // Punkty za typ w każdej rozliczonej kolejce (jeden typ na kolejkę, więc tyle samo we wszystkich rozgrywkach).
        $tipPoints = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('team_scores.season_team_id', $team->id)
            ->get(['matchdays.number', 'team_scores.tip_points', 'team_scores.has_tip'])
            ->mapWithKeys(fn ($r) => [(int) $r->number => $r->has_tip ? (int) $r->tip_points : null])
            ->all();
        $order = array_map(fn ($t) => $t->value, CompetitionType::cases());
        $out = [];

        foreach ($entries->sortBy(fn ($e) => [array_search($e->competition->type->value, $order, true), $e->competition->tier ?? 0]) as $entry) {
            $competition = $entry->competition;
            $row = [
                'name' => $competition->name ?: $competition->type->label(),
                'type' => $competition->type,
                // Do linków na stronę wyników (/results/sezon-N/{slug}?team=id zespołu).
                'slug' => $competition instanceof Competition ? $competition->slug() : '',
                'team_id' => $team->id,
                'trophy' => $competition->trophyKey(),
                'status' => '',
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'points' => null,
                'matches' => self::entryMatches($entry, $team, $tipPoints),
                // Puchar i Liga Legend: kolejka/runda, w której zespół odpadł (kafelek „odpadł z rozgrywek”).
                'eliminated_round' => $entry->eliminated_round,
            ];

            if ($competition->type === CompetitionType::Cup) {
                $row['status'] = $entry->eliminated_round
                    ? __('Knocked out in: :round', ['round' => CupBracket::roundName($entry->eliminated_round)])
                    : __('Still in the cup');
            } elseif ($competition->type->isEuropean() && $competition instanceof Competition) {
                // Puchary europejskie: miejsce w grupie, a po odpadnięciu etap (faza grupowa albo runda pucharowa).
                $group = EuropeanCup::groupOf($entry->seed);
                $table = EuropeanCup::groupTables($competition)[$group]->values();
                $index = $table->search(fn ($r) => $r['entry_id'] === $entry->id);
                $own = $index === false ? null : $table[$index];

                $row['status'] = match (true) {
                    $entry->eliminated_round === EuropeanCup::GROUP_ROUNDS => __('Knocked out in the group stage'),
                    $entry->eliminated_round !== null => __('Knocked out in: :round', ['round' => EuropeanCup::stageLabel($entry->eliminated_round)]),
                    $index === false => '—',
                    default => __('Group :group: :place. place', ['group' => $group, 'place' => $index + 1]),
                };
                $row['points'] = $own['points'] ?? null;
                foreach (['played', 'won', 'drawn', 'lost'] as $key) {
                    $row[$key] = (int) ($own[$key] ?? 0);
                }
            } else {
                $table = Standings::for($competition)->values();
                $index = $table->search(fn ($r) => $r['entry_id'] === $entry->id);
                $own = $index === false ? null : $table[$index];

                $row['status'] = $index === false ? '—' : __(':place. place of :count', ['place' => $index + 1, 'count' => $table->count()]);
                // Liga Legend: wyniki zostają w tabeli także po odpadnięciu (dwie porażki w jednej kolejce).
                if ($entry->eliminated_round !== null) {
                    $row['status'] .= ' · '.__('Knocked out after matchday :number', ['number' => $entry->eliminated_round]);
                }
                $row['points'] = $own['points'] ?? null;
                foreach (['played', 'won', 'drawn', 'lost'] as $key) {
                    $row[$key] = (int) ($own[$key] ?? 0);
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Mecze zespołu w jednych rozgrywkach (kafelki na zakładce Rozgrywki): runda, rywal, wynik, rozstrzygnięcie
     * i punkty za typ w tej kolejce (null: bez typu albo przed wynikami).
     *
     * @param  array<int, ?int>  $tipPoints  numer kolejki => punkty za typ
     * @return array<int, array{fixture_id: int, round: int, rival: string, score: ?string, outcome: ?string, tip_points: ?int, played: bool}>
     */
    private static function entryMatches(CompetitionEntry $entry, SeasonTeam $team, array $tipPoints): array
    {
        $fixtures = Fixture::with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $entry->competition_id)
            ->where(fn ($q) => $q->where('home_entry_id', $entry->id)->orWhere('away_entry_id', $entry->id))
            ->orderBy('round')
            ->get();

        $out = [];
        foreach ($fixtures as $fixture) {
            $home = (int) $fixture->home_entry_id === $entry->id;
            $rival = $home ? $fixture->away : $fixture->home;
            $played = $fixture->isPlayed();
            [$outcome, $for, $against] = $played ? self::result($fixture, $entry->id, $home) : [null, null, null];

            $out[] = [
                'fixture_id' => (int) $fixture->id,
                'round' => (int) $fixture->round,
                'rival' => $rival?->seasonTeam?->name ?? Fixture::VIRTUAL_OPPONENT,
                'score' => $played ? $for.':'.$against.($fixture->decided_by_time ? ' '.__('(pen.)') : '') : null,
                'outcome' => $outcome,
                'tip_points' => $played ? ($tipPoints[(int) $fixture->round] ?? null) : null,
                'played' => $played,
            ];
        }

        return $out;
    }

    /**
     * Statystyki sezonu gracza, a obok średnie i maksima wszystkich graczy (maksimum = 100% przy kolorach kafelków,
     * dla optymizmu największe odchylenie od zera).
     *
     * @return array{mine: array<string, mixed>, average: array<string, float>, max: array<string, float>, rank: ?array{place: int, count: int}}
     */
    public static function season(User $user, Season $season): array
    {
        $all = self::seasonAll($season);
        $team = self::team($user, $season);
        $mine = $team ? ($all[$team->id] ?? self::empty()) : self::empty();

        $average = [];
        $max = [];
        if ($all !== []) {
            foreach (array_keys(self::empty()) as $key) {
                if ($key !== 'best_matchday' && (is_numeric($mine[$key] ?? null) || ($mine[$key] ?? null) === null)) {
                    $values = array_filter(array_column($all, $key), fn ($v) => $v !== null && is_numeric($v));
                    $average[$key] = $values === [] ? 0.0 : array_sum($values) / count($values);
                    $max[$key] = $values === [] ? 0.0 : (float) ($key === 'optimism' ? max(array_map(fn ($v) => abs((float) $v), $values)) : max($values));
                }
            }
        }

        // Miejsce wśród graczy według punktów za typy (pokazuje premium).
        $rank = null;
        if ($team && isset($all[$team->id]) && $all[$team->id]['scored'] > 0) {
            $points = $all[$team->id]['tip_points'];
            $rank = [
                'place' => count(array_filter($all, fn ($row) => $row['tip_points'] > $points)) + 1,
                'count' => count($all),
            ];
        }

        return ['mine' => $mine, 'average' => $average, 'max' => $max, 'rank' => $rank];
    }

    /**
     * Statystyki sezonu dowolnego zespołu (do okna z drużyną na stronie wyników). Bot nie ma typów
     * do statystyk, więc ma tylko mecze.
     *
     * @return array<string, mixed>
     */
    public static function teamSeason(SeasonTeam $team, Season $season): array
    {
        $all = self::seasonAll($season);

        if (isset($all[$team->id])) {
            return $all[$team->id];
        }

        $matches = self::seasonMatches($season, collect([$team->id]));

        return array_merge(self::empty(), $matches[$team->id] ?? self::matchSummary([]));
    }

    /**
     * Statystyki sezonu bota (profil /bots): tylko kolejki, w których bot naprawdę grał (team_scores.bot_id, Ownership),
     * także gdy w trakcie sezonu zmienił miejsce na liście. Bot nie ma bonusów.
     *
     * @return array<string, mixed>
     */
    public static function botSeason(Bot $bot, Season $season): array
    {
        $rows = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->where('team_scores.bot_id', $bot->id)
            ->select('team_scores.*', 'matchdays.number', 'matchdays.lech_goals as real_lech')
            ->get();

        $perMatchday = $rows->unique('matchday_id')->sortBy('number')->values();
        $tipped = $perMatchday->where('has_tip', true);
        $tips = $tipped->count();
        [$streak, $streakBest] = self::streak($perMatchday->map(fn ($r) => (bool) $r->outcome_hit)->all());

        // Mecze: wszystkie miejsca, którymi bot grał w sezonie, tylko z jego kolejek.
        $teamIds = $rows->pluck('season_team_id')->merge(
            SeasonTeam::where('season_id', $season->id)->where('bot_id', $bot->id)->pluck('id')
        )->unique();
        $teamOfEntry = CompetitionEntry::whereIn('season_team_id', $teamIds)->pluck('season_team_id', 'id');
        $results = [];
        foreach (Fixture::whereIn('competition_id', Competition::where('season_id', $season->id)->select('id'))
            ->whereNotNull('home_goals')->orderBy('round')->orderBy('id')->get() as $fixture) {
            foreach ([[$fixture->home_entry_id, true], [$fixture->away_entry_id, false]] as [$entryId, $home]) {
                if ($entryId && isset($teamOfEntry[$entryId])
                    && Ownership::at($season->id, (int) $teamOfEntry[$entryId], (int) $fixture->round) === 'b'.$bot->id) {
                    $results[] = self::result($fixture, (int) $entryId, $home);
                }
            }
        }

        return array_merge(self::empty(), [
            'scored' => $perMatchday->count(),
            'tips' => $tips,
            'exact' => $tipped->where('exact_hit', true)->count(),
            'diff' => $tipped->where('diff_hit', true)->count(),
            'outcome' => $tipped->where('outcome_hit', true)->count(),
            'accuracy' => $tips > 0 ? round($tipped->where('outcome_hit', true)->count() / $tips * 100) : null,
            'tip_points' => (int) $tipped->sum('tip_points'),
            'avg_tip' => $tips > 0 ? round($tipped->avg('tip_points'), 2) : null,
            'outcome_streak' => $streak,
            'outcome_streak_best' => $streakBest,
            'optimism' => $tips > 0 ? round($tipped->avg(fn ($r) => $r->tip_lech - (int) $r->getAttribute('real_lech')), 2) : null,
            'best_matchday' => $best = self::bestMatchday($rows),
            'best_points' => $best['points'] ?? null,
        ], self::matchSummary($results));
    }

    /**
     * Moje skalpy (premium): rywale-gracze (bez botów i zbanowanych), z którymi gracz ma lepszy bilans bezpośredni (więcej wygranych
     * niż porażek) ze wszystkich sezonów i rozgrywek, od najwyżej w rankingu Hall of Fame (potem bilans, potem nazwa).
     *
     * $blacklist = true: Czarna lista (premium), czyli odwrotnie: rywale z gorszym dla gracza bilansem
     * (więcej porażek niż wygranych), od najgorszego bilansu (potem ranking Hall of Fame).
     *
     * @return list<array{name: string, owner: string, url: string, bot: bool, won: int, drawn: int, lost: int, hof: float}>
     */
    public static function scalps(User $user, bool $blacklist = false): array
    {
        $records = array_filter(self::rivalRecords($user->id), fn ($r) => $blacklist ? $r['lost'] > $r['won'] : $r['won'] > $r['lost']);

        if ($records === []) {
            return [];
        }

        $ids = array_keys($records);
        $hof = HallOfFameAward::whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, sum(points) as total')->pluck('total', 'user_id');

        $out = [];
        foreach (User::whereIn('id', $ids)->get(['id', 'name', 'team_name']) as $rival) {
            $out[] = ['name' => $rival->team_name ?: $rival->name, 'owner' => $rival->name, 'url' => route('team.show', $rival), 'bot' => false,
                'hof' => round((float) ($hof[$rival->id] ?? 0), 1)] + $records[$rival->id];
        }

        if ($blacklist) {
            usort($out, fn ($a, $b) => [$b['lost'] - $b['won'], $b['hof'], $a['name']] <=> [$a['lost'] - $a['won'], $a['hof'], $b['name']]);
        } else {
            usort($out, fn ($a, $b) => [$b['hof'], $b['won'] - $b['lost'], $a['name']] <=> [$a['hof'], $a['won'] - $a['lost'], $b['name']]);
        }

        return $out;
    }

    /**
     * Bilans bezpośredni gracza z każdym innym graczem (wszystkie sezony i rozgrywki), cały w SQL i w PageCache.
     * Strony meczu = kto grał miejscem w tej kolejce (team_scores, Ownership). Bez botów i zbanowanych kont (16.10.2026):
     * gracz i tak nie może już zmienić z nimi bilansu. Remis w pucharze rozstrzygnięty czasem typu = wygrana albo porażka.
     *
     * @return array<int, array{won: int, drawn: int, lost: int}>
     */
    public static function rivalRecords(int $userId): array
    {
        return PageCache::remember('rivals:'.$userId, function () use ($userId): array {
            $sides = self::rivalSide($userId, 'home_entry_id', 'away_entry_id', 'rs.user_id as rival,
                CASE WHEN f.home_goals > f.away_goals OR (f.home_goals = f.away_goals AND f.winner_entry_id = f.home_entry_id) THEN 1 ELSE 0 END as won,
                CASE WHEN f.home_goals = f.away_goals AND f.winner_entry_id IS NULL THEN 1 ELSE 0 END as drawn')
                ->unionAll(self::rivalSide($userId, 'away_entry_id', 'home_entry_id', 'rs.user_id as rival,
                CASE WHEN f.away_goals > f.home_goals OR (f.home_goals = f.away_goals AND f.winner_entry_id = f.away_entry_id) THEN 1 ELSE 0 END as won,
                CASE WHEN f.home_goals = f.away_goals AND f.winner_entry_id IS NULL THEN 1 ELSE 0 END as drawn'));

            $out = [];
            foreach (DB::query()->fromSub($sides, 'x')
                ->whereNotIn('rival', self::bannedIds())
                ->groupBy('rival')
                ->selectRaw('rival, SUM(won) as won, SUM(drawn) as drawn, COUNT(*) - SUM(won) - SUM(drawn) as lost')
                ->get() as $row) {
                $out[(int) $row->rival] = ['won' => (int) $row->won, 'drawn' => (int) $row->drawn, 'lost' => (int) $row->lost];
            }

            return $out;
        });
    }

    /**
     * Mecze gracza jedną stroną (gospodarz albo gość) z rywalem-graczem w tej samej kolejce.
     *
     * @param  literal-string  $select
     */
    private static function rivalSide(int $userId, string $mine, string $theirs, string $select): QueryBuilder
    {
        return DB::table('fixtures as f')
            ->join('competitions as c', 'c.id', '=', 'f.competition_id')
            ->join('matchdays as fm', fn (JoinClause $j) => $j->on('fm.season_id', '=', 'c.season_id')->on('fm.number', '=', 'f.round'))
            ->join('competition_entries as me', 'me.id', '=', 'f.'.$mine)
            ->join('competition_entries as re', 're.id', '=', 'f.'.$theirs)
            ->join('team_scores as ms', fn (JoinClause $j) => $j->on('ms.matchday_id', '=', 'fm.id')
                ->on('ms.season_team_id', '=', 'me.season_team_id')
                ->on('ms.score_set', '=', DB::raw(GameStars::SCORE_SET_SQL)))
            ->join('team_scores as rs', fn (JoinClause $j) => $j->on('rs.matchday_id', '=', 'fm.id')
                ->on('rs.season_team_id', '=', 're.season_team_id')
                ->on('rs.score_set', '=', DB::raw(GameStars::SCORE_SET_SQL)))
            ->whereNotNull('f.home_goals')
            ->where('ms.user_id', $userId)
            ->whereNotNull('rs.user_id')
            ->where('rs.user_id', '!=', $userId)
            ->selectRaw($select);
    }

    /** Liczba rywali-ludzi gracza (do procentu skalpów): wszyscy inni niezbanowani gracze z zespołem w jakimkolwiek sezonie. */
    public static function humanRivals(User $user): int
    {
        return (int) SeasonTeam::whereNotNull('user_id')->where('user_id', '!=', $user->id)->whereNotIn('user_id', self::bannedIds())->distinct()->count('user_id');
    }

    /** Podzapytanie: id zbanowanych kont (pomijane w skalpach i czarnej liście). */
    private static function bannedIds(): QueryBuilder
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', RoleName::Banned->value)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->select('mr.model_id');
    }

    /**
     * Ulubione typy (wszystkie sezony): najczęstszy wynik gracza i wszystkich graczy serwisu.
     *
     * @return array{mine: ?array{score: string, count: int, total: int}, all: ?array{score: string, count: int, total: int}}
     */
    public static function favourites(User $user): array
    {
        return [
            'mine' => self::topScore(Tip::where('user_id', $user->id)),
            'all' => self::topScore(Tip::query()),
        ];
    }

    /**
     * Najczęstszy typ w każdych rozgrywkach sezonu (statystyki premium): typy graczy z zespołami w tych rozgrywkach.
     *
     * @return array<int, array{name: string, trophy: string, score: string, count: int, total: int}>
     */
    public static function competitionFavourites(Season $season): array
    {
        $rows = Tip::query()
            ->join('matchdays', 'matchdays.id', '=', 'tips.matchday_id')
            ->join('season_teams', fn ($j) => $j->on('season_teams.user_id', '=', 'tips.user_id')->on('season_teams.season_id', '=', 'matchdays.season_id'))
            ->join('competition_entries', 'competition_entries.season_team_id', '=', 'season_teams.id')
            ->where('matchdays.season_id', $season->id)
            ->groupBy('competition_entries.competition_id', 'tips.lech_goals', 'tips.opponent_goals')
            ->selectRaw('competition_entries.competition_id as competition_id, tips.lech_goals as lech, tips.opponent_goals as opponent, count(*) as tips_count')
            ->get()
            ->groupBy('competition_id');

        $order = array_map(fn ($t) => $t->value, CompetitionType::cases());
        $competitions = Competition::where('season_id', $season->id)->get()
            ->sortBy(fn ($c) => [array_search($c->type->value, $order, true), $c->tier ?? 0]);

        $out = [];
        foreach ($competitions as $competition) {
            $scores = $rows->get($competition->id);
            if (! $scores) {
                continue;
            }
            $top = $scores->sortByDesc('tips_count')->first();
            $out[] = [
                'name' => $competition->name ?: $competition->type->label(),
                'trophy' => $competition->trophyKey(),
                'score' => $top->lech.':'.$top->opponent,
                'count' => (int) $top->tips_count,
                'total' => (int) $scores->sum('tips_count'),
            ];
        }

        return $out;
    }

    /**
     * Najlepszy sezon gracza: najwięcej punktów za typy (wszystkie sezony, także trwający).
     *
     * @return array{season: Season, points: int}|null
     */
    public static function bestSeason(User $user): ?array
    {
        $teams = SeasonTeam::where('user_id', $user->id)->pluck('season_id', 'id');

        if ($teams->isEmpty()) {
            return null;
        }

        $perSeason = TeamScore::whereIn('season_team_id', $teams->keys())
            ->get(['season_team_id', 'matchday_id', 'tip_points'])
            ->unique('matchday_id')
            ->groupBy(fn ($r) => $teams[$r->season_team_id])
            ->map(fn ($rows) => (int) $rows->sum('tip_points'));

        if ($perSeason->isEmpty()) {
            return null;
        }

        $seasonId = $perSeason->sortDesc()->keys()->first();

        return ['season' => Season::find($seasonId), 'points' => $perSeason[$seasonId]];
    }

    /** Najczęstszy wynik w zbiorze typów. @return array{score: string, count: int, total: int}|null */
    private static function topScore($query): ?array
    {
        $rows = $query->groupBy('lech_goals', 'opponent_goals')
            ->selectRaw('lech_goals, opponent_goals, count(*) as tips_count')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $top = $rows->sortByDesc('tips_count')->first();

        return ['score' => $top->lech_goals.':'.$top->opponent_goals, 'count' => (int) $top->tips_count, 'total' => (int) $rows->sum('tips_count')];
    }

    /**
     * Kolor kafelka względem średniej graczy: zielony powyżej, czerwony poniżej, żółty w granicach ±15%.
     */
    public static function color(?float $value, ?float $average): string
    {
        if ($value === null || $average === null) {
            return 'zinc';
        }

        $margin = abs($average) * 0.15;

        return match (true) {
            $value > $average + $margin => 'green',
            $value < $average - $margin => 'red',
            default => 'yellow',
        };
    }

    /**
     * Procent do koloru kafelka: udział (np. oddane typy 3/4 = 75%) albo wynik względem najlepszego gracza
     * w serwisie (np. Koziołki 1 przy maksimum 3 = 33%). $inverse: mniej znaczy lepiej (np. Pechowiec).
     */
    public static function percent(int|float|null $value, int|float|null $max, bool $inverse = false): ?float
    {
        if ($value === null || $max === null) {
            return null;
        }

        if ($max <= 0) {
            return $inverse ? 100.0 : null;
        }

        $share = min(100, max(0, $value / $max * 100));

        return $inverse ? 100 - $share : $share;
    }

    /** Kolor kafelka z procentu: czerwony poniżej 35%, żółty od 35% do 65%, zielony powyżej 65%. */
    public static function percentColor(?float $percent): string
    {
        return match (true) {
            $percent === null => 'zinc',
            $percent < 35 => 'red',
            $percent <= 65 => 'yellow',
            default => 'green',
        };
    }

    /** Średni czas przed pierwszym gwizdkiem w pełnych minutach, zawsze w minutach (2 h = „120 min”). */
    public static function formatLead(?int $minutes): string
    {
        return $minutes === null ? '—' : number_format($minutes, 0, ',', ' ').' min';
    }

    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $seasonCache = [];

    /**
     * Statystyki wszystkich zespołów ludzi w sezonie: id zespołu => statystyki.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function seasonAll(Season $season): array
    {
        if (isset(self::$seasonCache[$season->id])) {
            return self::$seasonCache[$season->id];
        }

        $userOfTeam = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->pluck('user_id', 'id');
        $teamIds = $userOfTeam->keys();

        // Tylko kolejki, w których miejscem grał obecny właściciel (gracz za bota nie przejmuje dorobku bota, Ownership).
        $rows = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->whereIn('team_scores.season_team_id', $teamIds)
            ->select('team_scores.*', 'matchdays.number', 'matchdays.lech_goals as real_lech')
            ->get()
            ->filter(fn ($r) => (int) $r->user_id === (int) $userOfTeam[$r->season_team_id])
            ->groupBy('season_team_id');

        $matches = self::seasonMatches($season, $teamIds, $userOfTeam);
        $leads = self::leadTimes($season);
        $duels = self::duels($season, $teamIds, $userOfTeam);
        $out = [];

        foreach ($teamIds as $teamId) {
            $all = $rows->get($teamId, collect());
            $perMatchday = $all->unique('matchday_id')->sortBy('number')->values();
            $tipped = $perMatchday->where('has_tip', true);
            $tips = $tipped->count();
            $withTip = $all->where('has_tip', true);
            [$streak, $streakBest] = self::streak($perMatchday->map(fn ($r) => (bool) $r->outcome_hit)->all());
            $m = $matches[$teamId] ?? self::matchSummary([]);

            $out[$teamId] = [
                'scored' => $perMatchday->count(),
                'tips' => $tips,
                'tips_ratio' => $perMatchday->count() > 0 ? $tips / $perMatchday->count() : null,
                'missing' => $perMatchday->count() - $tips,
                'exact' => $tipped->where('exact_hit', true)->count(),
                'diff' => $tipped->where('diff_hit', true)->count(),
                'outcome' => $tipped->where('outcome_hit', true)->count(),
                'accuracy' => $tips > 0 ? round($tipped->where('outcome_hit', true)->count() / $tips * 100) : null,
                'tip_points' => (int) $tipped->sum('tip_points'),
                'avg_tip' => $tips > 0 ? round($tipped->avg('tip_points'), 2) : null,
                'avg_offense' => $withTip->isNotEmpty() ? round($withTip->avg('offense_bonus'), 2) : null,
                'avg_defense' => $withTip->isNotEmpty() ? round($withTip->avg('defense_bonus'), 2) : null,
                'outcome_streak' => $streak,
                'outcome_streak_best' => $streakBest,
                // Optymizm: o ile goli Lecha średnio typujemy więcej (+) albo mniej (−) niż padło naprawdę.
                'optimism' => $tips > 0 ? round($tipped->avg(fn ($r) => $r->tip_lech - $r->real_lech), 2) : null,
                'lead_minutes' => $leads[$userOfTeam[$teamId]] ?? null,
                'best_matchday' => $best = self::bestMatchday($all),
                'best_points' => $best['points'] ?? null,
            ] + ($duels[$teamId] ?? self::noDuels()) + $m;
        }

        return self::$seasonCache[$season->id] = $out;
    }

    /**
     * Średni czas zapisu typu przed pierwszym gwizdkiem w pełnych minutach: id gracza => minuty.
     *
     * @return array<int, int>
     */
    private static function leadTimes(Season $season): array
    {
        $tips = Tip::query()
            ->join('matchdays', 'matchdays.id', '=', 'tips.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->whereNotNull('matchdays.kickoff_at')
            ->get(['tips.user_id', 'tips.saved_at', 'matchdays.kickoff_at']);

        return $tips->groupBy('user_id')
            ->map(fn ($rows) => (int) round($rows->avg(fn ($t) => max(0, Carbon::parse($t->kickoff_at)->getTimestamp() - Carbon::parse($t->saved_at)->getTimestamp()) / 60)))
            ->all();
    }

    /**
     * Najlepsza kolejka sezonu: najwięcej punktów za typ razem z bonusami (najlepsze rozgrywki w tej kolejce).
     *
     * @return array{number: int, points: int}|null
     */
    private static function bestMatchday(Collection $rows): ?array
    {
        $best = null;
        foreach ($rows->where('has_tip', true)->groupBy('matchday_id') as $sets) {
            $points = (int) $sets->max(fn ($r) => $r->tip_points + $r->offense_bonus + $r->defense_bonus);
            if ($best === null || $points > $best['points']) {
                $best = ['number' => (int) $sets->first()->number, 'points' => $points];
            }
        }

        return $best;
    }

    /** Puste statystyki (gracz bez rozliczonych kolejek). */
    private static function empty(): array
    {
        return [
            'scored' => 0, 'tips' => 0, 'tips_ratio' => null, 'missing' => 0, 'exact' => 0, 'diff' => 0, 'outcome' => 0,
            'accuracy' => null, 'tip_points' => 0, 'avg_tip' => null, 'avg_offense' => null, 'avg_defense' => null,
            'outcome_streak' => 0, 'outcome_streak_best' => 0,
            'optimism' => null, 'lead_minutes' => null, 'best_matchday' => null,
            'best_points' => null,
        ] + self::noDuels() + self::matchSummary([]);
    }

    /** @return array{iron: int, mason: int, wizard: int, unlucky: int} */
    private static function noDuels(): array
    {
        return ['iron' => 0, 'mason' => 0, 'wizard' => 0, 'unlucky' => 0];
    }

    /**
     * Wyróżnienia z meczów sezonu (id zespołu => liczniki), z rozliczenia obu stron w typie rozgrywek:
     *  - Żelazna pięść: mój bonus ofensywny większy niż punkty za typ rywala + jego bonus ofensywny i defensywny,
     *  - Murarz: wygrany mecz, a mój bonus defensywny większy niż atak rywala (punkty za typ + bonus ofensywny),
     *  - Czarodziej: wygrany mecz mimo typu za 0 punktów,
     *  - Pechowiec: przegrany mecz mimo dokładnego typu (Koziołka).
     * Mecz z wirtualnym rywalem w podwórkowej liczy się tylko do Czarodzieja i Pechowca.
     *
     * @param  Collection<int, int>  $teamIds
     * @param  Collection<int, int>  $userOfTeam  miejsce => gracz (liczą się tylko kolejki rozegrane przez niego)
     * @return array<int, array{iron: int, mason: int, wizard: int, unlucky: int}>
     */
    private static function duels(Season $season, Collection $teamIds, Collection $userOfTeam): array
    {
        $competitions = Competition::where('season_id', $season->id)->get(['id', 'type'])->keyBy('id');
        $teamOfEntry = CompetitionEntry::whereIn('competition_id', $competitions->keys())->pluck('season_team_id', 'id');
        $wanted = array_flip($teamIds->all());

        // Rozliczenia: zespół => kolejka => typ rozgrywek => wiersz.
        $scores = [];
        foreach (TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->get(['team_scores.season_team_id', 'team_scores.user_id', 'team_scores.score_set', 'team_scores.has_tip', 'team_scores.tip_points',
                'team_scores.exact_hit', 'team_scores.offense_bonus', 'team_scores.defense_bonus', 'matchdays.number']) as $row) {
            $scores[$row->season_team_id][(int) $row->number][$row->score_set->value] = $row;
        }

        $out = [];
        $fixtures = Fixture::whereIn('competition_id', $competitions->keys())->whereNotNull('home_goals')->whereNotNull('home_entry_id')->get();

        foreach ($fixtures as $fixture) {
            $set = $competitions[$fixture->competition_id]->type->scoreSet()->value;

            foreach ([[$fixture->home_entry_id, $fixture->away_entry_id, true], [$fixture->away_entry_id, $fixture->home_entry_id, false]] as [$entryId, $rivalEntryId, $home]) {
                $team = $entryId ? ($teamOfEntry[$entryId] ?? null) : null;

                if (! $team || ! isset($wanted[$team]) || ! ($me = $scores[$team][$fixture->round][$set] ?? null)
                    || (int) $me->user_id !== (int) ($userOfTeam[$team] ?? 0)) {
                    continue;
                }

                $rival = $rivalEntryId ? ($scores[$teamOfEntry[$rivalEntryId] ?? 0][$fixture->round][$set] ?? null) : null;
                [$outcome] = self::result($fixture, (int) $entryId, $home);
                $out[$team] ??= self::noDuels();

                if ($rival && $me->offense_bonus > $rival->tip_points + $rival->offense_bonus + $rival->defense_bonus) {
                    $out[$team]['iron']++;
                }
                if ($rival && $outcome === 'W' && $me->defense_bonus > $rival->tip_points + $rival->offense_bonus) {
                    $out[$team]['mason']++;
                }
                if ($outcome === 'W' && $me->has_tip && (int) $me->tip_points === 0) {
                    $out[$team]['wizard']++;
                }
                if ($outcome === 'L' && $me->exact_hit) {
                    $out[$team]['unlucky']++;
                }
            }
        }

        return $out;
    }

    /**
     * Mecze sezonu pogrupowane na zespoły: id zespołu => podsumowanie meczów.
     *
     * $userOfTeam (miejsce => gracz): liczą się tylko mecze z kolejek, w których miejscem grał ten gracz (Ownership).
     * Bez niego: wszystkie mecze miejsca (np. okno zespołu bota).
     *
     * @param  Collection<int, int>  $teamIds
     * @param  Collection<int, int>|null  $userOfTeam
     * @return array<int, array<string, mixed>>
     */
    private static function seasonMatches(Season $season, Collection $teamIds, ?Collection $userOfTeam = null): array
    {
        $teamOfEntry = CompetitionEntry::whereIn('season_team_id', $teamIds)
            ->whereIn('competition_id', Competition::where('season_id', $season->id)->select('id'))
            ->pluck('season_team_id', 'id');

        $fixtures = Fixture::whereIn('competition_id', Competition::where('season_id', $season->id)->select('id'))
            ->whereNotNull('home_goals')
            ->orderBy('round')->orderBy('id')
            ->get();

        $perTeam = [];
        foreach ($fixtures as $fixture) {
            foreach ([[$fixture->home_entry_id, true], [$fixture->away_entry_id, false]] as [$entryId, $home]) {
                if ($entryId && isset($teamOfEntry[$entryId])) {
                    $team = (int) $teamOfEntry[$entryId];
                    if ($userOfTeam !== null && ! Ownership::playedBy($season->id, $team, (int) $fixture->round, (int) ($userOfTeam[$team] ?? 0))) {
                        continue;
                    }
                    $perTeam[$team][] = self::result($fixture, $entryId, $home);
                }
            }
        }

        return array_map(fn ($results) => self::matchSummary($results), $perTeam);
    }

    /** Wynik meczu z perspektywy wpisu: [W|D|L, gole zdobyte, stracone]. */
    private static function result(Fixture $fixture, int $entryId, bool $home): array
    {
        $mine = (int) ($home ? $fixture->home_goals : $fixture->away_goals);
        $theirs = (int) ($home ? $fixture->away_goals : $fixture->home_goals);

        // Remis rozstrzygnięty czasem typu (puchar) liczymy jako wygraną albo porażkę.
        if ($mine === $theirs && $fixture->winner_entry_id) {
            $outcome = (int) $fixture->winner_entry_id === $entryId ? 'W' : 'L';
        } else {
            $outcome = $mine > $theirs ? 'W' : ($mine === $theirs ? 'D' : 'L');
        }

        return [$outcome, $mine, $theirs];
    }

    /** @param array<int, array{0: string, 1: int, 2: int}> $results */
    private static function matchSummary(array $results): array
    {
        $outcomes = array_column($results, 0);
        [$unbeaten, $unbeatenBest] = self::streak(array_map(fn ($r) => $r !== 'L', $outcomes));
        [$wins, $winsBest] = self::streak(array_map(fn ($r) => $r === 'W', $outcomes));
        $played = count($results);
        $won = count(array_filter($outcomes, fn ($r) => $r === 'W'));

        return [
            'played' => $played,
            'won' => $won,
            'drawn' => count(array_filter($outcomes, fn ($r) => $r === 'D')),
            'lost' => count(array_filter($outcomes, fn ($r) => $r === 'L')),
            'win_ratio' => $played > 0 ? $won / $played : null,
            'for' => array_sum(array_column($results, 1)),
            'against' => array_sum(array_column($results, 2)),
            'record_goals' => $results === [] ? 0 : max(array_column($results, 1)),
            'unbeaten' => $unbeaten, 'unbeaten_best' => $unbeatenBest,
            'wins' => $wins, 'wins_best' => $winsBest,
            'form' => array_slice($outcomes, -5),
        ];
    }

    /**
     * Historia startów (tabele końcowe zakończonych sezonów) i rekordy osobiste ze wszystkich meczów.
     *
     * @return array{competitions: array<int, array<string, mixed>>, records: array<string, mixed>}
     */
    public static function history(User $user): array
    {
        $standings = FinalStanding::with('competition', 'season:id,number')->where('user_id', $user->id)->get();

        $groups = $standings->groupBy(fn ($s) => $s->competition->type->value.'-'.($s->competition->tier ?? 0));
        $competitions = [];

        foreach ($groups as $rows) {
            $competition = $rows->first()->competition;
            $label = $competition->type === CompetitionType::League
                ? (League::tryFrom((int) $competition->tier)?->label() ?? $competition->name)
                : $competition->type->label();

            $competitions[] = [
                'label' => $label,
                'key' => $competition->trophyKey(),
                'type' => $competition->type,
                'tier' => $competition->tier ?? 0,
                'seasons' => $rows->count(),
                'best' => (int) $rows->min('place'),
                'titles' => $rows->where('place', 1)->count(),
                'points' => (int) $rows->sum(fn ($s) => $s->stats['points'] ?? 0),
                'won' => (int) $rows->sum(fn ($s) => $s->stats['won'] ?? 0),
                'goals' => (int) $rows->sum(fn ($s) => $s->stats['for'] ?? 0),
            ];
        }

        $order = array_map(fn ($t) => $t->value, CompetitionType::cases());
        usort($competitions, fn ($a, $b) => [array_search($a['type']->value, $order, true), $a['tier']] <=> [array_search($b['type']->value, $order, true), $b['tier']]);

        return ['competitions' => $competitions, 'records' => self::matches($user)];
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private static array $teams = [];

    private static function team(User $user, Season $season): ?SeasonTeam
    {
        return self::$teams[$user->id.'-'.$season->id] ??= SeasonTeam::where('season_id', $season->id)->where('user_id', $user->id)->first();
    }

    /** Jeden wiersz rozliczenia na kolejkę (dane typu są takie same we wszystkich zestawach), z numerem kolejki. */
    private static function tipRows(User $user, Season $season): Collection
    {
        $team = self::team($user, $season);

        if (! $team) {
            return collect();
        }

        // Tylko kolejki rozegrane przez gracza (nie przez bota, za którego wskoczył).
        return TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('team_scores.season_team_id', $team->id)
            ->where('team_scores.user_id', $user->id)
            ->select('team_scores.*', 'matchdays.number')
            ->get()
            ->unique('matchday_id')
            ->values();
    }

    /**
     * Mecze gracza ze wszystkich sezonów (rekordy osobiste), tylko z kolejek, w których naprawdę grał (Ownership).
     *
     * @return array<string, mixed>
     */
    private static function matches(User $user): array
    {
        $teamOfEntry = CompetitionEntry::whereIn('season_team_id', self::userTeamIds($user))->pluck('season_team_id', 'id');
        $entries = $teamOfEntry->keys();
        $fixtures = Fixture::query()
            ->join('competitions', 'competitions.id', '=', 'fixtures.competition_id')
            ->join('seasons', 'seasons.id', '=', 'competitions.season_id')
            ->where(fn ($q) => $q->whereIn('home_entry_id', $entries)->orWhereIn('away_entry_id', $entries))
            ->whereNotNull('home_goals')
            ->orderBy('seasons.number')->orderBy('fixtures.round')->orderBy('fixtures.id')
            ->select('fixtures.*', 'competitions.season_id as fixture_season_id')
            ->get();

        $results = [];
        foreach ($fixtures as $fixture) {
            $home = $entries->contains($fixture->home_entry_id);
            $entryId = (int) ($home ? $fixture->home_entry_id : $fixture->away_entry_id);

            if (! Ownership::playedBy((int) $fixture->getAttribute('fixture_season_id'), (int) $teamOfEntry[$entryId], (int) $fixture->round, $user->id)) {
                continue;
            }

            $results[] = self::result($fixture, $entryId, $home);
        }

        return self::matchSummary($results);
    }

    /**
     * Miejsca z list sezonów, którymi gracz kiedykolwiek grał: obecne i te, które po odejściu przejął bot
     * (z rozliczeń kolejek, team_scores.user_id).
     *
     * @return Collection<int, int>
     */
    private static function userTeamIds(User $user): Collection
    {
        return self::ownerTeamIds($user->id, null);
    }

    /**
     * Miejsca, którymi grał gracz albo bot (obecne i z rozliczeń kolejek).
     *
     * @return Collection<int, int>
     */
    public static function ownerTeamIds(?int $userId, ?int $botId): Collection
    {
        $column = $userId ? 'user_id' : 'bot_id';
        $id = $userId ?: $botId;

        if (! $id) {
            return collect();
        }

        return SeasonTeam::where($column, $id)->pluck('id')
            ->merge(TeamScore::where($column, $id)->distinct()->pluck('season_team_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** Obecna i najdłuższa seria wartości true. @param array<int, bool> $values @return array{0: int, 1: int} */
    private static function streak(array $values): array
    {
        $current = $best = 0;
        foreach ($values as $value) {
            $current = $value ? $current + 1 : 0;
            $best = max($best, $current);
        }

        return [$current, $best];
    }
}
