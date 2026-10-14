<?php

namespace App\Console\Commands;

use App\Actions\Results\SaveMatchdayResult;
use App\Actions\Seasons\FinishSeason;
use App\Actions\Seasons\SetUpSeason;
use App\Actions\Tips\SaveTip;
use App\Enums\CompetitionType;
use App\Enums\MatchCompetition;
use App\Enums\PlayerPosition;
use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\HallOfFameAward;
use App\Models\LechMatch;
use App\Models\LechPlayer;
use App\Models\MatchAppearance;
use App\Models\Matchday;
use App\Models\MatchEvent;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use App\Support\Audit;
use App\Support\HallOfFame;
use App\Support\MatchReport;
use App\Support\PlayerCompetitions;
use App\Support\Premium;
use App\Support\Roster;
use App\Support\Squad;
use App\Support\Standings;
use App\Support\SystemFeed;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Symulacja jednego pełnego sezonu z wiersza poleceń (dane testowe, NIE na produkcji):
 *
 *   php artisan sim:season
 *
 * Każde uruchomienie to jeden sezon, tymi samymi klasami co panel admina i strona typowania:
 *  1. nowy sezon z losowym sloganem i 9 meczów Lecha w kalendarzu w losowej kolejności (1 towarzyski, 5 ligowych,
 *     1 Puchar Polski, 2 z jednych losowych pucharów europejskich), u siebie albo na wyjeździe losowo;
 *     pierwszy gwizdek w chwili uruchomienia (albo 4 dni po ostatnim meczu poprzedniego sezonu), każdy następny 4 dni później
 *     (odpoczynek zawodników działa jak w prawdziwym sezonie),
 *  2. SetUpSeason (lista z poprzedniego sezonu z awansami, spadkami i czyszczeniem z botów, nowi gracze do podwórkowej,
 *     zatwierdzenie), potem aktywacja,
 *  3. w każdej kolejce: typy 98-100% graczy (SaveTip, zawsze wygrana Lecha: 1:0, 2:0, 2:1 albo 3:1) i 1-4 losowych dostępnych
 *     zawodników w każdym typie rozgrywek gracza (Squad::toggle); boty wystawiają według ustawień desygnowania (bot_players_min/max),
 *  4. mecz Lecha z protokołem (match_events): stała jedenastka (LINEUP), 10 rezerwowych (w tym bramkarz), 5 zmian w przerwie
 *     albo w 2. połowie, Lech wygrywa (w Europie wygrywa albo remisuje), gole 65% napastnicy / 30% pomocnicy / 5% obrońcy,
 *     90% goli z asystą (70% pomocnicy / 20% obrońcy / 10% napastnicy), 1-5 żółtych kartek (każda 8% na drugą żółtą),
 *     czerwona 3% i samobój 10% na mecz; potem przeliczenie (SaveMatchdayResult), nowi gracze (1-5% kont),
 *     raz na sezon ban jednego gracza (bez admina),
 *  5. zakończenie sezonu (FinishSeason: tabele końcowe, Hall of Fame, nieaktywni) i 10-15% nowych kont na następny sezon.
 *
 * Czas: symulacja przestawia zegar aplikacji (Date::setTestNow), żeby typy były przed pierwszym gwizdkiem,
 * a wyniki po nim; na końcu zegar wraca do normalnego.
 */
class SimulateSeason extends Command
{
    protected $signature = 'sim:season {--seed= : Ziarno losowania (ten sam przebieg przy tej samej bazie)}';

    protected $description = 'Symuluje jeden pełny sezon (typy, wyniki, nowi gracze, ban, koniec sezonu) i wypisuje podsumowanie';

    /** Rywale i rodzaje meczów sezonu (kolejność losowana przy każdym uruchomieniu). */
    private const FRIENDLY = ['Zjednoczeni Kaczory'];

    private const LEAGUE = ['Górnik Zabrze', 'Legia Warszawa', 'Widzew Łódź', 'Jagiellonia Białystok', 'Raków Częstochowa'];

    private const CUP = ['Warta Poznań'];

    private const EUROPE = ['Manchester United', 'Borussia Dortmund'];

    /** Typy graczy: zawsze wygrana Lecha, jeden z tych wyników (Lech : rywal). */
    private const TIPS = [[1, 0], [2, 0], [2, 1], [3, 1]];

