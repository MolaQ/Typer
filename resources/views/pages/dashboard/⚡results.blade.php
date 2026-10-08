<?php

use App\Actions\Questions\DrawQuestions;
use App\Actions\Results\SaveMatchdayResult;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\QuestionSide;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Wyniki kolejek (regulamin, punkt 11): admin wpisuje wynik meczu Lecha po 90 minutach i poprawne
 * odpowiedzi na wszystkie pytania kolejki. Zapis ustawia kolejkę na "rozegrana" i przelicza punkty,
 * wyniki meczów oraz awanse w pucharze. Podgląd: season-list, zapis: season-edit.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new class extends Component {
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    #[Url(as: 'matchday', except: 0)]
    public int $number = 0;

    public string $lech = '';
    public string $opponent = '';

    /** matchday_question_id => '1' | '0' | '' */
    public array $correct = [];

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }

        $this->pickDefaultMatchday();
        $this->loadResult();
    }

    public function render(): View
    {
        return $this->view()->title(__('Results'));
    }

    public function updatedSeasonId(): void
    {
        unset($this->matchdays, $this->matchday, $this->season);
        $this->number = 0;
        $this->pickDefaultMatchday();
        $this->loadResult();
    }

    public function updatedNumber(): void
    {
        unset($this->matchday, $this->assigned);
        $this->loadResult();
    }

    /** Domyślnie pierwsza kolejka bez wyniku (a gdy wszystkie mają wynik, ostatnia). */
    private function pickDefaultMatchday(): void
    {
        if ($this->number > 0 && $this->matchdays->firstWhere('number', $this->number)) {
            return;
        }

        $pending = $this->matchdays->first(fn($m) => $m->status !== MatchdayStatus::Played);
        $this->number = $pending?->number ?? ($this->matchdays->last()?->number ?? 0);
    }

    private function loadResult(): void
    {
        $matchday = $this->matchday;

        $this->lech = $matchday?->lech_goals !== null ? (string) $matchday->lech_goals : '';
        $this->opponent = $matchday?->opponent_goals !== null ? (string) $matchday->opponent_goals : '';
        $this->correct = $matchday
            ? MatchdayQuestion::where('matchday_id', $matchday->id)->get()
                ->mapWithKeys(fn($slot) => [$slot->id => $slot->correct_answer === null ? '' : ($slot->correct_answer ? '1' : '0')])
                ->all()
            : [];
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
        return $this->matchdays->firstWhere('number', $this->number);
    }

    /** Zestawy pytań kolejki: typ => [strona => miejsca]. */
    #[Computed]
    public function assigned(): array
    {
        if (!$this->matchday) {
            return [];
        }

        $all = MatchdayQuestion::with('question:id,text')->where('matchday_id', $this->matchday->id)->orderBy('position')->get();
        $sets = [];

        foreach (DrawQuestions::typesOfSeason($this->seasonId) as $type) {
            $own = $all->where('competition_type', $type);

            if ($own->isNotEmpty()) {
                $sets[] = [
                    'type' => $type,
                    'sides' => [
                        QuestionSide::Offensive->value => $own->where('side', QuestionSide::Offensive)->values(),
                        QuestionSide::Defensive->value => $own->where('side', QuestionSide::Defensive)->values(),
                    ],
                ];
            }
        }

        return $sets;
    }

    /** Powód, dla którego nie można teraz wpisać wyniku (null = można). */
    #[Computed]
    public function blocker(): ?string
    {
        if (!$this->matchday) {
            return null;
        }

        try {
            SaveMatchdayResult::ensureEditable($this->matchday);
        } catch (DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    public function save(SaveMatchdayResult $action): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);

        if (!$this->matchday) {
            return;
        }

        try {
            $stats = $action->handle($this->matchday, $this->lech, $this->opponent, $this->correct);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->matchdays, $this->matchday, $this->blocker);
        $this->loadResult();

        Flux::toast(variant: 'success', text: __('Result saved. Matches settled: :count.', ['count' => $stats['fixtures']]));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Results') }}</flux:heading>
            <flux:subheading>
                {{ __('Enter the Lech score after 90 minutes and the correct answers. Saving settles all matches of the matchday.') }}
                {{ __('“No answer” cancels the question and deletes all answers to it.') }}
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
                                @if ($option->status === \App\Enums\MatchdayStatus::Played) ({{ $option->lech_goals }}:{{ $option->opponent_goals }}) @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </div>

    @if (!$this->season || !$this->matchday)
        <flux:card class="space-y-4">
            <flux:heading>{{ __('No matchdays yet.') }}</flux:heading>
            <div>
                <flux:button :href="route('dashboard.matchdays')" wire:navigate>{{ __('Go to matchdays') }}</flux:button>
            </div>
        </flux:card>
    @else
        @php
            $canSave = $this->blocker === null && auth()->user()->can(\App\Enums\Permission::SeasonEdit->value);
        @endphp

        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Matchday :number', ['number' => $this->matchday->number]) }}:
                        {{ $this->matchday->fixture }}</flux:heading>
                    <flux:text>
                        {{ $this->matchday->kickoff_at ? $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') : __('not set yet') }}
                    </flux:text>
                </div>
                <flux:badge :color="$this->matchday->status->color()">{{ $this->matchday->status->label() }}</flux:badge>
            </div>

            @if ($this->blocker)
                <flux:text class="text-amber-600 dark:text-amber-400">{{ $this->blocker }}</flux:text>
            @endif

            {{-- Wynik zawsze z perspektywy Lecha (Lech : rywal), po 90 minutach. --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="lech"
                        :label="'Lech Poznań'" :disabled="! $canSave" />
                </div>
                <span class="pb-2 text-lg font-semibold">:</span>
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="opponent"
                        :label="$this->matchday->opponent ?? __('Opponent')" :disabled="! $canSave" />
                </div>
            </div>
        </flux:card>

        @if (count($this->assigned) === 0)
            <flux:card>
                <flux:text>{{ __('This matchday has no questions assigned.') }}</flux:text>
            </flux:card>
        @endif

        @foreach ($this->assigned as $set)
            <flux:card class="space-y-4" wire:key="set-{{ $set['type']->value }}">
                <flux:heading size="lg">{{ $set['type']->questionSetLabel() }}</flux:heading>

                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ($set['sides'] as $sideValue => $items)
                        @php
                            $side = \App\Enums\QuestionSide::from($sideValue);
                        @endphp
                        <div class="space-y-3">
                            <flux:badge :color="$side->color()">{{ $side->label() }}</flux:badge>

                            @foreach ($items as $item)
                                <div class="space-y-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                                    wire:key="c-{{ $item->id }}">
                                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item->position }}.
                                        {{ $item->question->text }}</flux:text>
                                    <x-answer-toggle :model="'correct.' . $item->id" :value="$correct[$item->id] ?? ''"
                                        :disabled="! $canSave" />
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endforeach

        @if ($canSave)
            <div>
                <flux:button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">
                        {{ $this->matchday->status === \App\Enums\MatchdayStatus::Played ? __('Save and recalculate') : __('Save result') }}
                    </span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        @endif
    @endif
</div>
