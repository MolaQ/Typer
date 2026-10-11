<?php

namespace App\Actions\Seasons;

use App\Actions\Questions\DrawQuestions;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Audit;
use App\Support\Players;
use App\Support\Roster;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * „Utwórz sezon” jednym kliknięciem (po zapisie nowego sezonu w panelu). W jednej transakcji:
 *  1. lista zespołów: z tabel końcowych poprzedniego sezonu (BuildListFromPrevious), a w sezonie 1 z graczy
 *     w kolejności rejestracji i botów do 100 miejsc; nowi gracze trafiają do Ligi podwórkowej (Roster::place),
 *  2. zatwierdzenie (ApproveSeason): boty do 512, 10 lig, Puchar Polski, Liga podwórkowa, Liga Legend
 *     i puchary europejskie (EuropeanQualification) z terminarzami,
 *  3. losowanie pytań do kolejek (brak pytań w banku nie blokuje, admin losuje je później).
 * Mecze Lecha admin wybiera później z kalendarza. Przy błędzie nic się nie zapisuje, sezon zostaje szkicem.
 */
class SetUpSeason
{
    public function __construct(
        private BuildListFromPrevious $fromPrevious,
        private ApproveSeason $approve,
        private DrawQuestions $drawQuestions,
    ) {}

    /**
     * @return array{players: int, leagues: int, fixtures: int, questions: int}
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

            $running = Season::where('number', '<', $season->number)
                ->whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Approved->value])
                ->orderByDesc('number')
                ->first();

            if ($running) {
                throw new DomainException(__('Finish :season first. The new list is built from its final standings (promotion and relegation).', ['season' => $running->title]));
            }

            if (! SeasonTeam::where('season_id', $season->id)->exists()) {
                BuildListFromPrevious::previousSeason($season)
                    ? $this->fromPrevious->handle($season)
                    : $this->buildFromPlayers($season);
            }

            // Nowi gracze (bez miejsca na liście) do Ligi podwórkowej.
            foreach (Players::unlisted($season->id)->orderBy('created_at')->orderBy('id')->pluck('id') as $userId) {
                Roster::place($season->id, (int) $userId, League::Podworkowa);
            }

            $approved = $this->approve->handle($season);

            $questions = 0;
            foreach ($season->matchdays()->get() as $matchday) {
                try {
                    $questions += $this->drawQuestions->handle($matchday);
                } catch (DomainException) {
                    // Za mało pytań w banku: admin wylosuje je później (strona „Pytania kolejki”).
                }
            }

            Audit::log('season.set_up', null, [], ['leagues' => $approved['leagues'], 'fixtures' => $approved['fixtures'], 'questions' => $questions], $season->title);

            return [
                'players' => SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->count(),
                'leagues' => $approved['leagues'],
                'fixtures' => $approved['fixtures'],
                'questions' => $questions,
            ];
        });
    }

    /** Sezon 1: gracze w kolejności rejestracji, potem boty, aż pierwsze 100 miejsc (ligi) będzie pełne. */
    private function buildFromPlayers(Season $season): void
    {
        $playerIds = Players::eligible()->orderBy('created_at')->orderBy('id')->pluck('id');
        $needed = max(0, League::TOP_TEAMS - $playerIds->count());
        $bots = $needed > 0 ? Bot::orderBy('sort_order')->limit($needed)->pluck('id') : collect();

        if ($bots->count() < $needed) {
            throw new DomainException(__('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'));
        }

        $now = now();
        $position = 0;
        $rows = [];
        foreach ($playerIds as $userId) {
            $rows[] = ['season_id' => $season->id, 'position' => ++$position, 'user_id' => $userId, 'bot_id' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach ($bots as $botId) {
            $rows[] = ['season_id' => $season->id, 'position' => ++$position, 'user_id' => null, 'bot_id' => $botId, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SeasonTeam::insert($chunk);
        }

        Audit::log('season_list.built', null, [], ['players' => $playerIds->count(), 'bots' => $bots->count()], $season->title);
    }
}
