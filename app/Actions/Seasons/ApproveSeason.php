<?php

namespace App\Actions\Seasons;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Players;
use App\Support\Roster;
use App\Support\Audit;
use App\Support\LeagueSchedule;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Zatwierdzenie sezonu (szkic -> zatwierdzony). Wszystko w jednej transakcji:
 *  1. sprawdza, czy lista jest zbudowana i czy żaden gracz nie został pominięty,
 *  2. dodaje wszystkie boty z puli (lista zamknięta, min. 512 zespołów),
 *  3. tworzy 10 lig (poziomy 1-10) z uczestnikami według pozycji na liście,
 *  4. generuje terminarz z LeagueSchedule (9 kolejek po 5 meczów w każdej lidze),
 *  5. zmienia status i zapisuje wpis w dzienniku.
 * Przy jakimkolwiek błędzie nic się nie zapisuje. Błędy "do pokazania adminowi"
 * to DomainException z przetłumaczonym komunikatem.
 */
class ApproveSeason
{
    public function __construct(private FillTeamListWithBots $fillWithBots) {}

    /**
     * @return array{bots: int, leagues: int, fixtures: int}
     *
     * @throws DomainException
     */
    public function handle(Season $season): array
    {
        return DB::transaction(function () use ($season): array {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);

            if ($season->status !== SeasonStatus::Draft) {
                throw new DomainException(__('Only a draft season can be approved.'));
            }

            if (! SeasonTeam::where('season_id', $season->id)->exists()) {
                throw new DomainException(__('Build the team list before approving the season.'));
            }

            // Zbanowani i bez roli tracą miejsca na rzecz botów (nie startują w żadnych rozgrywkach).
            Roster::releaseIneligible($season->id);

            // Gracz (użytkownik z rolą) bez miejsca na liście musi zostać przypisany do ligi przed zatwierdzeniem.
            $unlisted = Players::unlisted($season->id)->count();

            if ($unlisted > 0) {
                throw new DomainException(__(':count players are not on the list yet.', ['count' => $unlisted]).' '.__('Add them to the list before approving.'));
            }

            $botsAdded = $this->fillWithBots->handle($season);

            $total = SeasonTeam::where('season_id', $season->id)->count();
            $top = SeasonTeam::where('season_id', $season->id)
                ->where('position', '<=', League::TOP_TEAMS)
                ->orderBy('position')
                ->get(['id', 'position']);

            // Bez 512 zespołów nie ułożymy pucharu, bez 100 pierwszych miejsc lig.
            if ($total < SeasonTeam::CUP_SIZE || $top->count() < League::TOP_TEAMS) {
                throw new DomainException(__('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'));
            }

            $fixtures = 0;
            $leagues = 0;

            foreach (League::cases() as $league) {
                if (! $league->isTop()) {
                    continue; // liga podwórkowa (system szwajcarski) - etap 8b
                }

                $competition = Competition::create([
                    'season_id' => $season->id,
                    'type' => CompetitionType::League,
                    'tier' => $league->value,
                    'name' => $league->label(),
                ]);

                $teams = $top
                    ->filter(fn ($t) => $t->position >= $league->firstPosition() && $t->position <= $league->lastPosition())
                    ->sortBy('position')
                    ->values();

                // seed = miejsce w lidze (1-10); id wpisu zapamiętujemy do terminarza.
                $entryBySeed = [];
                foreach ($teams as $index => $team) {
                    $seed = $index + 1;

                    $entryBySeed[$seed] = CompetitionEntry::create([
                        'competition_id' => $competition->id,
                        'season_team_id' => $team->id,
                        'seed' => $seed,
                    ])->id;
                }

                $now = now();
                $rows = [];

                foreach (LeagueSchedule::fixtures() as $match) {
                    $rows[] = [
                        'competition_id' => $competition->id,
                        'round' => $match['round'],
                        'home_entry_id' => $entryBySeed[$match['home']],
                        'away_entry_id' => $entryBySeed[$match['away']],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('fixtures')->insert($rows);

                $fixtures += count($rows);
                $leagues++;
            }

            $season->update(['status' => SeasonStatus::Approved]);

            Audit::log(
                'season.approved',
                null,
                ['status' => SeasonStatus::Draft->label()],
                [
                    'status' => SeasonStatus::Approved->label(),
                    'bots_added' => $botsAdded,
                    'teams' => $total,
                    'leagues' => $leagues,
                    'fixtures' => $fixtures,
                ],
                $season->title,
            );

            return ['bots' => $botsAdded, 'leagues' => $leagues, 'fixtures' => $fixtures];
        });
    }
}
