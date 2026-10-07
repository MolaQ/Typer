<?php

use App\Enums\League;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use App\Support\Audit;
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
 *  - edycja jest możliwa tylko, gdy sezon jest szkicem.
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

        if (!$this->ensureEditable()) {
            return;
        }

        if (SeasonTeam::where('season_id', $this->seasonId)->exists()) {
            Flux::toast(text: __('The list already exists. Clear it first to build it again.'), variant: 'warning');

            return;
        }

        $playerIds = User::whereNotNull('team_name')->orderBy('created_at')->orderBy('id')->pluck('id');

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

        Audit::log(
            'season_list.built',
            null,
            [],
            ['players' => $playerIds->count(), 'bots' => $botCount],
            $this->season->title,
        );

        $this->clearCaches();
        Flux::toast(text: __('Team list built.'), variant: 'success');
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

        DB::transaction(function () use ($ids): void {
            $now = now();

            foreach ($ids as $userId) {
                // Nowy gracz zajmuje miejsce najwyżej sklasyfikowanego bota w lidze podwórkowej
                // (bot wraca do puli). Gdy tam nie ma botów, gracz trafia na koniec listy.
                $bot = $this->bestBot(League::Podworkowa);

                if ($bot) {
                    $bot->update(['user_id' => $userId, 'bot_id' => null]);

                    continue;
                }

                $end = max(League::TOP_TEAMS, (int) SeasonTeam::where('season_id', $this->seasonId)->max('position')) + 1;
                SeasonTeam::insert([$this->row($end, $userId, null, $now)]);
            }
        });

        Audit::log('season_list.players_added', null, [], ['players' => $ids->count()], $this->season->title);

        $this->clearCaches();
        Flux::toast(text: __('Players added to the list.'), variant: 'success');
    }

    /**
     * Uzupełnia listę botami do rozmiaru Pucharu Polski (512 zespołów) i zapełnia ewentualne luki.
     * Regulamin: puste miejsca na liście zajmują boty. Przy zatwierdzaniu sezonu (etap 8)
     * zrobimy to automatycznie, a tu można to wywołać ręcznie, np. do symulacji.
     */
    public function fillToCupSize(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $taken = SeasonTeam::where('season_id', $this->seasonId)->pluck('position')->flip();

        $missing = [];
        for ($position = 1; $position <= SeasonTeam::CUP_SIZE; $position++) {
            if (!$taken->has($position)) {
                $missing[] = $position;
            }
        }

        if ($missing === []) {
            Flux::toast(text: __('The list is already full.'), variant: 'info');

            return;
        }

        $bots = $this->freeBots(count($missing));

        if ($bots->count() < count($missing)) {
            Flux::toast(text: __('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'), variant: 'danger');

            return;
        }

        $now = now();
        $rows = [];

        foreach ($missing as $index => $position) {
            $rows[] = $this->row($position, null, $bots[$index]->id, $now);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SeasonTeam::insert($chunk);
        }

        Audit::log('season_list.bots_added', null, [], ['bots' => count($rows)], $this->season->title);

        $this->clearCaches();
        Flux::toast(text: __('Bots added to the list.'), variant: 'success');
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

        if ($team->is_bot) {
            Flux::toast(text: __('Only teams with players can be moved.'), variant: 'warning');

            return;
        }

        $this->moveId = $team->id;
        $this->moveName = $team->name;
        $this->moveTier = $team->league->value;

        Flux::modal('move-team')->show();
    }

    /**
     * Przenosi gracza do wybranej ligi (regulamin, punkt 8):
     *  - do ligi 1-10: gracz zajmuje miejsce najwyżej sklasyfikowanego bota tej ligi,
     *    a bot trafia na dawne miejsce gracza (zamiana). Jeśli nie ma tam botów,
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

        abort_if($team->is_bot, 422);

        $target = League::tryFrom($this->moveTier);
        abort_if($target === null, 422);

        $from = $team->league;
        $oldPosition = $team->position;

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
                    // Zamiana miejsc: bot trafia na dawne miejsce gracza. Lista nie ma luk.
                    $this->swapPositions($team, $bot);

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
            Flux::toast(
                text: __('There are no bots in :league. Nothing was changed.', ['league' => $target->label()]),
                variant: 'warning',
            );

            return;
        }

        Audit::log(
            'season_list.moved',
            null,
            ['league' => $from->label(), 'position' => $oldPosition],
            ['league' => $target->label(), 'position' => $team->fresh()->position],
            $team->name . ' (' . $this->season->title . ')',
        );

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
        $this->reset('moveId', 'moveName', 'moveTier');
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

        $neighbour = $direction < 0
            ? $query->where('position', '<', $team->position)->orderByDesc('position')->first()
            : $query->where('position', '>', $team->position)->orderBy('position')->first();

        if (!$neighbour) {
            return;
        }

        $from = $team->position;
        $to = $neighbour->position;

        DB::transaction(fn() => $this->swapPositions($team, $neighbour));

        Audit::log(
            'season_list.reordered',
            null,
            ['position' => $from],
            ['position' => $to],
            $team->name . ' (' . $this->season->title . ')',
        );

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
        $query = SeasonTeam::where('season_id', $this->seasonId)
            ->whereNull('user_id')
            ->inLeague($league)
            ->orderBy('position');

        return ($lock ? $query->lockForUpdate() : $query)->first();
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

    /** Gracze (konta z nazwą zespołu), których nie ma jeszcze na liście sezonu. */
    private function unlistedPlayers()
    {
        return User::whereNotNull('team_name')->whereNotIn(
            'id',
            SeasonTeam::where('season_id', $this->seasonId)->whereNotNull('user_id')->select('user_id'),
        );
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
        return SeasonTeam::with(['user', 'bot'])->where('season_id', $this->seasonId)->findOrFail($id);
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

    private function clearCaches(): void
    {
        unset($this->teams, $this->stats, $this->leagueCounts);
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
            </flux:text>
        @endif

        @if ($this->stats['total'] === 0)
            {{-- Pusta lista: jeden przycisk buduje ją z zarejestrowanych graczy --}}
            <flux:card class="space-y-4">
                <flux:heading>{{ __('The list is empty.') }}</flux:heading>
                <flux:text>
                    {{ __('The list is built from registered players in order of registration: the first 10 go to Ekstraklasa, the next 10 to I liga and so on. Missing places in the first 100 are filled by bots. Later players go to Liga podwórkowa.') }}
                </flux:text>

                @if ($this->isEditable)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <div>
                            <flux:button variant="primary" icon="bolt" wire:click="buildList">
                                <span wire:loading.remove wire:target="buildList">{{ __('Build the list automatically') }}</span>
                                <span wire:loading wire:target="buildList">{{ __('Saving...') }}</span>
                            </flux:button>
                        </div>
                    @endcan
                @endif
            </flux:card>
        @else
            {{-- Gracze zarejestrowani po zbudowaniu listy --}}
            @if ($this->stats['unlisted'] > 0 && $this->isEditable)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:card class="flex flex-wrap items-center justify-between gap-4 border-amber-300 dark:border-amber-500/50">
                        <flux:text>
                            {{ __(':count players are not on the list yet.', ['count' => $this->stats['unlisted']]) }}
                        </flux:text>
                        <flux:button size="sm" icon="plus" wire:click="addNewPlayers">
                            {{ __('Add them to Liga podwórkowa') }}
                        </flux:button>
                    </flux:card>
                @endcan
            @endif

            {{-- Uzupełnienie botami do rozmiaru pucharu (512), np. do symulacji --}}
            @if ($this->stats['total'] < \App\Models\SeasonTeam::CUP_SIZE && $this->isEditable)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:card class="flex flex-wrap items-center justify-between gap-4">
                        <flux:text>
                            {{ __('Puchar Polski needs 512 teams. Fill the missing places with bots (for example for a simulation).') }}
                        </flux:text>
                        <flux:button size="sm" icon="cpu-chip" wire:click="fillToCupSize">
                            <span wire:loading.remove wire:target="fillToCupSize">{{ __('Fill with bots up to 512') }}</span>
                            <span wire:loading wire:target="fillToCupSize">{{ __('Saving...') }}</span>
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
                                        <flux:badge size="sm" color="blue">{{ $team->user->team_abbr }}</flux:badge>
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
                                            <flux:button variant="ghost" size="sm" icon="chevron-up" inset="top bottom" :disabled="$isFirst"
                                                wire:click="moveUp({{ $team->id }})" :aria-label="__('Move up')" />
                                            <flux:button variant="ghost" size="sm" icon="chevron-down" inset="top bottom"
                                                :disabled="$isLast" wire:click="moveDown({{ $team->id }})" :aria-label="__('Move down')" />

                                            @unless ($team->is_bot)
                                                <flux:button variant="ghost" size="sm" icon="arrows-right-left" inset="top bottom"
                                                    wire:click="openMove({{ $team->id }})" :aria-label="__('Move to another league')" />
                                            @endunless
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

            <flux:text class="text-sm">
                {{ __('The player takes the place of the highest ranked bot in the chosen league, and the bot takes the player\'s old place. If there are no bots there, nothing changes.') }}
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