<?php

namespace App\Console\Commands;

use App\Actions\Results\SaveMatchdayResult;
use App\Actions\Seasons\FinishSeason;
use App\Actions\Seasons\SetUpSeason;
use App\Actions\Tips\SaveTip;
use App\Enums\CompetitionType;
use App\Enums\MatchCompetition;
use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\HallOfFameAward;
use App\Models\LechMatch;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use App\Support\Audit;
use App\Support\HallOfFame;
use App\Support\Premium;
use App\Support\Roster;
use App\Support\Standings;
use App\Support\SystemFeed;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
 *     pierwszy gwizdek w chwili uruchomienia, każdy następny minutę później,
 *  2. SetUpSeason (lista z poprzedniego sezonu z awansami, spadkami i czyszczeniem z botów, nowi gracze do podwórkowej,
 *     zatwierdzenie, pytania), potem aktywacja,
 *  3. w każdej kolejce: typy 90-100% graczy (SaveTip), wynik Lecha (w pucharach europejskich remis albo porażka,
 *     w pozostałych wygrana), losowe poprawne odpowiedzi i przeliczenie (SaveMatchdayResult), nowi gracze (1-5% kont),
 *     raz na sezon ban jednego gracza (bez admina),
 *  4. zakończenie sezonu (FinishSeason: tabele końcowe, Hall of Fame, nieaktywni) i 10-15% nowych kont na następny sezon.
 *
 * Czas: symulacja przestawia zegar aplikacji (Carbon::setTestNow), żeby typy były przed pierwszym gwizdkiem,
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

    private const TEAM_WORDS = ['Kolejorz', 'Kaczory', 'Wiara', 'Bułgarska', 'Rataje', 'Wilda', 'Jeżyce', 'Łazarz', 'Winogrady', 'Piątkowo',
        'Dębiec', 'Naramowice', 'Smochowice', 'Junikowo', 'Grunwald', 'Malta', 'Cytadela', 'Warta', 'Koziołki', 'Ostrów'];

    /** Zdarzenia sezonu do podsumowania. @var array<int, string> */
    private array $log = [];

    private ?string $passwordHash = null;

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

        $start = now()->startOfMinute();

        try {
            // Przygotowanie sezonu „godzinę wcześniej”, żeby pytania dało się losować przed pierwszym gwizdkiem.
            Carbon::setTestNow($start->copy()->subHour());
            $season = $this->createSeason($start);
            $this->section('Sezon '.$season->title.': „'.$season->slogan.'”');
            $this->printCalendar($season);

            $setUpStats = $setUp->handle($season);
            $this->activate($season->refresh());
            $this->line(sprintf('Lista: %d graczy, %d lig, %d meczów w terminarzach, %d pytań w kolejkach.',
                $setUpStats['players'], $setUpStats['leagues'], $setUpStats['fixtures'], $setUpStats['questions']));
            $this->printLeagueHumans($season);

            $banRound = mt_rand(1, 9);

            foreach (Matchday::where('season_id', $season->id)->orderBy('number')->get() as $matchday) {
                $this->playMatchday($matchday, $saveTip, $results, $banRound);
            }

            // Koniec sezonu chwilę po ostatnim meczu.
            $last = Matchday::where('season_id', $season->id)->orderByDesc('number')->firstOrFail();
            Carbon::setTestNow($last->kickoff_at?->copy()->addMinutes(2));

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
            Carbon::setTestNow();
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

    private function createSeason(Carbon $start): Season
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

        foreach (array_values($games) as $index => [$opponent, $competition]) {
            $matchday = Matchday::where('season_id', $season->id)->where('number', $index + 1)->firstOrFail();
            $matchday->assignMatch(LechMatch::create([
                'opponent' => $opponent,
                'is_home' => (bool) mt_rand(0, 1),
                'competition' => $competition->value,
                'kickoff_at' => $start->copy()->addMinutes($index),
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

        // Pytania kolejki pogrupowane na zestawy: typ rozgrywek + strona.
        $sets = MatchdayQuestion::where('matchday_id', $matchday->id)->get()
            ->groupBy(fn (MatchdayQuestion $q) => $q->competition_type->value.'-'.$q->side->value)
            ->map(fn ($questions) => $questions->pluck('id')->all())
            ->all();

        // Typy: 90-100% graczy z listy sezonu, w kolejności zapisu (wcześniejszy zapis = mniejszy numer).
        $players = User::whereIn('id', SeasonTeam::where('season_id', $matchday->season_id)->whereNotNull('user_id')->pluck('user_id'))->with('roles')->get();
        $share = mt_rand(90, 100) / 100;
        $tippers = $players->shuffle()->take((int) round($players->count() * $share));
        $leads = collect(range(1, max(1, $tippers->count())))->map(fn () => mt_rand(1, 600))->sortDesc()->values();
        $saved = 0;
        $failed = 0;

        foreach ($tippers->values() as $index => $user) {
            /** @var User $user */
            Carbon::setTestNow($kickoff->copy()->subMinutes((int) $leads[$index]));
            $isAdmin = $user->hasRole(RoleName::Admin->value);

            if ($isAdmin) {
                // Admin: zawsze zwycięstwo Lecha, a w pucharach europejskich remis albo porażka; jedna odpowiedź w każdym zestawie.
                if ($europe) {
                    $rival = mt_rand(0, 2);
                    [$lech, $opponent] = [mt_rand(0, $rival), $rival];
                } else {
                    $lech = mt_rand(1, 3);
                    $opponent = mt_rand(0, $lech - 1);
                }
                $answers = $this->answers($sets, 1, 1);
            } else {
                [$lech, $opponent] = [mt_rand(0, 2), mt_rand(0, 2)];
                $answers = $this->answers($sets, 0, 3);
            }

            try {
                $saveTip->handle($user, $matchday, $lech, $opponent, $answers);
                $saved++;
            } catch (DomainException) {
                $failed++;
            }
        }

        // Wynik Lecha: puchary europejskie remis albo porażka, pozostałe mecze wygrana.
        if ($europe) {
            $opponentGoals = mt_rand(0, 3);
            $lechGoals = mt_rand(0, $opponentGoals);
        } else {
            $lechGoals = mt_rand(1, 4);
            $opponentGoals = mt_rand(0, $lechGoals - 1);
        }
        $correct = collect($sets)->flatten()->mapWithKeys(fn ($id) => [$id => (string) mt_rand(0, 1)])->all();

        Carbon::setTestNow($kickoff->copy()->addSeconds(30));
        $stats = $results->handle($matchday->fresh() ?? $matchday, $lechGoals, $opponentGoals, $correct);

        $line = sprintf('Kolejka %d: %s %d:%d (%s) | typy %d/%d%s | mecze %d, odpadli %d',
            $matchday->number,
            $matchday->fixture,
            $lechGoals,
            $opponentGoals,
            $matchday->competitionLabel() ?? '-',
            $saved,
            $players->count(),
            $failed > 0 ? ", odrzucone {$failed}" : '',
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
     * Losowe odpowiedzi: w każdym zestawie (typ rozgrywek + strona) od $min do $max pytań, tak albo nie.
     *
     * @param  array<string, array<int, int>>  $sets
     * @return array<int, string>
     */
    private function answers(array $sets, int $min, int $max): array
    {
        $answers = [];
        foreach ($sets as $ids) {
            shuffle($ids);
            foreach (array_slice($ids, 0, mt_rand($min, min($max, count($ids)))) as $id) {
                $answers[$id] = (string) mt_rand(0, 1);
            }
        }

        return $answers;
    }

    /* ==================================================================
     | Gracze: nowe konta i ban
     * ================================================================*/

    /** Dodaje nowe konta (ułamek wszystkich kont, co najmniej 1) z rolą User, część także z premium. */
    private function addUsers(float $share, bool $duringSeason): int
    {
        $count = max(1, (int) round(User::count() * $share));
        $this->passwordHash ??= Hash::make('password');

        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $number = (int) User::max('id') + 1;
            $team = self::TEAM_WORDS[array_rand(self::TEAM_WORDS)].' '.self::TEAM_WORDS[array_rand(self::TEAM_WORDS)].' '.$number;

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
            ->map(fn (Matchday $m) => [$m->number, $m->fixture, $m->competitionLabel() ?? '-', $m->kickoff_at?->format('H:i')])
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
        $rows = HallOfFameAward::with(['user:id,name,team_name', 'bot:id,name'])
            ->where('season_id', $season->id)
            ->whereNotNull('trophy')
            ->get()
            ->sortBy(fn (HallOfFameAward $a) => HallOfFame::trophyRank((string) $a->trophy))
            ->map(fn (HallOfFameAward $a) => [
                $names[$a->trophy] ?? $a->trophy,
                $a->user ? ($a->user->team_name ?: $a->user->name) : ($a->bot?->name.' [BOT]'),
            ])
            ->values()
            ->all();

        $this->table(['Trofeum', 'Zdobywca'], $rows);
        $humans = HallOfFameAward::where('season_id', $season->id)->whereNotNull('trophy')->whereNotNull('user_id')->count();
        $this->log[] = 'Trofea: '.count($rows).", w tym ludzie {$humans}";
    }
}
