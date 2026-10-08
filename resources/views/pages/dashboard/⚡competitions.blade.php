<?php

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Audit;
use App\Support\LeagueSchedule;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rozgrywki tworzone ręcznie (regulamin, punkty 7 i 10): Liga Mistrzów, Liga Europy, Liga Konferencji,
 * Liga Legend i Złota Liga. Ich skład zależy od wyników poprzedniego sezonu, wpłat i punktów
 * Hall of Fame, więc admin sam wybiera uczestników z listy zespołów sezonu.
 * Ligi 10-zespołowe (Mistrzów, Europy, Konferencji) dostają terminarz z LeagueSchedule, a miejsca
 * ustawiają się według pozycji zespołów na liście przedsezonowej (wyżej = faworyt = gospodarz).
 * Podgląd: season-list. Zmiany: season-edit.
 */
new class extends Component {
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    // Wybrane rozgrywki (id) do edycji uczestników.
    #[Url(as: 'c', except: 0)]
    public int $selectedId = 0;

    // Nowe rozgrywki.
    public string $newType = '';

    // Wyszukiwarka zespołów do dodania.
    public string $search = '';

    // Usuwanie rozgrywek.
    public ?int $deleteId = null;
    public string $deleteName = '';

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Competitions'));
    }

    public function updatedSeasonId(): void
    {
        $this->reset('selectedId', 'search');
    }

    public function updatedSelectedId(): void
    {
        $this->reset('search');
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

    /** Czy w tym sezonie można jeszcze zmieniać rozgrywki (wszystko poza zakończonym). */
    #[Computed]
    public function isOpen(): bool
    {
        return $this->season !== null && $this->season->status !== SeasonStatus::Finished;
    }

    /** Rozgrywki ręczne sezonu. */
    #[Computed]
    public function competitions()
    {
        return Competition::where('season_id', $this->seasonId)
            ->whereIn('type', array_map(fn($t) => $t->value, CompetitionType::manual()))
            ->withCount(['entries', 'fixtures'])
            ->orderBy('id')
            ->get();
    }

    /** Typy rozgrywek, których sezon jeszcze nie ma (po jednym na sezon). */
    #[Computed]
    public function availableTypes(): array
    {
        $existing = $this->competitions->map(fn($c) => $c->type)->all();

        return array_values(array_filter(CompetitionType::manual(), fn($t) => !in_array($t, $existing, true)));
    }

    #[Computed]
    public function selected(): ?Competition
    {
        return $this->competitions->firstWhere('id', $this->selectedId) ?? $this->competitions->first();
    }

    /** Uczestnicy wybranych rozgrywek według rozstawienia. */
    #[Computed]
    public function entries()
    {
        if (!$this->selected) {
            return collect();
        }

        return $this->selected
            ->entries()
            ->with(['seasonTeam.user:id,name,team_name,team_abbr', 'seasonTeam.bot:id,name'])
            ->get();
    }

    /** Zespoły z listy sezonu pasujące do wyszukiwania i jeszcze nie dodane do tych rozgrywek. */
    #[Computed]
    public function results()
    {
        $term = trim($this->search);

        if (!$this->selected || mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%' . $term . '%';

        return SeasonTeam::query()
            ->with(['user:id,name,team_name,team_abbr', 'bot:id,name'])
            ->where('season_id', $this->seasonId)
            ->whereNotIn('id', CompetitionEntry::where('competition_id', $this->selected->id)->select('season_team_id'))
            ->where(function ($q) use ($like) {
                $q->whereHas('user', fn($u) => $u->where('team_name', 'like', $like)->orWhere('name', 'like', $like))->orWhereHas('bot', fn($b) => $b->where('name', 'like', $like));
            })
            ->orderBy('position')
            ->limit(10)
            ->get();
    }

    /** Opis składu danego typu (wg regulaminu). */
    public function hint(CompetitionType $type): string
    {
        return match ($type) {
            CompetitionType::Champions => __('10 teams: the winners of the 10 leagues from the previous season. Round robin, 9 rounds.'),
            CompetitionType::Europa => __('10 teams: the second places of the 10 leagues from the previous season. Round robin, 9 rounds.'),
            CompetitionType::Conference => __('10 teams: the third places of the 10 leagues from the previous season. Round robin, 9 rounds.'),
            CompetitionType::Legends => __('Human teams only. The fixtures of this competition are added in a later stage.'),
            CompetitionType::Golden => __('Teams that paid to join. No Hall of Fame points. The fixtures are added in a later stage.'),
            default => '',
        };
    }

    /* ==================================================================
     | ROZGRYWKI
     * ================================================================*/

    public function openCreate(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $this->newType = $this->availableTypes[0]->value ?? '';

        Flux::modal('competition-form')->show();
    }

    public function create(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $type = CompetitionType::tryFrom($this->newType);
        abort_if($type === null || !$type->isManual(), 422);

        if (Competition::where('season_id', $this->seasonId)->where('type', $type->value)->exists()) {
            $this->addError('newType', __('This competition already exists in the season.'));

            return;
        }

        $competition = Competition::create([
            'season_id' => $this->seasonId,
            'type' => $type,
            'tier' => null,
            'name' => $type->label(),
        ]);

        Audit::log('competition.created', null, [], ['name' => $competition->name], $competition->name . ' (' . $this->season->title . ')');

        $this->selectedId = $competition->id;
        $this->reset('newType');
        $this->clearCaches();

        Flux::modal('competition-form')->close();
        Flux::toast(text: __('Competition created.'), variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $competition = $this->findCompetition($id);

        $this->deleteId = $competition->id;
        $this->deleteName = $competition->name;

        Flux::modal('delete-competition')->show();
    }

    public function delete(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $competition = $this->findCompetition((int) $this->deleteId);

        DB::transaction(function () use ($competition): void {
            $competition->fixtures()->delete();
            $competition->entries()->delete();
            $competition->delete();
        });

        Audit::log('competition.deleted', null, ['name' => $competition->name], [], $competition->name . ' (' . $this->season->title . ')');

        Flux::modal('delete-competition')->close();
        $this->reset('deleteId', 'deleteName', 'selectedId');
        $this->clearCaches();
        Flux::toast(text: __('Competition deleted.'), variant: 'success');
    }

    /* ==================================================================
     | UCZESTNICY
     * ================================================================*/

    public function addTeam(int $seasonTeamId): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen() || !$this->ensureNoFixtures()) {
            return;
        }

        $competition = $this->selected;
        $team = SeasonTeam::where('season_id', $this->seasonId)->findOrFail($seasonTeamId);

        if ($competition->type->isRoundRobin() && $competition->entries_count >= League::SIZE) {
            Flux::toast(text: __('This competition already has :count teams.', ['count' => League::SIZE]), variant: 'warning');

            return;
        }

        if (CompetitionEntry::where('competition_id', $competition->id)->where('season_team_id', $team->id)->exists()) {
            return;
        }

        CompetitionEntry::create([
            'competition_id' => $competition->id,
            'season_team_id' => $team->id,
            'seed' => ((int) CompetitionEntry::where('competition_id', $competition->id)->max('seed')) + 1,
        ]);

        Audit::log('competition.updated', null, [], ['added' => $team->name], $competition->name . ' (' . $this->season->title . ')');

        $this->reset('search');
        $this->clearCaches();
    }

    public function removeTeam(int $entryId): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen() || !$this->ensureNoFixtures()) {
            return;
        }

        $competition = $this->selected;
        $entry = CompetitionEntry::with('seasonTeam')->where('competition_id', $competition->id)->findOrFail($entryId);
        $name = $entry->seasonTeam->name;

        $entry->delete();

        Audit::log('competition.updated', null, ['removed' => $name], [], $competition->name . ' (' . $this->season->title . ')');

        $this->clearCaches();
    }

    /* ==================================================================
     | TERMINARZ (tylko ligi 10-zespołowe)
     * ================================================================*/

    /**
     * Ustawia miejsca według pozycji na liście przedsezonowej (wyżej na liście = niższy numer,
     * czyli faworyt i gospodarz) i generuje 9 kolejek z LeagueSchedule.
     */
    public function generateFixtures(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $competition = $this->selected;
        abort_unless($competition?->type->isRoundRobin(), 422);

        if ($competition->fixtures_count > 0) {
            Flux::toast(text: __('The fixtures are already generated. Clear them first.'), variant: 'warning');

            return;
        }

        $entries = $competition->entries()->with('seasonTeam:id,position')->get();

        if ($entries->count() !== League::SIZE) {
            Flux::toast(text: __('Exactly :count teams are needed to generate the fixtures.', ['count' => League::SIZE]), variant: 'warning');

            return;
        }

        DB::transaction(function () use ($competition, $entries): void {
            // Dwa kroki, żeby nie złamać unikalności (competition_id, seed).
            CompetitionEntry::where('competition_id', $competition->id)->increment('seed', 1000);

            $ordered = $entries->sortBy(fn($e) => $e->seasonTeam->position)->values();
            $entryBySeed = [];

            foreach ($ordered as $index => $entry) {
                $seed = $index + 1;
                $entry->update(['seed' => $seed]);
                $entryBySeed[$seed] = $entry->id;
            }

            $now = now();
            $rows = [];

            foreach (LeagueSchedule::fixtures() as $match) {
                $rows[] = [
                    'competition_id' => $competition->id,
                    'round' => $match['round'],
                    'home_seat' => $match['home'],
                    'away_seat' => $match['away'],
                    'home_entry_id' => $entryBySeed[$match['home']],
                    'away_entry_id' => $entryBySeed[$match['away']],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('fixtures')->insert($rows);
        });

        Audit::log('competition.drawn', null, [], ['matches' => 45], $competition->name . ' (' . $this->season->title . ')');

        $this->clearCaches();
        Flux::toast(text: __('Fixtures generated.'), variant: 'success');
    }

    /** Usuwa terminarz, żeby można było znów zmieniać uczestników. */
    public function clearFixtures(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureOpen()) {
            return;
        }

        $competition = $this->selected;
        abort_if($competition === null, 422);

        $competition->fixtures()->delete();

        Audit::log('competition.updated', null, ['fixtures' => 'cleared'], [], $competition->name . ' (' . $this->season->title . ')');

        $this->clearCaches();
        Flux::toast(text: __('Fixtures cleared.'), variant: 'success');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function findCompetition(int $id): Competition
    {
        return Competition::where('season_id', $this->seasonId)
            ->whereIn('type', array_map(fn($t) => $t->value, CompetitionType::manual()))
            ->findOrFail($id);
    }

    private function ensureOpen(): bool
    {
        if (!$this->isOpen) {
            Flux::toast(text: __('The season is finished, the competitions cannot be changed.'), variant: 'warning');

            return false;
        }

        return true;
    }

    /** Skład zmienia się tylko, gdy terminarz nie jest wygenerowany (inaczej mecze wskazywałyby na nieistniejących uczestników). */
    private function ensureNoFixtures(): bool
    {
        abort_if($this->selected === null, 422);

        if ($this->selected->fixtures_count > 0) {
            Flux::toast(text: __('Clear the fixtures before changing the participants.'), variant: 'warning');

            return false;
        }

        return true;
    }

    private function clearCaches(): void
    {
        unset($this->competitions, $this->availableTypes, $this->selected, $this->entries, $this->results);
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Competitions') }}</flux:heading>
            <flux:subheading>
                {{ __('European leagues, Liga Legend and Złota Liga are created by hand and filled with teams from the list.') }}
            </flux:subheading>
        </div>

        <div class="flex items-end gap-2">
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

            @if ($this->season && $this->isOpen && count($this->availableTypes) > 0)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:button variant="primary" icon="plus" wire:click="openCreate">{{ __('New competition') }}
                    </flux:button>
                @endcan
            @endif
        </div>
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
    @elseif ($this->competitions->isEmpty())
        <flux:card class="space-y-2">
            <flux:heading>{{ __('No competitions created by hand yet.') }}</flux:heading>
            <flux:text>
                {{ __('Their teams depend on the previous season, payments and Hall of Fame points, so you choose them yourself.') }}
            </flux:text>
        </flux:card>
    @else
        <div class="grid gap-6 xl:grid-cols-[18rem_1fr]">

            {{-- Lista rozgrywek --}}
            <div class="space-y-2 self-start">
                @foreach ($this->competitions as $competition)
                    <button type="button" wire:click="$set('selectedId', {{ $competition->id }})"
                        wire:key="comp-{{ $competition->id }}"
                        class="w-full rounded-lg border p-3 text-left transition {{ $this->selected?->id === $competition->id ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' }}">
                        <div class="font-medium">{{ $competition->name }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ __(':count teams', ['count' => $competition->entries_count]) }}
                            @if ($competition->fixtures_count > 0)
                                &middot; {{ __(':count matches', ['count' => $competition->fixtures_count]) }}
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>

            {{-- Szczegóły wybranych rozgrywek --}}
            @if ($this->selected)
                @php
                    $selected = $this->selected;
                    $fixed = $selected->fixtures_count > 0;
                @endphp

                <flux:card class="space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading size="lg">{{ $selected->name }}</flux:heading>
                            <flux:text class="text-sm">{{ $this->hint($selected->type) }}</flux:text>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm"
                                :href="route('dashboard.fixtures', ['season' => $this->seasonId, 'c' => 'c-'.$selected->id])"
                                wire:navigate>
                                {{ __('Open fixtures') }}
                            </flux:button>

                            @if ($this->isOpen)
                                @can(\App\Enums\Permission::SeasonEdit->value)
                                    <flux:button size="sm" variant="danger" icon="trash"
                                        wire:click="confirmDelete({{ $selected->id }})">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endcan
                            @endif
                        </div>
                    </div>

                    {{-- Uczestnicy --}}
                    <div class="space-y-2">
                        <flux:heading>{{ __('Teams') }} ({{ $selected->entries_count }})</flux:heading>

                        @forelse ($this->entries as $entry)
                            <div class="flex items-center gap-3 text-sm" wire:key="entry-{{ $entry->id }}">
                                <span class="w-7 text-right tabular-nums text-zinc-500">{{ $entry->seed }}.</span>
                                <span class="min-w-0 flex-1 truncate">{{ $entry->seasonTeam->name }}</span>
                                <span class="text-xs text-zinc-400">#{{ $entry->seasonTeam->position }}</span>
                                @if ($entry->seasonTeam->is_bot)
                                    <flux:badge size="sm" color="zinc">{{ __('Bot') }}</flux:badge>
                                @endif

                                @if ($this->isOpen && !$fixed)
                                    @can(\App\Enums\Permission::SeasonEdit->value)
                                        <flux:button size="xs" variant="ghost" icon="x-mark"
                                            wire:click="removeTeam({{ $entry->id }})" :aria-label="__('Remove')" />
                                    @endcan
                                @endif
                            </div>
                        @empty
                            <flux:text class="text-sm">{{ __('No teams yet.') }}</flux:text>
                        @endforelse
                    </div>

                    {{-- Dodawanie zespołów --}}
                    @if ($this->isOpen && !$fixed)
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <div class="space-y-2">
                                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable
                                    :label="__('Add a team')"
                                    :placeholder="__('Search the season list by team or player name...')" />

                                @foreach ($this->results as $team)
                                    <div class="flex items-center gap-3 text-sm" wire:key="result-{{ $team->id }}">
                                        <span class="min-w-0 flex-1 truncate">{{ $team->name }}</span>
                                        <span class="text-xs text-zinc-400">#{{ $team->position }} &middot;
                                            {{ $team->league->label() }}</span>
                                        <flux:button size="xs" icon="plus"
                                            wire:click="addTeam({{ $team->id }})">{{ __('Add') }}</flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endcan
                    @elseif ($fixed)
                        <flux:text class="text-sm text-amber-600 dark:text-amber-400">
                            {{ __('Clear the fixtures before changing the participants.') }}
                        </flux:text>
                    @endif

                    {{-- Terminarz --}}
                    @if ($selected->type->isRoundRobin() && $this->isOpen)
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                @if ($fixed)
                                    <flux:button size="sm" icon="trash" wire:click="clearFixtures">
                                        {{ __('Clear the fixtures') }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="primary" icon="bolt"
                                        wire:click="generateFixtures"
                                        :disabled="$selected->entries_count !== \App\Enums\League::SIZE">
                                        {{ __('Generate fixtures') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endcan
                    @endif
                </flux:card>
            @endif
        </div>
    @endif

    {{-- Modal: nowe rozgrywki --}}
    <flux:modal name="competition-form" class="w-full md:w-[28rem]">
        <form wire:submit="create" class="space-y-6">
            <flux:heading size="lg">{{ __('New competition') }}</flux:heading>

            <div>
                <flux:select wire:model="newType" :label="__('Competition')">
                    @foreach ($this->availableTypes as $type)
                        <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="newType" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: usuwanie rozgrywek --}}
    <flux:modal name="delete-competition" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete competition?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The competition :name with its teams and fixtures will be deleted.', ['name' => $deleteName]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
