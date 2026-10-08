<?php

use App\Actions\Competitions\BuildCompetitions;
use App\Actions\Competitions\DrawSwissRound;
use App\Enums\CompetitionType;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Support\CupBracket;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Terminarz wszystkich rozgrywek sezonu (regulamin, punkt 4 i 7):
 *  - ligi i rozgrywki ręczne z 10 zespołami: 9 kolejek po 5 meczów (plansza z kolejkami),
 *  - Puchar Polski: stała drabinka 512 zespołów, 9 rund (lista meczów wybranej rundy),
 *  - Liga podwórkowa: system szwajcarski, pary losuje się rundami przyciskiem "Losuj rundę".
 * Podgląd: season-list. Losowanie rundy i generowanie brakujących rozgrywek: season-edit.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    // Wybrane rozgrywki: league-1..league-10, cup, swiss albo c-ID (rozgrywki ręczne).
    #[Url(as: 'c', except: 'league-1')]
    public string $key = 'league-1';

    // Wybrana runda (puchar i liga szwajcarska).
    #[Url(as: 'round', except: 1)]
    public int $round = 1;

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

    public function updatedSeasonId(): void
    {
        $this->reset('round');
        $this->resetPage('matchesPage');
    }

    public function updatedKey(): void
    {
        $this->reset('round');
        $this->resetPage('matchesPage');
    }

    public function updatedRound(): void
    {
        $this->resetPage('matchesPage');
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

    /** Rozgrywki sezonu do listy wyboru: klucz => nazwa (ligi, puchar, podwórkowa, ręczne). */
    #[Computed]
    public function options(): array
    {
        $options = [];

        foreach (Competition::where('season_id', $this->seasonId)->orderBy('tier')->orderBy('id')->get() as $competition) {
            $options[$this->keyOf($competition)] = $competition->name;
        }

        return $options;
    }

    #[Computed]
    public function competition(): ?Competition
    {
        $key = array_key_exists($this->key, $this->options) ? $this->key : array_key_first($this->options);

        if ($key === null) {
            return null;
        }

        return Competition::where('season_id', $this->seasonId)->get()->first(fn(Competition $c) => $this->keyOf($c) === $key);
    }

    /** Czy to rozgrywki z planszą kolejek (ligi 10 zespołów)? */
    #[Computed]
    public function isBoard(): bool
    {
        return (bool) $this->competition?->type->isRoundRobin();
    }

    /** Czy to rozgrywki z listą meczów rundy (puchar, liga szwajcarska)? */
    #[Computed]
    public function isRoundList(): bool
    {
        return in_array($this->competition?->type, [CompetitionType::Cup, CompetitionType::Swiss], true);
    }

    #[Computed]
    public function entryCount(): int
    {
        return $this->competition ? $this->competition->entries()->count() : 0;
    }

    /** Uczestnicy małych rozgrywek (liga, rozgrywki ręczne) według rozstawienia. */
    #[Computed]
    public function entries()
    {
        if (!$this->competition || $this->isRoundList) {
            return collect();
        }

        return $this->competition
            ->entries()
            ->with(['seasonTeam.user:id,name,team_name,team_abbr', 'seasonTeam.bot:id,name'])
            ->get();
    }

    /** Plansza kolejek: [1 => [mecze], 2 => ...]. */
    #[Computed]
    public function rounds()
    {
        if (!$this->competition || !$this->isBoard) {
            return collect();
        }

        return $this->competition->fixtures()->with($this->sideRelations())->get()->groupBy('round');
    }

    /** Mecze wybranej rundy (puchar, liga szwajcarska), stronicowane. */
    #[Computed]
    public function roundFixtures()
    {
        if (!$this->competition || !$this->isRoundList) {
            return null;
        }

        return Fixture::query()->with($this->sideRelations())->where('competition_id', $this->competition->id)->where('round', $this->round)->orderBy('id')->paginate(32, pageName: 'matchesPage');
    }

    /** Ostatnia wylosowana runda ligi szwajcarskiej (0 = żadna). */
    #[Computed]
    public function drawnRounds(): int
    {
        if (!$this->competition || $this->competition->type !== CompetitionType::Swiss) {
            return 0;
        }

        return (int) Fixture::where('competition_id', $this->competition->id)->max('round');
    }

    /** Mecze Lecha z kolejek sezonu (rywal i termin w nagłówku kolejki). */
    #[Computed]
    public function matchdays()
    {
        return Matchday::where('season_id', $this->seasonId)->get()->keyBy('number');
    }

    /** Brakujące rozgrywki automatyczne można wygenerować w zatwierdzonym i aktywnym sezonie. */
    #[Computed]
    public function canGenerate(): bool
    {
        return in_array($this->season?->status, [SeasonStatus::Approved, SeasonStatus::Active], true);
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    /** Losuje kolejną rundę ligi podwórkowej (rundy 2-9 wymagają klasyfikacji z wyników, etap 11-12). */
    public function drawRound(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $competition = $this->competition;

        if (!$competition || $competition->type !== CompetitionType::Swiss) {
            return;
        }

        $next = $this->drawnRounds + 1;

        try {
            $matches = app(DrawSwissRound::class)->handle($competition, $next);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'warning');

            return;
        }

        $this->round = $next;
        unset($this->drawnRounds, $this->roundFixtures);
        Flux::toast(text: __('Round :round drawn: :matches matches.', ['round' => $next, 'matches' => $matches]), variant: 'success');
    }

    /** Tworzy ligi, puchar i ligę podwórkową, których jeszcze brakuje (np. po aktualizacji aplikacji). */
    public function generateMissing(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        if (!$this->canGenerate) {
            return;
        }

        try {
            app(BuildCompetitions::class)->handle($this->season);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->options, $this->competition);
        Flux::toast(text: __('Missing competitions generated.'), variant: 'success');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function keyOf(Competition $competition): string
    {
        return match ($competition->type) {
            CompetitionType::League => 'league-' . $competition->tier,
            CompetitionType::Cup => 'cup',
            CompetitionType::Swiss => 'swiss',
            default => 'c-' . $competition->id,
        };
    }

    private function sideRelations(): array
    {
        return ['home.seasonTeam.user:id,name,team_name,team_abbr', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name,team_abbr', 'away.seasonTeam.bot:id,name'];
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Fixtures') }}</flux:heading>
            <flux:subheading>{{ __('Leagues, Puchar Polski, Liga podwórkowa and the competitions created by hand.') }}
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
    @elseif (!$this->competition)
        <flux:card class="space-y-4">
            <flux:heading>{{ __('No fixtures yet.') }}</flux:heading>
            <flux:text>{{ __('The fixtures are generated when the season is approved.') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('dashboard.seasons')" wire:navigate>{{ __('Go to season setup') }}
                </flux:button>

                @if ($this->canGenerate)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <flux:button variant="primary" icon="bolt" wire:click="generateMissing">
                            {{ __('Generate missing competitions') }}</flux:button>
                    @endcan
                @endif
            </div>
        </flux:card>
    @else
        <div class="flex flex-wrap items-end gap-4">
            <div class="w-full sm:w-72">
                <flux:select wire:model.live="key" :label="__('Competition')">
                    @foreach ($this->options as $optionKey => $optionName)
                        <flux:select.option :value="$optionKey">{{ $optionName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->isRoundList)
                <div class="w-full sm:w-56">
                    <flux:select wire:model.live="round" :label="__('Round')">
                        @foreach (range(1, \App\Support\CupBracket::ROUNDS) as $number)
                            <flux:select.option :value="$number">
                                {{ $this->competition->type === \App\Enums\CompetitionType::Cup ? $number . '. ' . \App\Support\CupBracket::roundName($number) : __('Round :number', ['number' => $number]) }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @if ($this->canGenerate)
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:button size="sm" variant="ghost" icon="bolt" wire:click="generateMissing">
                        {{ __('Generate missing competitions') }}</flux:button>
                @endcan
            @endif
        </div>

        {{-- ============ Ligi i rozgrywki 10-zespołowe: plansza kolejek ============ --}}
        @if ($this->isBoard)
            <div class="grid gap-6 xl:grid-cols-[18rem_1fr]">

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

                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    @forelse ($this->rounds as $number => $fixtures)
                        @php
                            $matchday = $this->matchdays->get($number);
                        @endphp

                        <flux:card class="space-y-3">
                            <div>
                                <flux:heading>{{ __('Round :number', ['number' => $number]) }}</flux:heading>
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
                                            class="text-[11px] tabular-nums text-zinc-400/70 dark:text-zinc-500">{{ $fixture->home_seat }}&ndash;{{ $fixture->away_seat }}</span>
                                        <span class="truncate text-right">{{ $fixture->home->seasonTeam->name }}</span>
                                        <span class="text-zinc-400">&ndash;</span>
                                        <span class="truncate">{{ $fixture->away->seasonTeam->name }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </flux:card>
                    @empty
                        <flux:card class="md:col-span-2 2xl:col-span-3">
                            <flux:heading>{{ __('No matches yet.') }}</flux:heading>
                            <flux:text class="mt-1">
                                {{ __('This competition has no fixtures generated yet. Add exactly 10 teams and generate them on the Competitions page.') }}
                            </flux:text>
                        </flux:card>
                    @endforelse
                </div>
            </div>

            {{-- ============ Puchar Polski i Liga podwórkowa: lista meczów rundy ============ --}}
        @elseif ($this->isRoundList)
            @php
                $isCup = $this->competition->type === \App\Enums\CompetitionType::Cup;
                $matches = $this->roundFixtures;
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-4">
                <flux:text>
                    {{ __(':count teams', ['count' => $this->entryCount]) }}
                    @if ($isCup)
                        &middot; {{ __('Round :number', ['number' => $round]) }}:
                        {{ \App\Support\CupBracket::seatsInRound($round) }} {{ __('teams') }}
                    @endif
                </flux:text>

                @if (!$isCup)
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        @if ($this->drawnRounds < \App\Actions\Competitions\DrawSwissRound::ROUNDS)
                            <flux:button variant="primary" icon="arrows-right-left" wire:click="drawRound">
                                {{ __('Draw round :number', ['number' => $this->drawnRounds + 1]) }}
                            </flux:button>
                        @endif
                    @endcan
                @endif
            </div>

            @if ($isCup)
                <flux:text class="text-sm">
                    {{ __('The bracket is fixed: the best seat plays the worst. The winner takes the better seat of the pair, so later rounds show seat numbers until the results decide who stands there.') }}
                </flux:text>
            @else
                <flux:text class="text-sm">
                    {{ __('Round 1 is drawn from the pre-season list (1-2, 3-4, ...). Next rounds follow the standings, which need match results. With an odd number of teams the lowest ranked team without a bye plays a virtual opponent.') }}
                </flux:text>
            @endif

            <flux:table :paginate="$matches">
                <flux:table.columns>
                    <flux:table.column>{{ __('Seats') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Home') }}</flux:table.column>
                    <flux:table.column>{{ __('Away') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($matches as $fixture)
                        <flux:table.row :key="'fx-'.$fixture->id">
                            <flux:table.cell class="text-xs tabular-nums text-zinc-400">
                                {{ $fixture->home_seat }}&ndash;{{ $fixture->away_seat ?? '—' }}
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @if ($fixture->home)
                                    {{ $fixture->home->seasonTeam->name }}
                                @else
                                    <span
                                        class="text-zinc-400">{{ __('Seat :number', ['number' => $fixture->home_seat]) }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($fixture->away)
                                    {{ $fixture->away->seasonTeam->name }}
                                @elseif ($fixture->away_seat)
                                    <span
                                        class="text-zinc-400">{{ __('Seat :number', ['number' => $fixture->away_seat]) }}</span>
                                @else
                                    <span class="italic text-zinc-400">{{ __('Virtual opponent') }}</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="py-10 text-center text-zinc-500">
                                {{ $isCup ? __('No matches in this round.') : __('This round has not been drawn yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            {{-- ============ Rozgrywki ręczne bez stałego terminarza (Liga Legend, Złota Liga) ============ --}}
        @else
            <flux:card class="space-y-3">
                <flux:heading>{{ $this->competition->name }}</flux:heading>
                <flux:text>
                    {{ __('The fixtures of this competition are not generated automatically yet. The participants are managed on the Competitions page.') }}
                </flux:text>

                <ol class="space-y-1">
                    @foreach ($this->entries as $entry)
                        <li class="flex items-center gap-2 text-sm">
                            <span class="w-6 text-right tabular-nums text-zinc-500">{{ $entry->seed }}.</span>
                            <span class="truncate">{{ $entry->seasonTeam->name }}</span>
                        </li>
                    @endforeach
                </ol>
            </flux:card>
        @endif
    @endif
</div>
