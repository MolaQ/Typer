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
 * odpowiedzi na pytania kolejki. Wynik i każda odpowiedź zapisują się od razu. Po wpisaniu wyniku pojawia się
 * przycisk „Przelicz wyniki” (status „rozegrana”, punkty, mecze, awanse w pucharze), a kolejka już przeliczona
 * przelicza się od nowa po każdej zmianie, z informacją w toście. Podgląd: season-list, zapis: season-edit.
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

    /** Wynik zapisuje się od razu po wyjściu z pola (oba pola muszą być wypełnione). */
    public function updatedLech(): void
    {
        $this->saveScore();
    }

    public function updatedOpponent(): void
    {
        $this->saveScore();
    }

    private function saveScore(): void
    {
        if (!$this->authorizedMatchday() || $this->lech === '' || $this->opponent === '') {
            return;
        }

        $this->attempt(function (SaveMatchdayResult $action): void {
            $action->saveScore($this->matchday, $this->lech, $this->opponent);
        }, __('Result saved.'));
    }

    /** Kliknięcie odpowiedzi zapisuje ją od razu. */
    public function updatedCorrect(mixed $value, string $key): void
    {
        if (!$this->authorizedMatchday()) {
            return;
        }

        $this->attempt(function (SaveMatchdayResult $action) use ($value, $key): void {
            $action->saveAnswer($this->matchday, (int) $key, $value);
        });
    }

    /** Przycisk „Przelicz wyniki”. */
    public function recalculate(SaveMatchdayResult $action): void
    {
        if (!$this->authorizedMatchday()) {
            return;
        }

        try {
            $stats = $action->recalculate($this->matchday);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->refreshMatchday();
        Flux::toast(variant: 'success', text: __('Matchday recalculated. Matches settled: :count.', ['count' => $stats['fixtures']]));
    }

    /**
     * Zapis zmiany, a gdy kolejka była już przeliczona, od razu przeliczenie od nowa (z informacją w toście).
     */
    private function attempt(callable $change, ?string $message = null): void
    {
        $action = app(SaveMatchdayResult::class);

        try {
            $change($action);

            if ($this->matchday->fresh()->status === MatchdayStatus::Played) {
                $stats = $action->recalculate($this->matchday->fresh());
                $message = __('Saved and the matchday recalculated. Matches settled: :count.', ['count' => $stats['fixtures']]);
            }
        } catch (DomainException $e) {
            $this->refreshMatchday();
            $this->loadResult();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->refreshMatchday();

        if ($message) {
            Flux::toast(variant: 'success', text: $message);
        }
    }

    private function authorizedMatchday(): bool
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);

        return $this->matchday !== null;
    }

    private function refreshMatchday(): void
    {
        unset($this->matchdays, $this->matchday, $this->blocker);
    }

    /** Ile pytań nie ma jeszcze poprawnej odpowiedzi (przy przeliczeniu zostaną anulowane). */
    public function unanswered(): int
    {
        return count(array_filter($this->correct, fn($value) => $value === '' || $value === null));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Results') }}</flux:heading>
            <flux:subheading>
                {{ __('Enter the Lech score after 90 minutes and the correct answers. Every change is saved at once, then recalculate the matchday.') }}
                {{ __('A question left without a correct answer is cancelled at the recalculation and all answers to it are deleted.') }}
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
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.blur="lech"
                        :label="'Lech Poznań'" :disabled="! $canSave" />
                </div>
                <span class="pb-2 text-lg font-semibold">:</span>
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.blur="opponent"
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

        @if ($canSave && $this->matchday->lech_goals !== null)
            @php
                $played = $this->matchday->status === \App\Enums\MatchdayStatus::Played;
                $unanswered = $this->unanswered();
            @endphp

            @if (!$played)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('The result is saved, but the matchday is not recalculated yet.') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Recalculate the matchday to settle the points and matches.') }}
                        @if ($unanswered > 0)
                            {{ __('Questions without a correct answer (:count) will be cancelled.', ['count' => $unanswered]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @else
                <flux:text class="text-sm">{{ __('The matchday is recalculated. Every change recalculates it again automatically.') }}</flux:text>
            @endif

            <div>
                <flux:button :variant="$played ? 'filled' : 'primary'" icon="calculator" wire:click="recalculate" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="recalculate">{{ $played ? __('Recalculate again') : __('Recalculate results') }}</span>
                    <span wire:loading wire:target="recalculate">{{ __('Recalculating...') }}</span>
                </flux:button>
            </div>
        @endif
    @endif
</div>
