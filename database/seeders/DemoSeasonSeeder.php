<?php

namespace Database\Seeders;

use App\Actions\Questions\DrawQuestions;
use App\Actions\Seasons\ApproveSeason;
use App\Enums\League;
use App\Enums\MatchCompetition;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Audit;
use App\Support\Players;
use App\Support\SystemFeed;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sezon testowy gotowy do gry od razu po odświeżeniu bazy (php artisan migrate:fresh --seed).
 * Przechodzi te same kroki co admin w panelu, tymi samymi klasami, więc testuje prawdziwą ścieżkę:
 *
 *  1. Season::create() tworzy sezon (szkic) z kolejnym numerem, a createMissingMatchdays() 9 pustych kolejek.
 *  2. Kolejki dostają rywala, mecz u siebie / na wyjeździe, rozgrywki z enuma MatchCompetition
 *     i godzinę pierwszego gwizdka: kolejka 1 za 10 minut od uruchomienia seedera, kolejka 2 za 11 minut itd.
 *     Dzięki temu po kilku minutach kolejki same zamykają się do typowania i można testować wyniki
 *     (php artisan db:seed --class=ResultMatchSeeder).
 *  3. Lista uczestników jak przycisk „Zbuduj listę automatycznie” (strona season-teams): najpierw gracze
 *     (Players::eligible(), w kolejności rejestracji), potem boty do pełnych 100 miejsc w ligach.
 *  4. ApproveSeason::handle() dopisuje resztę botów z puli (min. 512 zespołów) i przez BuildCompetitions
 *     tworzy wszystkie rozgrywki z uczestnikami według kolejności listy i terminarzami: 10 lig (każda po 9 kolejek),
 *     Puchar Polski (drabinka 512), Ligę podwórkową (runda 1), Ligę Legend, a przy wpłatach Złotą Ligę.
 *  5. Aktywacja sezonu (status Active), wpis w dzienniku (Audit::log) i w informacjach systemowych (SystemFeed).
 *  6. DrawQuestions::handle() losuje pytania bonusowe do każdej kolejki z banku (QuestionsSeeder).
 *
 * Wymaga wcześniej: RolesAndPermissionsSeeder, BotsSeeder (512 botów), QuestionsSeeder, PlayersSeeder.
 * Gdy jakiś sezon jest już aktywny, seeder nic nie robi (żeby nie zakończyć sezonu, na którym ktoś gra).
 *
 *   php artisan db:seed --class=DemoSeasonSeeder
 */
class DemoSeasonSeeder extends Seeder
{
    /** Pierwszy gwizdek kolejki 1: tyle minut po uruchomieniu seedera. */
    private const FIRST_KICKOFF_MINUTES = 10;

    /** Odstęp między kolejnymi kolejkami w minutach (kolejka 2 = 11 minut, kolejka 3 = 12 minut...). */
    private const KICKOFF_STEP_MINUTES = 1;

    /**
     * Mecze kolejek 1-9: [rywal, czy Lech gra u siebie, rozgrywki].
     * Rozgrywki biorą się z enuma MatchCompetition, tego samego, z którego korzysta select w panelu „Kolejki”.
     *
     * @return array<int, array{0: string, 1: bool, 2: MatchCompetition}>
     */
    private static function fixtures(): array
    {
        return [
            ['Legia Warszawa', true, MatchCompetition::League],
            ['Raków Częstochowa', false, MatchCompetition::League],
            ['FC Kopenhaga', true, MatchCompetition::ConferenceLeague],
            ['Pogoń Szczecin', true, MatchCompetition::League],
            ['Wisła Kraków', false, MatchCompetition::PolishCup],
            ['Jagiellonia Białystok', false, MatchCompetition::League],
            ['Real Betis', false, MatchCompetition::ConferenceLeague],
            ['Górnik Zabrze', true, MatchCompetition::League],
            ['Widzew Łódź', true, MatchCompetition::League],
        ];
    }

    public function run(ApproveSeason $approve, DrawQuestions $drawQuestions): void
    {
        // Season::active() to zakres z modelu (status = active). Aktywny sezon zostawiamy w spokoju.
        if (Season::active()->exists()) {
            $this->command?->warn('Jest już aktywny sezon, sezon testowy nie powstanie.');

            return;
        }

        // ApproveSeason wymaga co najmniej 512 zespołów, a brakujące miejsca wypełniają boty.
        if (Bot::count() < SeasonTeam::CUP_SIZE) {
            $this->command?->error('Za mało botów. Uruchom najpierw: php artisan db:seed --class=BotsSeeder');

            return;
        }

        $season = $this->createSeason();
        $this->fillMatchdays($season);
        $this->buildList($season);

        try {
            // Ta sama akcja co przycisk „Zatwierdź” w panelu sezonów: boty, rozgrywki i terminarze w jednej transakcji.
            $built = $approve->handle($season);
        } catch (DomainException $e) {
            $this->command?->error('Zatwierdzenie sezonu: '.$e->getMessage());

            return;
        }

        $this->activate($season->refresh());
        $questions = $this->drawQuestions($season, $drawQuestions);

        $this->command?->info(sprintf(
            'Sezon testowy %s: %d botów dopisanych, %d lig, %d meczów w terminarzach, %d pytań w kolejkach. Kolejka 1 startuje o %s.',
            $season->title,
            $built['bots'] ?? 0,
            $built['leagues'] ?? 0,
            $built['fixtures'] ?? 0,
            $questions,
            now()->addMinutes(self::FIRST_KICKOFF_MINUTES)->format('H:i'),
        ));
    }

