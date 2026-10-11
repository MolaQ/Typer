<?php

use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\Season;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Prawy panel strony wyników, na samej górze: prawdziwy mecz Lecha w wybranej kolejce (rozgrywki Lecha, DOM/WYJAZD,
 * wynik albo termin) w barwach strony. Rozgrywki Lecha są tylko informacją, więc bez barw rozgrywek gry.
 * Kolejkę podaje strona wyników zdarzeniem „results-matchday”; przed nim panel bierze ją z adresu.
 * Kliknięcie otwiera okno meczu (pages::home.lech-match-modal, zdarzenie „show-lech-match”).
 */
new class extends Component {
    public int $matchdayId = 0;

    public function mount(): void
    {
        $seasonNumber = (int) str_replace('sezon-', '', (string) request()->route('season_slug'));
        $round = (int) str_replace('kolejka-', '', (string) request()->route('round_slug'));

        $season = ($seasonNumber > 0 ? Season::where('number', $seasonNumber)->first() : null)
            ?? Season::current()
            ?? Season::where('status', SeasonStatus::Finished)->orderByDesc('number')->first();

        if (!$season) {
            return;
        }

        $matchdays = Matchday::where('season_id', $season->id);
        $round = $round > 0 ? $round : max(1, (int) (clone $matchdays)->where('status', MatchdayStatus::Played)->max('number'));

        $this->matchdayId = (int) (clone $matchdays)->where('number', $round)->value('id');
    }

    #[On('results-matchday')]
    public function select(int $id): void
    {
        $this->matchdayId = $id;
        unset($this->matchday);
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdayId ? Matchday::find($this->matchdayId) : null;
    }
}; ?>

<div>
    @if (($matchday = $this->matchday) && filled($matchday->opponent))
        @php
            $played = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;
        @endphp
        <button type="button" wire:click="$dispatch('show-lech-match', { id: {{ $matchday->id }} })" title="{{ __('Match details') }}"
            class="lech-banner lech-bar-shadow block w-full space-y-2 rounded-2xl px-4 py-3 text-left transition hover:brightness-110">
            <div class="flex items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-widest text-lech-200">
                <span>{{ __('Lech match') }} · {{ __('Matchday :number', ['number' => $matchday->number]) }}</span>
                <span class="rounded-full bg-white/15 px-2 py-0.5 text-white">{{ $matchday->is_home ? __('HOME') : __('AWAY') }}</span>
            </div>
            @if ($matchday->competitionLabel())
                <div class="text-xs text-lech-100">{{ $matchday->competitionLabel() }}</div>
            @endif
            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                <span class="truncate text-sm font-bold">Lech</span>
                <span class="rounded-lg bg-white/15 px-2 py-0.5 text-lg font-black tabular-nums">
                    {{ $played ? $matchday->lech_goals . ':' . $matchday->opponent_goals : '–:–' }}
                </span>
                <span class="truncate text-right text-sm font-bold">{{ $matchday->opponent }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs text-lech-100">
                <span>{{ $matchday->kickoff_at?->translatedFormat('j M Y, H:i') }}</span>
                <span class="inline-flex items-center gap-1 font-semibold text-white">
                    {{ __('Questions and details') }}
                    <flux:icon.chevron-right variant="micro" />
                </span>
            </div>
        </button>
    @endif
</div>
