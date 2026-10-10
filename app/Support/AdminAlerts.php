<?php

namespace App\Support;

use App\Actions\Competitions\BuildCompetitions;
use App\Actions\Competitions\DrawSwissRound;
use App\Actions\Questions\DrawQuestions;
use App\Actions\Seasons\BuildListFromPrevious;
use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\QuestionProposal;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamNameChangeRequest;
use App\Models\User;

/**
 * Powiadomienia admina: wszystko, czego brakuje do prawidłowego działania rozgrywek w tej chwili
 * (pytania kolejki, wynik meczu, przeliczenie, pary Ligi podwórkowej, koniec sezonu), plus sprawy graczy.
 * Liczone na bieżąco z bazy, więc znikają same, gdy admin coś uzupełni.
 * Część ma akcję wykonywaną od razu z panelu (np. losowanie pytań).
 */
final class AdminAlerts
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * @return array<int, array{level: string, icon: string, title: string, detail: string, route: ?string, params: array<string, mixed>, action: ?array{method: string, arg: int, label: string}}>
     */
    public static function all(): array
    {
        $alerts = [];
        $season = Season::current();

        if ($season) {
            $alerts = array_merge($alerts, self::matchdays($season), self::swiss($season), self::european($season), self::golden($season));
        } else {
            $alerts = array_merge($alerts, self::lifecycle());
        }

        $withoutRole = Players::withoutRole()->whereNotNull('email_verified_at')->count();
        if ($withoutRole > 0) {
            $alerts[] = self::alert(self::INFO, 'user-plus', trans_choice(':count new user waits for a role|:count new users wait for a role', $withoutRole, ['count' => $withoutRole]), __('Give them the User role so they can play.'), 'dashboard.users', ['roleFilter' => '__none']);
        }

        $requests = TeamNameChangeRequest::pending()->count();
        if ($requests > 0) {
            $alerts[] = self::alert(self::INFO, 'identification', trans_choice(':count team name request|:count team name requests', $requests, ['count' => $requests]), __('Approve or reject the new names.'), 'dashboard.team-requests');
        }

        $proposals = QuestionProposal::pending()->count();
        if ($proposals > 0) {
            $alerts[] = self::alert(self::INFO, 'light-bulb', trans_choice(':count question proposal waits for review|:count question proposals wait for review', $proposals, ['count' => $proposals]), __('Accept them into the question bank or reject them.'), 'dashboard.question-proposals');
        }

        $unverified = User::whereNull('email_verified_at')->where('created_at', '<', now()->subDay())->count();
        if ($unverified > 0) {
            $alerts[] = self::alert(self::INFO, 'envelope', trans_choice(':count user has not confirmed the email for a day|:count users have not confirmed the email for a day', $unverified, ['count' => $unverified]), __('You can see their codes on the Users page.'), 'dashboard.users', ['roleFilter' => '__unverified']);
        }

        // Najpierw pilne, potem ostrzeżenia i informacje.
        $order = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];
        usort($alerts, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $alerts;
    }

    /** Liczba powiadomień pilnych i ostrzeżeń (do plakietki w menu). */
    public static function count(): int
    {
        return count(array_filter(self::all(), fn ($a) => $a['level'] !== self::INFO));
    }

    /** Kolejki aktywnego sezonu: mecz, pytania, wynik, przeliczenie, koniec sezonu. */
    private static function matchdays(Season $season): array
    {
        $alerts = [];
        $matchdays = Matchday::where('season_id', $season->id)->orderBy('number')->get();
        $types = DrawQuestions::typesOfSeason($season->id);
        $needed = count($types) * MatchdayQuestion::PER_SIDE * 2;
        $slots = MatchdayQuestion::whereIn('matchday_id', $matchdays->pluck('id'))->selectRaw('matchday_id, count(*) as total')->groupBy('matchday_id')->pluck('total', 'matchday_id');

        foreach ($matchdays as $matchday) {
            $label = __('Matchday :number', ['number' => $matchday->number]);
            $played = $matchday->status === MatchdayStatus::Played;
            $started = $matchday->kickoff_at && now()->gte($matchday->kickoff_at);

            // Najbliższa kolejka bez meczu (tylko pierwsza, dalsze mogą poczekać).
            if (! $matchday->isFilled() && ! $played) {
                if ($matchdays->first(fn ($m) => ! $m->isFilled() && $m->status !== MatchdayStatus::Played)?->is($matchday)) {
                    $alerts[] = self::alert(self::WARNING, 'calendar', __(':matchday: add the Lech match', ['matchday' => $label]), __('Opponent and kick-off time are missing.'), 'dashboard.matchdays', ['season' => $season->id]);
                }

                continue;
            }

            // Mecz dodany, a zestawy pytań niepełne: trzeba je dodać przed pierwszym gwizdkiem.
            $have = (int) ($slots[$matchday->id] ?? 0);
            if ($matchday->questionsEditable() && $have < $needed) {
                $alerts[] = self::alert(
                    self::DANGER,
                    'question-mark-circle',
                    __(':matchday (:fixture): add the bonus questions', ['matchday' => $label, 'fixture' => $matchday->fixture]),
                    __('Questions: :have of :needed. Drawing gives Liga Legend the questions with the fewest correct answers.', ['have' => $have, 'needed' => $needed]),
                    'dashboard.matchday-questions',
                    ['season' => $season->id, 'matchday' => $matchday->number],
                    ['method' => 'drawQuestions', 'arg' => $matchday->id, 'label' => __('Draw questions')],
                );
            }

            if ($started && ! $played && $matchday->status !== MatchdayStatus::Postponed) {
                $hasScore = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;
                $alerts[] = self::alert(
                    self::DANGER,
                    'flag',
                    $hasScore
                        ? __(':matchday: recalculate the results', ['matchday' => $label])
                        : __(':matchday: enter the result and the correct answers', ['matchday' => $label]),
                    $hasScore ? __('The score is saved, but the points are not counted yet.') : __('The match has started, the players are waiting for points.'),
                    'dashboard.results',
                    ['season' => $season->id, 'matchday' => $matchday->number],
                );
            }
        }

        if ($matchdays->count() >= Matchday::PER_SEASON && $matchdays->every(fn ($m) => $m->status === MatchdayStatus::Played)) {
            $alerts[] = self::alert(self::WARNING, 'flag', __('All matchdays are played: finish the season'), __('Finishing saves the final tables and Hall of Fame points.'), 'dashboard.seasons');
        }

        return $alerts;
    }

    /**
     * Kolejne kroki, gdy żaden sezon nie trwa: utworzenie sezonu, lista z poprzedniego sezonu,
     * mecze kolejek, zatwierdzenie i aktywacja (pierwszy niezakończony sezon po kolei).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lifecycle(): array
    {
        $upcoming = Season::whereIn('status', [SeasonStatus::Draft->value, SeasonStatus::Approved->value])->orderBy('number')->first();

        if (! $upcoming) {
            $last = Season::where('status', SeasonStatus::Finished->value)->max('number');

            return [self::alert(
                self::WARNING,
                'calendar-days',
                $last ? __('Season :number is finished: create the next season', ['number' => $last]) : __('No season yet: create the first season'),
                __('The new season starts as a draft. Then build its team list.'),
                'dashboard.seasons',
            )];
        }

        $params = ['season' => $upcoming->id];
        $alerts = [];

        if ($upcoming->status === SeasonStatus::Draft) {
            $hasList = SeasonTeam::where('season_id', $upcoming->id)->exists();
            $hasPrevious = BuildListFromPrevious::previousSeason($upcoming) !== null;

            if (! $hasList) {
                $alerts[] = self::alert(
                    self::WARNING,
                    'list-bullet',
                    __(':season: build the team list', ['season' => $upcoming->title]),
                    $hasPrevious
                        ? __('Use "Build from the previous season": promotion, relegation and European places come from the final standings.')
                        : __('Build the list automatically from the registered players.'),
                    'dashboard.season-teams',
                    $params,
                );
            }

            $missing = Matchday::where('season_id', $upcoming->id)->get()->reject(fn (Matchday $m) => $m->isFilled())->count();
            if ($missing > 0) {
                $alerts[] = self::alert(
                    self::WARNING,
                    'calendar',
                    __(':season: add the Lech matches', ['season' => $upcoming->title]),
                    trans_choice(':count matchday has no opponent or kick-off time.|:count matchdays have no opponent or kick-off time.', $missing, ['count' => $missing]),
                    'dashboard.matchdays',
                    $params,
                );
            }

            if ($hasList && $missing === 0) {
                $alerts[] = self::alert(
                    self::WARNING,
                    'check-badge',
                    __(':season: approve the season', ['season' => $upcoming->title]),
                    __('Approving creates the competitions with their participants and fixtures.'),
                    'dashboard.seasons',
                );
            }
        } else {
            $alerts[] = self::alert(
                self::WARNING,
                'play',
                __(':season: activate the season', ['season' => $upcoming->title]),
                __('Draw the questions of the first matchday, then activate the season so players can tip.'),
                'dashboard.seasons',
            );
        }

        if ($upcoming->status === SeasonStatus::Approved) {
            $alerts = array_merge($alerts, self::golden($upcoming));
        }

        $alerts[] = self::alert(self::INFO, 'clipboard-document-check', __('Season checklist'), __('Check what is still missing before the start.'), 'dashboard.checklist', $params);

        return $alerts;
    }

    /**
     * Ligi europejskie: gdy jest zakończony poprzedni sezon, miejsca 1-3 lig trafiają do LM, LE i LK.
     * Brak tych rozgrywek w trwającym sezonie to błąd (np. lista zbudowana przed końcem poprzedniego sezonu).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function european(Season $season): array
    {
        $builder = app(BuildCompetitions::class);

        if (! $builder->previousSeason($season)) {
            return [];
        }

        $missing = collect([CompetitionType::Champions, CompetitionType::Europa, CompetitionType::Conference])
            ->reject(fn (CompetitionType $type) => Competition::where('season_id', $season->id)->where('type', $type->value)->exists())
            ->filter(fn (CompetitionType $type) => $builder->europeanSeats($season, $type) !== [])
            ->map(fn (CompetitionType $type) => $type->label());

        if ($missing->isEmpty()) {
            return [];
        }

        return [self::alert(
            self::WARNING,
            'globe-europe-africa',
            __('Missing competitions: :list', ['list' => $missing->implode(', ')]),
            __('The places 1-3 of the leagues from the previous season are known. Create them from history.'),
            'dashboard.competitions',
            ['season' => $season->id],
        )];
    }

    /**
     * Złota Liga: propozycja składu z wpłat (GoldenLeague::seats) do zatwierdzenia jednym przyciskiem
     * albo do zmiany na stronie Rozgrywki. Gdy liga już jest, a przed pierwszą rozegraną kolejką wpłaty
     * dają inny skład, admin dostaje informację o zmianie.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function golden(Season $season): array
    {
        $proposal = GoldenLeague::seats($season);

        if ($proposal === []) {
            return [];
        }

        $existing = Competition::where('season_id', $season->id)->where('type', CompetitionType::Golden->value)->first();
        $names = SeasonTeam::with(['user:id,name,team_name', 'bot:id,name'])->whereIn('id', $proposal)->get()->keyBy('id');
        $list = collect($proposal)->map(fn (int $id) => $names->get($id)?->name)->filter()->implode(', ');

        if (! $existing) {
            return [self::alert(
                self::WARNING,
                'star',
                __('Złota Liga: approve the proposed line-up'),
                __('Proposal from the payments of the last 12 months: :list. You can approve it or change it on the Competitions page.', ['list' => $list]),
                'dashboard.competitions',
                ['season' => $season->id],
                ['method' => 'buildGolden', 'arg' => $season->id, 'label' => __('Approve Złota Liga')],
            )];
        }

        $current = $existing->entries()->orderBy('seed')->pluck('season_team_id')->map(fn ($id) => (int) $id)->all();
        $started = Matchday::where('season_id', $season->id)->where('status', MatchdayStatus::Played->value)->exists();

        if ($started || array_values($proposal) === $current) {
            return [];
        }

        return [self::alert(
            self::INFO,
            'star',
            __('Złota Liga: new payments change the line-up'),
            __('By the payments the line-up would be: :list. Change it on the Competitions page if you want.', ['list' => $list]),
            'dashboard.competitions',
            ['season' => $season->id],
        )];
    }

    /** Liga podwórkowa: runda N+1 powinna być rozlosowana, gdy kolejka N jest przeliczona. */
    private static function swiss(Season $season): array
    {
        $alerts = [];
        $played = Matchday::where('season_id', $season->id)->where('status', MatchdayStatus::Played->value)->max('number') ?? 0;
        $needed = min(DrawSwissRound::ROUNDS, $played + 1);

        foreach (Competition::where('season_id', $season->id)->where('type', CompetitionType::Swiss->value)->get() as $competition) {
            $drawn = (int) Fixture::where('competition_id', $competition->id)->max('round');

            if ($drawn < $needed) {
                $alerts[] = self::alert(
                    self::DANGER,
                    'arrows-right-left',
                    __('Liga podwórkowa: draw round :round', ['round' => $drawn + 1]),
                    __('Without the pairs the teams of this league have no match.'),
                    'dashboard.fixtures',
                    ['season' => $season->id, 'c' => 'swiss'],
                    ['method' => 'drawSwiss', 'arg' => $competition->id, 'label' => __('Draw the pairs')],
                );
            }
        }

        return $alerts;
    }

    private static function alert(string $level, string $icon, string $title, string $detail, ?string $route, array $params = [], ?array $action = null): array
    {
        return compact('level', 'icon', 'title', 'detail', 'route', 'params', 'action');
    }
}