    /** Krok 1: szkic sezonu z kolejnym numerem i 9 pustymi kolejkami (Season::createMissingMatchdays). */
    private function createSeason(): Season
    {
        $season = Season::create([
            'number' => (int) Season::max('number') + 1,
            'slogan' => 'Sezon testowy: typuj, zanim zabrzmi pierwszy gwizdek!',
            'status' => SeasonStatus::Draft->value,
        ]);

        $season->createMissingMatchdays();
        Audit::log('season.created', null, [], ['number' => $season->number], $season->title);

        return $season;
    }

    /**
     * Krok 2: rywal, miejsce, rozgrywki i godzina pierwszego gwizdka każdej kolejki.
     * now()->addMinutes() liczy czas w strefie aplikacji (Europe/Warsaw), tak jak godziny wpisywane w panelu.
     * Sekundy zerujemy (startOfMinute), bo formularz w panelu też ma dokładność do minuty.
     */
    private function fillMatchdays(Season $season): void
    {
        $start = now()->startOfMinute()->addMinutes(self::FIRST_KICKOFF_MINUTES);

        foreach (self::fixtures() as $index => [$opponent, $isHome, $competition]) {
            $matchday = Matchday::where('season_id', $season->id)->where('number', $index + 1)->firstOrFail();

            $matchday->update([
                'opponent' => $opponent,
                'is_home' => $isHome,
                'competition' => $competition->value, // w bazie wartość enuma, na stronie etykieta (competitionLabel)
                'kickoff_at' => $start->copy()->addMinutes($index * self::KICKOFF_STEP_MINUTES),
                'status' => MatchdayStatus::Planned->value,
            ]);

            // Wpis w „Informacjach systemowych” jak przy dodaniu meczu w panelu.
            SystemFeed::record(
                'matches',
                'Match added: :fixture (matchday :number, :date)',
                ['fixture' => $matchday->fixture, 'number' => $matchday->number, 'date' => $matchday->kickoff_at->format('d.m.Y H:i')],
                'results',
                ['round' => $matchday->number],
            );
        }
    }

    /**
     * Krok 3: lista przedsezonowa. Pozycja na liście decyduje o lidze (1-10 to Ekstraklasa, 11-20 I liga...),
     * rozstawieniu w pucharze i kolejności dodawania do rozgrywek.
     * Gracze w kolejności rejestracji (created_at, potem id), boty po sort_order, aż do League::TOP_TEAMS (100).
     * Wstawiamy hurtowo przez SeasonTeam::insert() w paczkach po 500 (jak w panelu).
     */
    private function buildList(Season $season): void
    {
        $playerIds = Players::eligible()->orderBy('created_at')->orderBy('id')->pluck('id');
        $needed = max(0, League::TOP_TEAMS - $playerIds->count());
        $botIds = Bot::orderBy('sort_order')->limit($needed)->pluck('id');

        DB::transaction(function () use ($season, $playerIds, $botIds): void {
            $now = now();
            $rows = [];
            $position = 0;

            foreach ($playerIds as $userId) {
                $rows[] = ['season_id' => $season->id, 'position' => ++$position, 'user_id' => $userId, 'bot_id' => null, 'created_at' => $now, 'updated_at' => $now];
            }

            foreach ($botIds as $botId) {
                $rows[] = ['season_id' => $season->id, 'position' => ++$position, 'user_id' => null, 'bot_id' => $botId, 'created_at' => $now, 'updated_at' => $now];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SeasonTeam::insert($chunk);
            }
        });

        Audit::log('season_list.built', null, [], ['players' => $playerIds->count(), 'bots' => $botIds->count()], $season->title);
    }

    /** Krok 5: sezon staje się aktywny (strona główna, typowanie, wyniki). */
    private function activate(Season $season): void
    {
        $old = $season->status->label();
        $season->update(['status' => SeasonStatus::Active->value]);

        Audit::log('season.activated', null, ['status' => $old], ['status' => SeasonStatus::Active->label()], $season->title);
        SystemFeed::record('seasons', 'Season :season started', ['season' => $season->title], 'results');
    }

    /**
     * Krok 6: pytania bonusowe do każdej kolejki (wszystkie typy rozgrywek sezonu).
     * Brak pytań w banku nie przerywa seedera: kolejka zostaje bez pytań, a admin może je dolosować w panelu.
     */
    private function drawQuestions(Season $season, DrawQuestions $drawQuestions): int
    {
        $total = 0;

        foreach (Matchday::where('season_id', $season->id)->orderBy('number')->get() as $matchday) {
            try {
                $total += $drawQuestions->handle($matchday);
            } catch (DomainException $e) {
                $this->command?->warn("Kolejka {$matchday->number}: {$e->getMessage()}");
            }
        }

        return $total;
    }
}
