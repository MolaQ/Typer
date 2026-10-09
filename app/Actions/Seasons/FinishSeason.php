<?php

namespace App\Actions\Seasons;

use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\User;
use App\Support\Audit;
use App\Support\CupBracket;
use App\Support\LegendsRanking;
use App\Support\Roster;
use App\Support\Standings;
use App\Support\SystemFeed;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Zakończenie sezonu (aktywny -> zakończony):
 *  1. zapisuje tabele końcowe wszystkich rozgrywek (final_standings): podstawa listy na nowy sezon,
 *     kwalifikacji do lig europejskich i Hall of Fame (punkty i trofea przyznaje AwardHallOfFame),
 *  2. gracze, którzy 5 kolejek z rzędu nie typowali, tracą rolę User i dostają rolę Inactive
 *     (regulamin, punkt 3); ich miejsca w niezakończonych sezonach przejmują boty,
 *  3. zmienia status i zapisuje wpis w dzienniku.
 */
class FinishSeason
{
    /** Tyle kolejek z rzędu bez typu oznacza nieaktywnego gracza. */
    public const INACTIVE_STREAK = 5;

    /**
     * @param  string|null  $note  dopisek do dziennika (np. zakończony przez aktywację innego sezonu)
     * @return array{standings: int, inactive: int}
     *
     * @throws DomainException
     */
    public function handle(Season $season, ?string $note = null): array
    {
        $result = DB::transaction(function () use ($season, $note): array {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);

            if ($season->status !== SeasonStatus::Active) {
                throw new DomainException(__('Only the active season can be finished.'));
            }

            $standings = $this->saveStandings($season);
            app(AwardHallOfFame::class)->handle($season);
            $inactive = $this->inactiveUsers($season);

            $season->update(['status' => SeasonStatus::Finished]);

            Audit::log(
                'season.finished',
                null,
                ['status' => SeasonStatus::Active->label()],
                ['status' => SeasonStatus::Finished->label(), 'inactive' => count($inactive)],
                $season->title.($note ? ' ('.$note.')' : ''),
            );

            return ['standings' => $standings, 'inactive' => $inactive];
        });

        // Po zakończeniu: nieaktywni tracą rolę User, a ich miejsca w kolejnych sezonach przejmują boty.
        $role = Role::findOrCreate(RoleName::Inactive->value, 'web');

        foreach ($result['inactive'] as $user) {
            $user->removeRole(RoleName::User->value);
            $user->assignRole($role);
            Roster::syncUser($user);

            Audit::log('roles.updated', $user, ['roles' => RoleName::User->value], ['roles' => RoleName::Inactive->value], __('No tips in :count matchdays in a row', ['count' => self::INACTIVE_STREAK]));
        }

        $this->recordFeed($season);

