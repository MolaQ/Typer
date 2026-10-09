<?php

use App\Actions\Seasons\BuildListFromPrevious;
use App\Actions\Seasons\FillTeamListWithBots;
use App\Enums\League;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use App\Support\Audit;
use App\Support\Players;
use App\Support\Roster;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista przedsezonowa (regulamin, punkty 2 i 8).
 *  - lista powstaje automatycznie: gracze według kolejności rejestracji,
 *    pierwsze 10 miejsc to Ekstraklasa, następne 10 to I liga itd.,
 *    brakujące miejsca w pierwszych 100 zajmują boty,
 *  - admin poprawia ręcznie: przenosi gracza do innej ligi albo zmienia kolejność,
 *  - boty pochodzą z puli 512 botów (tabela bots, BotsSeeder), mają nazwy edytowalne w panelu,
 *  - przycisk "Uzupełnij botami" dopisuje wszystkie wolne boty (to samo dzieje się przy zatwierdzaniu sezonu),
 *  - edycja jest możliwa tylko, gdy sezon jest szkicem (po zatwierdzeniu lista jest zamknięta).
 * Uprawnienia: podgląd season-list, zmiany season-edit.
 */
new class extends Component {
    use WithPagination;

    // Wybrany sezon i liga (zapamiętane w adresie).
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    #[Url(as: 'league', except: 1)]
    public int $leagueTier = 1;

    // --- modal "przenieś do ligi" ---
    public ?int $moveId = null;
    public string $moveName = '';
    public int $moveTier = 1;
    public int $moveSlot = 0; // 0 = automatycznie, 1-10 = konkretne miejsce w lidze

    // --- modal "przypisz gracza do ligi" (gracze z rolą, którzy nie mają jeszcze miejsca na liście) ---
    public ?int $assignUserId = null;
    public string $assignName = '';
    public int $assignTier = 11;

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Team list'));
    }

    public function updatedSeasonId(): void
    {
        $this->resetPage('teamsPage');
    }

    public function updatedLeagueTier(): void
    {
        $this->resetPage('teamsPage');
    }

    /* ==================================================================
     | DANE DO WIDOKU
     * ================================================================*/

    #[Computed]
    public function seasons()
    {
        return Season::orderByDesc('number')->get();
    }

    #[Computed]
    public function season(): ?Season
    {
        return Season::find($this->seasonId);
    }

    /** Listę można zmieniać tylko w szkicu sezonu. */
    #[Computed]
    public function isEditable(): bool
    {
        return $this->season?->status === SeasonStatus::Draft;
    }

    /** Dopisywanie graczy do lig jest możliwe, dopóki sezon się nie zakończył (szkic, zatwierdzony, aktywny). */
    #[Computed]
    public function isOpen(): bool
    {
        return $this->season !== null && $this->season->status !== SeasonStatus::Finished;
    }

    /** Pierwsi gracze z rolą bez ligi (do podglądu na stronie). */
    #[Computed]
    public function unlistedPreview()
    {
        return $this->unlistedPlayers()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'name', 'email', 'team_name']);
    }

    #[Computed]
    public function selectedLeague(): League
    {
        return League::tryFrom($this->leagueTier) ?? League::Ekstraklasa;
    }

    /** Podsumowanie: wszystkie zespoły, gracze, boty i gracze spoza listy. */
    #[Computed]
    public function stats(): array
    {
        $teams = SeasonTeam::where('season_id', $this->seasonId);

        return [
            'total' => (clone $teams)->count(),
            'players' => (clone $teams)->whereNotNull('user_id')->count(),
            'bots' => (clone $teams)->whereNull('user_id')->count(),
            'unlisted' => $this->unlistedPlayers()->count(),
            // Użytkownicy bez żadnej roli: czekają na zatwierdzenie konta przez admina.
            'noRole' => Players::withoutRole()->count(),
            // Boty z puli, których nie ma jeszcze na liście tego sezonu.
            'freeBots' => Bot::whereNotIn('id', SeasonTeam::where('season_id', $this->seasonId)->whereNotNull('bot_id')->select('bot_id'))->count(),
        ];
    }

    /** Liczba zespołów w każdej lidze (do listy wyboru). */
    #[Computed]
    public function leagueCounts(): array
    {
        $rows = [];

        foreach (League::cases() as $league) {
            $rows[] = [
                'league' => $league,
                'total' => SeasonTeam::where('season_id', $this->seasonId)->inLeague($league)->count(),
            ];
        }

        return $rows;
    }

    /** Zespoły wybranej ligi (liga podwórkowa jest stronicowana). */
    #[Computed]
    public function teams()
    {
        return SeasonTeam::query()
            ->with(['user:id,name,email,team_name,team_short_name,team_abbr', 'bot:id,name,sort_order'])
            ->where('season_id', $this->seasonId)
            ->inLeague($this->selectedLeague)
            ->orderBy('position')
            ->paginate(25, pageName: 'teamsPage');
    }

    /* ==================================================================
     | BUDOWA LISTY
     * ================================================================*/

    /**
     * Buduje listę od zera z zarejestrowanych graczy (konta z nazwą zespołu).
     * Kolejność rejestracji = kolejność na liście. Jeśli graczy jest mniej niż 100,
     * resztę pierwszych 100 miejsc zajmują boty. Wszystko w jednej transakcji.
     */
    public function buildList(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable() || !$this->ensurePreviousFinished()) {
            return;
        }

        if (SeasonTeam::where('season_id', $this->seasonId)->exists()) {
            Flux::toast(text: __('The list already exists. Clear it first to build it again.'), variant: 'warning');

            return;
        }

        // Na listę trafiają tylko użytkownicy z rolą (konto zatwierdzone przez admina).
        $playerIds = Players::eligible()->orderBy('created_at')->orderBy('id')->pluck('id');

        // Ile botów potrzeba, żeby pierwsze 100 miejsc było pełne.
        $needed = max(0, League::TOP_TEAMS - $playerIds->count());
        $bots = $this->freeBots($needed);

        if ($bots->count() < $needed) {
            Flux::toast(text: __('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'), variant: 'danger');

            return;
        }

        DB::transaction(function () use ($playerIds, $bots): void {
            $now = now();
            $rows = [];
            $position = 0;

            foreach ($playerIds as $userId) {
                $rows[] = $this->row(++$position, $userId, null, $now);
            }

            foreach ($bots as $bot) {
                $rows[] = $this->row(++$position, null, $bot->id, $now);
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SeasonTeam::insert($chunk);
            }
        });

        $botCount = $bots->count();

        Audit::log('season_list.built', null, [], ['players' => $playerIds->count(), 'bots' => $botCount], $this->season->title);

        $this->clearCaches();
        Flux::toast(text: __('Team list built.'), variant: 'success');
    }

    /** Poprzedni zakończony sezon z tabelami końcowymi (lista może powstać z jego wyników). */
    #[Computed]
    public function previousSeason(): ?Season
    {
        return $this->season ? BuildListFromPrevious::previousSeason($this->season) : null;
    }

    /** Buduje listę z wyników poprzedniego sezonu: awanse, spadki i czyszczenie lig z botów. */
    public function buildFromPrevious(BuildListFromPrevious $action): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable() || !$this->ensurePreviousFinished()) {
            return;
        }

        try {
            $stats = $action->handle($this->season);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->clearCaches();
        Flux::toast(text: __('Team list built from :season: :players players, :bots bots.', ['season' => $this->previousSeason?->title, 'players' => $stats['players'], 'bots' => $stats['bots']]), variant: 'success');
    }

    /**
     * Dopisuje na koniec (do ligi podwórkowej) graczy zarejestrowanych po zbudowaniu listy.
     * Admin może ich potem przenieść do wybranej ligi.
     */
    public function addNewPlayers(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $ids = $this->unlistedPlayers()->orderBy('created_at')->orderBy('id')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Każdy gracz zajmuje miejsce najwyżej sklasyfikowanego bota w lidze podwórkowej
        // (bot przechodzi na koniec listy), a gdy tam nie ma botów, trafia na koniec listy.
        DB::transaction(function () use ($ids): void {
            foreach ($ids as $userId) {
                $this->placePlayer($userId, League::Podworkowa);
            }
        });

        Audit::log('season_list.players_added', null, [], ['players' => $ids->count()], $this->season->title);

        $this->clearCaches();
        Flux::toast(text: __('Players added to the list.'), variant: 'success');
    }

    /**
     * Dopisuje WSZYSTKIE wolne boty z puli (luki w numeracji najpierw, potem koniec listy).
     * Pierwsze 512 miejsc to Puchar Polski, reszta trafia do ligi podwórkowej.
     * Zatwierdzenie sezonu robi to samo automatycznie (App\Actions\Seasons\ApproveSeason).
     */
    public function fillWithBots(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $added = app(FillTeamListWithBots::class)->handle($this->season);

        if ($added === 0) {
            Flux::toast(text: __('All bots are already on the list.'), variant: 'info');

            return;
        }

        Audit::log('season_list.bots_added', null, [], ['bots' => $added], $this->season->title);

        $this->clearCaches();
        Flux::toast(text: __(':count bots added to the list.', ['count' => $added]), variant: 'success');
    }

    /* ==================================================================
     | PRZYPISANIE GRACZA DO LIGI (także po zatwierdzeniu sezonu)
     * ================================================================*/

    public function openAssign(int $userId): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $user = $this->unlistedPlayers()->find($userId);

        if (!$user) {
            Flux::toast(text: __('This player is already on the list.'), variant: 'warning');
            $this->clearCaches();

            return;
        }

        $this->assignUserId = $user->id;
        $this->assignName = $user->team_name ?: $user->name;
        $this->assignTier = League::Podworkowa->value;

        Flux::modal('assign-player')->show();
    }

    /**
     * Przypisuje gracza do wybranej ligi wg tej samej zasady co ręczne przenoszenie:
     * gracz zajmuje miejsce najwyżej sklasyfikowanego bota w tej lidze, a bot przechodzi na koniec listy (Liga podwórkowa).
     * Po zatwierdzeniu sezonu zmienia się tylko właściciel miejsca, więc terminarz zostaje.
     * W lidze 1-10 bez botów nic się nie zmienia, w podwórkowej gracz idzie na koniec listy.
     */
    public function assignPlayer(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $league = League::tryFrom($this->assignTier);
        abort_if($league === null, 422);

        $user = $this->unlistedPlayers()->find($this->assignUserId);

        if (!$user) {
            Flux::modal('assign-player')->close();
            $this->resetAssign();
            $this->clearCaches();
            Flux::toast(text: __('This player is already on the list.'), variant: 'warning');

            return;
        }

        $placed = DB::transaction(fn() => $this->placePlayer($user->id, $league));

        if (!$placed) {
            Flux::toast(text: __('There are no bots in :league. Nothing was changed.', ['league' => $league->label()]), variant: 'warning');

            return;
        }

        Audit::log('season_list.player_assigned', $user, [], ['league' => $league->label()], ($user->team_name ?: $user->name) . ' (' . $this->season->title . ')');

        Flux::modal('assign-player')->close();
        $this->resetAssign();
        $this->clearCaches();
        Flux::toast(text: __('Player assigned to :league.', ['league' => $league->label()]), variant: 'success');
    }

    public function resetAssign(): void
    {
        $this->reset('assignUserId', 'assignName', 'assignTier');
    }

    public function confirmReset(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if ($this->ensureEditable()) {
            Flux::modal('reset-list')->show();
        }
    }

    /** Czyści całą listę sezonu (można ją potem zbudować od nowa). */
    public function resetList(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $count = SeasonTeam::where('season_id', $this->seasonId)->count();
        SeasonTeam::where('season_id', $this->seasonId)->delete();

        Audit::log('season_list.reset', null, ['teams' => $count], [], $this->season->title);

        Flux::modal('reset-list')->close();
        $this->clearCaches();
        Flux::toast(text: __('Team list cleared.'), variant: 'success');
    }

    /* ==================================================================
     | RĘCZNE POPRAWKI
     * ================================================================*/

    public function openMove(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $team = $this->findTeam($id);

        $this->moveId = $team->id;
        $this->moveName = $team->name;
        $this->moveTier = $team->league->value;
        $this->moveSlot = 0;

        Flux::modal('move-team')->show();
    }

    /**
     * Przenosi gracza do wybranej ligi (regulamin, punkt 8):
     *  - do ligi 1-10: gracz zajmuje miejsce najwyżej sklasyfikowanego bota tej ligi,
     *    a boty pomiędzy przesuwają się kaskadowo o jedno miejsce bota (cascadeBots). Jeśli nie ma tam botów,
     *    nic się nie dzieje (admin dostaje ostrzeżenie),
     *  - do ligi podwórkowej: tak samo, a gdy nie ma tam botów, gracz trafia na koniec
     *    listy, a jego dawne miejsce w lidze zajmuje wolny bot (liga ma nadal 10 zespołów).
     */
    public function moveTeam(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $team = $this->findTeam((int) $this->moveId);

        $target = League::tryFrom($this->moveTier);
        abort_if($target === null, 422);

        $from = $team->league;
        $oldPosition = $team->position;

        // Konkretne miejsce: zamiana z zespołem, który tam stoi (gracz z graczem, gracz z botem...).
        if ($this->moveSlot > 0) {
            $this->moveToSlot($team, $target, min($this->moveSlot, League::SIZE));

            return;
        }

        if ($target === $from) {
            Flux::modal('move-team')->close();
            $this->resetMove();

            return;
        }

        try {
            $moved = DB::transaction(function () use ($team, $target, $oldPosition): bool {
                // Najwyżej sklasyfikowany bot w docelowej lidze = najniższy numer pozycji.
                $bot = $this->bestBot($target, lock: true);

                if ($bot) {
                    // Gracz zajmuje miejsce bota, a boty przesuwają się kolejno o jedno miejsce bota. Lista nie ma luk.
                    $this->cascadeBots($team, $bot);

                    return true;
                }

                // Brak botów. W lidze podwórkowej to nie przeszkoda: gracz idzie na koniec listy,
                // a jego dawne miejsce w lidze zajmuje wolny bot z puli.
                if ($target === League::Podworkowa) {
                    $end = max(League::TOP_TEAMS, (int) SeasonTeam::where('season_id', $this->seasonId)->max('position')) + 1;

                    $team->update(['position' => $end]);
                    $this->createBot($oldPosition);

                    return true;
                }

                return false;
            });
        } catch (\RuntimeException) {
            Flux::toast(text: __('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'), variant: 'danger');

            return;
        }

        if (!$moved) {
            Flux::toast(text: __('There are no bots in :league. Nothing was changed.', ['league' => $target->label()]), variant: 'warning');

            return;
        }

        Audit::log('season_list.moved', null, ['league' => $from->label(), 'position' => $oldPosition], ['league' => $target->label(), 'position' => $team->fresh()->position], $team->name . ' (' . $this->season->title . ')');

        Flux::modal('move-team')->close();
        $this->resetMove();
        $this->clearCaches();
        Flux::toast(text: __('Team moved.'), variant: 'success');
    }

    /**
     * Swobodne przeniesienie: zespół zamienia się miejscem z tym, który stoi na wskazanym
     * miejscu wybranej ligi (1 = pierwszy w lidze). Działa także w obrębie jednej ligi.
     */
    private function moveToSlot(SeasonTeam $team, League $target, int $slot): void
    {
        $from = $team->league;
        $oldPosition = $team->position;
        $targetPosition = $target->firstPosition() + $slot - 1;

        if ($targetPosition === $oldPosition) {
            Flux::modal('move-team')->close();
            $this->resetMove();

            return;
        }

        $moved = DB::transaction(function () use ($team, $targetPosition): bool {
            $other = SeasonTeam::where('season_id', $this->seasonId)->where('position', $targetPosition)->lockForUpdate()->first();

            if (!$other) {
                return false;
            }

            $this->swapPositions($team, $other);

            return true;
        });

        if (!$moved) {
            Flux::toast(text: __('There is no team at that place of the list.'), variant: 'warning');

            return;
        }

        Audit::log('season_list.moved', null, ['league' => $from->label(), 'position' => $oldPosition], ['league' => $target->label(), 'position' => $targetPosition], $team->name . ' (' . $this->season->title . ')');

        Flux::modal('move-team')->close();
        $this->resetMove();
        $this->clearCaches();
        Flux::toast(text: __('Team moved.'), variant: 'success');
    }

    public function moveUp(int $id): void
    {
        $this->shift($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->shift($id, 1);
    }

    /** Czyści stan modala (wywoływane także przez @close). */
    public function resetMove(): void
    {
        $this->reset('moveId', 'moveName', 'moveTier', 'moveSlot');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    /**
     * Zamienia miejscami zespół z sąsiadem w tej samej lidze.
     * Kierunek -1 to w górę (wyżej na liście), 1 to w dół.
     */
    private function shift(int $id, int $direction): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $team = $this->findTeam($id);

        $query = SeasonTeam::where('season_id', $this->seasonId)->inLeague($team->league);

        $neighbour = $direction < 0 ? $query->where('position', '<', $team->position)->orderByDesc('position')->first() : $query->where('position', '>', $team->position)->orderBy('position')->first();

        if (!$neighbour) {
            return;
        }

        $from = $team->position;
        $to = $neighbour->position;

        DB::transaction(fn() => $this->swapPositions($team, $neighbour));

        Audit::log('season_list.reordered', null, ['position' => $from], ['position' => $to], $team->name . ' (' . $this->season->title . ')');

        unset($this->teams);
    }

    /** Dodaje wolnego bota z puli na wskazanej pozycji. Brak botów w puli = wyjątek. */
    private function createBot(int $position): void
    {
        $bot = $this->freeBots(1)->first();

        if (!$bot) {
            throw new \RuntimeException('no-free-bots');
        }

        SeasonTeam::create([
            'season_id' => $this->seasonId,
            'position' => $position,
            'user_id' => null,
            'bot_id' => $bot->id,
        ]);
    }

    /** Boty z puli, które nie występują jeszcze na liście tego sezonu (po numerze slotu). */
    private function freeBots(int $limit)
    {
        if ($limit <= 0) {
            return collect();
        }

        return Bot::query()
            ->whereNotIn('id', SeasonTeam::where('season_id', $this->seasonId)->whereNotNull('bot_id')->select('bot_id'))
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    /** Najwyżej sklasyfikowany bot (najniższy numer pozycji) w danej lidze albo null. */
    private function bestBot(League $league, bool $lock = false): ?SeasonTeam
    {
        $query = SeasonTeam::where('season_id', $this->seasonId)->whereNull('user_id')->inLeague($league)->orderBy('position');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Gracz wskakuje na miejsce bota, a boty między tym miejscem a dawnym miejscem gracza przesuwają się
     * kaskadowo: wyparty bot zajmuje miejsce kolejnego bota w rankingu, ten następnego itd., a ostatni
     * trafia na zwolnione miejsce gracza. Zespoły ludzi pomiędzy zostają na swoich miejscach.
     */
    private function cascadeBots(SeasonTeam $team, SeasonTeam $bot): void
    {
        $from = $team->position;
        $to = $bot->position;
        $up = $to < $from;

        // Boty na drodze między miejscem docelowym a dawnym miejscem gracza (w kolejności przesuwania).
        $chain = SeasonTeam::where('season_id', $this->seasonId)
            ->whereNull('user_id')
            ->whereKeyNot($team->id)
            ->whereBetween('position', [min($from, $to), max($from, $to)])
            ->orderBy('position', $up ? 'asc' : 'desc')
            ->lockForUpdate()
            ->get();

        // Miejsca, które zajmą kolejne boty: miejsce następnego bota w łańcuchu, ostatni dostaje miejsce gracza.
        $targets = $chain->pluck('position')->slice(1)->push($from)->values();

        // Najpierw pozycje tymczasowe (ujemne), żeby nie złamać unikalności, potem docelowe.
        $team->update(['position' => -$team->id]);
        foreach ($chain as $item) {
            $item->update(['position' => -$item->id - 1000000]);
        }

        $team->update(['position' => $to]);
        foreach ($chain->values() as $i => $item) {
            $item->update(['position' => $targets[$i]]);
        }
    }

    /** Zamienia miejscami dwa zespoły (chwilowo pozycja -1, żeby nie złamać unikalności). */
    private function swapPositions(SeasonTeam $a, SeasonTeam $b): void
    {
        $positionA = $a->position;
        $positionB = $b->position;

        $b->update(['position' => -1]);
        $a->update(['position' => $positionB]);
        $b->update(['position' => $positionA]);
    }

    /** Gracze (użytkownicy z rolą), których nie ma jeszcze na liście sezonu. */
    private function unlistedPlayers()
    {
        return Players::unlisted($this->seasonId);
    }

    /**
     * Wstawia gracza do ligi: przejmuje miejsce najwyżej sklasyfikowanego bota.
     * Liga podwórkowa bez botów: gracz na koniec listy. Liga 1-10 bez botów: false.
     */
    private function placePlayer(int $userId, League $league): bool
    {
        return Roster::place($this->seasonId, $userId, $league);
    }

    /** Wiersz do masowego wstawiania (insert() nie ustawia znaczników czasu sam). */
    private function row(int $position, ?int $userId, ?int $botId, $now): array
    {
        return [
            'season_id' => $this->seasonId,
            'position' => $position,
            'user_id' => $userId,
            'bot_id' => $botId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Zespół musi należeć do wybranego sezonu (zabezpieczenie przed podmianą ID). */
    private function findTeam(int $id): SeasonTeam
    {
        return SeasonTeam::with(['user', 'bot'])
            ->where('season_id', $this->seasonId)
            ->findOrFail($id);
    }

    /** Dopisywanie graczy działa też po zatwierdzeniu, ale nie w zakończonym sezonie. */
    private function ensureOpen(): bool
    {
        if (!$this->isOpen) {
            Flux::toast(text: __('The season is finished, the list cannot be changed.'), variant: 'warning');

            return false;
        }

        return true;
    }

    private function ensureEditable(): bool
    {
        if (!$this->season) {
            return false;
        }

        if (!$this->isEditable) {
            Flux::toast(text: __('The team list can only be changed while the season is a draft.'), variant: 'warning');

            return false;
        }

        return true;
    }

    /**
     * Wcześniejszy sezon, który jeszcze trwa. Lista nowego sezonu musi powstać z jego tabel końcowych
     * (awanse i spadki, regulamin pkt 9), więc dopóki go nie zakończymy, listy nie budujemy.
     * Inaczej aktywacja nowego sezonu zakończyłaby stary, a lista zostałaby ułożona według rejestracji.
     */
    #[Computed]
    public function unfinishedPrevious(): ?Season
    {
        if (!$this->season) {
            return null;
        }

        return Season::where('number', '<', $this->season->number)
            ->whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Approved->value])
            ->orderByDesc('number')
            ->first();
    }

    /** Blokada budowy listy, dopóki poprzedni sezon trwa. */
    private function ensurePreviousFinished(): bool
    {
        if ($this->unfinishedPrevious) {
            Flux::toast(text: __('Finish :season first. The new list is built from its final standings (promotion and relegation).', ['season' => $this->unfinishedPrevious->title]), variant: 'warning');

            return false;
        }

        return true;
    }

    private function clearCaches(): void
    {
        unset($this->teams, $this->stats, $this->leagueCounts, $this->unlistedPreview);
    }

    /** Akcje Livewire to osobne żądania, więc uprawnienie sprawdzamy w każdej z nich. */
    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    {{-- Nagłówek i wybór sezonu --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Team list') }}</flux:heading>
            <flux:subheading>{{ __('The pre-season list decides the leagues and who is the favourite in a pair.') }}
            </flux:subheading>
        </div>

        @if ($this->seasons->isNotEmpty())
            <div class="w-full sm:w-56">
                <flux:select wire:model.live="seasonId" :label="__('Season')">
                    @foreach ($this->seasons as $option)
                        <flux:select.option :value="$option->id">
                            {{ $option->title }} ({{ $option->status->label() }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    @if (!$this->season)
        <flux:card class="space-y-4">
            <flux:heading>{{ __('Create a season first.') }}</flux:heading>
            <div>
                <flux:button variant="primary" :href="route('dashboard.seasons')" wire:navigate>
                    {{ __('Go to season setup') }}
                </flux:button>
            </div>
        </flux:card>
    @else
        {{-- Statystyki --}}
        <div class="grid gap-4 md:grid-cols-3">
            <flux:card class="flex items-center gap-4">
                <flux:icon.list-bullet class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Teams on the list') }}</flux:text>
                    <flux:heading size="xl">{{ $this->stats['total'] }}</flux:heading>
                </div>
            </flux:card>
            <flux:card class="flex items-center gap-4">
                <flux:icon.users class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Players') }}</flux:text>
                    <flux:heading size="xl">{{ $this->stats['players'] }}</flux:heading>
                </div>
            </flux:card>
            <flux:card class="flex items-center gap-4">
                <flux:icon.cpu-chip class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Bots') }}</flux:text>
                    <flux:heading size="xl">{{ $this->stats['bots'] }}</flux:heading>
                </div>
            </flux:card>
        </div>

        @if (!$this->isEditable)
            <flux:text class="text-amber-600 dark:text-amber-400">
                {{ __('The team list can only be changed while the season is a draft.') }}
                @if ($this->season->status === \App\Enums\SeasonStatus::Approved)
                    {{ __('To change it, revert the approval in Season setup.') }}
                @endif
            </flux:text>
        @endif

        {{-- Użytkownicy bez roli: nie są graczami, dopóki admin nie nada im roli --}}
        @if ($this->stats['noRole'] > 0 && $this->isOpen)
            <flux:card class="flex flex-wrap items-center justify-between gap-4">
                <flux:text>
                    {{ __(':count users have no role yet. They are not on the list until an administrator gives them a role.', ['count' => $this->stats['noRole']]) }}
                </flux:text>

                @if (auth()->user()->hasRole('Admin'))
                    <flux:button size="sm" :href="route('dashboard.users')" wire:navigate>
                        {{ __('Go to users') }}
                    </flux:button>
                @endif
            </flux:card>
        @endif

        @if ($this->stats['total'] === 0)
            {{-- Pusta lista: jeden przycisk buduje ją z zarejestrowanych graczy --}}
            <flux:card class="space-y-4">
                <flux:heading>{{ __('The list is empty.') }}</flux:heading>
                <flux:text>
                    {{ __('The list is built from players with a role in order of registration: the first 10 go to Ekstraklasa, the next 10 to I liga and so on. Missing places in the first 100 are filled by bots. Later players go to Liga podwórkowa.') }}
                </flux:text>

                @if ($this->isEditable && $this->previousSeason)
                    <flux:text>
                        {{ __('You can also build the list from the final standings of :season: 4 teams go up and down between leagues, bots are swapped for players, inactive players are replaced by bots.', ['season' => $this->previousSeason->title]) }}
                    </flux:text>
                @endif

                @if ($this->isEditable && $this->unfinishedPrevious)
                    {{-- Poprzedni sezon trwa: lista powstanie z jego tabel końcowych dopiero po zakończeniu --}}
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Finish :season first. The new list is built from its final standings (promotion and relegation).', ['season' => $this->unfinishedPrevious->title])" />
                @elseif ($this->isEditable)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <div class="flex flex-wrap gap-2">
                            @if ($this->previousSeason)
                                <flux:button variant="primary" icon="arrows-up-down" wire:click="buildFromPrevious">
                                    <span wire:loading.remove wire:target="buildFromPrevious">{{ __('Build from the previous season') }}</span>
                                    <span wire:loading wire:target="buildFromPrevious">{{ __('Saving...') }}</span>
                                </flux:button>
                            @endif
                            <flux:button :variant="$this->previousSeason ? 'filled' : 'primary'" icon="bolt" wire:click="buildList">
                                <span wire:loading.remove
                                    wire:target="buildList">{{ __('Build the list automatically') }}</span>
                                <span wire:loading wire:target="buildList">{{ __('Saving...') }}</span>
                            </flux:button>
                        </div>
                    @endcan
                @endif
            </flux:card>
        @else
            {{-- Gracze z rolą, którzy nie mają jeszcze ligi --}}
            @if ($this->stats['unlisted'] > 0 && $this->isOpen)
                <flux:card class="space-y-4 border-amber-300 dark:border-amber-500/50">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <flux:heading>
                                {{ __(':count players with a role have no league yet.', ['count' => $this->stats['unlisted']]) }}
                            </flux:heading>
                            <flux:text class="text-sm">
                                {{ $this->isEditable ? __('Assign them to a league before approving the season.') : __('Assign each of them to a league.') }}
                            </flux:text>
                        </div>

                        @if ($this->isEditable)
                            @can(\App\Enums\Permission::SeasonEdit->value)
                                <flux:button size="sm" icon="plus" wire:click="addNewPlayers">
                                    {{ __('Add them all to Liga podwórkowa') }}
                                </flux:button>
                            @endcan
                        @endif
                    </div>

                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->unlistedPreview as $player)
                            <li class="flex items-center justify-between gap-3 py-2"
                                wire:key="unlisted-{{ $player->id }}">
                                <div class="min-w-0">
                                    <div class="truncate">{{ $player->team_name ?: $player->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $player->name }}
                                        ({{ $player->email }})
                                    </div>
                                </div>

                                @can(\App\Enums\Permission::SeasonEdit->value)
                                    <flux:button size="sm" icon="arrows-right-left"
                                        wire:click="openAssign({{ $player->id }})">
                                        {{ __('Assign to a league') }}
                                    </flux:button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->stats['unlisted'] > 20)
                        <flux:text class="text-xs">{{ __('Showing the first 20 players.') }}</flux:text>
                    @endif
                </flux:card>
            @endif

            {{-- Uzupełnienie wszystkimi botami z puli --}}
            @if ($this->stats['freeBots'] > 0 && $this->isEditable)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:card class="flex flex-wrap items-center justify-between gap-4">
                        <flux:text>
                            {{ __(':count bots are not on the list yet. Add them all (this also happens when the season is approved).', ['count' => $this->stats['freeBots']]) }}
                        </flux:text>
                        <flux:button size="sm" icon="cpu-chip" wire:click="fillWithBots">
                            <span wire:loading.remove wire:target="fillWithBots">{{ __('Fill with bots') }}</span>
                            <span wire:loading wire:target="fillWithBots">{{ __('Saving...') }}</span>
                        </flux:button>
                    </flux:card>
                @endcan
            @endif

            {{-- Wybór ligi --}}
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="w-full sm:w-64">
                    <flux:select wire:model.live="leagueTier" :label="__('League')">
                        @foreach ($this->leagueCounts as $row)
                            <flux:select.option :value="$row['league']->value">
                                {{ $row['league']->label() }} ({{ $row['total'] }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->isEditable)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmReset">
                            {{ __('Clear the list') }}
                        </flux:button>
                    @endcan
                @endif
            </div>

            @php
                $teams = $this->teams;
            @endphp

            <flux:table :paginate="$teams">
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>{{ __('Team') }}</flux:table.column>
                    <flux:table.column>{{ __('Owner') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($teams as $team)
                        @php
                            // Miejsce w lidze: w ligach 1-10 z pozycji, w podwórkowej z numeru wiersza.
                            $rank = $this->selectedLeague->isTop()
                                ? $team->position - $this->selectedLeague->firstPosition() + 1
                                : $teams->firstItem() + $loop->index;
                            $isFirst = $loop->first && $teams->onFirstPage();
                            $isLast = $loop->last && !$teams->hasMorePages();
                        @endphp

                        <flux:table.row :key="'team-'.$team->id">
                            <flux:table.cell variant="strong">{{ $rank }}</flux:table.cell>

                            <flux:table.cell>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>{{ $team->name }}</span>
                                    @if ($team->is_bot)
                                        <flux:badge size="sm" color="zinc">{{ __('Bot') }}</flux:badge>
                                    @elseif ($team->user?->team_abbr)
                                        <flux:badge size="sm" color="blue">{{ $team->user->team_abbr }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="text-zinc-500">
                                @if ($team->user)
                                    {{ $team->user->name }}
                                    <span class="text-xs">({{ $team->user->email }})</span>
                                @else
                                    —
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @if ($this->isEditable)
                                    @can(\App\Enums\Permission::SeasonEdit->value)
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:button variant="ghost" size="sm" icon="chevron-up" inset="top bottom"
                                                :disabled="$isFirst" wire:click="moveUp({{ $team->id }})"
                                                :aria-label="__('Move up')" />
                                            <flux:button variant="ghost" size="sm" icon="chevron-down"
                                                inset="top bottom" :disabled="$isLast"
                                                wire:click="moveDown({{ $team->id }})"
                                                :aria-label="__('Move down')" />

                                            <flux:button variant="ghost" size="sm" icon="arrows-right-left"
                                                inset="top bottom" wire:click="openMove({{ $team->id }})"
                                                :aria-label="__('Move to another league')" />
                                        </div>
                                    @endcan
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="py-10 text-center text-zinc-500">
                                {{ __('No teams in this league.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif
    @endif

    {{-- Modal: przeniesienie gracza do innej ligi --}}
    <flux:modal name="move-team" class="w-full md:w-[30rem]" @close="resetMove">
        <form wire:submit="moveTeam" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Move to another league') }}</flux:heading>
                <flux:text class="mt-1">{{ $moveName }}</flux:text>
            </div>

            <flux:select wire:model="moveTier" :label="__('League')">
                @foreach (\App\Enums\League::cases() as $league)
                    <flux:select.option :value="$league->value">{{ $league->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="moveSlot" :label="__('Place in the league')">
                <flux:select.option value="0">{{ __('Automatic (the highest ranked bot)') }}</flux:select.option>
                @foreach (range(1, \App\Enums\League::SIZE) as $slot)
                    <flux:select.option :value="$slot">{{ $slot }}.</flux:select.option>
                @endforeach
            </flux:select>

            <flux:text class="text-sm">
                {{ __('Automatic: the team takes the place of the highest ranked bot in the chosen league and the bot takes its old place. A chosen place: the two teams swap places, whoever stands there.') }}
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    <span wire:loading.remove wire:target="moveTeam">{{ __('Move') }}</span>
                    <span wire:loading wire:target="moveTeam">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: przypisanie gracza do ligi --}}
    <flux:modal name="assign-player" class="w-full md:w-[30rem]" @close="resetAssign">
        <form wire:submit="assignPlayer" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Assign to a league') }}</flux:heading>
                <flux:text class="mt-1">{{ $assignName }}</flux:text>
            </div>

            <flux:select wire:model="assignTier" :label="__('League')">
                @foreach (\App\Enums\League::cases() as $league)
                    <flux:select.option :value="$league->value">{{ $league->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:text class="text-sm">
                {{ __('The player takes the place of the highest ranked bot in the chosen league, and the bot moves to the end of the list (Liga podwórkowa). In Liga podwórkowa without bots the player goes to the end of the list.') }}
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    <span wire:loading.remove wire:target="assignPlayer">{{ __('Assign') }}</span>
                    <span wire:loading wire:target="assignPlayer">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: czyszczenie listy --}}
    <flux:modal name="reset-list" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Clear the list?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('All teams will be removed from this season list. You can build it again afterwards.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="resetList">{{ __('Clear the list') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
