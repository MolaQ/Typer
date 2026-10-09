<?php

use App\Enums\CompetitionType;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\CupBracket;
use App\Support\LegendsRanking;
use App\Support\PlayerStats;
use App\Support\Premium;
use App\Support\Rivals;
use App\Support\Standings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Wyniki i tabele sezonu (dla wszystkich, także gości). Domyślnie sezon aktywny, a gdy go nie ma, ostatnio zakończony.
 * Strzałki w banerze przesuwają o jeden sezon wstecz albo do przodu (archiwum: sezony aktywne i zakończone).
 * Ligi i Liga podwórkowa: tabela + mecze wybranej kolejki. Puchar Polski: pasek rund, „Twoja droga”, karty meczów rundy
 * (partials/cup-fixture-card), objaśnienia i legenda jak w Lidze Legend.
 * Kliknięcie zespołu otwiera okno ze statystykami (podstawowe i premium), kliknięcie meczu okno ze szczegółami
 * (widoczność jak w podglądzie rywali: przed zamknięciem typowania, po zamknięciu i po wynikach).
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    // Wybrane rozgrywki: stały klucz Competition::slug() (ekstraklasa, i-liga, puchar-polski, liga-legend …).
    public string $key = '';

    public int $round = 0;

    // Numer oglądanego sezonu; 0 = domyślny (aktywny albo ostatnio zakończony).
    public int $seasonNumber = 0;

    /** Zespół (season_teams.id) i mecz pokazywane w oknach. */
    public ?int $teamId = null;

    /**
     * Przyjazny adres: /results/sezon-2/ekstraklasa/kolejka-9. Stare adresy z parametrami (?c=league-1&round=9&s=2,
     * np. w informacjach systemowych) też działają, a po pierwszej zmianie w formularzu adres zmienia się na nowy.
     */
    public function mount(?string $season_slug = null, ?string $competition_slug = null, ?string $round_slug = null): void
    {
        $this->seasonNumber = (int) str_replace('sezon-', '', $season_slug ?? (string) request()->query('s', '0'));
        $this->round = (int) str_replace('kolejka-', '', $round_slug ?? (string) request()->query('round', '0'));
        $this->key = $competition_slug ?? $this->legacyKey((string) request()->query('c', ''));

        $this->normalize();
    }

    /** Stare klucze z parametru c (league-N, cup, swiss, c-ID) na klucze z adresu. */
    private function legacyKey(string $key): string
    {
        if ($key === '' || !$this->season) {
            return '';
        }

        $competition = Competition::where('season_id', $this->season->id)->get()->first(fn(Competition $c) => match ($c->type) {
            CompetitionType::League => $key === 'league-' . $c->tier,
            CompetitionType::Cup => $key === 'cup',
            CompetitionType::Swiss => $key === 'swiss',
            default => $key === 'c-' . $c->id,
        });

        return $competition?->slug() ?? '';
    }

    /** Adres strony dla sezonu, rozgrywek i kolejki (domyślnie bieżący wybór). */
    public function pageUrl(?int $round = null, ?int $seasonNumber = null): string
    {
        $number = $seasonNumber ?? $this->season?->number;

        if (!$number || $this->key === '') {
            return route('results');
        }

        return route('results', [
            'season_slug' => 'sezon-' . $number,
            'competition_slug' => $this->key,
            'round_slug' => 'kolejka-' . ($round ?? $this->round),
        ]);
    }

    /** Po zmianie wyboru podmieniamy adres w pasku przeglądarki bez przeładowania strony. */
    private function syncUrl(): void
    {
        $this->js('history.replaceState(history.state, "", ' . json_encode($this->pageUrl()) . ')');
    }

    /** Poprawia wybór rozgrywek i kolejki po wejściu na stronę albo po zmianie sezonu. */
    private function normalize(): void
    {
        // Domyślnie rozgrywki zalogowanego gracza (jego liga albo podwórkowa), inaczej Ekstraklasa.
        if (!array_key_exists($this->key, $this->options)) {
            $this->key = $this->myKey() ?? (array_key_first($this->options) ?? '');
        }

        if ($this->round < 1 || $this->round > Matchday::PER_SEASON) {
            $this->round = max(1, (int) Matchday::where('season_id', $this->season?->id ?? 0)
                ->where('status', \App\Enums\MatchdayStatus::Played)->max('number'));
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Results and tables'));
    }

    public function updatedKey(): void
    {
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    public function updatedRound(): void
    {
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    /**
     * Numery sezonów do przeglądania (aktywny i zakończone), rosnąco.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function seasonNumbers(): array
    {
        return Season::whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Finished->value])
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($number) => (int) $number)
            ->all();
    }

    /** Sezon domyślny: aktywny, a gdy go nie ma, ostatnio zakończony. */
    #[Computed]
    public function defaultSeasonNumber(): int
    {
        $active = Season::current()?->number;

        return (int) ($active ?? Season::where('status', SeasonStatus::Finished->value)->max('number'));
    }

    #[Computed]
    public function season(): ?Season
    {
        $number = in_array($this->seasonNumber, $this->seasonNumbers, true) ? $this->seasonNumber : $this->defaultSeasonNumber;

        return $number > 0 ? Season::where('number', $number)->first() : null;
    }

    /** Numer sezonu o $step dalej na liście archiwum (−1 starszy, 1 nowszy) albo null na krańcu. */
    public function neighbourSeason(int $step): ?int
    {
        $index = array_search($this->season?->number, $this->seasonNumbers, true);

        return $index === false ? null : ($this->seasonNumbers[$index + $step] ?? null);
    }

    /**
     * Strzałki w banerze: przejście o jeden sezon. Rozgrywki i kolejka zostają (klucz rozgrywek jest taki sam
     * w każdym sezonie), więc można porównać ten sam etap w różnych sezonach.
     */
    public function shiftSeason(int $step): void
    {
        $target = $this->neighbourSeason($step <=> 0);

        if ($target === null) {
            return;
        }

        $this->seasonNumber = $target;
        $this->teamId = null;

        unset($this->season, $this->options, $this->competition, $this->isCup, $this->hasTable, $this->table,
            $this->isLegends, $this->legends, $this->legendsHistory, $this->cupPath, $this->cupPlayed, $this->matchday, $this->matches, $this->myTeamId);

        $this->normalize();
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    /** klucz => nazwa rozgrywek sezonu */
    #[Computed]
    public function options(): array
    {
        if (!$this->season) {
            return [];
        }

        $options = [];
        foreach (Competition::where('season_id', $this->season->id)->orderBy('tier')->orderBy('id')->get() as $competition) {
            $options[$this->keyOf($competition)] = $competition->name;
        }

        return $options;
    }

    #[Computed]
    public function competition(): ?Competition
    {
        if (!$this->season) {
            return null;
        }

        return Competition::with('sponsor')->where('season_id', $this->season->id)->get()->first(fn(Competition $c) => $this->keyOf($c) === $this->key);
    }

    #[Computed]
    public function isCup(): bool
    {
        return $this->competition?->type === CompetitionType::Cup;
    }

    #[Computed]
    public function hasTable(): bool
    {
        return $this->competition !== null && !$this->isCup && $this->competition->type !== CompetitionType::Legends;
    }

    #[Computed]
    public function table()
    {
        return $this->hasTable ? Standings::for($this->competition) : collect();
    }

    #[Computed]
    public function isLegends(): bool
    {
        return $this->competition?->type === CompetitionType::Legends;
    }

    /** Liga Legend: ranking po wybranej kolejce (narastająco od kolejki 1). */
    #[Computed]
    public function legends()
    {
        return $this->isLegends ? LegendsRanking::for($this->competition, $this->round) : collect();
    }

    /**
     * Liga Legend: miejsce zespołu po każdej kolejce 1..wybrana (wśród zespołów, które jeszcze grały).
     * entry_id => [kolejka => miejsce]. Do kafelków przy nazwie zespołu.
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function legendsHistory(): array
    {
        if (!$this->isLegends) {
            return [];
        }

        $played = min((int) Matchday::where('season_id', $this->season->id)->where('status', \App\Enums\MatchdayStatus::Played)->max('number'), $this->round);
        $history = [];

        if ($played < 1) {
            return [];
        }

        foreach (range(1, $played) as $stage) {

            foreach (LegendsRanking::for($this->competition, $stage, onlyAlive: true)->values() as $index => $row) {
                $history[$row['entry_id']][$stage] = $index + 1;
            }
        }

        return $history;
    }

    /**
     * Puchar Polski: mecze zespołu zalogowanego gracza w kolejnych rundach (runda => mecz).
     * Do paska „Twoja droga” i przypiętego meczu nad drabinką.
     *
     * @return array<int, Fixture>
     */
    #[Computed]
    public function cupPath(): array
    {
        if (!$this->isCup || !$this->myTeamId) {
            return [];
        }

        $entryId = \App\Models\CompetitionEntry::where('competition_id', $this->competition->id)
            ->where('season_team_id', $this->myTeamId)
            ->value('id');

        if (!$entryId) {
            return [];
        }

        return Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where(fn ($q) => $q->where('home_entry_id', $entryId)->orWhere('away_entry_id', $entryId))
            ->get()
            ->keyBy('round')
            ->all();
    }

    /** Puchar Polski: ile meczów wybranej rundy ma już wynik. */
    #[Computed]
    public function cupPlayed(): int
    {
        if (!$this->isCup) {
            return 0;
        }

        return Fixture::where('competition_id', $this->competition->id)->where('round', $this->round)->whereNotNull('home_goals')->count();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->season ? Matchday::where('season_id', $this->season->id)->where('number', $this->round)->first() : null;
    }

    #[Computed]
    public function matches()
    {
        if (!$this->competition) {
            return null;
        }

        return Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where('round', $this->round)
            ->orderBy('id')
            ->paginate(32, pageName: 'matchesPage');
    }

    /** Id zespołu zalogowanego gracza na liście sezonu (do wyróżnienia w tabeli). */
    #[Computed]
    public function myTeamId(): ?int
    {
        if (!auth()->check() || !$this->season) {
            return null;
        }

        return SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id');
    }

    /* ==================================================================
     | OKNA: ZESPÓŁ I MECZ
     * ================================================================*/

    public function showTeam(int $id): void
    {
        $this->teamId = $id;
        unset($this->teamCard);
        Flux::modal('duel-details')->close();
        Flux::modal('team-details')->show();
    }

    /** Szczegóły pojedynku w uniwersalnym oknie (pages::home.duel-modal w layoucie publicznym). */
    public function showFixture(int $id): void
    {
        $this->dispatch('show-duel', id: $id);
    }

    /** Statystyki zespołu w sezonie; część tylko dla premium (oglądającego). */
    #[Computed]
    public function teamCard(): ?array
    {
        if (!$this->teamId || !$this->season) {
            return null;
        }

        $team = SeasonTeam::with(['user:id,name,team_name,premium_until', 'bot:id,name'])
            ->where('season_id', $this->season->id)
            ->find($this->teamId);

        if (!$team) {
            return null;
        }

        $viewer = auth()->user();
        $premium = $viewer !== null && Premium::isActive($viewer);
        $index = $this->hasTable ? $this->table->search(fn($row) => $row['team']->id === $team->id) : false;

        return [
            'team' => $team,
            'place' => $index === false ? null : $index + 1,
            'count' => $this->table->count(),
            'stats' => PlayerStats::teamSeason($team, $this->season),
            'premium' => $premium,
            'owner_premium' => $team->user !== null && Premium::isActive($team->user),
            'favourite' => $premium && $team->user ? PlayerStats::favourites($team->user)['mine'] : null,
            'h2h' => $premium && $team->user_id !== $viewer?->id ? Rivals::headToHead($viewer, $team) : null,
        ];
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function myKey(): ?string
    {
        if (!$this->myTeamId) {
            return null;
        }

        $competition = Competition::where('season_id', $this->season->id)
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->whereHas('entries', fn($q) => $q->where('season_team_id', $this->myTeamId))
            ->first();

        return $competition ? $this->keyOf($competition) : null;
    }

    private function keyOf(Competition $competition): string
    {
        return $competition->slug();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    @php
        $older = $this->neighbourSeason(-1);
        $newer = $this->neighbourSeason(1);
        $archived = $this->season && $this->season->status !== \App\Enums\SeasonStatus::Active;
    @endphp
    <x-page-banner :title="__('Results and tables')" :subtitle="$this->season?->title"
        :eyebrow="$archived ? __('Season archive') : 'LechTYPER'">
        {{-- Dyskretne strzałki archiwum: o jeden sezon wstecz albo do przodu --}}
        @if ($older || $newer)
            <x-slot:aside>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="shiftSeason(-1)" @disabled(!$older)
                        title="{{ __('Previous season') }}" aria-label="{{ __('Previous season') }}"
                        class="flex size-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10">
                        <flux:icon.chevron-left variant="mini" />
                    </button>
                    <button type="button" wire:click="shiftSeason(1)" @disabled(!$newer)
                        title="{{ __('Next season') }}" aria-label="{{ __('Next season') }}"
                        class="flex size-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10">
                        <flux:icon.chevron-right variant="mini" />
                    </button>
                </div>
            </x-slot:aside>
        @endif
    </x-page-banner>

    @if (!$this->season || count($this->options) === 0)
        <flux:card>
            <flux:text>{{ __('There is no season to show yet.') }}</flux:text>
        </flux:card>
    @else
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-72">
                <flux:select wire:model.live="key" :label="__('Competition')">
                    @foreach ($this->options as $optionKey => $optionName)
                        <flux:select.option :value="$optionKey">{{ $optionName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="w-full sm:w-64">
                <flux:select wire:model.live="round" :label="$this->isCup ? __('Round') : __('Matchday')">
                    @foreach (range(1, \App\Models\Matchday::PER_SEASON) as $number)
                        <flux:select.option :value="$number">
                            {{ $this->isCup ? $number . '. ' . \App\Support\CupBracket::roundName($number) : __('Matchday :number', ['number' => $number]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->competition)
            <x-competition-header :competition="$this->competition" />
        @endif

        @if ($this->matchday && filled($this->matchday->opponent))
            <flux:text>
                {{ $this->matchday->fixture }}
                @if ($this->matchday->status === \App\Enums\MatchdayStatus::Played)
                    &middot; <span class="font-semibold">{{ $this->matchday->is_home ? $this->matchday->lech_goals . ':' . $this->matchday->opponent_goals : $this->matchday->opponent_goals . ':' . $this->matchday->lech_goals }}</span>
                @elseif ($this->matchday->kickoff_at)
                    &middot; {{ $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') }}
                @endif
            </flux:text>
        @endif

        <div class="grid gap-6 {{ $this->hasTable ? 'lg:grid-cols-[1fr_20rem]' : '' }}">
            {{-- ============ Tabela ============ --}}
            @if ($this->hasTable)
                <flux:card class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-zinc-500">
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="px-3 py-2 text-right">#</th>
                                <th class="px-3 py-2 text-left">{{ __('Team') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Matches played') }}">{{ __('P') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Won') }}">{{ __('W') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Drawn') }}">{{ __('D') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Lost') }}">{{ __('L') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Goals') }}">{{ __('Goals') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Exact tips') }}">{{ __('Ex.') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Bonus') }}">{{ __('Bon.') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Pts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->table as $index => $row)
                                @php
                                    $zone = \App\Support\Standings::zone($this->competition, $index + 1, $this->table->count());
                                    $badge = \App\Support\Standings::europeanBadge($this->competition, $index + 1);
                                    $zoneClass = match ($zone) {
                                        'up' => 'border-l-4 border-l-green-500',
                                        'down' => 'border-l-4 border-l-red-500',
                                        default => 'border-l-4 border-l-transparent',
                                    };
                                @endphp
                                <tr wire:key="row-{{ $row['entry_id'] }}"
                                    class="{{ $zoneClass }} border-b border-zinc-100 last:border-b-0 dark:border-zinc-700/50 {{ $row['team']->id == $this->myTeamId ? 'bg-amber-50 font-semibold dark:bg-amber-900/20' : '' }}">
                                    <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $index + 1 }}.</td>
                                    <td class="max-w-56 truncate px-3 py-1.5">
                                        <button type="button" wire:click="showTeam({{ $row['team']->id }})" class="truncate hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $row['team']->name }}</button>
                                        @if ($badge)
                                            <span class="ms-1 rounded bg-blue-100 px-1 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $badge }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['played'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['won'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['drawn'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['lost'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['for'] }}:{{ $row['against'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['exact'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['bonus'] }}</td>
                                    <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ $row['points'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (in_array($this->competition->type, [\App\Enums\CompetitionType::League, \App\Enums\CompetitionType::Swiss], true))
                        <div class="flex flex-wrap gap-4 border-t border-zinc-200 px-3 py-2 text-xs text-zinc-500 dark:border-zinc-700">
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-green-500"></span>{{ __('Promotion') }}</span>
                            @if ($this->competition->type === \App\Enums\CompetitionType::League)
                                <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-red-500"></span>{{ __('Relegation') }}</span>
                                <span>{{ __('LM, LE, LK: Liga Mistrzów, Europy and Konferencji next season') }}</span>
                            @endif
                        </div>
                    @endif
                </flux:card>
            @endif

            {{-- ============ Liga Legend: ranking z odcięciem ============ --}}
            @if ($this->isLegends)
                @php
                    $rounds = \App\Support\LegendsRanking::ROUNDS;
                    // Najpierw zespoły, które grają w tej rundzie (według punktów Legend), potem odpadnięci wcześniej.
                    [$alive, $gone] = $this->legends->partition(fn ($row) => $row['eliminated_round'] === null || $row['eliminated_round'] >= $round);
                    $legendRows = $alive->concat($gone->sortByDesc('eliminated_round'))->values();
                    $limit = $round < $rounds ? \App\Support\LegendsRanking::limitAfter($round) : 1;
                    $history = $this->legendsHistory;
                @endphp
                <div class="space-y-2">
                    {{-- Pasek etapów: 512 → 256 → … → Finał, każdy etap to link do swojej kolejki --}}
                    <nav class="flex flex-wrap items-center gap-1 text-xs" aria-label="{{ __('Rounds') }}">
                        @foreach (range(1, $rounds) as $stage)
                            @php
                                $stageClass = $stage === $round
                                    ? 'lech-bar font-semibold'
                                    : ($stage < $round ? 'bg-lech-100 text-lech-800 hover:bg-lech-200 dark:bg-lech-900/40 dark:text-lech-200' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800');
                            @endphp
                            <a href="{{ $this->pageUrl($stage) }}" wire:navigate title="{{ __('Matchday :number', ['number' => $stage]) }}: {{ \App\Enums\KnockoutStage::labelFor($stage) }}"
                                class="{{ $stageClass }} rounded-full px-2.5 py-1 tabular-nums transition">
                                {{ $stage >= \App\Enums\KnockoutStage::QuarterFinal->value ? \App\Enums\KnockoutStage::labelFor($stage) : \App\Support\LegendsRanking::limitAfter($stage - 1) }}
                            </a>
                            @if ($stage < $rounds)
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                            @endif
                        @endforeach
                    </nav>

                    <flux:card class="overflow-x-auto p-0">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-zinc-500">
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-3 py-2 text-right">#</th>
                                    <th class="px-3 py-2 text-left">{{ __('Team') }}</th>
                                    <th class="px-2 py-2 text-left" title="{{ __('Place after each matchday') }}">{{ __('Matchdays 1-9') }}</th>
                                    <th class="px-2 py-2 text-right" title="{{ __('Points in matchday :round', ['round' => $round]) }}">{{ __('Matchday') }}</th>
                                    <th class="px-2 py-2 text-right" title="{{ __('Exact score, outcome and goal difference') }}">{{ __('Tip') }}</th>
                                    <th class="px-2 py-2 text-right" title="{{ __('Bonus questions') }}">{{ __('Bon.') }}</th>
                                    <th class="px-2 py-2 text-right" title="{{ __('Exact tips') }}">{{ __('Ex.') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Pts') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($legendRows as $index => $row)
                                    @php
                                        $out = $row['eliminated_round'] !== null && $row['eliminated_round'] < $round;
                                        $zoneClass = $out ? 'border-l-transparent text-zinc-400' : ($index < $limit ? 'border-l-green-500' : 'border-l-red-500');
                                    @endphp
                                    <tr wire:key="leg-{{ $row['entry_id'] }}"
                                        class="{{ $zoneClass }} border-b border-l-4 border-b-zinc-100 last:border-b-0 dark:border-b-zinc-700/50 {{ $row['team']->id == $this->myTeamId ? 'bg-amber-50 font-semibold dark:bg-amber-900/20' : '' }}">
                                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $out ? '' : ($index + 1) . '.' }}</td>
                                        <td class="max-w-56 truncate px-3 py-1.5">
                                            <button type="button" wire:click="showTeam({{ $row['team']->id }})" class="truncate hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $row['team']->name }}</button>
                                        </td>
                                        {{-- Kafelki: miejsce po kolejce; zielone = przeszedł dalej, czerwone = odpadł, szare „–” = już nie grał --}}
                                        <td class="px-2 py-1.5">
                                            <div class="flex gap-0.5">
                                                @foreach (range(1, $rounds) as $stage)
                                                    @php
                                                        $place = $history[$row['entry_id']][$stage] ?? null;
                                                        $absent = $row['eliminated_round'] !== null && $row['eliminated_round'] < $stage;
                                                        $lost = $place !== null && ($row['eliminated_round'] === $stage || ($stage === $rounds && $place > 1));
                                                        $tileClass = match (true) {
                                                            $place === null && $absent => 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800',
                                                            $place === null => 'bg-zinc-50 text-transparent dark:bg-zinc-800/40',
                                                            $lost => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                                            default => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                                        };
                                                    @endphp
                                                    <span class="{{ $tileClass }} flex h-5 w-8 shrink-0 items-center justify-center rounded text-[10px] font-semibold tabular-nums"
                                                        title="{{ __('Matchday :number', ['number' => $stage]) }}">{{ $place ?? ($absent ? '–' : '·') }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-2 py-1.5 text-right tabular-nums text-zinc-500">{{ $row['last_points'] ?? '–' }}</td>
                                        <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['hit_points'] }}</td>
                                        <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['bonus_points'] }}</td>
                                        <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['exact'] }}</td>
                                        <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ $row['points'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </flux:card>

                    {{-- Objaśnienia kursywą pod tabelą i legenda kolorów --}}
                    <div class="space-y-1 px-1 text-xs italic text-zinc-500">
                        <p>
                            @if ($round < $rounds)
                                {{ __('After matchday :round the best :limit teams stay in the competition.', ['round' => $round, 'limit' => $limit]) }}
                            @else
                                {{ __('Final: the better total from matchday 1 wins.') }}
                            @endif
                            {{ __('Legend points per matchday: 12 each for the exact score, the outcome and the goal difference, and for each question set 2, 4, 8, 16 or 32 for 1-5 correct answers (up to 100).') }}
                        </p>
                        <p class="flex flex-wrap gap-4 not-italic">
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-green-500"></span>{{ __('Goes through') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-red-500"></span>{{ __('Eliminated') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-zinc-300 dark:bg-zinc-600"></span>{{ __('No longer playing') }}</span>
                        </p>
                    </div>
                </div>
            @endif

            {{-- ============ Puchar Polski: drabinka rundy w kartach meczów ============ --}}
            @if ($this->isCup)
                @php
                    $rounds = \App\Support\CupBracket::ROUNDS;
                    $total = intdiv(\App\Support\CupBracket::seatsInRound($round), 2);
                    $path = $this->cupPath;
                    $myId = $this->myTeamId;
                    $final = $round === $rounds ? $this->matches?->first() : null;
                    // Cztery kolumny tej samej szerokości; półfinał (2 mecze) i finał (1) wyśrodkowane, karty nie rosną.
                    $cupGrid = 'flex flex-wrap justify-center gap-2';
                    $cupCell = 'shrink-0 grow-0';
                    $cupCellStyle = 'width: calc((100% - 1.5rem) / 4); min-width: min(100%, 16rem);';
                    // Etykieta etapu w pasku: liczba zespołów, od ćwierćfinału nazwa etapu (KnockoutStage).
                    $stageLabel = fn (int $stage) => $stage >= \App\Enums\KnockoutStage::QuarterFinal->value
                        ? \App\Enums\KnockoutStage::labelFor($stage)
                        : (string) \App\Support\CupBracket::seatsInRound($stage);
                @endphp
                <div class="space-y-3">
                    {{-- Pasek etapów: 512 → 256 → … → Finał, każdy etap to link do swojej rundy --}}
                    <nav class="flex flex-wrap items-center gap-1 text-xs" aria-label="{{ __('Rounds') }}">
                        @foreach (range(1, $rounds) as $stage)
                            @php
                                $stageClass = $stage === $round
                                    ? 'lech-bar font-semibold'
                                    : ($stage < $round ? 'bg-lech-100 text-lech-800 hover:bg-lech-200 dark:bg-lech-900/40 dark:text-lech-200' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800');
                            @endphp
                            <a href="{{ $this->pageUrl($stage) }}" wire:navigate title="{{ \App\Support\CupBracket::roundName($stage) }}"
                                class="{{ $stageClass }} rounded-full px-2.5 py-1 tabular-nums transition">{{ $stageLabel($stage) }}</a>
                            @if ($stage < $rounds)
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                            @endif
                        @endforeach
                    </nav>

                    {{-- Zdobywca pucharu po rozegranym finale --}}
                    @if ($final && $final->winner_entry_id)
                        @php
                            $champion = $final->winner_entry_id === $final->home_entry_id ? $final->home : $final->away;
                        @endphp
                        <div class="lech-banner lech-bar-shadow flex items-center gap-3 rounded-xl px-4 py-3">
                            <flux:icon.trophy class="size-8 shrink-0 text-amber-300" />
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-80">{{ __('Cup winner') }}</div>
                                <button type="button" wire:click="showTeam({{ $champion?->season_team_id ?? 0 }})" class="truncate text-lg font-bold hover:underline">{{ $champion?->seasonTeam->name }}</button>
                            </div>
                        </div>
                    @endif

                    {{-- Twoja droga w pucharze: karta na rundę z paskiem koloru (zielony = awans, czerwony = odpadnięcie, bursztynowy = przed wynikiem) --}}
                    @if ($path)
                        @php
                            $pathLabel = fn (int $stage) => match ($stage) {
                                $rounds => __('Final'),
                                default => '1/' . intdiv(\App\Support\CupBracket::seatsInRound($stage), 2),
                            };
                            $lastStage = max(array_keys($path));
                            $lastFx = $path[$lastStage];
                            $lastMine = $lastFx->home?->season_team_id === $myId ? $lastFx->home_entry_id : $lastFx->away_entry_id;
                            $pathStatus = match (true) {
                                !$lastFx->isPlayed() => __('Still in: :round', ['round' => \App\Support\CupBracket::roundName($lastStage)]),
                                $lastFx->winner_entry_id !== $lastMine => __('Knocked out: :round', ['round' => \App\Support\CupBracket::roundName($lastStage)]),
                                $lastStage === $rounds => __('Cup winner'),
                                default => __('Through to: :round', ['round' => \App\Support\CupBracket::roundName($lastStage + 1)]),
                            };
                        @endphp
                        <section class="lech-bar-shadow overflow-hidden rounded-2xl border border-lech-950/20 bg-white dark:border-lech-900 dark:bg-zinc-900">
                            <header class="lech-bar flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                                <h3 class="flex items-center gap-2 font-bold">
                                    <flux:icon.trophy class="size-5 text-lech-200" />
                                    {{ __('Your cup path') }}
                                </h3>
                                <span class="text-sm font-semibold text-lech-200">{{ $pathStatus }}</span>
                            </header>
                            <div class="overflow-x-auto p-4">
                                <ol class="grid gap-2" style="grid-template-columns: repeat({{ $rounds }}, minmax(6.5rem, 1fr));">
                                    @foreach (range(1, $rounds) as $stage)
                                        @php
                                            $fx = $path[$stage] ?? null;
                                            $isHome = $fx && $fx->home?->season_team_id === $myId;
                                            $myEntryId = $fx ? ($isHome ? $fx->home_entry_id : $fx->away_entry_id) : null;
                                            $opponent = $fx ? ($isHome ? $fx->away : $fx->home) : null;
                                            $myGoals = $fx ? ($isHome ? $fx->home_goals : $fx->away_goals) : null;
                                            $theirGoals = $fx ? ($isHome ? $fx->away_goals : $fx->home_goals) : null;
                                            [$strip, $stateClass, $stateText] = match (true) {
                                                $fx === null => ['bg-zinc-200 dark:bg-zinc-700', 'text-zinc-400', '—'],
                                                !$fx->isPlayed() => ['bg-amber-400', 'text-amber-700 dark:text-amber-300', __('Not played yet')],
                                                $fx->winner_entry_id === $myEntryId => ['bg-green-500', 'text-green-700 dark:text-green-400', $fx->decided_by_time ? __('Through on penalties') : __('Through')],
                                                default => ['bg-red-500', 'text-red-600 dark:text-red-400', $fx->decided_by_time ? __('Out on penalties') : __('Out')],
                                            };
                                        @endphp
                                        <li>
                                            <a @if ($fx) href="{{ $this->pageUrl($stage) }}" wire:navigate @endif
                                                title="{{ \App\Support\CupBracket::roundName($stage) }}"
                                                class="{{ $stage === $round ? 'ring-2 ring-lech-600 dark:ring-lech-400' : '' }} {{ $fx ? 'hover:shadow-md' : 'opacity-60' }} flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition dark:border-zinc-700 dark:bg-zinc-900">
                                                <span class="{{ $strip }} block h-1.5"></span>
                                                <span class="flex flex-1 flex-col items-center gap-0.5 px-2 py-2 text-center">
                                                    <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $pathLabel($stage) }}</span>
                                                    <span class="text-2xl font-black tabular-nums text-lech-900 dark:text-white">
                                                        {{ $fx && $fx->isPlayed() ? $myGoals . ':' . $theirGoals : '–' }}
                                                    </span>
                                                    <span class="w-full truncate text-xs text-zinc-600 dark:text-zinc-300">{{ $opponent?->seasonTeam->name ?? ($fx ? __('Opponent not known yet') : '') }}</span>
                                                    <span class="{{ $stateClass }} text-xs font-semibold">{{ $stateText }}</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </section>
                    @endif
                    <flux:card class="space-y-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <flux:heading>{{ \App\Support\CupBracket::roundName($round) }}</flux:heading>
                            <flux:text class="text-xs tabular-nums">
                                {{ __(':played of :total matches played', ['played' => $this->cupPlayed, 'total' => $total]) }}
                            </flux:text>
                        </div>
                        {{-- Pasek postępu rundy --}}
                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-lech-600" style="width: {{ $total ? round($this->cupPlayed / $total * 100) : 0 }}%"></div>
                        </div>

                        @if ($this->matches && $this->matches->isNotEmpty())
                            {{-- Własny mecz przypięty nad drabinką --}}
                            @if (isset($path[$round]))
                                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('Your match') }}</div>
                                <div class="{{ $cupGrid }}">
                                    <div class="{{ $cupCell }}" style="{{ $cupCellStyle }}" wire:key="cup-cell-mine">
                                        @include('partials.cup-fixture-card', ['fixture' => $path[$round], 'mine' => true])
                                    </div>
                                </div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('All matches') }}</div>
                            @endif

                            <div class="{{ $cupGrid }}">
                                @foreach ($this->matches as $fixture)
                                    <div class="{{ $cupCell }}" style="{{ $cupCellStyle }}" wire:key="cup-cell-{{ $fixture->id }}">
                                        @include('partials.cup-fixture-card', [
                                            'fixture' => $fixture,
                                            'mine' => $myId && in_array($myId, [$fixture->home?->season_team_id, $fixture->away?->season_team_id]),
                                        ])
                                    </div>
                                @endforeach
                            </div>

                            @if ($this->matches->hasPages())
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <flux:button size="sm" variant="ghost" icon="chevron-left"
                                        wire:click="previousPage('matchesPage')" :disabled="$this->matches->onFirstPage()" />
                                    <flux:text class="text-xs tabular-nums">
                                        {{ __('Page :current of :last', ['current' => $this->matches->currentPage(), 'last' => $this->matches->lastPage()]) }}
                                    </flux:text>
                                    <flux:button size="sm" variant="ghost" icon="chevron-right"
                                        wire:click="nextPage('matchesPage')" :disabled="!$this->matches->hasMorePages()" />
                                </div>
                            @endif
                        @else
                            <flux:text>{{ __('No matches yet.') }}</flux:text>
                        @endif
                    </flux:card>

                    {{-- Objaśnienia kursywą pod drabinką i legenda kolorów --}}
                    <div class="space-y-1 px-1 text-xs italic text-zinc-500">
                        <p>
                            {{ __('The favourite plays on the left and the winner takes the better seed of the pair. A draw goes to the team that saved its tip earlier (at an equal time the higher seed), shown as “after penalties”.') }}
                            {{ __('The number next to the team is its seed in this round.') }}
                        </p>
                        <p class="flex flex-wrap gap-4 not-italic">
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-green-500"></span>{{ __('Goes through') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-red-400"></span>{{ __('Eliminated') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-amber-400"></span>{{ __('Your match / not played yet') }}</span>
                        </p>
                    </div>
                </div>
            @endif

            {{-- ============ Mecze kolejki / rundy ============ --}}
            @if (!$this->isLegends && !$this->isCup)
            <flux:card class="space-y-2 self-start">
                <flux:heading>
                    {{ $this->isCup ? \App\Support\CupBracket::roundName($round) : __('Matchday :number', ['number' => $round]) }}
                </flux:heading>

                @if ($this->matches && $this->matches->isNotEmpty())
                    <ul class="space-y-1.5">
                        @foreach ($this->matches as $fixture)
                            @php
                                $mine = $this->myTeamId && in_array($this->myTeamId, [$fixture->home?->season_team_id, $fixture->away?->season_team_id]);
                            @endphp
                            <li wire:key="fx-{{ $fixture->id }}" wire:click="showFixture({{ $fixture->id }})" title="{{ __('Match details') }}"
                                class="-mx-2 grid cursor-pointer grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-md px-2 py-0.5 text-sm transition hover:bg-zinc-100 dark:hover:bg-zinc-700/50 {{ $mine ? 'font-semibold' : '' }}">
                                <span class="truncate text-right {{ $fixture->winner_entry_id && $fixture->winner_entry_id === $fixture->home_entry_id && $this->isCup ? 'text-green-700 dark:text-green-400' : '' }}">
                                    {{ $fixture->home?->seasonTeam->name ?? __('Seat :number', ['number' => $fixture->home_seat]) }}
                                </span>
                                <span class="tabular-nums {{ $fixture->isPlayed() ? '' : 'text-zinc-400' }}">{{ $fixture->score() }}</span>
                                <span class="truncate {{ $fixture->winner_entry_id && $fixture->winner_entry_id === $fixture->away_entry_id && $this->isCup ? 'text-green-700 dark:text-green-400' : '' }}">
                                    @if ($fixture->away)
                                        {{ $fixture->away->seasonTeam->name }}
                                    @elseif ($fixture->away_seat)
                                        {{ __('Seat :number', ['number' => $fixture->away_seat]) }}
                                    @else
                                        <span class="italic">{{ \App\Models\Fixture::VIRTUAL_OPPONENT }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->matches->hasPages())
                        {{-- Własny, zwarty pager: domyślne linki Laravela nie mieszczą się w wąskiej kolumnie. --}}
                        <div class="flex items-center justify-between gap-2 pt-2">
                            <flux:button size="sm" variant="ghost" icon="chevron-left"
                                wire:click="previousPage('matchesPage')" :disabled="$this->matches->onFirstPage()" />
                            <flux:text class="text-xs tabular-nums">
                                {{ __('Page :current of :last', ['current' => $this->matches->currentPage(), 'last' => $this->matches->lastPage()]) }}
                            </flux:text>
                            <flux:button size="sm" variant="ghost" icon="chevron-right"
                                wire:click="nextPage('matchesPage')" :disabled="!$this->matches->hasMorePages()" />
                        </div>
                    @endif
                @else
                    <flux:text>{{ __('No matches yet.') }}</flux:text>
                @endif
            </flux:card>
            @endif
        </div>
    @endif

    @include('partials.results-modals')
</div>