    /** Wyjściowa jedenastka Lecha w każdym meczu (nazwiska z kadry). */
    private const LINEUP = ['Lis', 'Pereira', 'Mońka', 'Yegbe', 'Gurgul', 'Wålemark', 'Rodríguez', 'Palma', 'Sayyadmanesh', 'Gholizadeh', 'Ishak'];

    private const BENCH = 10;

    private const SUBSTITUTIONS = 5;

    /** Kto strzela i kto asystuje (procent według pozycji). */
    private const GOAL_WEIGHTS = ['forward' => 65, 'midfielder' => 30, 'defender' => 5];

    private const ASSIST_WEIGHTS = ['midfielder' => 70, 'defender' => 20, 'forward' => 10];

    /** Prawdopodobieństwa w procentach: gol z asystą, druga żółta (od każdej żółtej), czerwona i samobój (na mecz). */
    private const ASSIST_CHANCE = 90;

    private const SECOND_YELLOW_CHANCE = 8;

    private const RED_CHANCE = 3;

    private const OWN_GOAL_CHANCE = 10;

    /** Odstęp między kolejkami w dniach. */
    private const DAYS_BETWEEN = 4;

    private const SLOGANS = [
        'Kolejorz gra, a my typujemy!',
        'Jeden mecz, tysiąc typów.',
        'Wiara w Lecha od pierwszego gwizdka.',
        'Typuj z głową, kibicuj sercem.',
        'Bułgarska po każdej trafionej kolejce.',
        'Od podwórka do Ekstraklasy.',
        'Niebiesko-biała przepowiednia.',
        'Kto nie typuje, ten nie wygrywa.',
        'Sezon pełen skalpów.',
        'Bot czy człowiek? Pokaż na boisku.',
    ];

    private const FIRST_NAMES = ['Adam', 'Bartek', 'Celina', 'Dawid', 'Ewa', 'Filip', 'Grzegorz', 'Hanna', 'Igor', 'Julia', 'Kamil', 'Lena',
        'Marek', 'Natalia', 'Oskar', 'Paulina', 'Radek', 'Sylwia', 'Tomasz', 'Urszula', 'Wiktor', 'Zofia', 'Jakub', 'Maja'];

    private const LAST_NAMES = ['Nowak', 'Kowalski', 'Wiśniewski', 'Wójcik', 'Kamiński', 'Lewandowski', 'Zieliński', 'Szymański', 'Woźniak',
        'Dąbrowski', 'Kozłowski', 'Jankowski', 'Mazur', 'Kwiatkowski', 'Krawczyk', 'Piotrowski', 'Grabowski', 'Pawlak'];

    /** Człony nazw zespołów: przydomek + miejsce (+ opcjonalny dopisek), razem kilka tysięcy kombinacji bez numerów. */
    private const TEAM_PREFIXES = ['Kolejorz', 'Kaczory', 'Wiara', 'Duma', 'Lwy', 'Orły', 'Sokoły', 'Wilki', 'Ekipa', 'Brygada', 'Załoga',
        'Fala', 'Huragan', 'Grom', 'Iskra', 'Płomień', 'Sztorm', 'Rakieta', 'Koziołki', 'Niebiescy', 'Bractwo', 'Gwardia', 'Szwadron', 'Sfora', 'Legion'];

    private const TEAM_PLACES = ['Rataje', 'Wilda', 'Jeżyce', 'Łazarz', 'Winogrady', 'Piątkowo', 'Dębiec', 'Naramowice', 'Smochowice', 'Junikowo',
        'Grunwald', 'Malta', 'Cytadela', 'Ostrów', 'Bułgarska', 'Chwaliszewo', 'Śródka', 'Starołęka', 'Podolany', 'Strzeszyn', 'Główna', 'Antoninek',
        'Kiekrz', 'Krzesiny', 'Luboń', 'Swarzędz', 'Kórnik', 'Mosina', 'Puszczykowo', 'Gniezno', 'Września', 'Śrem', 'Oborniki', 'Szamotuły',
        'Kościan', 'Leszno', 'Konin', 'Piła', 'Kalisz', 'Ostrzeszów'];

    private const TEAM_SUFFIXES = ['FC', 'United', 'Team', 'Squad', 'Ultras', 'Crew', '1922'];

    /**
     * Zdarzenia sezonu do podsumowania.
     *
     * @var array<int, string>
     */
    private array $log = [];

