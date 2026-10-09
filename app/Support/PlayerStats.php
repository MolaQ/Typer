<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\MatchdayStatus;
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
use App\Models\TipAnswer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statystyki gracza do zakładek strony „Moje typy”: kolejki (typy i braki), postęp w rozgrywkach,
 * statystyki sezonu (typy, pytania, mecze, serie, miejsce wśród graczy) i historia z rekordami osobistymi.
 * Liczymy z gotowych danych: team_scores (rozliczone kolejki), fixtures (mecze) i final_standings (tabele końcowe).
 */
final class PlayerStats
{
    /**
     * Kolejki sezonu z typem gracza i punktami (rozliczone) albo informacją o braku typu.
     *
     * @return array<int, array{matchday: Matchday, tip: ?string, default: bool, points: ?int, exact: bool, missing: bool, open: bool}>
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
                'default' => (bool) $tip?->is_default,
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
            } elseif ($competition->type === CompetitionType::Legends) {
                $row['legends'] = $competition instanceof Competition ? self::legendsPath($competition, $entry, $tipPoints) : [];
                $last = end($row['legends']);
                $row['points'] = $last ? $last['total'] : null;
                $row['status'] = $entry->eliminated_round
                    ? __('Knocked out after matchday :number', ['number' => $entry->eliminated_round])
                    : ($last ? __(':place. place of :count', ['place' => $last['place'], 'count' => $last['count']]) : __('Still in the game'));
            } else {
                $table = Standings::for($competition)->values();
                $index = $table->search(fn ($r) => $r['entry_id'] === $entry->id);
                $own = $index === false ? null : $table[$index];

                $row['status'] = $index === false ? '—' : __(':place. place of :count', ['place' => $index + 1, 'count' => $table->count()]);
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
     * Liga Legend na zakładce Rozgrywki: po każdej rozliczonej kolejce miejsce zespołu (wśród tych, którzy jeszcze grali),
     * punkty Legend z tej kolejki i suma z tabeli Legend, limit awansu i czy zespół przeszedł dalej.
     *
     * @param  array<int, ?int>  $tipPoints  numer kolejki => punkty za typ
     * @return array<int, array{round: int, place: ?int, count: int, limit: int, points: ?int, total: int, through: bool, eliminated: bool, tip_points: ?int}>
     */
    private static function legendsPath(Competition $competition, CompetitionEntry $entry, array $tipPoints): array
    {
        $played = (int) Matchday::where('season_id', $competition->season_id)->where('status', MatchdayStatus::Played)->max('number');
        $out = [];

        for ($stage = 1; $stage <= $played; $stage++) {
            if ($entry->eliminated_round !== null && $entry->eliminated_round < $stage) {
                break; // dalej już nie grał
            }

            $ranking = LegendsRanking::for($competition, $stage, onlyAlive: true)->values();
            $index = $ranking->search(fn ($r) => $r['entry_id'] === $entry->id);
            $own = $index === false ? null : $ranking[$index];
            $limit = $stage < LegendsRanking::ROUNDS ? LegendsRanking::limitAfter($stage) : 1;

            $out[] = [
                'round' => $stage,
                'place' => $index === false ? null : $index + 1,
                'count' => $ranking->count(),
                'limit' => $limit,
                'points' => $own['last_points'] ?? null,
                'total' => (int) ($own['points'] ?? 0),
                'through' => $index !== false && $index < $limit,
                'eliminated' => $entry->eliminated_round === $stage,
                'tip_points' => $tipPoints[$stage] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Mecze zespołu w jednych rozgrywkach (kafelki na zakładce Rozgrywki): runda, rywal, wynik, rozstrzygnięcie
     * i punkty za typ w tej kolejce (null: bez typu albo przed wynikami).
     *
     * @param  array<int, ?int>  $tipPoints  numer kolejki => punkty za typ
     * @return array<int, array{round: int, rival: string, score: ?string, outcome: ?string, tip_points: ?int, played: bool}>
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
     * Statystyki sezonu gracza, a obok średnie wszystkich graczy (do kolorów kafelków).
     *
     * @return array{mine: array<string, mixed>, average: array<string, float>, rank: ?array{place: int, count: int}}
     */
    public static function season(User $user, Season $season): array
    {
        $all = self::seasonAll($season);
        $team = self::team($user, $season);
        $mine = $team ? ($all[$team->id] ?? self::empty()) : self::empty();

        $average = [];
        if ($all !== []) {
            foreach (array_keys(self::empty()) as $key) {
                if ($key !== 'best_matchday' && (is_numeric($mine[$key] ?? null) || ($mine[$key] ?? null) === null)) {
                    $values = array_filter(array_column($all, $key), fn ($v) => $v !== null && is_numeric($v));
                    $average[$key] = $values === [] ? 0.0 : array_sum($values) / count($values);
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

        return ['mine' => $mine, 'average' => $average, 'rank' => $rank];
    }

    /**
     * Statystyki sezonu dowolnego zespołu (do okna z drużyną na stronie wyników). Bot nie odpowiada
     * na pytania i nie ma typów do statystyk, więc ma tylko mecze.
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
     * Moje skalpy (premium): rywale (gracze i boty), z którymi gracz ma lepszy bilans bezpośredni (więcej wygranych
     * niż porażek) ze wszystkich sezonów i rozgrywek, od najwyżej w rankingu Hall of Fame (potem bilans, potem nazwa).
     *
     * @return array<int, array{name: string, owner: ?string, user: ?User, bot: bool, won: int, drawn: int, lost: int, hof: float}>
     */
    public static function scalps(User $user): array
    {
        $mine = CompetitionEntry::whereIn('season_team_id', SeasonTeam::where('user_id', $user->id)->select('id'))->pluck('id');

        if ($mine->isEmpty()) {
            return [];
        }

        $fixtures = Fixture::whereNotNull('home_goals')
            ->where(fn ($q) => $q->whereIn('home_entry_id', $mine)->orWhereIn('away_entry_id', $mine))
            ->whereNotNull('home_entry_id')
            ->whereNotNull('away_entry_id')
            ->get();

        // Właściciel każdego wpisu rywala: 'u12' (gracz) albo 'b34' (bot).
        $rivalEntries = $fixtures->map(fn ($f) => $mine->contains($f->home_entry_id) ? $f->away_entry_id : $f->home_entry_id)->unique()->values();
        $ownerOfEntry = [];
        foreach (CompetitionEntry::query()
            ->join('season_teams', 'season_teams.id', '=', 'competition_entries.season_team_id')
            ->whereIn('competition_entries.id', $rivalEntries)
            ->get(['competition_entries.id as entry_id', 'season_teams.user_id', 'season_teams.bot_id']) as $row) {
            $ownerOfEntry[(int) $row->entry_id] = $row->user_id ? 'u'.$row->user_id : ($row->bot_id ? 'b'.$row->bot_id : null);
        }

        $records = [];
        foreach ($fixtures as $fixture) {
            $home = $mine->contains($fixture->home_entry_id);
            $rivalEntry = (int) ($home ? $fixture->away_entry_id : $fixture->home_entry_id);
            $owner = $ownerOfEntry[$rivalEntry] ?? null;

            if (! $owner || $owner === 'u'.$user->id) {
                continue;
            }

            [$outcome] = self::result($fixture, (int) ($home ? $fixture->home_entry_id : $fixture->away_entry_id), $home);
            $records[$owner] ??= ['won' => 0, 'drawn' => 0, 'lost' => 0];
            $records[$owner][['W' => 'won', 'D' => 'drawn', 'L' => 'lost'][$outcome]]++;
        }

        $records = array_filter($records, fn ($r) => $r['won'] > $r['lost']);

        if ($records === []) {
            return [];
        }

        $userIds = array_map(fn ($k) => (int) substr($k, 1), array_filter(array_keys($records), fn ($k) => $k[0] === 'u'));
        $botIds = array_map(fn ($k) => (int) substr($k, 1), array_filter(array_keys($records), fn ($k) => $k[0] === 'b'));

        $hofUsers = HallOfFameAward::whereIn('user_id', $userIds)->groupBy('user_id')->selectRaw('user_id, sum(points) as total')->pluck('total', 'user_id');
        $hofBots = HallOfFameAward::whereIn('bot_id', $botIds)->groupBy('bot_id')->selectRaw('bot_id, sum(points) as total')->pluck('total', 'bot_id');
        $users = User::whereIn('id', $userIds)->get(['id', 'name', 'team_name'])->keyBy('id');
        $bots = Bot::whereIn('id', $botIds)->pluck('name', 'id');

        $out = [];
        foreach ($records as $owner => $record) {
            $id = (int) substr($owner, 1);

            if ($owner[0] === 'u' && ($rival = $users->get($id))) {
                $out[] = ['name' => $rival->team_name ?: $rival->name, 'owner' => $rival->name, 'user' => $rival, 'bot' => false, 'hof' => round((float) ($hofUsers[$id] ?? 0), 1)] + $record;
            } elseif ($owner[0] === 'b' && isset($bots[$id])) {
                $out[] = ['name' => $bots[$id], 'owner' => null, 'user' => null, 'bot' => true, 'hof' => round((float) ($hofBots[$id] ?? 0), 1)] + $record;
            }
        }

        usort($out, fn ($a, $b) => [$b['hof'], $b['won'] - $b['lost'], $a['name']] <=> [$a['hof'], $a['won'] - $a['lost'], $b['name']]);

        return $out;
    }

    /** Liczba rywali-ludzi gracza (do procentu skalpów): wszyscy inni gracze z zespołem w jakimkolwiek sezonie. */
    public static function humanRivals(User $user): int
    {
        return (int) SeasonTeam::whereNotNull('user_id')->where('user_id', '!=', $user->id)->distinct()->count('user_id');
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

        $rows = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->whereIn('team_scores.season_team_id', $teamIds)
            ->select('team_scores.*', 'matchdays.number', 'matchdays.lech_goals as real_lech')
            ->get()
            ->groupBy('season_team_id');

        $matches = self::seasonMatches($season, $teamIds);
        $questions = self::questionAccuracy($season);
        $leads = self::leadTimes($season);
        $duels = self::duels($season, $teamIds);
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
                'zeroed' => $withTip->where('offense_zeroed', true)->count() + $withTip->where('defense_zeroed', true)->count(),
                'outcome_streak' => $streak,
                'outcome_streak_best' => $streakBest,
                'q_offense' => $questions[$userOfTeam[$teamId]]['offensive'] ?? null,
                'q_defense' => $questions[$userOfTeam[$teamId]]['defensive'] ?? null,
                // Optymizm: o ile goli Lecha średnio typujemy więcej (+) albo mniej (−) niż padło naprawdę.
                'optimism' => $tips > 0 ? round($tipped->avg(fn ($r) => $r->tip_lech - $r->real_lech), 2) : null,
                'lead_hours' => $leads[$userOfTeam[$teamId]] ?? null,
                'best_matchday' => $best = self::bestMatchday($all),
                'best_points' => $best['points'] ?? null,
            ] + ($duels[$teamId] ?? self::noDuels()) + $m;
        }

        return self::$seasonCache[$season->id] = $out;
    }

    /**
     * Trafność odpowiedzi na pytania w sezonie (rozliczone kolejki): id gracza => strona => procent poprawnych.
     *
     * @return array<int, array<string, int>>
     */
    private static function questionAccuracy(Season $season): array
    {
        $rows = TipAnswer::query()
            ->join('matchday_questions', 'matchday_questions.id', '=', 'tip_answers.matchday_question_id')
            ->join('matchdays', 'matchdays.id', '=', 'matchday_questions.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->where('matchdays.status', MatchdayStatus::Played->value)
            ->whereNotNull('matchday_questions.correct_answer')
            ->groupBy('tip_answers.user_id', 'matchday_questions.side')
            ->selectRaw('tip_answers.user_id as user_id, matchday_questions.side as side, count(*) as answers, sum(case when tip_answers.answer = matchday_questions.correct_answer then 1 else 0 end) as correct')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->user_id][$row->side] = (int) round($row->correct / max(1, $row->answers) * 100);
        }

        return $out;
    }

    /**
     * Średni czas zapisu typu przed pierwszym gwizdkiem w godzinach: id gracza => godziny (bez typów domyślnych).
     *
     * @return array<int, float>
     */
    private static function leadTimes(Season $season): array
    {
        $tips = Tip::query()
            ->join('matchdays', 'matchdays.id', '=', 'tips.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->where('tips.is_default', false)
            ->whereNotNull('matchdays.kickoff_at')
            ->get(['tips.user_id', 'tips.saved_at', 'matchdays.kickoff_at']);

        return $tips->groupBy('user_id')
            ->map(fn ($rows) => round($rows->avg(fn ($t) => max(0, Carbon::parse($t->kickoff_at)->getTimestamp() - Carbon::parse($t->saved_at)->getTimestamp()) / 3600), 1))
            ->all();
    }

    /**
     * Najlepsza kolejka sezonu: najwięcej punktów za typ razem z bonusami (najlepszy zestaw pytań w tej kolejce).
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
            'zeroed' => 0, 'outcome_streak' => 0, 'outcome_streak_best' => 0,
            'q_offense' => null, 'q_defense' => null, 'optimism' => null, 'lead_hours' => null, 'best_matchday' => null,
            'best_points' => null,
        ] + self::noDuels() + self::matchSummary([]);
    }

    /** @return array{iron: int, mason: int, wizard: int, unlucky: int} */
    private static function noDuels(): array
    {
        return ['iron' => 0, 'mason' => 0, 'wizard' => 0, 'unlucky' => 0];
    }

    /**
     * Wyróżnienia z meczów sezonu (id zespołu => liczniki), z rozliczenia obu stron w zestawie pytań rozgrywek:
     *  - Żelazna pięść: mój bonus ofensywny większy niż punkty za typ rywala + jego bonus ofensywny i defensywny,
     *  - Murarz: wygrany mecz, a mój bonus defensywny większy niż atak rywala (punkty za typ + bonus ofensywny),
     *  - Czarodziej: wygrany mecz mimo typu za 0 punktów,
     *  - Pechowiec: przegrany mecz mimo dokładnego typu (Koziołka).
     * Mecz z wirtualnym rywalem w podwórkowej liczy się tylko do Czarodzieja i Pechowca.
     *
     * @param  Collection<int, int>  $teamIds
     * @return array<int, array{iron: int, mason: int, wizard: int, unlucky: int}>
     */
    private static function duels(Season $season, Collection $teamIds): array
    {
        $competitions = Competition::where('season_id', $season->id)->get(['id', 'type'])->keyBy('id');
        $teamOfEntry = CompetitionEntry::whereIn('competition_id', $competitions->keys())->pluck('season_team_id', 'id');
        $wanted = array_flip($teamIds->all());

        // Rozliczenia: zespół => kolejka => zestaw pytań => wiersz.
        $scores = [];
        foreach (TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('matchdays.season_id', $season->id)
            ->get(['team_scores.season_team_id', 'team_scores.question_set', 'team_scores.has_tip', 'team_scores.tip_points',
                'team_scores.exact_hit', 'team_scores.offense_bonus', 'team_scores.defense_bonus', 'matchdays.number']) as $row) {
            $scores[$row->season_team_id][(int) $row->number][$row->question_set->value] = $row;
        }

        $out = [];
        $fixtures = Fixture::whereIn('competition_id', $competitions->keys())->whereNotNull('home_goals')->whereNotNull('home_entry_id')->get();

        foreach ($fixtures as $fixture) {
            $set = $competitions[$fixture->competition_id]->type->questionSet()->value;

            foreach ([[$fixture->home_entry_id, $fixture->away_entry_id, true], [$fixture->away_entry_id, $fixture->home_entry_id, false]] as [$entryId, $rivalEntryId, $home]) {
                $team = $entryId ? ($teamOfEntry[$entryId] ?? null) : null;

                if (! $team || ! isset($wanted[$team]) || ! ($me = $scores[$team][$fixture->round][$set] ?? null)) {
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
     * @param  Collection<int, int>  $teamIds
     * @return array<int, array<string, mixed>>
     */
    private static function seasonMatches(Season $season, Collection $teamIds): array
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
                    $perTeam[$teamOfEntry[$entryId]][] = self::result($fixture, $entryId, $home);
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

        $teamIds = SeasonTeam::where('user_id', $user->id)->pluck('id');

        return ['competitions' => $competitions, 'records' => self::matches($teamIds)];
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

        return TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('team_scores.season_team_id', $team->id)
            ->select('team_scores.*', 'matchdays.number')
            ->get()
            ->unique('matchday_id')
            ->values();
    }

    /**
     * Mecze zespołów ze wszystkich sezonów (rekordy osobiste).
     *
     * @param  Collection<int, int>  $teamIds
     * @return array<string, mixed>
     */
    private static function matches(Collection $teamIds): array
    {
        $entries = CompetitionEntry::whereIn('season_team_id', $teamIds)->pluck('id');
        $fixtures = Fixture::query()
            ->join('competitions', 'competitions.id', '=', 'fixtures.competition_id')
            ->join('seasons', 'seasons.id', '=', 'competitions.season_id')
            ->where(fn ($q) => $q->whereIn('home_entry_id', $entries)->orWhereIn('away_entry_id', $entries))
            ->whereNotNull('home_goals')
            ->orderBy('seasons.number')->orderBy('fixtures.round')->orderBy('fixtures.id')
            ->select('fixtures.*')
            ->get();

        $results = [];
        foreach ($fixtures as $fixture) {
            $home = $entries->contains($fixture->home_entry_id);
            $results[] = self::result($fixture, $home ? $fixture->home_entry_id : $fixture->away_entry_id, $home);
        }

        return self::matchSummary($results);
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
