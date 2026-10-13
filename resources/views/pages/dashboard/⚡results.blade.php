<?php

use App\Actions\Results\SaveMatchdayResult;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Models\Matchday;
use App\Models\Season;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Wyniki kolejek (regulamin, punkt 11): admin wpisuje wynik meczu Lecha po 90 minutach.
 * Wynik zapisuje się od razu. Po jego wpisaniu pojawia się
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
        unset($this->matchday);
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

    /** Wynik zapisuje się sam chwilę po wpisaniu liczby (oba pola muszą być wypełnione). */
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
        $text = __('Matchday recalculated. Matches settled: :count.', ['count' => $stats['fixtures']]);

        // Liga podwórkowa losuje się sama po przeliczeniu (SaveMatchdayResult::recalculate).
        if (($stats['swiss'] ?? 0) > 0) {
            $text .= ' ' . __('Liga podwórkowa: the next round has been drawn (:count matches).', ['count' => $stats['swiss']]);
        }

        Flux::toast(variant: 'success', text: $text);
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

}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Results') }}</flux:heading>
            <flux:subheading>
                {{ __('Enter the Lech score after 90 minutes. Every change is saved at once, then recalculate the matchday.') }}
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
                <flux:callout variant="warning" icon="lock-closed" :heading="$this->blocker" />
            @endif

            {{-- Wynik zawsze z perspektywy Lecha (Lech : rywal), po 90 minutach. --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.live.debounce.600ms="lech"
                        :label="'Lech Poznań'" :disabled="! $canSave" />
                </div>
                <span class="pb-2 text-lg font-semibold">:</span>
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.live.debounce.600ms="opponent"
                        :label="$this->matchday->opponent ?? __('Opponent')" :disabled="! $canSave" />
                </div>
            </div>
        </flux:card>

        @if ($canSave)
            @php
                $played = $this->matchday->status === \App\Enums\MatchdayStatus::Played;
                $hasScore = $this->matchday->lech_goals !== null;
            @endphp

            @if (!$hasScore)
                <flux:text class="text-sm">{{ __('Enter the score: it saves itself, then recalculate the matchday.') }}</flux:text>
            @elseif (!$played)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('The result is saved, but the matchday is not recalculated yet.') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Recalculate the matchday to settle the points and matches.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                <flux:text class="text-sm">{{ __('The matchday is recalculated. Every change recalculates it again automatically.') }}</flux:text>
            @endif

            <div>
                <flux:button :variant="$played ? 'filled' : 'primary'" icon="calculator" wire:click="recalculate" wire:loading.attr="disabled"
                    :disabled="! $hasScore">
                    <span wire:loading.remove wire:target="recalculate">{{ $played ? __('Recalculate again') : __('Recalculate results') }}</span>
                    <span wire:loading wire:target="recalculate">{{ __('Recalculating...') }}</span>
                </flux:button>
            </div>
        @endif

        {{-- Raport meczu do desygnowania zawodników (pojawia się po wpisaniu wyniku, przy zmianie wyniku ładuje się od nowa). --}}
        @if ($this->matchday->lech_goals !== null)
            <livewire:pages::dashboard.match-report :matchday-id="$this->matchday->id"
                :key="'report-' . $this->matchday->id . '-' . $this->matchday->lech_goals . '-' . $this->matchday->opponent_goals" />
        @endif
    @endif
</div>
