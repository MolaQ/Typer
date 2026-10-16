<?php

use App\Enums\MatchdayStatus;
use App\Enums\PlayerPosition;
use App\Models\LechPlayer;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\User;
use App\Support\GameStars;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gwiazdy gry (15.10.2026, przebudowa 16.10.2026): zawodnicy Lecha w drużynach graczy (bez botów, para drużyna + zawodnik,
 * gotowe sumy w `star_stats`, Support\GameStars) do porównania własnych zawodników z cudzymi.
 * Filtry: sezon (domyślnie wszystkie), pozycja, drużyny (wszystkie, moja, porównanie z wybraną drużyną), wybrani zawodnicy
 * (przyciski, kilku naraz), minimum występów, wyszukiwarka (zawodnik albo drużyna od 2 liter), sortowanie w nagłówku, paginacja `?stars=`.
 * Kolumny: miejsce, numer w kolorze pozycji, zawodnik (biret = wychowanek) i drużyna, występy, G, A, CK i obrona (tylko B i O),
 * atak, dorobek (atak + obrona), doświadczenie, % wygranych meczów drużyny z zawodnikiem. Nad tabelą liderzy sezonu.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    /** Parametr strony w adresie (?stars=2). */
    private const PAGE = 'stars';

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Kolumny do sortowania: klucz => wyrażenie w zapytaniu. */
    public const SORTS = [
        'number' => 'lp.number', 'name' => 'lp.name', 'fielded' => 's.fielded', 'goals' => 's.goals', 'assists' => 's.assists',
        'clean_sheets' => 's.clean_sheets', 'attack' => 's.attack', 'defense' => 's.defense', 'contribution' => 's.contribution',
        'experience' => 's.experience', 'win_pct' => 's.win_pct',
    ];

    /** 0 = wszystkie sezony. */
    public int $season = 0;

    /** '' = wszystkie pozycje, inaczej PlayerPosition. */
    public string $position = '';

    /** 'all' (wszystkie drużyny), 'mine' (moja drużyna) albo 'compare' (moja i wybrana). */
    public string $clubs = 'all';

    /** Drużyna do porównania (id gracza), 0 = brak. */
    public int $compareId = 0;

    /** Wpisywana nazwa drużyny do porównania. */
    public string $compareSearch = '';

    /** Wybrani zawodnicy (id z lech_players); pusto = wszyscy. @var array<int, int> */
    public array $picked = [];

    public int $minGames = 1;

    public string $search = '';

    public string $sort = 'contribution';

    public string $dir = 'desc';

    public int $perPage = 25;

    public function render(): View
    {
        return $this->view()->title(__('Game stars'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['season', 'position', 'clubs', 'minGames', 'search', 'perPage'], true)) {
            if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
                $this->perPage = 25;
            }
            $this->resetPage(self::PAGE);
        }
    }

    /** Klik w nagłówek: ta sama kolumna odwraca kierunek, nowa zaczyna od największych (nazwa i numer od początku). */
    public function sortBy(string $column): void
    {
        if (! isset(self::SORTS[$column])) {
            return;
        }
        $this->dir = $this->sort === $column ? ($this->dir === 'desc' ? 'asc' : 'desc') : (in_array($column, ['name', 'number'], true) ? 'asc' : 'desc');
        $this->sort = $column;
        $this->resetPage(self::PAGE);
    }

    /** Przycisk zawodnika: dodaje go do wybranych albo zdejmuje. */
    public function togglePlayer(int $id): void
    {
        $this->picked = in_array($id, $this->picked, true)
            ? array_values(array_diff($this->picked, [$id]))
            : [...$this->picked, $id];
        $this->resetPage(self::PAGE);
    }

    /** Wybór drużyny do porównania z listy podpowiedzi. */
    public function compareWith(int $userId): void
    {
        $this->compareId = $userId;
        $this->compareSearch = '';
        $this->clubs = 'compare';
        $this->resetPage(self::PAGE);
    }

    public function clearFilters(): void
    {
        $this->reset('position', 'clubs', 'compareId', 'compareSearch', 'picked', 'minGames', 'search', 'sort', 'dir');
        $this->resetPage(self::PAGE);
    }

    /** Sezony z rozliczonymi kolejkami, od najnowszego. @return Collection<int, Season> */
    #[Computed]
    public function seasons(): Collection
    {
        $ids = Matchday::where('status', MatchdayStatus::Played->value)->distinct()->pluck('season_id');

        return Season::whereIn('id', $ids)->orderByDesc('number')->get();
    }

    /** Zawodnicy do przycisków filtra: kadra bez niedostępnych plus ci, którzy mają liczby, według pozycji i numeru. @return Collection<int, LechPlayer> */
    #[Computed]
    public function squad(): Collection
    {
        $order = array_flip(PlayerPosition::values());
        $withStats = \Illuminate\Support\Facades\DB::table('star_stats')->where('season_id', 0)->distinct()->pluck('lech_player_id');

        return \App\Support\Squad::available()
            ->concat(LechPlayer::whereIn('id', $withStats)->get())
            ->unique('id')
            ->sortBy(fn (LechPlayer $p) => [$order[$p->position->value] ?? 9, $p->number ?? 999])
            ->values();
    }

    /** Podpowiedzi drużyn do porównania (od 2 liter). @return Collection<int, User> */
    #[Computed]
    public function compareOptions(): Collection
    {
        $needle = trim($this->compareSearch);
        if (mb_strlen($needle) < 2) {
            return collect();
        }

        return User::query()
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('star_stats')->where('season_id', 0)->select('user_id'))
            ->where(fn ($q) => $q->where('team_name', 'like', '%'.$needle.'%')->orWhere('name', 'like', '%'.$needle.'%'))
            ->when(auth()->id(), fn ($q, $me) => $q->whereKeyNot($me))
            ->orderBy('team_name')
            ->limit(8)
            ->get(['id', 'name', 'team_name']);
    }

    #[Computed]
    public function compareUser(): ?User
    {
        return $this->compareId ? User::find($this->compareId, ['id', 'name', 'team_name']) : null;
    }

    /**
     * Wiersz zapytania GameStars z danymi do widoku (pozycja jako enum, adres profilu drużyny).
     *
     * @return array<string, mixed>
     */
    private function decorate(object $row, int $rank = 0): array
    {
        $data = (array) $row;

        return [
            'rank' => $rank,
            'url' => route('team.show', (int) $data['user_id']),
            'user_id' => (int) $data['user_id'],
            'player_id' => (int) $data['player_id'],
            'name' => (string) $data['name'],
            'number' => $data['number'] !== null ? (int) $data['number'] : null,
            'position' => PlayerPosition::from((string) $data['position']),
            'academy' => (bool) $data['is_academy'],
            'club' => (string) $data['club'],
            'won' => (int) $data['won'],
            'games' => (int) $data['games'],
            'win_pct' => $data['win_pct'] !== null ? (float) $data['win_pct'] : null,
        ] + array_map(fn ($v) => $v + 0, array_intersect_key($data, array_flip(['fielded', 'goals', 'assists', 'clean_sheets', 'attack', 'defense', 'contribution', 'experience'])));
    }

    /** Liczby do banera i liderzy (bez filtrów poza sezonem). @return array{count: int, goals: int, leaders: array<string, array<string, mixed>|null>} */
    #[Computed]
    public function summary(): array
    {
        return GameStars::summary($this->season ?: null);
    }

    /** Strona tabeli: filtry, sortowanie i stronicowanie robi baza na gotowych sumach (star_stats). */
    #[Computed]
    public function pageItems(): LengthAwarePaginator
    {
        $me = (int) auth()->id();
        $needle = mb_strtolower(trim($this->search));
        $sortKey = isset(self::SORTS[$this->sort]) ? $this->sort : 'contribution';
        $dir = $this->dir === 'asc' ? 'asc' : 'desc';

        $query = GameStars::query($this->season ?: null)
            ->when($this->position !== '', fn ($q) => $q->where('s.position', $this->position))
            ->when($this->clubs === 'mine', fn ($q) => $q->where('s.user_id', $me))
            ->when($this->clubs === 'compare', fn ($q) => $q->whereIn('s.user_id', array_filter([$me, $this->compareId])))
            ->when($this->picked !== [], fn ($q) => $q->whereIn('s.lech_player_id', $this->picked))
            ->when($this->minGames > 1, fn ($q) => $q->where('s.fielded', '>=', $this->minGames))
            ->when(mb_strlen($needle) >= 2, fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(lp.name) LIKE ?', ['%'.$needle.'%'])
                ->orWhereRaw('LOWER(u.team_name) LIKE ?', ['%'.$needle.'%'])))
            // Brak procentu (bez meczów) zawsze na końcu; remisy: dorobek, występy, nazwisko.
            ->when($sortKey === 'win_pct', fn ($q) => $q->orderByRaw('CASE WHEN s.win_pct IS NULL THEN 1 ELSE 0 END'))
            ->orderBy(self::SORTS[$sortKey], $dir)
            ->orderByDesc('s.contribution')->orderByDesc('s.fielded')->orderBy('lp.name');

        $paginator = $query->paginate($this->perPage, ['*'], self::PAGE);
        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $paginator->setCollection($paginator->getCollection()->values()->map(fn ($row, $i) => $this->decorate($row, $offset + $i + 1)));

        return $paginator;
    }

    /**
     * Liderzy wybranego sezonu: gole, asysty, czyste konta, dorobek, procent wygranych (min. 5 meczów).
     *
     * @return array<int, array{label: string, icon: string, value: string, row: array<string, mixed>}>
     */
    #[Computed]
    public function leaders(): array
    {
        $labels = [
            'goals' => [__('Top scorer'), 'fire'],
            'assists' => [__('Top assister'), 'hand-raised'],
            'clean_sheets' => [__('Most clean sheets'), 'shield-check'],
            'contribution' => [__('Best contribution'), 'star'],
            'win_pct' => [__('Best win rate'), 'trophy'],
        ];
        $out = [];
        foreach ($labels as $key => [$label, $icon]) {
            $best = $this->summary['leaders'][$key] ?? null;
            if ($best) {
                $row = $this->decorate((object) $best);
                $value = $key === 'win_pct' ? \App\Support\ClubStats::format((float) $row['win_pct']).'%' : \App\Support\ClubStats::format($row[$key]);
                $out[] = ['label' => $label, 'icon' => $icon, 'value' => $value, 'row' => $row];
            }
        }

        return $out;
    }

    public function isFiltered(): bool
    {
        return $this->position !== '' || $this->clubs !== 'all' || $this->picked !== [] || $this->minGames > 1 || mb_strlen(trim($this->search)) >= 2;
    }
}; ?>