    private ?string $passwordHash = null;

    /** @var array<string, mixed>|null zajęte nazwy zespołów (małymi literami) */
    private ?array $takenNames = null;

    /** @var list<string>|null nieużyte nazwy zespołów (kluby, potem generator) */
    private ?array $clubNames = null;

    public function handle(SetUpSeason $setUp, SaveTip $saveTip, SaveMatchdayResult $results, FinishSeason $finish): int
    {
        if (app()->isProduction()) {
            $this->error('Symulacja tworzy dane testowe i nie działa na produkcji.');

            return self::FAILURE;
        }

        if ($this->option('seed') !== null) {
            mt_srand((int) $this->option('seed'));
        }

        if (Season::whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Approved->value])->exists()) {
            $this->error('Jest już aktywny albo zatwierdzony sezon. Zakończ go w panelu (albo odśwież bazę) i uruchom ponownie.');

            return self::FAILURE;
        }

        $this->renameNumberedTeams();

        // Pierwszy gwizdek teraz, ale nie wcześniej niż 4 dni po ostatnim meczu poprzedniego sezonu (odpoczynek i kolejność gwizdków).
        $start = now()->startOfMinute();
        $lastKickoff = Matchday::max('kickoff_at');
        if ($lastKickoff !== null) {
            $start = $start->max(Date::parse($lastKickoff)->addDays(self::DAYS_BETWEEN)->startOfMinute());
        }

        try {
            // Przygotowanie sezonu „godzinę wcześniej”, przed pierwszym gwizdkiem.
            Date::setTestNow($start->copy()->subHour());
            $season = $this->createSeason($start);
            $this->section('Sezon '.$season->title.': „'.$season->slogan.'”');
            $this->printCalendar($season);

            $setUpStats = $setUp->handle($season);
            $this->activate($season->refresh());
            $this->line(sprintf('Lista: %d graczy, %d lig, %d meczów w terminarzach.',
                $setUpStats['players'], $setUpStats['leagues'], $setUpStats['fixtures']));
            $this->printLeagueHumans($season);

            $banRound = mt_rand(1, 9);

            foreach (Matchday::where('season_id', $season->id)->orderBy('number')->get() as $matchday) {
                $this->playMatchday($matchday, $saveTip, $results, $banRound);
            }

            // Koniec sezonu chwilę po ostatnim meczu.
            $last = Matchday::where('season_id', $season->id)->orderByDesc('number')->firstOrFail();
            Date::setTestNow($last->kickoff_at?->copy()->addMinutes(2));

            $moves = $this->moveSummary($season);
            $finished = $finish->handle($season->refresh(), 'symulacja');
            $this->section('Koniec sezonu');
            $this->line(sprintf('Tabele końcowe: %d wpisów, nieaktywni (5 kolejek bez typu): %d.', $finished['standings'], $finished['inactive']));
            $this->printMoves($moves);
            $this->printTrophies($season);

            $added = $this->addUsers(mt_rand(10, 15) / 100, false);
            $this->line(sprintf('Nowe konta po sezonie: %d (trafią do Ligi podwórkowej w następnym sezonie).', $added));
        } catch (DomainException $e) {
            $this->error('Przerwano: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            Date::setTestNow();
        }

        $this->section('Podsumowanie');
        foreach ($this->log as $entry) {
            $this->line('• '.$entry);
        }
        $this->info('Następny sezon: php artisan sim:season');

        return self::SUCCESS;
    }

    /* ==================================================================
     | Przygotowanie sezonu
     * ================================================================*/

    private function createSeason(CarbonInterface $start): Season
    {
        $season = Season::create([
            'number' => (int) Season::max('number') + 1,
            'slogan' => self::SLOGANS[array_rand(self::SLOGANS)],
            'status' => SeasonStatus::Draft->value,
        ]);
        $season->createMissingMatchdays();
        Audit::log('season.created', null, [], ['number' => $season->number], $season->title.' (symulacja)');

        // Jedne puchary europejskie na sezon, oba mecze europejskie w nich.
        $europe = [MatchCompetition::ChampionsLeague, MatchCompetition::EuropaLeague, MatchCompetition::ConferenceLeague][mt_rand(0, 2)];
        $games = [];
        foreach (self::FRIENDLY as $opponent) {
            $games[] = [$opponent, MatchCompetition::Friendly];
        }
        foreach (self::LEAGUE as $opponent) {
            $games[] = [$opponent, MatchCompetition::League];
        }
        foreach (self::CUP as $opponent) {
            $games[] = [$opponent, MatchCompetition::PolishCup];
        }
        foreach (self::EUROPE as $opponent) {
            $games[] = [$opponent, $europe];
        }
        shuffle($games);

        foreach ($games as $index => [$opponent, $competition]) {
            $matchday = Matchday::where('season_id', $season->id)->where('number', $index + 1)->firstOrFail();
            $matchday->assignMatch(LechMatch::create([
                'opponent' => $opponent,
                'is_home' => (bool) mt_rand(0, 1),
                'competition' => $competition->value,
                'kickoff_at' => $start->copy()->addDays($index * self::DAYS_BETWEEN),
            ]));
        }

        return $season;
    }

    private function activate(Season $season): void
    {
        $season->update(['status' => SeasonStatus::Active]);
        Audit::log('season.activated', null, ['status' => SeasonStatus::Approved->label()], ['status' => SeasonStatus::Active->label()], $season->title);
        SystemFeed::record('seasons', 'Season :season started', ['season' => $season->title], 'results');
    }

    /* ==================================================================
     | Kolejka
     * ================================================================*/

    private function playMatchday(Matchday $matchday, SaveTip $saveTip, SaveMatchdayResult $results, int $banRound): void
    {
        $kickoff = $matchday->kickoff_at?->copy() ?? now();
        $europe = in_array($matchday->competition, [MatchCompetition::ChampionsLeague->value, MatchCompetition::EuropaLeague->value, MatchCompetition::ConferenceLeague->value], true);

        // Typy: 98-100% graczy z listy sezonu, w kolejności zapisu (wcześniejszy zapis = mniejszy numer), zawsze wygrana Lecha,
        // i 1-4 losowych dostępnych zawodników w każdym typie rozgrywek gracza.
        $players = User::whereIn('id', SeasonTeam::where('season_id', $matchday->season_id)->whereNotNull('user_id')->pluck('user_id'))->get();
        $share = mt_rand(98, 100) / 100;
        $tippers = $players->shuffle()->take((int) round($players->count() * $share));
        $leads = collect(range(1, max(1, $tippers->count())))->map(fn () => mt_rand(1, 600))->sortDesc()->values();
        $squad = Squad::available()->pluck('id')->all();
        $saved = 0;
        $failed = 0;
        $picked = 0;

        foreach ($tippers->values() as $index => $user) {
            /** @var User $user */
            Date::setTestNow($kickoff->copy()->subMinutes((int) $leads[$index]));
            [$lech, $opponent] = self::TIPS[array_rand(self::TIPS)];

            try {
                $saveTip->handle($user, $matchday, $lech, $opponent);
                $saved++;
                $picked += $this->pickPlayers($user, $matchday, $squad);
            } catch (DomainException) {
                $failed++;
            }
        }

        // Mecz Lecha z protokołem: wygrana, w pucharach europejskich wygrana albo remis.
        [$lechGoals, $opponentGoals, $scorers] = $this->playLechMatch($matchday, $europe);

        Date::setTestNow($kickoff->copy()->addSeconds(30));
        $stats = $results->handle($matchday->fresh() ?? $matchday, $lechGoals, $opponentGoals);

        $line = sprintf('Kolejka %d: %s %d:%d (%s) [%s] | typy %d/%d%s, zawodnicy %d | mecze %d, odpadli %d',
            $matchday->number,
            (string) $matchday->getAttribute('fixture'),
            // Wynik w kolejności meczu (gospodarz pierwszy).
            $matchday->is_home ? $lechGoals : $opponentGoals,
            $matchday->is_home ? $opponentGoals : $lechGoals,
            $matchday->competitionLabel() ?? '-',
            $scorers,
            $saved,
            $players->count(),
            $failed > 0 ? ", odrzucone {$failed}" : '',
            $picked,
            $stats['fixtures'],
            $stats['eliminated'],
        );

        // Nowi gracze w trakcie sezonu (1-5% wszystkich kont) trafiają od razu do Ligi podwórkowej.
        $added = $this->addUsers(mt_rand(1, 5) / 100, true);
        $line .= " | nowi gracze {$added}";

        if ($matchday->number === $banRound) {
            $line .= ' | '.$this->banSomebody();
        }

        $this->line($line);
    }

    /**
     * Gracz wystawia 1-4 losowych dostępnych (nieodpoczywających) zawodników w każdym swoim typie rozgrywek, każdego raz.
     *
     * @param  array<int, int>  $squad  id dostępnych zawodników
     */
    private function pickPlayers(User $user, Matchday $matchday, array $squad): int
    {
        $free = array_values(array_diff($squad, array_keys(Squad::locks($user->id, null, $matchday))));
        shuffle($free);
        $picked = 0;

        foreach (PlayerCompetitions::types($user->id, $matchday->season_id, $matchday->number) as $set) {
            foreach (array_splice($free, 0, mt_rand(1, 4)) as $playerId) {
                try {
                    Squad::toggle($user, $matchday, $set, $playerId);
                    $picked++;
                } catch (DomainException) {
                    // np. limit zawodników w typie rozgrywek
                }
            }
        }

        return $picked;
    }

    /* ==================================================================
     | Mecz Lecha: skład i protokół
     * ================================================================*/

    /**
     * Raport meczu Lecha: jedenastka z LINEUP, 10 rezerwowych (w tym bramkarz), 5 zmian w przerwie albo w 2. połowie
     * i protokół (gole z asystami, samobój, kartki, zmiany). Wynik: Lech wygrywa, w Europie wygrywa albo remisuje;
     * samobój to gol rywala.
     *
     * @return array{0: int, 1: int, 2: string} gole Lecha, gole rywala, strzelcy do raportu
     */
    private function playLechMatch(Matchday $matchday, bool $europe): array
    {
        $matchId = (int) $matchday->lech_match_id;
        MatchEvent::where('lech_match_id', $matchId)->delete();
        MatchAppearance::where('lech_match_id', $matchId)->delete();

        $squad = Squad::available();
        $starters = collect(self::LINEUP)
            ->map(fn (string $surname) => $squad->first(fn (LechPlayer $p) => Str::endsWith($p->name, ' '.$surname)))
            ->filter()
            ->values();
        $rest = $squad->reject(fn (LechPlayer $p) => $starters->contains('id', $p->id))->shuffle()->values();
        $keeper = $rest->first(fn (LechPlayer $p) => $p->position === PlayerPosition::Goalkeeper);
        $bench = collect($keeper ? [$keeper] : [])
            ->concat($rest->reject(fn (LechPlayer $p) => $p->id === $keeper?->id)->take(self::BENCH - ($keeper ? 1 : 0)))
            ->values();

        foreach ([[$starters, true], [$bench, false]] as [$group, $started]) {
            foreach ($group as $player) {
                MatchAppearance::create(['lech_match_id' => $matchId, 'lech_player_id' => $player->id, 'started' => $started,
                    'on_slot' => $started ? 0 : null, 'off_slot' => MatchReport::END_REGULAR]);
            }
        }

        // Zmiany: 5 zawodników z pola schodzi w przerwie (45) albo w trakcie 2. połowy (>45), wchodzą rezerwowi z pola.
        $goingOff = $starters->reject(fn (LechPlayer $p) => $p->position === PlayerPosition::Goalkeeper)->shuffle()->take(self::SUBSTITUTIONS)->values();
        $comingOn = $bench->reject(fn (LechPlayer $p) => $p->position === PlayerPosition::Goalkeeper)->shuffle()->take(self::SUBSTITUTIONS)->values();
        $subs = [];
        $on = $starters->mapWithKeys(fn (LechPlayer $p) => [$p->id => 0])->all();
        $subbedOff = [];
        foreach ($goingOff as $i => $player) {
            if (! isset($comingOn[$i])) {
                break;
            }
            $slot = mt_rand(2, 3);
            $subs[] = [$player->id, $comingOn[$i]->id, $slot];
            $on[$comingOn[$i]->id] = $slot;
            $subbedOff[$player->id] = true;
        }
        /** @var Collection<int, LechPlayer> $played */
        $played = $starters->concat($bench->filter(fn (LechPlayer $p) => isset($on[$p->id])))->values();

        // Wynik: samobój (gol rywala) wymaga co najmniej jednego gola rywala, a Lech dalej nie przegrywa.
        $ownGoal = mt_rand(1, 100) <= self::OWN_GOAL_CHANCE;
        if ($europe && mt_rand(0, 1) === 1) {
            $lechGoals = mt_rand($ownGoal ? 1 : 0, 3);
            $opponentGoals = $lechGoals;
        } else {
            $lechGoals = mt_rand($ownGoal ? 2 : 1, 4);
            $opponentGoals = mt_rand($ownGoal ? 1 : 0, $lechGoals - 1);
        }

        $event = fn (array $data) => MatchEvent::create(['lech_match_id' => $matchId] + $data);
        $scorers = [];

        for ($goal = 0; $goal < $lechGoals; $goal++) {
            $scorer = $this->weightedPlayer($played, self::GOAL_WEIGHTS);
            if (! $scorer) {
                break;
            }
            $assistant = mt_rand(1, 100) <= self::ASSIST_CHANCE
                ? $this->weightedPlayer($played->reject(fn (LechPlayer $p) => $p->id === $scorer->id), self::ASSIST_WEIGHTS)
                : null;
            $event(['type' => MatchReport::EVENT_GOAL, 'lech_player_id' => $scorer->id, 'other_player_id' => $assistant?->id]);
            $scorers[] = Str::afterLast($scorer->name, ' ');
        }

        if ($ownGoal && ($author = $played->reject(fn (LechPlayer $p) => $p->position === PlayerPosition::Goalkeeper)->random())) {
            $event(['type' => MatchReport::EVENT_OWN_GOAL, 'lech_player_id' => $author->id]);
            $scorers[] = Str::afterLast($author->name, ' ').' (sam.)';
        }

        // Kartki: 1-5 żółtych dla różnych zawodników; druga żółta i czerwona tylko dla grających do końca (zejście w 2. połowie
        // albo od wejścia do końca, zawodnik zmieniony nie wraca na boisko).
        $sentOff = [];
        $sendOffSlot = fn (LechPlayer $p) => mt_rand(max(1, $on[$p->id]), 3);
        foreach ($played->shuffle()->take(mt_rand(1, 5)) as $player) {
            $event(['type' => MatchReport::EVENT_CARD, 'lech_player_id' => $player->id, 'card' => 'yellow']);
            if (! isset($subbedOff[$player->id]) && mt_rand(1, 100) <= self::SECOND_YELLOW_CHANCE) {
                $event(['type' => MatchReport::EVENT_CARD, 'lech_player_id' => $player->id, 'card' => 'second_yellow', 'slot' => $sendOffSlot($player)]);
                $sentOff[$player->id] = true;
            }
        }
        if (mt_rand(1, 100) <= self::RED_CHANCE) {
            $candidates = $played->reject(fn (LechPlayer $p) => isset($subbedOff[$p->id]) || isset($sentOff[$p->id]));
            if ($candidates->isNotEmpty()) {
                $player = $candidates->random();
                $event(['type' => MatchReport::EVENT_CARD, 'lech_player_id' => $player->id, 'card' => 'red', 'slot' => $sendOffSlot($player)]);
            }
        }

        foreach ($subs as [$offId, $onId, $slot]) {
            $event(['type' => MatchReport::EVENT_SUB, 'lech_player_id' => $offId, 'other_player_id' => $onId, 'slot' => $slot]);
        }

        return [$lechGoals, $opponentGoals, $scorers === [] ? '-' : implode(', ', $scorers)];
    }

    /**
     * Losowy zawodnik z grających: najpierw pozycja według wag (procent), potem zawodnik tej pozycji.
     *
     * @param  Collection<int, LechPlayer>  $players
     * @param  array<string, int>  $weights  pozycja (PlayerPosition) => waga
     */
    private function weightedPlayer(Collection $players, array $weights): ?LechPlayer
    {
        $byPosition = $players->groupBy(fn (LechPlayer $p) => $p->position->value);
        $weights = array_filter($weights, fn (string $position) => $byPosition->has($position), ARRAY_FILTER_USE_KEY);

        if ($weights === []) {
            return $players->isEmpty() ? null : $players->random();
        }

        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $position => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $byPosition[$position]->random();
            }
        }

        return null;
    }

    /* ==================================================================
     | Gracze: nowe konta i ban
     * ================================================================*/

    /** Konta z wcześniejszych symulacji z nazwą w rodzaju „Dębiec Naramowice 34” dostają nową nazwę z puli. */
    private function renameNumberedTeams(): void
    {
        $renamed = 0;
        foreach (User::where('email', 'like', '%@sim.test')->whereNotNull('team_name')->get() as $user) {
            if (preg_match('/\s\d+$/u', (string) $user->team_name) === 1) {
                $user->forceFill(['team_name' => $this->uniqueTeamName()])->save();
                $renamed++;
            }
        }

        if ($renamed > 0) {
            $this->line("Zmienione nazwy zespołów z numerem: {$renamed}.");
        }
    }

    /**
     * Losuje nazwę zespołu (najpierw z puli prawdziwych klubów), której nie ma jeszcze żaden gracz ani bot (bez względu na wielkość liter).
     * Numer dopisuje dopiero po wyczerpaniu wszystkich kilku tysięcy nazw.
     */
    private function uniqueTeamName(): string
    {
        $this->takenNames ??= User::whereNotNull('team_name')->pluck('team_name')
            ->merge(Bot::pluck('name'))
            ->map(fn (string $name) => mb_strtolower($name))
            ->flip()
            ->all();

        // Kolejność: prawdziwe kluby (database/data/club-names.php), potem „przydomek miejsce”, potem z dopiskiem.
        // array_pop bierze od końca, więc kluby lądują na końcu listy.
        if ($this->clubNames === null) {
            /** @var list<string> $clubs */
            $clubs = require database_path('data/club-names.php');
            $plain = $suffixed = [];
            foreach (self::TEAM_PREFIXES as $prefix) {
                foreach (self::TEAM_PLACES as $place) {
                    $plain[] = $prefix.' '.$place;
                    foreach (self::TEAM_SUFFIXES as $suffix) {
                        $suffixed[] = $prefix.' '.$place.' '.$suffix;
                    }
                }
            }
            shuffle($clubs);
            shuffle($plain);
            shuffle($suffixed);
            $this->clubNames = array_merge($suffixed, $plain, $clubs);
        }

        while (($candidate = array_pop($this->clubNames)) !== null) {
            if (! isset($this->takenNames[mb_strtolower($candidate)])) {
                $this->takenNames[mb_strtolower($candidate)] = true;

                return $candidate;
            }
        }

        // Ostateczność (kilka tysięcy nazw zajętych): kolejny numer.
        $name = self::TEAM_PREFIXES[0].' '.self::TEAM_PLACES[0];
        for ($number = 2; isset($this->takenNames[mb_strtolower($name)]); $number++) {
            $name = self::TEAM_PREFIXES[0].' '.self::TEAM_PLACES[0].' '.$number;
        }

        $this->takenNames[mb_strtolower($name)] = true;

        return $name;
    }

    /** Dodaje nowe konta (ułamek wszystkich kont, co najmniej 1) z rolą User, część także z premium. */
    private function addUsers(float $share, bool $duringSeason): int
    {
        $count = max(1, (int) round(User::count() * $share));
        $this->passwordHash ??= Hash::make('password');

        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $number = (int) User::max('id') + 1;
            $team = $this->uniqueTeamName();

            $user = User::forceCreate([
                'name' => $first.' '.$last,
                'email' => Str::slug($first.'.'.$last).'.'.$number.'@sim.test',
                'password' => $this->passwordHash,
                'team_name' => $team,
                'email_verified_at' => now(),
            ]);

            $user->assignRole(RoleName::User->value);
            if (mt_rand(1, 100) <= 25) {
                Premium::extend($user, [7, 30, 90, 182, 365][mt_rand(0, 4)]);
            }

            SystemFeed::record('players', ':name joined the game', ['name' => $team], 'team.show', ['user' => $user->id], $user->id);

            if ($duringSeason) {
                Roster::syncUser($user);
            }
        }

        $this->log[] = ($duringSeason ? 'W trakcie sezonu' : 'Po sezonie').": +{$count} kont";

        return $count;
    }

    /** Ban jednego losowego gracza (nigdy admina): traci miejsce na rzecz bota, nierozliczone typy znikają. */
    private function banSomebody(): string
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', RoleName::User->value))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', [RoleName::Admin->value, RoleName::Banned->value]))
            ->inRandomOrder()
            ->first();

        if (! $user) {
            return 'ban: brak kandydata';
        }

        $user->syncRoles([RoleName::Banned->value]);
        Roster::syncUser($user);
        SystemFeed::record('moderation', ':name was banned', ['name' => $user->name], null, [], $user->id);
        Audit::log('roles.updated', $user, [], ['roles' => RoleName::Banned->value], 'symulacja');

        $this->log[] = 'Ban: '.($user->team_name ?: $user->name)." (#{$user->id})";

        return 'ban: '.($user->team_name ?: $user->name);
    }

    /* ==================================================================
     | Raport
     * ================================================================*/

    private function section(string $title): void
    {
        $this->newLine();
        $this->info('=== '.$title.' ===');
    }

    private function printCalendar(Season $season): void
    {
        $rows = Matchday::where('season_id', $season->id)->orderBy('number')->get()
            ->map(fn (Matchday $m): array => [$m->number, (string) $m->getAttribute('fixture'), $m->competitionLabel() ?? '-', (string) $m->kickoff_at?->format('d.m H:i')])
            ->all();
        $this->table(['#', 'Mecz', 'Rozgrywki', 'Gwizdek'], $rows);
    }

    /** Ilu ludzi i botów jest w każdej lidze na starcie (pokazuje efekt awansów i czyszczenia z botów). */
    private function printLeagueHumans(Season $season): void
    {
        $rows = [];
        foreach (Competition::where('season_id', $season->id)->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])->orderBy('tier')->get() as $competition) {
            $teams = SeasonTeam::whereIn('id', $competition->entries()->pluck('season_team_id'))->get(['user_id']);
            $humans = $teams->whereNotNull('user_id')->count();
            $rows[] = [$competition->name ?: $competition->type->label(), $humans, $teams->count() - $humans];
        }
        $this->table(['Liga', 'Ludzie', 'Boty'], $rows);
    }

    /**
     * Awanse i spadki po ostatniej kolejce (te same co przy budowie następnego sezonu), z rozbiciem na zamiany za boty:
     * liga => [awanse, spadki, zamiany].
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    private function moveSummary(Season $season): array
    {
        $moves = Standings::seasonMoves($season->id);
        $out = [];

        foreach (Competition::where('season_id', $season->id)->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])->orderBy('tier')->get() as $competition) {
            $table = Standings::for($competition)->values();
            $count = $table->count();
            $up = $down = $swaps = 0;

            foreach ($table as $index => $row) {
                $move = $moves[$row['team']->id] ?? null;
                $place = $index + 1;
                if ($move === 'up') {
                    $up++;
                    $swaps += (int) ($place > 4);
                } elseif ($move === 'down') {
                    $down++;
                    $swaps += (int) ($place <= $count - 4);
                }
            }

            $out[$competition->name ?: $competition->type->label()] = [$up, $down, $swaps];
        }

        return $out;
    }

    /** @param array<string, array{0: int, 1: int, 2: int}> $moves */
    private function printMoves(array $moves): void
    {
        $rows = [];
        $swaps = 0;
        foreach ($moves as $league => [$up, $down, $swap]) {
            $rows[] = [$league, $up, $down, $swap];
            $swaps += $swap;
        }
        $this->table(['Liga', 'Awanse', 'Spadki', 'W tym zamiany za boty'], $rows);
        $this->log[] = 'Zamiany z czyszczenia lig z botów: '.intdiv($swaps, 2);
    }

    private function printTrophies(Season $season): void
    {
        $names = HallOfFame::trophies();
        $awards = HallOfFameAward::with(['user:id,name,team_name', 'bot:id,name'])
            ->where('season_id', $season->id)
            ->whereNotNull('trophy')
            ->get()
            ->sortBy(fn (HallOfFameAward $a) => HallOfFame::trophyRank((string) $a->trophy));

        $rows = [];
        foreach ($awards as $award) {
            $user = $award->user;
            $bot = $award->bot;
            $rows[] = [
                $names[(string) $award->trophy] ?? (string) $award->trophy,
                $user instanceof User ? ($user->team_name ?: $user->name) : ($bot ? $bot->name.' [BOT]' : '-'),
            ];
        }

        $this->table(['Trofeum', 'Zdobywca'], $rows);
        $humans = HallOfFameAward::where('season_id', $season->id)->whereNotNull('trophy')->whereNotNull('user_id')->count();
        $this->log[] = 'Trofea: '.count($rows).", w tym ludzie {$humans}";
    }
}
