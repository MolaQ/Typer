<?php

use App\Enums\CompetitionType;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Support\CupBracket;
use App\Support\Rivals;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Uniwersalne okno pojedynku dwóch zespołów #LechTYPER, osadzone raz w layoucie publicznym.
 * Otwiera je zdarzenie „show-duel” z id meczu, np. wire:click="$dispatch('show-duel', { id: 12 })"
 * (strona wyników, zakładka Rozgrywki na /tips). Treść: partials/duel-card.
 */
new class extends Component {
    public ?int $fixtureId = null;

    #[On('show-duel')]
    public function open(int $id): void
    {
        $this->fixtureId = $id;
        unset($this->card);
        Flux::modal('duel-details')->show();
    }

    /** Dane pojedynku (App\Support\Rivals::fixture) z rozgrywkami, rundą i meczem Lecha tej kolejki. */
    #[Computed]
    public function card(): ?array
    {
        $fixture = $this->fixtureId ? Fixture::with('competition')->find($this->fixtureId) : null;

        if (!$fixture) {
            return null;
        }

        $competition = $fixture->competition;
        $isCup = $competition->type === CompetitionType::Cup;
        $matchday = Matchday::where('season_id', $competition->season_id)->where('number', $fixture->round)->first();

        return Rivals::fixture($fixture, $matchday, auth()->user()) + [
            'round' => $fixture->round,
            'matchday' => $matchday,
            'competition_name' => $competition->name ?: $competition->type->label(),
            'round_label' => $isCup ? CupBracket::roundName($fixture->round) : __('Matchday :number', ['number' => $fixture->round]),
            'is_cup' => $isCup,
        ];
    }
}; ?>

<div>
    <flux:modal name="duel-details" class="w-full max-w-2xl">
        @if ($card = $this->card)
            @include('partials.duel-card', [
                'card' => $card,
                'competitionName' => $card['competition_name'],
                'roundLabel' => $card['round_label'],
                'isCup' => $card['is_cup'],
            ])
        @endif
    </flux:modal>
</div>
