<?php

namespace App\Support;

use App\Actions\Competitions\DrawSwissRound;
use App\Actions\Questions\DrawQuestions;
use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
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
            $alerts = array_merge($alerts, self::matchdays($season), self::swiss($season));
        } else {
            $upcoming = Season::whereIn('status', [SeasonStatus::Draft->value, SeasonStatus::Approved->value])->orderBy('number')->first();
            $alerts[] = self::alert(
                self::INFO,
                'calendar-days',
                $upcoming ? __('No active season. :season is being prepared.', ['season' => $upcoming->title]) : __('No active season.'),
                __('Check what is still missing before the start.'),
                'dashboard.checklist',
                $upcoming ? ['season' => $upcoming->id] : [],
            );
        }

        $withoutRole = Players::withoutRole()->whereNotNull('email_verified_at')->count();
        if ($withoutRole > 0) {
            $alerts[] = self::alert(self::INFO, 'user-plus', trans_choice(':count new user waits for a role|:count new users wait for a role', $withoutRole, ['count' => $withoutRole]), __('Give them the User role so they can play.'), 'dashboard.users', ['roleFilter' => '__none']);
        }

        $requests = TeamNameChangeRequest::pending()->count();
        if ($requests > 0) {
            $alerts[] = self::alert(self::INFO, 'identification', trans_choice(':count team name request|:count team name requests', $requests, ['count' => $requests]), __('Approve or reject the new names.'), 'dashboard.team-requests');
        }

        $unverified = User::whereNull('email_verified_at')->where('created_at', '<', now()->subDay())->count();
        if ($unverified > 0) {
            $alerts[] = self::alert(self::INFO, 'envelope', trans_choice(':count user has not confirmed the email for a day|:count users have not confirmed the email for a day', $unverified, ['count' => $unverified]), __('You can see their codes on the Users page.'), 'dashboard.users', ['roleFilter' => '__unverified']);
        }

        // Najpierw pilne, potem ostrzeżenia i informacje.
        $order = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];
        usort($alerts, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $alerts;
    }

    /** Liczba powiadomień pilnych i ostrzeżeń (do plakietki w menu). */
    public static function count(): int
    {
        return count(array_filter(self::all(), fn($a) => $a['level'] !== self::INFO));
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
            if (!$matchday->isFilled() && !$played) {
                if ($matchdays->first(fn($m) => !$m->isFilled() && $m->status !== MatchdayStatus::Played)?->is($matchday)) {
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

            if ($started && !$played && $matchday->status !== MatchdayStatus::Postponed) {
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

        if ($matchdays->count() >= Matchday::PER_SEASON && $matchdays->every(fn($m) => $m->status === MatchdayStatus::Played)) {
            $alerts[] = self::alert(self::WARNING, 'flag', __('All matchdays are played: finish the season'), __('Finishing saves the final tables and Hall of Fame points.'), 'dashboard.seasons');
        }

        return $alerts;
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
