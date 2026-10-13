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

    /**
     * Postęp kolejek do kart (mecz, typy, wynik): liczba typów każdej kolejki.
     *
     * @return array{tips: array<int, int>}
     */
    #[Computed]
    public function progress(): array
    {
        $ids = $this->matchdays->pluck('id');

        return [
            'tips' => \App\Models\Tip::whereIn('matchday_id', $ids)->selectRaw('matchday_id, count(*) as total')->groupBy('matchday_id')->pluck('total', 'matchday_id')->map(fn($n) => (int) $n)->all(),
        ];
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

        unset($this->matchdays, $this->stats, $this->progress);
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
        unset($this->matchdays, $this->stats, $this->progress);

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
    @include('partials.matchday-tabs', ['tab' => 'matchdays'])


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
            {{-- Karty kolejek: każda prowadzi przez kroki kolejki (mecz, typowanie, wynik) z szybkimi akcjami --}}
            @php
                $progress = $this->progress;
                $canEdit = auth()->user()->can(\App\Enums\Permission::SeasonEdit->value) && !$this->isLocked;
            @endphp
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->matchdays as $matchday)
                    @php
                        $played = $matchday->status === \App\Enums\MatchdayStatus::Played;
                        $started = $matchday->kickoff_at && now()->gte($matchday->kickoff_at);
                        $hasScore = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;
                        $tips = $progress['tips'][$matchday->id] ?? 0;
                        $routeParams = ['season' => $this->season->id, 'matchday' => $matchday->number];

                        // Kroki: [ikona stanu, kolor, tytuł, opis, akcja]; stan: done / todo / urgent / wait
                        $steps = [];
                        $steps[] = [
                            $matchday->isFilled() ? 'done' : 'todo',
                            __('Match'),
                            $matchday->isFilled()
                                ? $matchday->fixture . ' · ' . $matchday->kickoff_at->format('d.m.Y H:i')
                                : ($matchday->lech_match_id ? __('Unnamed match: add the opponent and the kickoff.') : __('No match yet.')),
                        ];
                        $steps[] = [
                            $started || $played ? 'done' : ($matchday->isOpenForTips() ? 'wait' : 'todo'),
                            __('Tipping'),
                            ($matchday->isOpenForTips() ? __('Tips open') : __('Tips closed')) . ' · ' . __('Tips: :count', ['count' => $tips]),
                        ];
                        $steps[] = [
                            $played ? 'done' : ($started && $matchday->status !== \App\Enums\MatchdayStatus::Postponed ? 'urgent' : 'todo'),
                            __('Result and points'),
                            $played
                                ? $matchday->lech_goals . ':' . $matchday->opponent_goals . (isset($this->accuracy[$matchday->id]) ? ' · ' . __('Outcome :outcome%, exact :exact%', $this->accuracy[$matchday->id]) : '')
                                : ($hasScore ? __('The score is saved, but the points are not counted yet.') : __('After the final whistle.')),
                        ];
                        $stateIcons = ['done' => 'check-circle', 'todo' => 'minus-circle', 'urgent' => 'exclamation-circle', 'wait' => 'clock'];
                        $stateColors = ['done' => 'text-green-600', 'todo' => 'text-zinc-400', 'urgent' => 'text-red-600', 'wait' => 'text-lech-600'];
                    @endphp
                    <flux:card class="flex flex-col gap-4" wire:key="matchday-card-{{ $matchday->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">{{ __('Matchday :number', ['number' => $matchday->number]) }}</flux:heading>
                                <flux:text size="sm">{{ $matchday->competitionLabel() ?? '—' }}@if (filled($matchday->opponent)) · {{ $matchday->is_home ? __('Home') : __('Away') }}@endif</flux:text>
                            </div>
                            <flux:badge size="sm" :color="$matchday->status->color()">{{ $matchday->status->label() }}</flux:badge>
                        </div>

                        <ol class="space-y-3">
                            @foreach ($steps as [$state, $title, $detail])
                                <li class="flex items-start gap-3">
                                    <flux:icon :name="$stateIcons[$state]" class="{{ $stateColors[$state] }} mt-0.5 size-5 shrink-0" />
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">{{ $title }}</div>
                                        <div class="truncate text-sm text-zinc-500" title="{{ $detail }}">{{ $detail }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        {{-- Akcje kolejki: najważniejsza (pierwszy niezałatwiony krok) jako główny przycisk --}}
                        <div class="mt-auto flex flex-wrap gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                            @if ($canEdit && !$played)
                                <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $matchday->id }})"
                                    :variant="$matchday->isFilled() ? 'filled' : 'primary'">
                                    {{ $matchday->isFilled() ? __('Edit match') : __('Fill in') }}
                                </flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" icon="check-badge" :href="route('dashboard.tips', $routeParams)" wire:navigate>
                                {{ __('Tips') }}
                            </flux:button>
                            <flux:button size="sm" :variant="$started && !$played ? 'primary' : 'ghost'" icon="flag" :href="route('dashboard.results', $routeParams)" wire:navigate>
                                {{ __('Result') }}
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>

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
