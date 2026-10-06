<?php

use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\Season;
use App\Support\Audit;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kolejki sezonu = 9 rzeczywistych meczów Lecha (regulamin, punkty 1 i 3).
 * Admin wybiera sezon, tworzy 9 pustych kolejek i uzupełnia: rywal, termin, status.
 * Uprawnienia: podgląd season-list, zmiany season-edit (bez nowych uprawnień w bazie).
 */
new class extends Component {
    // Wybrany sezon (zapamiętany w adresie jako ?season=ID).
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    // --- formularz edycji kolejki ---
    public ?int $matchdayId = null;
    public string $opponent = '';
    public bool $isHome = true;
    public string $competition = '';
    public string $kickoffAt = ''; // format pola datetime-local: 2026-10-18T17:30
    public string $status = 'planned';

    /** Domyślnie pokazujemy sezon aktywny, a gdy go nie ma, ostatni z numerem. */
    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Matchdays'));
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

    #[Computed]
    public function matchdays()
    {
        return $this->season?->matchdays()->get() ?? collect();
    }

    /** Liczba uzupełnionych kolejek i najbliższy mecz. */
    #[Computed]
    public function stats(): array
    {
        $next = $this->matchdays->filter(fn(Matchday $m) => $m->isOpenForTips())->sortBy('kickoff_at')->first();

        return [
            'filled' => $this->matchdays->filter(fn(Matchday $m) => $m->isFilled())->count(),
            'next' => $next,
        ];
    }

    /** Zakończony sezon jest tylko do odczytu. */
    #[Computed]
    public function isLocked(): bool
    {
        return $this->season?->status === SeasonStatus::Finished;
    }

    /* ==================================================================
     | TWORZENIE 9 KOLEJEK
     * ================================================================*/

    /**
     * Tworzy brakujące kolejki 1-9. Można wywołać wielokrotnie:
     * istniejące kolejki zostają nietknięte (nic się nie nadpisuje).
     */
    public function createMatchdays(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $season = Season::findOrFail($this->seasonId);

        if ($season->status === SeasonStatus::Finished) {
            Flux::toast(text: __('This season is finished, its matchdays cannot be changed.'), variant: 'warning');

            return;
        }

        $created = DB::transaction(function () use ($season): int {
            $existing = $season->matchdays()->pluck('number')->all();
            $created = 0;

            for ($n = 1; $n <= Matchday::PER_SEASON; $n++) {
                if (!in_array($n, $existing, true)) {
                    $season->matchdays()->create(['number' => $n]);
                    $created++;
                }
            }

            return $created;
        });

        if ($created > 0) {
            Audit::log('matchday.created', null, [], ['matchdays' => $created], $season->title);
        }

        unset($this->matchdays, $this->stats);
        Flux::toast(text: __('Matchdays created.'), variant: 'success');
    }

    /* ==================================================================
     | EDYCJA KOLEJKI
     * ================================================================*/

    public function edit(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $matchday = $this->findMatchday($id);

        if (!$this->canChange($matchday)) {
            return;
        }

        $this->matchdayId = $matchday->id;
        $this->opponent = (string) $matchday->opponent;
        $this->isHome = $matchday->is_home;
        $this->competition = (string) $matchday->competition;
        $this->kickoffAt = $matchday->kickoff_at?->format('Y-m-d\TH:i') ?? '';
        $this->status = $matchday->status->value;
        $this->resetValidation();

        Flux::modal('matchday-form')->show();
    }

    protected function rules(): array
    {
        return [
            'opponent' => ['required', 'string', 'max:80'],
            'isHome' => ['boolean'],
            'competition' => ['nullable', 'string', 'max:60'],
            // Termin jest wymagany dla kolejki zaplanowanej. Przełożona może być bez daty.
            'kickoffAt' => [Rule::requiredIf(fn() => $this->status === MatchdayStatus::Planned->value), 'nullable', 'date_format:Y-m-d\TH:i'],
            'status' => ['required', Rule::in([MatchdayStatus::Planned->value, MatchdayStatus::Postponed->value])],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'opponent' => __('Opponent'),
            'competition' => __('Competition'),
            'kickoffAt' => __('Kickoff'),
            'status' => __('Status'),
        ];
    }

    /**
     * Zapis kolejki. Pilnujemy, żeby terminy rosły razem z numerami kolejek
     * (kolejka 3 nie może być przed kolejką 2).
     */
    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $matchday = $this->findMatchday((int) $this->matchdayId);

        if (!$this->canChange($matchday)) {
            return;
        }

        $this->opponent = Str::squish($this->opponent);
        $this->competition = Str::squish($this->competition);

        $this->validate();

        $kickoff = $this->kickoffAt !== '' ? Carbon::createFromFormat('Y-m-d\TH:i', $this->kickoffAt) : null;

        if ($kickoff && $this->status === MatchdayStatus::Planned->value && !$this->checkOrder($matchday, $kickoff)) {
            return;
        }

        $before = $this->snapshot($matchday);

        $matchday
            ->fill([
                'opponent' => $this->opponent,
                'is_home' => $this->isHome,
                'competition' => $this->competition !== '' ? $this->competition : null,
                'kickoff_at' => $kickoff,
                'status' => $this->status,
            ])
            ->save();

        $after = $this->snapshot($matchday);

        if ($before !== $after) {
            Audit::log('matchday.updated', null, $before, $after, $this->season->title . ', ' . __('matchday :n', ['n' => $matchday->number]));
        }

        Flux::modal('matchday-form')->close();
        $this->resetForm();
        unset($this->matchdays, $this->stats);

        Flux::toast(text: __('Matchday saved.'), variant: 'success');
    }

    /** Wywoływana także przez @close modala, więc jest publiczna. */
    public function resetForm(): void
    {
        $this->reset('matchdayId', 'opponent', 'isHome', 'competition', 'kickoffAt', 'status');
        $this->resetValidation();
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    /** Kolejka musi należeć do wybranego sezonu (zabezpieczenie przed podmianą ID). */
    private function findMatchday(int $id): Matchday
    {
        return Matchday::where('season_id', $this->seasonId)->findOrFail($id);
    }

    /** Zakończony sezon i rozegrana kolejka są zablokowane. */
    private function canChange(Matchday $matchday): bool
    {
        if ($this->isLocked) {
            Flux::toast(text: __('This season is finished, its matchdays cannot be changed.'), variant: 'warning');

            return false;
        }

        if ($matchday->status === MatchdayStatus::Played) {
            Flux::toast(text: __('A played matchday cannot be edited.'), variant: 'warning');

            return false;
        }

        return true;
    }

    /**
     * Sprawdza, czy termin mieści się między sąsiednimi zaplanowanymi kolejkami.
     * Przy naruszeniu dodaje błąd do pola i zwraca false.
     */
    private function checkOrder(Matchday $matchday, Carbon $kickoff): bool
    {
        $neighbours = Matchday::where('season_id', $matchday->season_id)->where('status', MatchdayStatus::Planned->value)->whereNotNull('kickoff_at');

        $previous = (clone $neighbours)->where('number', '<', $matchday->number)->orderByDesc('number')->first();
        $next = (clone $neighbours)->where('number', '>', $matchday->number)->orderBy('number')->first();

        if ($previous && $kickoff->lte($previous->kickoff_at)) {
            $this->addError(
                'kickoffAt',
                __('The kickoff must be later than matchday :n (:date).', [
                    'n' => $previous->number,
                    'date' => $previous->kickoff_at->format('d.m.Y H:i'),
                ]),
            );

            return false;
        }

        if ($next && $kickoff->gte($next->kickoff_at)) {
            $this->addError(
                'kickoffAt',
                __('The kickoff must be earlier than matchday :n (:date).', [
                    'n' => $next->number,
                    'date' => $next->kickoff_at->format('d.m.Y H:i'),
                ]),
            );

            return false;
        }

        return true;
    }

    /** Pola zapisywane w dzienniku (do porównania "było -> jest"). */
    private function snapshot(Matchday $matchday): array
    {
        return [
            'opponent' => $matchday->opponent,
            'home_away' => $matchday->is_home ? __('Home') : __('Away'),
            'competition' => $matchday->competition,
            'kickoff_at' => $matchday->kickoff_at?->format('Y-m-d H:i'),
            'status' => $matchday->status->label(),
        ];
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
            <flux:heading size="xl" level="1">{{ __('Matchdays') }}</flux:heading>
            <flux:subheading>
                {{ __('One real Lech match per matchday. Every competition of the season tips the same match.') }}
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
        {{-- Brak sezonów --}}
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
                <flux:icon.calendar-days class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Season') }}</flux:text>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ $this->season->title }}</flux:heading>
                        <flux:badge size="sm" :color="$this->season->status->color()">
                            {{ $this->season->status->label() }}</flux:badge>
                    </div>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4">
                <flux:icon.check-circle class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Filled matchdays') }}</flux:text>
                    <flux:heading size="xl">{{ $this->stats['filled'] }} / {{ \App\Models\Matchday::PER_SEASON }}
                    </flux:heading>
                </div>
            </flux:card>

            <flux:card class="flex items-center gap-4">
                <flux:icon.clock class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Next kickoff') }}</flux:text>
                    <flux:heading size="xl">
                        {{ $this->stats['next']?->kickoff_at?->format('d.m.Y H:i') ?? '—' }}
                    </flux:heading>
                </div>
            </flux:card>
        </div>

        @if ($this->matchdays->isEmpty())
            {{-- Sezon bez kolejek: jeden przycisk tworzy wszystkie 9 --}}
            <flux:card class="space-y-4">
                <flux:heading>{{ __('No matchdays yet.') }}</flux:heading>

                @if (!$this->isLocked)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <div>
                            <flux:button variant="primary" icon="plus" wire:click="createMatchdays">
                                {{ __('Create 9 matchdays') }}
                            </flux:button>
                        </div>
                    @endcan
                @endif
            </flux:card>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>{{ __('Match') }}</flux:table.column>
                    <flux:table.column>{{ __('Competition') }}</flux:table.column>
                    <flux:table.column>{{ __('Kickoff') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->matchdays as $matchday)
                        <flux:table.row :key="'matchday-'.$matchday->id">
                            <flux:table.cell variant="strong">{{ $matchday->number }}</flux:table.cell>

                            <flux:table.cell>
                                @if (filled($matchday->opponent))
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span>{{ $matchday->fixture }}</span>
                                        <flux:badge size="sm" color="zinc">
                                            {{ $matchday->is_home ? __('Home') : __('Away') }}</flux:badge>
                                    </div>
                                @else
                                    <span class="text-zinc-400">{{ __('Not set') }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-zinc-500">{{ $matchday->competition ?: '—' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($matchday->kickoff_at)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span>{{ $matchday->kickoff_at->format('d.m.Y H:i') }}</span>
                                        @if ($matchday->isOpenForTips())
                                            <flux:badge size="sm" color="green">{{ __('Tips open') }}
                                            </flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Tips closed') }}
                                            </flux:badge>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" :color="$matchday->status->color()">
                                    {{ $matchday->status->label() }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @can(\App\Enums\Permission::SeasonEdit->value)
                                    @if (!$this->isLocked && $matchday->status !== \App\Enums\MatchdayStatus::Played)
                                        <flux:button variant="ghost" size="sm" icon="pencil-square" inset="top bottom"
                                            wire:click="edit({{ $matchday->id }})" :aria-label="__('Edit')" />
                                    @endif
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            {{-- Dobranie brakujących kolejek, gdyby któraś została usunięta z bazy --}}
            @if ($this->matchdays->count() < \App\Models\Matchday::PER_SEASON && !$this->isLocked)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <div>
                        <flux:button icon="plus" wire:click="createMatchdays">{{ __('Create 9 matchdays') }}
                        </flux:button>
                    </div>
                @endcan
            @endif
        @endif
    @endif

    {{-- Modal: edycja kolejki --}}
    <flux:modal name="matchday-form" class="w-full md:w-[30rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit matchday') }}</flux:heading>
                <flux:text class="mt-1">{{ $this->season?->title }}</flux:text>
            </div>

            <flux:input wire:model="opponent" :label="__('Opponent')" placeholder="Legia Warszawa" />

            <flux:switch wire:model="isHome" :label="__('Home match')" />

            <flux:input wire:model="competition" :label="__('Competition')" placeholder="Ekstraklasa" />

            <flux:input wire:model="kickoffAt" type="datetime-local" :label="__('Kickoff')"
                :description="__('Tips close at this time.')" />

            <flux:select wire:model="status" :label="__('Status')">
                <flux:select.option value="planned">{{ __('Planned') }}</flux:select.option>
                <flux:select.option value="postponed">{{ __('Postponed') }}</flux:select.option>
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
