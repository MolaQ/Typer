<?php

namespace App\Actions\Seasons;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Audit;
use App\Support\Players;
use App\Support\Promotion;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lista przedsezonowa nowego sezonu z tabel końcowych poprzedniego (regulamin, punkt 9):
 * awanse i spadki po 4 z czyszczeniem z botów (App\Support\Promotion), Liga podwórkowa według
 * klasyfikacji końcowej. Zespół gracza, który nie może już grać (ban, nieaktywny), zostaje na swoim
 * miejscu jako bot. Nowi gracze dochodzą potem przyciskiem "Dodaj nowych graczy" (na koniec podwórkowej).
 * Każde miejsce pamięta, z którego miejsca poprzedniego sezonu powstało (previous_id).
 */
class BuildListFromPrevious
{
    /**
     * @return array{teams: int, players: int, bots: int}
     *
     * @throws DomainException
     */
    public function handle(Season $season): array
    {
        if ($season->status !== SeasonStatus::Draft) {
            throw new DomainException(__('Only a draft season can be changed.'));
        }

        if (SeasonTeam::where('season_id', $season->id)->exists()) {
            throw new DomainException(__('The list already exists. Clear it first to build it again.'));
        }

        $previous = self::previousSeason($season);

        if (! $previous) {
            throw new DomainException(__('There is no finished previous season with final standings.'));
        }

        // Tabele końcowe lig 1-10 i podwórkowej: poziom => zespoły w kolejności miejsc.
        $competitions = Competition::where('season_id', $previous->id)
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->get()
            ->keyBy('id');

        $standings = FinalStanding::with('seasonTeam:id,user_id,bot_id')
            ->whereIn('competition_id', $competitions->keys())
            ->orderBy('place')
            ->get();

        $eligible = Players::eligible()->pluck('id')->flip();
        $tiers = [];
        $old = [];

        foreach ($standings as $row) {
            $level = $competitions[$row->competition_id]->tier ?? League::Podworkowa->value;
            $team = $row->seasonTeam;
            $old[$team->id] = $team;
            $tiers[$level][] = ['id' => $team->id, 'human' => $team->user_id !== null && isset($eligible[$team->user_id])];
        }

        foreach (League::cases() as $league) {
            if ($league->isTop() && count($tiers[$league->value] ?? []) !== League::SIZE) {
                throw new DomainException(__('The final standings of :league are incomplete.', ['league' => $league->label()]));
            }
        }

        $tiers[League::Podworkowa->value] ??= [];
        $order = Promotion::apply($tiers);

        return DB::transaction(function () use ($season, $previous, $order, $old, $eligible): array {
            // Wolne boty z puli dla miejsc graczy, którzy już nie grają.
            $usedBots = collect($old)->pluck('bot_id')->filter()->all();
            $freeBots = Bot::whereNotIn('id', $usedBots)->orderBy('sort_order')->pluck('id')->all();

            $now = now();
            $rows = [];
            $position = 0;
            $players = 0;

            ksort($order);
            foreach ($order as $ids) {
                foreach ($ids as $id) {
                    $team = $old[$id];
                    $human = $team->user_id !== null && isset($eligible[$team->user_id]);
                    $players += (int) $human;

                    $rows[] = [
                        'season_id' => $season->id,
                        'position' => ++$position,
                        'user_id' => $human ? $team->user_id : null,
                        'bot_id' => $human ? null : ($team->bot_id ?? array_shift($freeBots)),
                        'previous_id' => $team->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SeasonTeam::insert($chunk);
            }

            Audit::log(
                'season_list.built',
                null,
                [],
                ['players' => $players, 'bots' => count($rows) - $players, 'from' => $previous->title],
                $season->title,
            );

            return ['teams' => count($rows), 'players' => $players, 'bots' => count($rows) - $players];
        });
    }

    /** Ostatni zakończony sezon przed tym, który ma tabele końcowe. */
    public static function previousSeason(Season $season): ?Season
    {
        return Season::where('status', SeasonStatus::Finished->value)
            ->where('number', '<', $season->number)
            ->whereHas('competitions', fn ($q) => $q->whereIn('id', FinalStanding::select('competition_id')))
            ->orderByDesc('number')
            ->first();
    }
}
