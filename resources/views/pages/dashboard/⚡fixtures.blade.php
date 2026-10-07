<?php

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\Competition;
use App\Models\Matchday;
use App\Models\Season;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Terminarz lig (regulamin, punkt 4): 9 kolejek, w każdej 5 meczów w lidze.
 * Strona tylko do podglądu (uprawnienie season-list). Terminarz powstaje przy
 * zatwierdzeniu sezonu, a cofnięcie zatwierdzenia go usuwa.
 */
new class extends Component {
    // Wybrany sezon i liga (zapamiętane w adresie).
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    #[Url(as: 'league', except: 1)]
    public int $leagueTier = 1;

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Fixtures'));
    }

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

    /** Wszystkie ligi. Podwórkowa nie ma stałego terminarza (system szwajcarski, etap 8b). */
    #[Computed]
    public function tiers(): array
    {
        return League::cases();
    }

    /** Rozgrywki wybranej ligi w wybranym sezonie albo null (sezon niezatwierdzony). */
    #[Computed]
    public function competition(): ?Competition
    {
        return Competition::query()->where('season_id', $this->seasonId)->where('type', CompetitionType::League->value)->where('tier', $this->leagueTier)->first();
    }

    /** Uczestnicy ligi według rozstawienia (z nazwami zespołów). */
    #[Computed]
    public function entries()
    {
        return $this->competition
            ? $this->competition
                ->entries()
                ->with(['seasonTeam.user:id,name,team_name,team_abbr', 'seasonTeam.bot:id,name'])
                ->get()
            : collect();
    }

    /** Mecze pogrupowane po kolejkach: [1 => [fixture, ...], 2 => ...]. */
    #[Computed]
    public function rounds()
    {
        if (!$this->competition) {
            return collect();
        }

        return $this->competition
            ->fixtures()
            ->with(['home.seasonTeam.user:id,name,team_name,team_abbr', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name,team_abbr', 'away.seasonTeam.bot:id,name'])
            ->get()
            ->groupBy('round');
    }

    /** Mecze Lecha z kolejek sezonu (rywal i termin do nagłówka każdej kolejki). */
    #[Computed]
    public function matchdays()
    {
        return Matchday::where('season_id', $this->seasonId)->get()->keyBy('number');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Fixtures') }}</flux:heading>
            <flux:subheading>{{ __('League fixtures: 9 rounds, every team plays every other team once.') }}
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
        <flux:card class="space-y-4">
            <flux:heading>{{ __('Create a season first.') }}</flux:heading>
            <div>
                <flux:button variant="primary" :href="route('dashboard.seasons')" wire:navigate>
                    {{ __('Go to season setup') }}
                </flux:button>
            </div>
        </flux:card>
    @else
        <div class="w-full sm:w-64">
            <flux:select wire:model.live="leagueTier" :label="__('League')">
                @foreach ($this->tiers as $league)
                    <flux:select.option :value="$league->value">{{ $league->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($leagueTier === \App\Enums\League::Podworkowa->value)
            <flux:card class="space-y-2">
                <flux:heading>{{ __('Liga podwórkowa') }}</flux:heading>
                <flux:text>
                    {{ __('Liga podwórkowa uses the Swiss system: the pairs of each round are drawn after the previous round. This arrives in the next stage.') }}
                </flux:text>
            </flux:card>
        @elseif (!$this->competition)
            <flux:card class="space-y-4">
                <flux:heading>{{ __('No fixtures yet.') }}</flux:heading>
                <flux:text>{{ __('The fixtures are generated when the season is approved.') }}</flux:text>
                <div>
                    <flux:button :href="route('dashboard.seasons')" wire:navigate>
                        {{ __('Go to season setup') }}
                    </flux:button>
                </div>
            </flux:card>
        @else
            <div class="grid gap-6 xl:grid-cols-[18rem_1fr]">

                {{-- Uczestnicy ligi: numer = rozstawienie, wyżej = faworyt (gospodarz) --}}
                <flux:card class="space-y-3 self-start">
                    <flux:heading>{{ $this->competition->name }}</flux:heading>

                    <ol class="space-y-1">
                        @foreach ($this->entries as $entry)
                            <li class="flex items-center gap-2 text-sm">
                                <span class="w-6 text-right tabular-nums text-zinc-500">{{ $entry->seed }}.</span>
                                <span class="truncate">{{ $entry->seasonTeam->name }}</span>
                                @if ($entry->seasonTeam->is_bot)
                                    <flux:badge size="sm" color="zinc">{{ __('Bot') }}</flux:badge>
                                @endif
                            </li>
                        @endforeach
                    </ol>

                    <flux:text class="text-xs">
                        {{ __('The team higher on the list is the favourite and plays at home.') }}</flux:text>
                </flux:card>

                {{-- Kolejki --}}
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($this->rounds as $round => $fixtures)
                        @php
                            $matchday = $this->matchdays->get($round);
                        @endphp

                        <flux:card class="space-y-3">
                            <div>
                                <flux:heading>{{ __('Round :number', ['number' => $round]) }}</flux:heading>
                                <flux:text class="text-xs">
                                    @if ($matchday && filled($matchday->opponent))
                                        {{ $matchday->fixture }}
                                        @if ($matchday->kickoff_at)
                                            &middot; {{ $matchday->kickoff_at->format('d.m.Y H:i') }}
                                        @endif
                                    @else
                                        {{ __('Lech match not set yet') }}
                                    @endif
                                </flux:text>
                            </div>

                            <ul class="space-y-2">
                                @foreach ($fixtures as $fixture)
                                    <li class="grid grid-cols-[2.25rem_1fr_auto_1fr] items-center gap-2 text-sm">
                                        <span
                                            class="text-[11px] tabular-nums text-zinc-400/70 dark:text-zinc-500">{{ $fixture->home->seed }}&ndash;{{ $fixture->away->seed }}</span>
                                        <span class="truncate text-right">{{ $fixture->home->seasonTeam->name }}</span>
                                        <span class="text-zinc-400">&ndash;</span>
                                        <span class="truncate">{{ $fixture->away->seasonTeam->name }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
