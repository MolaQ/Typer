<?php

use App\Actions\Questions\DrawQuestions;
use App\Enums\CompetitionType;
use App\Enums\Permission;
use App\Enums\QuestionSide;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Question;
use App\Models\Season;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Zestawy pytań bonusowych kolejek (regulamin, punkt 4): dla każdego typu rozgrywek 5 ofensywnych
 * i 5 defensywnych, bez powtórek w obrębie kolejki. Admin wybiera pytania ręcznie albo losuje z banku.
 * Zmiany są możliwe do godziny meczu. Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    #[Url(as: 'matchday', except: 1)]
    public int $number = 1;

    // Modal wyboru pytania do miejsca w zestawie.
    public string $slotType = '';
    public string $slotSide = '';
    public int $slotPosition = 0;
    public string $search = '';

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Matchday questions'));
    }

    public function updatedSeasonId(): void
    {
        $this->number = 1;
    }

    /* ==================================================================
     | DANE
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
        return Matchday::where('season_id', $this->seasonId)->orderBy('number')->get();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdays->firstWhere('number', $this->number) ?? $this->matchdays->first();
    }

    /** Typy rozgrywek sezonu (powstają przy zatwierdzeniu). */
    #[Computed]
    public function types(): array
    {
        return DrawQuestions::typesOfSeason($this->seasonId);
    }

    /** Przypisane pytania wybranej kolejki: "typ|strona|numer" => MatchdayQuestion. */
    #[Computed]
    public function assigned()
    {
        if (!$this->matchday) {
            return collect();
        }

        return MatchdayQuestion::with('question:id,text')
            ->where('matchday_id', $this->matchday->id)
            ->get()
            ->keyBy(fn($row) => $row->competition_type->value . '|' . $row->side->value . '|' . $row->position);
    }

    #[Computed]
    public function editable(): bool
    {
        return $this->matchday?->questionsEditable() ?? false;
    }

    /** Pytania do wyboru w modalu: aktywne, ze strony tego miejsca, jeszcze nieużyte w kolejce. */
    #[Computed]
    public function candidates()
    {
        if ($this->slotSide === '' || !$this->matchday) {
            return collect();
        }

        return Question::query()
            ->where('is_active', true)
            ->where('side', $this->slotSide)
            ->whereNotIn('id', MatchdayQuestion::where('matchday_id', $this->matchday->id)->select('question_id'))
            ->when(trim($this->search) !== '', fn($q) => $q->where('text', 'like', '%' . trim($this->search) . '%'))
            ->orderBy('text')
            ->limit(25)
            ->get(['id', 'text']);
    }

    /** Ile miejsc w kolejce jest zajętych (z maksymalnych 10 na typ). */
    public function filled(CompetitionType $type): int
    {
        return collect($this->assigned)->filter(fn($row) => $row->competition_type === $type)->count();
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    public function openPick(string $type, string $side, int $position): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        abort_if(CompetitionType::tryFrom($type) === null || QuestionSide::tryFrom($side) === null, 422);
        abort_unless($position >= 1 && $position <= MatchdayQuestion::PER_SIDE, 422);

        $this->slotType = $type;
        $this->slotSide = $side;
        $this->slotPosition = $position;
        $this->search = '';

        Flux::modal('pick-question')->show();
    }

    public function resetPick(): void
    {
        $this->reset('slotType', 'slotSide', 'slotPosition', 'search');
    }

    /** Wstawia wybrane pytanie na miejsce (zastępując poprzednie). */
    public function assign(int $questionId): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $type = CompetitionType::tryFrom($this->slotType);
        $side = QuestionSide::tryFrom($this->slotSide);
        abort_if($type === null || $side === null || $this->slotPosition < 1, 422);

        $question = Question::where('is_active', true)->where('side', $side->value)->findOrFail($questionId);

        // Pytanie nie może wystąpić w tej kolejce drugi raz (także w innym typie rozgrywek).
        $usedElsewhere = MatchdayQuestion::where('matchday_id', $this->matchday->id)
            ->where('question_id', $question->id)
            ->exists();

        if ($usedElsewhere) {
            Flux::toast(text: __('This question is already used in this matchday.'), variant: 'warning');

            return;
        }

        MatchdayQuestion::updateOrCreate(
            [
                'matchday_id' => $this->matchday->id,
                'competition_type' => $type->value,
                'side' => $side->value,
                'position' => $this->slotPosition,
            ],
            ['question_id' => $question->id, 'correct_answer' => null],
        );

        Audit::log(
            'matchday_questions.updated',
            null,
            [],
            ['question' => $question->text],
            $this->describe($type),
        );

        Flux::modal('pick-question')->close();
        $this->resetPick();
        unset($this->assigned, $this->candidates);
    }

    public function clearSlot(string $type, string $side, int $position): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        MatchdayQuestion::where('matchday_id', $this->matchday->id)
            ->where('competition_type', $type)
            ->where('side', $side)
            ->where('position', $position)
            ->delete();

        unset($this->assigned);
    }

    /** Czyści cały zestaw jednego typu rozgrywek. */
    public function clearType(string $type): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->ensureEditable()) {
            return;
        }

        $enum = CompetitionType::tryFrom($type);
        abort_if($enum === null, 422);

        MatchdayQuestion::where('matchday_id', $this->matchday->id)->where('competition_type', $enum->value)->delete();

        Audit::log('matchday_questions.updated', null, ['set' => 'cleared'], [], $this->describe($enum));

        unset($this->assigned);
    }

    /** Losuje brakujące pytania: dla jednego typu albo dla wszystkich typów sezonu. */
    public function draw(?string $type = null): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        abort_if($this->matchday === null, 422);

        try {
            $filled = app(DrawQuestions::class)->handle($this->matchday, $type ? CompetitionType::tryFrom($type) : null);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->assigned);

        Flux::toast(
            text: $filled > 0 ? __(':count questions drawn.', ['count' => $filled]) : __('Nothing to draw: all places are filled.'),
            variant: $filled > 0 ? 'success' : 'info',
        );
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function describe(CompetitionType $type): string
    {
        return $type->questionSetLabel() . ', ' . __('Matchday :number', ['number' => $this->matchday->number]) . ' (' . $this->season->title . ')';
    }

    private function ensureEditable(): bool
    {
        if (!$this->editable) {
            Flux::toast(text: __('The questions of this matchday can no longer be changed.'), variant: 'warning');

            return false;
        }

        return true;
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Matchday questions') }}</flux:heading>
            <flux:subheading>
                {{ __('5 offensive and 5 defensive questions for each competition type. Questions do not repeat within a matchday.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            @if ($this->seasons->isNotEmpty())
                <div class="w-full sm:w-48">
                    <flux:select wire:model.live="seasonId" :label="__('Season')">
                        @foreach ($this->seasons as $option)
                            <flux:select.option :value="$option->id">{{ $option->title }} ({{ $option->status->label() }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @if ($this->matchdays->isNotEmpty())
                <div class="w-full sm:w-64">
                    <flux:select wire:model.live="number" :label="__('Matchday')">
                        @foreach ($this->matchdays as $option)
                            <flux:select.option :value="$option->number">
                                {{ $option->number }}. {{ filled($option->opponent) ? $option->fixture : __('not set yet') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </div>

    @if (!$this->season || $this->matchdays->isEmpty())
        <flux:card class="space-y-4">
            <flux:heading>{{ __('No matchdays yet.') }}</flux:heading>
            <flux:text>{{ __('Create a season with its matchdays first.') }}</flux:text>
            <div>
                <flux:button :href="route('dashboard.matchdays')" wire:navigate>{{ __('Go to matchdays') }}</flux:button>
            </div>
        </flux:card>
    @elseif (count($this->types) === 0)
        <flux:card class="space-y-2">
            <flux:heading>{{ __('Approve the season first: the competitions are created on approval.') }}</flux:heading>
            <div>
                <flux:button :href="route('dashboard.seasons')" wire:navigate>{{ __('Go to season setup') }}</flux:button>
            </div>
        </flux:card>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:text>
                @if ($this->editable)
                    {{ __('You can change the questions until the match starts.') }}
                @else
                    <span
                        class="text-amber-600 dark:text-amber-400">{{ __('The questions of this matchday can no longer be changed.') }}</span>
                @endif
            </flux:text>

            @if ($this->editable)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:button variant="primary" icon="sparkles" wire:click="draw">
                        <span wire:loading.remove wire:target="draw">{{ __('Draw all missing') }}</span>
                        <span wire:loading wire:target="draw">{{ __('Saving...') }}</span>
                    </flux:button>
                @endcan
            @endif
        </div>

        <div class="grid gap-4">
            @foreach ($this->types as $type)
                <flux:card class="space-y-4" wire:key="type-{{ $type->value }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <flux:heading>{{ $type->questionSetLabel() }}</flux:heading>
                            <flux:text class="text-xs">{{ $this->filled($type) }} /
                                {{ \App\Models\MatchdayQuestion::PER_SIDE * 2 }}</flux:text>
                        </div>

                        @if ($this->editable)
                            @can(\App\Enums\Permission::SeasonEdit->value)
                                <div class="flex gap-1">
                                    <flux:button size="xs" icon="sparkles" wire:click="draw('{{ $type->value }}')">{{ __('Draw') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="clearType('{{ $type->value }}')">
                                        {{ __('Clear') }}</flux:button>
                                </div>
                            @endcan
                        @endif
                    </div>

                    {{-- Ofensywne z lewej, defensywne z prawej (na dużym ekranie). --}}
                    <div class="grid gap-6 md:grid-cols-2">
                    @foreach (\App\Enums\QuestionSide::cases() as $side)
                        <div class="space-y-1">
                            <flux:badge size="sm" :color="$side->color()">{{ $side->label() }}</flux:badge>

                            <ol class="space-y-1 pt-1">
                                @foreach (range(1, \App\Models\MatchdayQuestion::PER_SIDE) as $position)
                                    @php
                                        $slot = collect($this->assigned)->get($type->value . '|' . $side->value . '|' . $position);
                                    @endphp

                                    <li class="flex items-start gap-2 text-sm"
                                        wire:key="slot-{{ $type->value }}-{{ $side->value }}-{{ $position }}">
                                        <span class="w-5 shrink-0 text-right tabular-nums text-zinc-400">{{ $position }}.</span>

                                        @if ($slot)
                                            <span class="min-w-0 flex-1">{{ $slot->question->text }}</span>
                                        @else
                                            <span class="min-w-0 flex-1 italic text-zinc-400">{{ __('empty') }}</span>
                                        @endif

                                        @if ($this->editable)
                                            @can(\App\Enums\Permission::SeasonEdit->value)
                                                <flux:button size="xs" variant="ghost" icon="pencil-square" inset="top bottom"
                                                    wire:click="openPick('{{ $type->value }}', '{{ $side->value }}', {{ $position }})"
                                                    :aria-label="__('Choose a question')" />
                                                @if ($slot)
                                                    <flux:button size="xs" variant="ghost" icon="x-mark" inset="top bottom"
                                                        wire:click="clearSlot('{{ $type->value }}', '{{ $side->value }}', {{ $position }})"
                                                        :aria-label="__('Remove')" />
                                                @endif
                                            @endcan
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    {{-- Modal: wybór pytania do miejsca --}}
    <flux:modal name="pick-question" class="w-full md:w-[38rem]" @close="resetPick">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Choose a question') }}</flux:heading>

            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable
                :placeholder="__('Search questions...')" />

            <div class="max-h-96 space-y-1 overflow-y-auto">
                @forelse ($this->candidates as $question)
                    <button type="button" wire:click="assign({{ $question->id }})" wire:key="cand-{{ $question->id }}"
                        class="w-full rounded-md px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
                        {{ $question->text }}
                    </button>
                @empty
                    <flux:text class="py-6 text-center">
                        {{ __('No free questions of this type match. Add more in the Question bank.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>