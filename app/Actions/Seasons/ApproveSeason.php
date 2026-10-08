<?php

namespace App\Actions\Seasons;

use App\Actions\Competitions\BuildCompetitions;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Players;
use App\Support\Roster;
use App\Support\Audit;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Zatwierdzenie sezonu (szkic -> zatwierdzony). Wszystko w jednej transakcji:
 *  1. sprawdza, czy lista jest zbudowana i czy żaden gracz nie został pominięty,
 *  2. dodaje wszystkie boty z puli (lista zamknięta, min. 512 zespołów),
 *  3. tworzy rozgrywki (App\Actions\Competitions\BuildCompetitions): 10 lig z terminarzem
 *     (9 kolejek po 5 meczów), Puchar Polski (drabinka 512, 9 rund) i Ligę podwórkową,
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

            // 10 lig, Puchar Polski (cała drabinka) i Liga podwórkowa (uczestnicy, pary losowane rundami).
            $built = app(BuildCompetitions::class)->handle($season);

            $season->update(['status' => SeasonStatus::Approved]);

            Audit::log(
                'season.approved',
                null,
                ['status' => SeasonStatus::Draft->label()],
                [
                    'status' => SeasonStatus::Approved->label(),
                    'bots_added' => $botsAdded,
                    'teams' => $total,
                    'leagues' => $built['leagues'],
                    'fixtures' => $built['league_fixtures'] + $built['cup_fixtures'],
                ],
                $season->title,
            );

            return [
                'bots' => $botsAdded,
                'leagues' => $built['leagues'],
                'fixtures' => $built['league_fixtures'] + $built['cup_fixtures'],
            ];
        });
    }
}