        return ['standings' => $result['standings'], 'inactive' => count($result['inactive'])];
    }

    /** Informacje systemowe: koniec sezonu i trofea zdobyte przez ludzi. */
    private function recordFeed(Season $season): void
    {
        SystemFeed::record('seasons', 'Season :season finished', ['season' => $season->title], 'hall-of-fame');

        $awards = HallOfFameAward::with(['user', 'competition'])
            ->where('season_id', $season->id)
            ->whereNotNull('trophy')
            ->whereNotNull('user_id')
            ->get();

        foreach ($awards as $award) {
            if (! $award->user) {
                continue;
            }

            SystemFeed::record(
                'trophies',
                ':team won :competition',
                ['team' => $award->user->team_name ?: $award->user->name, 'competition' => $award->competition?->name ?? $season->title],
                'team.show',
                ['user' => $award->user_id],
                $award->user_id,
            );
        }
    }

    /** Zapisuje tabele końcowe. Zwraca liczbę zapisanych miejsc. */
    private function saveStandings(Season $season): int
    {
        FinalStanding::where('season_id', $season->id)->delete();

        $lastRound = (int) Matchday::where('season_id', $season->id)->where('status', MatchdayStatus::Played->value)->max('number');
        $rows = [];
        $now = now();

        foreach (Competition::where('season_id', $season->id)->get() as $competition) {
            $ranked = match (true) {
                $competition->type === CompetitionType::Cup => $this->cupPlaces($competition),
                $competition->type === CompetitionType::Legends => $this->legendsPlaces($competition, $lastRound),
                default => Standings::for($competition)->map(fn ($row) => [
                    'team' => $row['team'],
                    'stats' => collect($row)->except(['team', 'entry_id'])->all(),
                ]),
            };

            foreach ($ranked->values() as $index => $row) {
                $rows[] = [
                    'season_id' => $season->id,
                    'competition_id' => $competition->id,
                    'season_team_id' => $row['team']->id,
                    'user_id' => $row['team']->user_id,
                    'bot_id' => $row['team']->bot_id,
                    'place' => $index + 1,
                    'stats' => json_encode($row['stats']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            FinalStanding::insert($chunk);
        }

        return count($rows);
    }

    /** Puchar: zwycięzca i finalista (miejsca 1 i 2), jeśli finał ma wynik. */
    private function cupPlaces(Competition $competition)
    {
        $final = Fixture::with(['home.seasonTeam', 'away.seasonTeam'])
            ->where('competition_id', $competition->id)
            ->where('round', CupBracket::ROUNDS)
            ->whereNotNull('winner_entry_id')
            ->first();

        if (! $final) {
            return collect();
        }

        $winnerIsHome = $final->winner_entry_id === $final->home_entry_id;
        $stats = ['score' => $final->score()];

        return collect([
            ['team' => ($winnerIsHome ? $final->home : $final->away)->seasonTeam, 'stats' => $stats],
            ['team' => ($winnerIsHome ? $final->away : $final->home)->seasonTeam, 'stats' => $stats],
        ]);
    }

    /** Liga Legend: najpierw ci, którzy dotrwali najdłużej, w każdej grupie według rankingu z kolejki odpadnięcia. */
    private function legendsPlaces(Competition $competition, int $lastRound)
    {
        if ($lastRound < 1) {
            return collect();
        }

        $places = collect();

        for ($round = $lastRound; $round >= 1; $round--) {
            $group = LegendsRanking::for($competition, $round)
                ->filter(fn ($row) => $round === $lastRound ? ($row['eliminated_round'] === null || $row['eliminated_round'] >= $round) : $row['eliminated_round'] === $round);

            $places = $places->concat($group->map(fn ($row) => [
                'team' => $row['team'],
                'stats' => collect($row)->except(['team', 'entry_id'])->all() + ['round_reached' => $round],
            ]));
        }

        return $places;
    }

    /**
     * Gracze z listy sezonu, którzy nie typowali w 5 kolejnych rozegranych kolejkach.
     *
     * @return array<int, User>
     */
    private function inactiveUsers(Season $season): array
    {
        $matchdays = Matchday::where('season_id', $season->id)->where('status', MatchdayStatus::Played->value)->orderBy('number')->pluck('id');

        if ($matchdays->count() < self::INACTIVE_STREAK) {
            return [];
        }

        $userIds = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->pluck('user_id');
        $tipped = Tip::whereIn('matchday_id', $matchdays)->get(['matchday_id', 'user_id'])
            ->groupBy('user_id')
            ->map(fn ($tips) => $tips->pluck('matchday_id')->flip());

        $inactive = [];

        foreach ($userIds as $userId) {
            $streak = 0;
            $best = 0;

            foreach ($matchdays as $matchdayId) {
                $streak = isset($tipped[$userId][$matchdayId]) ? 0 : $streak + 1;
                $best = max($best, $streak);
            }

            if ($best >= self::INACTIVE_STREAK) {
                $inactive[] = $userId;
            }
        }

        // Admin nie traci roli przez brak typów (konto techniczne).
        return User::whereIn('id', $inactive)->get()->reject(fn (User $user) => $user->hasRole(RoleName::Admin->value))->values()->all();
    }
}
