<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Question;
use App\Enums\QuestionSide;
use App\Models\Season;
use App\Models\SeasonTeam;
use Spatie\Permission\Models\Role;

/**
 * Lista "Konieczne do zrobienia": wszystko, co musi być ustawione, żeby sezon działał poprawnie.
 * Każdy punkt to: klucz, tytuł, czy zrobione, szczegół (np. 7/9), trasa do poprawienia
 * i wskazówka. W kolejnych etapach (pytania, typowanie, wyniki...) dopisujemy tu nowe punkty.
 */
class SeasonChecklist
{
    /**
     * @return array<int, array{group: string, title: string, ok: bool, detail: string, route: ?string, hint: ?string}>
     */
    public static function for(?Season $season): array
    {
        $items = [];

        // --- Ustawienia ogólne ---
        $rolesOk = Role::whereIn('name', RoleName::values())->count() === count(RoleName::values());
        $items[] = self::item(
            __('General'),
            __('Roles Admin, User, Premium and Banned exist'),
            $rolesOk,
            $rolesOk ? '' : __('Missing roles.'),
            'dashboard.roles',
            'php artisan db:seed --class=RolesAndPermissionsSeeder'
        );

        $bots = Bot::count();
        $botsOk = $bots >= SeasonTeam::CUP_SIZE;
        $items[] = self::item(
            __('General'),
            __('The pool has :count bots', ['count' => SeasonTeam::CUP_SIZE]),
            $botsOk,
            $bots . ' / ' . SeasonTeam::CUP_SIZE,
            'dashboard.bots',
            'php artisan db:seed --class=BotsSeeder'
        );

        $players = Players::eligible()->count();
        $items[] = self::item(
            __('General'),
            __('There are players with a role'),
            $players > 0,
            (string) $players,
            'dashboard.users',
            null
        );

        // --- Sezon ---
        if (!$season) {
            $items[] = self::item(__('Season'), __('The season exists'), false, '', 'dashboard.seasons', null);

            return $items;
        }

        $items[] = self::item(__('Season'), __('The season exists'), true, $season->title, 'dashboard.seasons', null);

        $matchdays = Matchday::where('season_id', $season->id)->get();
        $items[] = self::item(
            __('Season'),
            __('All :count matchdays are created', ['count' => Matchday::PER_SEASON]),
            $matchdays->count() >= Matchday::PER_SEASON,
            $matchdays->count() . ' / ' . Matchday::PER_SEASON,
            'dashboard.matchdays',
            null
        );

        $filled = $matchdays->filter->isFilled()->count();
        $items[] = self::item(
            __('Season'),
            __('Every matchday has an opponent and a date'),
            $filled >= Matchday::PER_SEASON,
            $filled . ' / ' . Matchday::PER_SEASON,
            'dashboard.matchdays',
            null
        );

        // --- Lista zespołów ---
        $total = SeasonTeam::where('season_id', $season->id)->count();
        $items[] = self::item(__('Team list'), __('The team list is built'), $total > 0, (string) $total, 'dashboard.season-teams', null);

        $unlisted = Players::unlisted($season->id)->count();
        $items[] = self::item(
            __('Team list'),
            __('Every player with a role has a league'),
            $total > 0 && $unlisted === 0,
            $unlisted > 0 ? __(':count without a league', ['count' => $unlisted]) : '',
            'dashboard.season-teams',
            null
        );

        $banned = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')
            ->whereNotIn('user_id', Players::eligible()->select('id'))->count();
        $items[] = self::item(
            __('Team list'),
            __('No banned or role-less users on the list'),
            $banned === 0,
            $banned > 0 ? (string) $banned : '',
            'dashboard.season-teams',
            null
        );

        $items[] = self::item(
            __('Team list'),
            __('The list has at least :count teams (bots included)', ['count' => SeasonTeam::CUP_SIZE]),
            $total >= SeasonTeam::CUP_SIZE,
            $total . ' / ' . SeasonTeam::CUP_SIZE,
            'dashboard.season-teams',
            null
        );

        // --- Pytania ---
        $perSide = MatchdayQuestion::PER_SIDE;
        $types = \App\Actions\Questions\DrawQuestions::typesOfSeason($season->id);
        $need = $perSide * max(1, count($types));
        foreach ([QuestionSide::Offensive, QuestionSide::Defensive] as $side) {
            $have = Question::where('side', $side)->where('is_active', true)->count();
            $items[] = self::item(
                __('Questions'),
                __('The bank has enough :side questions', ['side' => mb_strtolower($side->label())]),
                $have >= $need,
                $have . ' / ' . $need,
                'dashboard.questions',
                'php artisan db:seed --class=QuestionsSeeder'
            );
        }

        $slotsPerMatchday = $perSide * 2 * count($types);
        $counts = MatchdayQuestion::whereIn('matchday_id', $matchdays->pluck('id'))
            ->selectRaw('matchday_id, count(*) as c')->groupBy('matchday_id')->pluck('c', 'matchday_id');
        $full = $matchdays->filter(fn($m) => (int) ($counts[$m->id] ?? 0) >= $slotsPerMatchday && $slotsPerMatchday > 0)->count();
        $items[] = self::item(
            __('Questions'),
            __('Every matchday has full question sets'),
            $full >= Matchday::PER_SEASON,
            $full . ' / ' . Matchday::PER_SEASON,
            'dashboard.matchday-questions',
            null
        );

        // --- Zatwierdzenie i aktywacja ---
        $approved = $season->status !== SeasonStatus::Draft;
        $items[] = self::item(__('Launch'), __('The season is approved'), $approved, $season->status->label(), 'dashboard.seasons', null);

        $competitions = Competition::where('season_id', $season->id)->get();
        $leagueIds = $competitions->where('type', CompetitionType::League)->pluck('id');
        $leagueFixtures = Fixture::whereIn('competition_id', $leagueIds)->count();
        $items[] = self::item(
            __('Launch'),
            __('League fixtures are generated'),
            $leagueIds->count() === 10 && $leagueFixtures === 450,
            $leagueIds->count() . ' ' . __('leagues') . ', ' . $leagueFixtures . ' ' . __('matches'),
            'dashboard.fixtures',
            null
        );

        $cup = $competitions->firstWhere('type', CompetitionType::Cup);
        $cupFixtures = $cup ? Fixture::where('competition_id', $cup->id)->count() : 0;
        $items[] = self::item(
            __('Launch'),
            __('The Puchar Polski bracket is generated'),
            $cupFixtures === 511,
            $cupFixtures . ' / 511',
            'dashboard.fixtures',
            null
        );

        $swiss = $competitions->firstWhere('type', CompetitionType::Swiss);
        $swissEntries = $swiss ? CompetitionEntry::where('competition_id', $swiss->id)->count() : 0;
        $items[] = self::item(
            __('Launch'),
            __('Liga podwórkowa has its teams'),
            $swissEntries > 0,
            (string) $swissEntries,
            'dashboard.fixtures',
            null
        );

        $items[] = self::item(
            __('Launch'),
            __('The season is active'),
            in_array($season->status, [SeasonStatus::Active, SeasonStatus::Finished], true),
            '',
            'dashboard.seasons',
            null
        );

        return $items;
    }

    private static function item(string $group, string $title, bool $ok, string $detail, ?string $route, ?string $hint): array
    {
        return compact('group', 'title', 'ok', 'detail', 'route', 'hint');
    }
}