@php
    $me = auth()->id();
    // Barwy pozycji (B/O/P/N): kolor kwadratu z numerem, jak w „Mojej kadrze”
    $posTone = [
        'goalkeeper' => 'bg-amber-400 text-amber-950',
        'defender' => 'bg-sky-400 text-sky-950',
        'midfielder' => 'bg-emerald-400 text-emerald-950',
        'forward' => 'bg-rose-400 text-rose-950',
    ];
    // Czyste konta i punkty obrony liczą się tylko bramkarzom i obrońcom
    $sheetPositions = ['goalkeeper', 'defender'];
    $cols = [
        'fielded' => [__('Apps'), __('Appearances: matches in which the club fielded the player')],
        'goals' => ['G', __('Goals')],
        'assists' => ['A', __('Assists')],
        'clean_sheets' => [__('CS'), __('Clean sheets')],
        'attack' => [__('Att.'), __('Attack points')],
        'defense' => [__('Def.'), __('Defence points')],
        'contribution' => [__('Contr.'), __('Attack points + defence points')],
        'experience' => [__('Exp.'), __('Experience')],
        'win_pct' => [__('Win %'), __('Won matches of the club in which the player was fielded')],
    ];
    $fmt = fn ($v) => \App\Support\ClubStats::format($v);
    $seasonTitle = $season ? $this->seasons->firstWhere('id', $season)?->title : __('All seasons');
    $compareName = $this->compareUser ? ($this->compareUser->team_name ?: $this->compareUser->name) : null;
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('Lech players in the clubs')" :title="__('Game stars')"
        :subtitle="__('Compare your Lech players with those of other clubs: every club has its own numbers, counted from the matchdays in which it fielded the player.')">
        <x-slot:aside>
            <div class="grid grid-cols-2 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->summary['count'] }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Players in clubs') }}</div>
                </div>
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->summary['goals'] }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Goals') }}</div>
                </div>
            </div>
        </x-slot:aside>
    </x-page-banner>

    @if ($this->summary['count'] === 0 && ! $season)
        <flux:card>
            <flux:text>{{ __('The stars appear after the first settled matchday with fielded players.') }}</flux:text>
        </flux:card>
    @else
        {{-- Liderzy sezonu --}}
        @if (count($this->leaders) > 0)
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                @foreach ($this->leaders as $leader)
                    @php
                        $row = $leader['row'];
                    @endphp
                    <div wire:key="leader-{{ $loop->index }}" class="lech-banner relative overflow-hidden rounded-2xl p-4 text-white shadow-md {{ $loop->last ? 'col-span-2 md:col-span-1' : '' }}">
                        <span class="pointer-events-none absolute -bottom-3 -right-1 select-none text-7xl font-black leading-none text-white/10 tabular-nums">{{ $row['number'] }}</span>
                        <div class="relative flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-lech-200">
                            <flux:icon :name="$leader['icon']" variant="micro" />{{ $leader['label'] }}
                        </div>
                        <div class="relative mt-2 text-3xl font-black tabular-nums">{{ $leader['value'] }}</div>
                        <div class="relative mt-1 truncate font-bold">{{ $row['name'] }}</div>
                        <a href="{{ $row['url'] }}" wire:navigate class="relative block truncate text-xs text-white/70 hover:text-white hover:underline">{{ $row['club'] }}</a>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Filtry --}}
        <div class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable :placeholder="__('Search player or club (min. 2 letters)')" />
                <flux:select wire:model.live="season">
                    <flux:select.option value="0">{{ __('All seasons') }}</flux:select.option>
                    @foreach ($this->seasons as $s)
                        <flux:select.option :value="$s->id">{{ $s->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="minGames">
                    @foreach ([1, 3, 5, 10, 20] as $option)
                        <flux:select.option :value="$option">{{ $option === 1 ? __('Any number of appearances') : __('At least :count appearances', ['count' => $option]) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="perPage" class="lg:w-24">
                    @foreach ([25, 50, 100] as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Pozycja --}}
                <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-white/10">
                    @foreach (['' => __('Everyone')] + collect(PlayerPosition::cases())->mapWithKeys(fn ($pos) => [$pos->value => $pos->short()])->all() as $posKey => $posLabel)
                        <button type="button" wire:click="$set('position', '{{ $posKey }}')" title="{{ $posKey === '' ? $posLabel : PlayerPosition::from($posKey)->label() }}"
                            class="{{ $position === $posKey ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} min-w-9 rounded-full px-3 py-1 text-xs font-bold transition">{{ $posLabel }}</button>
                    @endforeach
                </div>
                {{-- Drużyny: wszystkie, moja, porównanie --}}
                <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-white/10">
                    <button type="button" wire:click="$set('clubs', 'all')"
                        class="{{ $clubs === 'all' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} rounded-full px-3 py-1 text-xs font-bold transition">{{ __('All teams') }}</button>
                    @auth
                        <button type="button" wire:click="$set('clubs', 'mine')"
                            class="{{ $clubs === 'mine' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} rounded-full px-3 py-1 text-xs font-bold transition">{{ __('My team') }}</button>
                    @endauth
                    @if ($compareName)
                        <button type="button" wire:click="$set('clubs', 'compare')"
                            class="{{ $clubs === 'compare' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} max-w-48 truncate rounded-full px-3 py-1 text-xs font-bold transition">{{ auth()->check() ? __('Me vs :team', ['team' => $compareName]) : $compareName }}</button>
                    @endif
                </div>
                {{-- Porównanie z drużyną: wpisywana nazwa i podpowiedzi --}}
                <div class="relative w-full sm:w-64">
                    <flux:input size="sm" wire:model.live.debounce.300ms="compareSearch" icon="arrows-right-left" clearable :placeholder="__('Compare with a team…')" />
                    @if ($this->compareOptions->isNotEmpty())
                        <div class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($this->compareOptions as $option)
                                <button type="button" wire:click="compareWith({{ $option->id }})" wire:key="cmp-{{ $option->id }}"
                                    class="block w-full truncate px-3 py-2 text-left text-sm hover:bg-lech-50 dark:hover:bg-white/5">
                                    <span class="font-semibold">{{ $option->team_name ?: $option->name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $option->name }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <span class="ms-auto text-sm text-zinc-500">{{ trans_choice(':count player found|:count players found', $this->pageItems->total(), ['count' => $this->pageItems->total()]) }}</span>
                @if ($this->isFiltered())
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @endif
            </div>

            {{-- Zawodnicy: przyciski, można wybrać kilku --}}
            <div x-data="{ show: {{ $picked !== [] ? 'true' : 'false' }} }" class="border-t border-zinc-100 pt-3 dark:border-white/5">
                <button type="button" x-on:click="show = ! show" class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-500 hover:text-lech-700">
                    <flux:icon.chevron-right variant="micro" class="transition" x-bind:class="show && 'rotate-90'" />
                    {{ __('Players') }}
                    @if ($picked !== [])
                        <span class="rounded-full bg-lech-700 px-1.5 text-[10px] text-white">{{ count($picked) }}</span>
                    @endif
                </button>
                <div x-show="show" x-collapse x-cloak class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($this->squad as $player)
                        @php
                            $on = in_array($player->id, $picked, true);
                        @endphp
                        <button type="button" wire:click="togglePlayer({{ $player->id }})" wire:key="pick-{{ $player->id }}" title="{{ $player->position->label() }}"
                            class="{{ $on ? 'border-lech-700 bg-lech-700 text-white shadow' : 'border-zinc-200 bg-white text-zinc-700 hover:border-lech-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' }} inline-flex items-center gap-1.5 rounded-full border py-0.5 pe-2.5 ps-0.5 text-xs font-semibold transition">
                            <span class="{{ $posTone[$player->position->value] }} grid size-5 place-items-center rounded-full text-[10px] font-black tabular-nums">{{ $player->number ?? '–' }}</span>
                            {{ $player->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Tabela --}}
        <x-lech-panel :title="__('Game stars') . ' – ' . $seasonTitle" :subtitle="__('Click a column to sort.')" icon="star" flush>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-zinc-50 text-[11px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-white/5">
                        <tr>
                            <th class="w-12 ps-4 pe-2 py-2 text-left">#</th>
                            @foreach (['number' => ['Nr', __('Number'), 'text-center w-10'], 'name' => [__('Footballer'), __('Player and club'), 'text-left']] as $sortKey => [$short, $long, $align])
                                <th class="{{ $align }} px-1.5 py-2" title="{{ $long }}">
                                    <button type="button" wire:click="sortBy('{{ $sortKey }}')" class="{{ $sort === $sortKey ? 'text-lech-700 dark:text-lech-300' : '' }} inline-flex items-center gap-1 uppercase hover:text-lech-700 dark:hover:text-lech-300">
                                        {{ $short }}@if ($sort === $sortKey)<span class="text-[9px]">{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif
                                    </button>
                                </th>
                            @endforeach
                            @foreach ($cols as $col => [$short, $long])
                                <th class="px-1.5 py-2 text-right last:pe-4" title="{{ $long }}">
                                    <button type="button" wire:click="sortBy('{{ $col }}')" class="{{ $sort === $col ? 'text-lech-700 dark:text-lech-300' : '' }} inline-flex items-center gap-1 uppercase hover:text-lech-700 dark:hover:text-lech-300">
                                        @if ($sort === $col)<span class="text-[9px]">{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif{{ $short }}
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @forelse ($this->pageItems as $row)
                            @php
                                $mine = $me && $row['user_id'] === (int) $me;
                                $rival = $clubs === 'compare' && $row['user_id'] === $compareId;
                                $sheets = in_array($row['position']->value, $sheetPositions, true);
                                $pct = $row['win_pct'];
                                $pctTone = $pct === null ? 'text-zinc-400' : ($pct > 65 ? 'text-green-600 dark:text-green-400' : ($pct >= 35 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400'));
                            @endphp
                            <tr wire:key="star-{{ $row['user_id'] }}-{{ $row['player_id'] }}" class="{{ $mine ? 'bg-amber-50 dark:bg-amber-500/10' : ($rival ? 'bg-sky-50 dark:bg-sky-500/10' : '') }} transition hover:bg-lech-50/70 dark:hover:bg-white/5">
                                <td class="ps-4 pe-2 py-2">
                                    <span class="{{ $row['rank'] <= 3 ? 'bg-amber-400 text-amber-950' : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }} grid size-7 place-items-center rounded-full text-[11px] font-black tabular-nums">{{ $row['rank'] }}</span>
                                </td>
                                <td class="px-1.5 py-2 text-center">
                                    <span class="{{ $posTone[$row['position']->value] }} mx-auto grid size-8 place-items-center rounded-lg text-xs font-black tabular-nums" title="{{ $row['position']->label() }}">{{ $row['number'] ?? '–' }}</span>
                                </td>
                                <td class="px-1.5 py-2">
                                    <div class="flex items-center gap-1.5 whitespace-nowrap font-semibold text-zinc-900 dark:text-white">
                                        {{ $row['name'] }}
                                        @if ($row['academy'])
                                            <flux:icon.academic-cap variant="micro" class="shrink-0 text-lech-600 dark:text-lech-300" title="{{ __('Academy') }}" />
                                        @endif
                                    </div>
                                    <a href="{{ $row['url'] }}" wire:navigate class="block max-w-48 truncate text-xs text-zinc-500 hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $row['club'] }}</a>
                                </td>
                                @foreach ($cols as $col => $label)
                                    @php
                                        $value = $row[$col];
                                    @endphp
                                    <td class="{{ $col === 'contribution' ? 'font-bold text-lech-800 dark:text-lech-200' : '' }} px-1.5 py-2 text-right tabular-nums last:pe-4">
                                        @if (in_array($col, ['clean_sheets', 'defense'], true) && ! $sheets)
                                            <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                        @elseif ($col === 'win_pct')
                                            <span class="{{ $pctTone }} font-semibold" title="{{ __(':won of :games', ['won' => $row['won'], 'games' => $row['games']]) }}">{{ $value === null ? '—' : $fmt($value) . '%' }}</span>
                                        @elseif ($col === 'experience')
                                            {{ \App\Support\ClubStats::experience($value) }}
                                        @else
                                            <span class="{{ $value ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $fmt($value) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-4 py-8 text-center text-sm text-zinc-500">{{ __('No players match the filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Legenda: kolor numeru = pozycja, biret = wychowanek --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-zinc-200 px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-700">
                @foreach (PlayerPosition::cases() as $pos)
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $posTone[$pos->value] }} size-3 rounded"></span>{{ $pos->label() }}</span>
                @endforeach
                <span class="inline-flex items-center gap-1.5"><flux:icon.academic-cap variant="micro" class="text-lech-600 dark:text-lech-300" />{{ __('Academy') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="text-zinc-300">·</span>{{ __('Clean sheets and defence points count only for goalkeepers and defenders') }}</span>
                @if ($clubs === 'compare')
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-100"></span>{{ __('My team') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-sky-100"></span>{{ $compareName }}</span>
                @endif
            </div>
        </x-lech-panel>

        <flux:pagination :paginator="$this->pageItems" />
        <flux:text class="text-xs">{{ __('Only players\' teams are listed (no bots). Appearances count matches that took place (Legends League: each duel). Win % = won matches of the team in the competition type in which the player was fielded; a cup draw decided by tip time counts as a win or a loss.') }}</flux:text>
    @endif
</div>
