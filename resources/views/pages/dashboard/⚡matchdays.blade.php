<?php

use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\LechMatch;
use App\Models\Matchday;
use App\Models\Season;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kolejki sezonu = 9 rzeczywistych meczów Lecha (regulamin, punkty 1 i 3).
 * Sezon dostaje przy utworzeniu 9 kolejek z „meczami bez nazwy” w kalendarzu Lecha (Season::createPlaceholderMatches).
 * Admin uzupełnia mecz kolejki od razu tutaj (rywal, gospodarz, rozgrywki, termin) albo wybiera inny mecz z kalendarza.
 * Dane meczu są kopiowane do kolejki (Matchday::assignMatch), typy graczy zostają.
 * Uprawnienia: podgląd season-list, zmiany season-edit (bez nowych uprawnień w bazie).
 */
new class extends Component {
    // Wybrany sezon (zapamiętany w adresie jako ?season=ID).
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    // --- formularz edycji kolejki ---
    public ?int $matchdayId = null;
    public string $mode = 'edit'; // 'edit' = uzupełnij mecz kolejki, 'pick' = wybierz inny mecz z kalendarza
    public string $lechMatchId = '';
    public string $opponent = '';
    public bool $isHome = true;
    public string $competition = 'league';
    public string $kickoffAt = '';

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

    /**
     * Trafność typów w rozegranych kolejkach: id kolejki => [typy, trafione rozstrzygnięcia %, dokładne %].
     *
     * @return array<int, array{tips: int, outcome: ?int, exact: ?int}>
     */
    #[Computed]
    public function accuracy(): array
    {
        $played = $this->matchdays->where('status', MatchdayStatus::Played)->keyBy('id');

        if ($played->isEmpty()) {
            return [];
        }

        $out = [];
        foreach (\App\Models\Tip::whereIn('matchday_id', $played->keys())->get(['matchday_id', 'lech_goals', 'opponent_goals'])->groupBy('matchday_id') as $id => $tips) {
            $m = $played[$id];
            $real = $m->lech_goals <=> $m->opponent_goals;
            $outcome = $tips->filter(fn($t) => ($t->lech_goals <=> $t->opponent_goals) === $real)->count();
            $exact = $tips->filter(fn($t) => $t->lech_goals === $m->lech_goals && $t->opponent_goals === $m->opponent_goals)->count();

            $out[$id] = [
                'tips' => $tips->count(),
                'outcome' => (int) round($outcome / $tips->count() * 100),
                'exact' => (int) round($exact / $tips->count() * 100),
            ];
        }

        return $out;
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

            $season->createPlaceholderMatches();

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

        $match = $matchday->lechMatch;

        $this->matchdayId = $matchday->id;
        $this->mode = 'edit';
        $this->lechMatchId = (string) ($matchday->lech_match_id ?? '');
        $this->opponent = (string) $match?->opponent;
        $this->isHome = $match?->is_home ?? true;
        $this->competition = \App\Enums\MatchCompetition::tryFrom((string) $match?->competition)?->value ?? \App\Enums\MatchCompetition::DEFAULT->value;
        $this->kickoffAt = $match?->kickoff_at?->format('Y-m-d\TH:i') ?? '';
        $this->resetValidation();
        unset($this->availableMatches);

        Flux::modal('matchday-form')->show();
    }

    /**
     * Mecze z kalendarza do wyboru: wolne (bez kolejki) i z terminem w przyszłości albo bez terminu,
     * plus mecz obecnie przypisany do edytowanej kolejki.
     */
    #[Computed]
    public function availableMatches()
    {
        $current = $this->matchdayId ? Matchday::whereKey($this->matchdayId)->value('lech_match_id') : null;

        return LechMatch::query()
            ->where(fn($q) => $q->whereDoesntHave('matchday')->where(fn($q) => $q->whereNull('kickoff_at')->orWhere('kickoff_at', '>', now())))
            ->when($current, fn($q) => $q->orWhere('id', $current))
            ->orderByRaw('kickoff_at is null')
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Zapis kolejki. Tryb „edit”: dane meczu kolejki (zwykle „meczu bez nazwy”) zmieniamy w kalendarzu i kopiujemy do kolejki,
     * termin może zostać pusty. Tryb „pick”: wybrany mecz z kalendarza (z terminem, nieprzełożony).
     * Pilnujemy, żeby terminy rosły razem z numerami kolejek (kolejka 3 nie może być przed kolejką 2).
     */
    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $matchday = $this->findMatchday((int) $this->matchdayId);

        if (!$this->canChange($matchday)) {
            return;
        }

        $match = $this->mode === 'pick' ? $this->pickedMatch($matchday) : $this->editedMatch($matchday);

        if ($match === null) {
            return;
        }

        $before = $this->snapshot($matchday);

        DB::transaction(function () use ($matchday, $match): void {
            if (!$match->exists || $match->isDirty()) {
                $snapshot = $match->exists ? $this->matchSnapshot($match->fresh()) : [];
                $match->save();
                Audit::log($snapshot ? 'lech_match.updated' : 'lech_match.created', null, $snapshot, $this->matchSnapshot($match), $match->fixture());
            }

            $matchday->assignMatch($match);
        });

        $after = $this->snapshot($matchday);

        if ($before !== $after) {
            Audit::log('matchday.updated', null, $before, $after, $this->season->title . ', ' . __('matchday :n', ['n' => $matchday->number]));

            // Informacje systemowe: nowy albo przełożony mecz (rywal lub godzina), tylko dla kompletnych kolejek.
            if ($matchday->isFilled() && ($before['opponent'] !== $after['opponent'] || $before['kickoff_at'] !== $after['kickoff_at'])) {
                \App\Support\SystemFeed::record(
                    'matches',
                    filled($before['opponent']) ? 'Match updated: :fixture (matchday :number, :date)' : 'Match added: :fixture (matchday :number, :date)',
                    ['fixture' => $matchday->fixture, 'number' => $matchday->number, 'date' => $matchday->kickoff_at->format('d.m.Y H:i')],
                    'results',
                    ['round' => $matchday->number],
                );
            }
        }

        Flux::modal('matchday-form')->close();
        $this->resetForm();
        unset($this->matchdays, $this->stats);

        Flux::toast(text: __('Matchday saved.'), variant: 'success');
    }

    /** Wywoływana także przez @close modala, więc jest publiczna. */
    public function resetForm(): void
    {
        $this->reset('matchdayId', 'mode', 'lechMatchId', 'opponent', 'isHome', 'competition', 'kickoffAt');
        $this->resetValidation();
    }

    /** Tryb „pick”: mecz z kalendarza musi mieć termin, nie być przełożony i pasować do kolejności kolejek. */
    private function pickedMatch(Matchday $matchday): ?LechMatch
    {
        $this->validate(['lechMatchId' => ['required', 'integer', Rule::in($this->availableMatches->pluck('id')->all())]], [], ['lechMatchId' => __('Match')]);

        $match = LechMatch::findOrFail((int) $this->lechMatchId);

        if ($match->postponed || $match->kickoff_at === null) {
            $this->addError('lechMatchId', __('This match is postponed or has no kickoff yet. Update it in the Lech calendar first.'));

            return null;
        }

        if ($error = $matchday->kickoffOrderError($match->kickoff_at)) {
            $this->addError('lechMatchId', $error);

            return null;
        }

        return $match;
    }

    /** Tryb „edit”: mecz kolejki (albo nowy, gdy kolejka nie ma meczu) z danymi z formularza, jeszcze niezapisany. */
    private function editedMatch(Matchday $matchday): ?LechMatch
    {
        $this->opponent = \Illuminate\Support\Str::squish($this->opponent);

        $this->validate(
            [
                'opponent' => ['required', 'string', 'max:80'],
                'isHome' => ['boolean'],
                'competition' => ['required', Rule::enum(\App\Enums\MatchCompetition::class)],
                'kickoffAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            ],
            [],
            ['opponent' => __('Opponent'), 'competition' => __('Competition'), 'kickoffAt' => __('Kickoff')],
        );

        $kickoff = $this->kickoffAt !== '' ? \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $this->kickoffAt) : null;

        if ($kickoff && ($error = $matchday->kickoffOrderError($kickoff))) {
            $this->addError('kickoffAt', $error);

            return null;
        }

        return ($matchday->lechMatch ?? new LechMatch(['postponed' => false]))->fill([
            'opponent' => $this->opponent,
            'is_home' => $this->isHome,
            'competition' => $this->competition,
            'kickoff_at' => $kickoff,
        ]);
    }

    /** Pola meczu w kalendarzu do dziennika (jak w panelu kalendarza). */
    private function matchSnapshot(LechMatch $match): array
    {
        return [
            'opponent' => $match->opponent,
            'home_away' => $match->is_home ? __('Home') : __('Away'),
            'competition' => $match->competitionLabel(),
            'kickoff_at' => $match->kickoff_at?->format('Y-m-d H:i'),
        ];
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

    /** Pola zapisywane w dzienniku (do porównania "było -> jest"). */
    private function snapshot(Matchday $matchday): array
    {
        return [
            'opponent' => $matchday->opponent,
            'home_away' => $matchday->is_home ? __('Home') : __('Away'),
            'competition' => $matchday->competitionLabel(),
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
                    <flux:table.column>{{ __('Result') }}</flux:table.column>
                    <flux:table.column>{{ __('Correct tips') }}</flux:table.column>
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
                                @elseif ($matchday->lech_match_id)
                                    <flux:badge size="sm" color="amber" icon="pencil-square">{{ __('Unnamed match') }}</flux:badge>
                                @else
                                    <span class="text-zinc-400">{{ __('Not set') }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-zinc-500">{{ $matchday->competitionLabel() ?? '—' }}
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

                            {{-- Rozstrzygnięcie z perspektywy Lecha: zielony wygrana, żółty remis, czerwony porażka --}}
                            <flux:table.cell>
                                @if ($matchday->status === \App\Enums\MatchdayStatus::Played)
                                    @php
                                        $diff = $matchday->lech_goals <=> $matchday->opponent_goals;
                                        $resultColor = [1 => 'green', 0 => 'yellow', -1 => 'red'][$diff];
                                        $resultLabel = [1 => __('Lech wins'), 0 => __('A draw'), -1 => __('Lech loses')][$diff];
                                    @endphp
                                    <flux:badge size="sm" :color="$resultColor" :title="$resultLabel">
                                        {{ $matchday->lech_goals }}:{{ $matchday->opponent_goals }} &middot; {{ $resultLabel }}
                                    </flux:badge>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-sm">
                                @if (isset($this->accuracy[$matchday->id]))
                                    @php
                                        $acc = $this->accuracy[$matchday->id];
                                    @endphp
                                    <div class="tabular-nums" title="{{ __('Tips: :count', ['count' => $acc['tips']]) }}">
                                        {{ __('Outcome :outcome%, exact :exact%', ['outcome' => $acc['outcome'], 'exact' => $acc['exact']]) }}
                                    </div>
                                    <div class="text-xs text-zinc-500">{{ __('Tips: :count', ['count' => $acc['tips']]) }}</div>
                                @elseif ($matchday->status === \App\Enums\MatchdayStatus::Played)
                                    <span class="text-zinc-400">{{ __('No tips') }}</span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @can(\App\Enums\Permission::SeasonEdit->value)
                                    @if (!$this->isLocked && $matchday->status !== \App\Enums\MatchdayStatus::Played)
                                        @if ($matchday->isFilled())
                                            <flux:button variant="ghost" size="sm" icon="pencil-square" inset="top bottom"
                                                wire:click="edit({{ $matchday->id }})" :aria-label="__('Edit')" />
                                        @else
                                            <flux:button variant="primary" size="sm" icon="pencil-square" inset="top bottom"
                                                wire:click="edit({{ $matchday->id }})">{{ __('Fill in') }}</flux:button>
                                        @endif
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

            <flux:radio.group wire:model.live="mode" variant="segmented">
                <flux:radio value="edit" :label="__('This match')" />
                <flux:radio value="pick" :label="__('Another match from the calendar')" />
            </flux:radio.group>

            @if ($mode === 'edit')
                {{-- Dane meczu kolejki: zapis zmienia mecz w kalendarzu Lecha i kolejkę, typy graczy zostają --}}
                <flux:input wire:model="opponent" :label="__('Opponent')" placeholder="Legia Warszawa" />
                <flux:switch wire:model="isHome" :label="__('Home match')" />
                <flux:select wire:model="competition" :label="__('Competition')">
                    @foreach (\App\Enums\MatchCompetition::options() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="kickoffAt" type="datetime-local" :label="__('Kickoff')"
                    :description="__('Polish time. Can be empty when the date is not known yet.')" />
            @else
                {{-- Mecz z kalendarza Lecha: dane meczu trafiają do kolejki, typy graczy zostają --}}
                <flux:select wire:model="lechMatchId" :label="__('Match')" :placeholder="__('Choose a match...')">
                    @foreach ($this->availableMatches as $option)
                        <flux:select.option :value="$option->id">
                            {{ $option->kickoff_at?->format('d.m.Y H:i') ?? __('No date') }} · {{ $option->fixture() }}
                            ({{ $option->competitionLabel() }}){{ $option->postponed ? ' · ' . __('Postponed') : '' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:text class="text-sm">
                    {{ __('Missing match? Add it in the') }}
                    <flux:link :href="route('dashboard.lech-calendar')" wire:navigate>{{ __('Lech calendar') }}</flux:link>.
                </flux:text>
            @endif

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
