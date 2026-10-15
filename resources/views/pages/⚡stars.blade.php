<?php

use App\Enums\MatchdayStatus;
use App\Enums\PlayerPosition;
use App\Models\Bot;
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
 * Gwiazdy gry (15.10.2026): zawodnicy Lecha w klubach wszystkich graczy i botów (para klub + zawodnik, Support\GameStars)
 * do porównania własnych zawodników z cudzymi. Filtry: sezon (domyślnie wszystkie), pozycja, kluby (wszystkie, moje, gracze, boty),
 * minimum występów, wyszukiwarka (zawodnik albo klub od 2 liter), sortowanie kliknięciem w nagłówek, paginacja `?stars=`.
 * Kolumny: numer, zawodnik i klub, pozycja, występy, statystyki kluczowe dla pozycji (wyróżnione), dorobek (atak + obrona),
 * doświadczenie i procent wygranych meczów klubu, w których zawodnik był wystawiony. Nad tabelą liderzy wybranego sezonu.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    /** Parametr strony w adresie (?stars=2). */
    private const PAGE = 'stars';

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Kolumny do sortowania. */
    public const SORTS = ['number', 'name', 'fielded', 'goals', 'assists', 'clean_sheets', 'attack', 'defense', 'contribution', 'experience', 'win_pct'];

    /** 0 = wszystkie sezony. */
    public int $season = 0;

    /** '' = wszystkie pozycje, inaczej PlayerPosition. */
    public string $position = '';

    /** 'all', 'mine', 'users' albo 'bots'. */
    public string $clubs = 'all';

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
        if (! in_array($column, self::SORTS, true)) {
            return;
        }
        $this->dir = $this->sort === $column ? ($this->dir === 'desc' ? 'asc' : 'desc') : (in_array($column, ['name', 'number'], true) ? 'asc' : 'desc');
        $this->sort = $column;
        $this->resetPage(self::PAGE);
    }

    public function clearFilters(): void
    {
        $this->reset('position', 'clubs', 'minGames', 'search', 'sort', 'dir');
        $this->resetPage(self::PAGE);
    }

    /** Sezony z rozliczonymi kolejkami, od najnowszego. @return Collection<int, Season> */
    #[Computed]
    public function seasons(): Collection
    {
        $ids = Matchday::where('status', MatchdayStatus::Played->value)->distinct()->pluck('season_id');

        return Season::whereIn('id', $ids)->orderByDesc('number')->get();
    }

    /** @return Collection<int, LechPlayer> */
    #[Computed]
    public function players(): Collection
    {
        return LechPlayer::all()->keyBy('id');
    }

    /**
     * Nazwy klubów i adresy profili: klucz u{id} albo b{id}.
     *
     * @return array<string, array{name: string, owner: string|null, url: string}>
     */
    #[Computed]
    public function clubNames(): array
    {
        $rows = collect(GameStars::rows($this->season ?: null));
        $out = [];
        foreach (User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->get(['id', 'name', 'team_name']) as $user) {
            $out['u'.$user->id] = ['name' => $user->team_name ?: $user->name, 'owner' => $user->name, 'url' => route('team.show', $user)];
        }
        foreach (Bot::whereIn('id', $rows->pluck('bot_id')->filter()->unique())->get(['id', 'name']) as $bot) {
            $out['b'.$bot->id] = ['name' => $bot->name, 'owner' => null, 'url' => route('bot.show', $bot)];
        }

        return $out;
    }

    /**
     * Wszystkie wiersze sezonu z nazwami (zawodnik, klub).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function all(): Collection
    {
        $players = $this->players;
        $names = $this->clubNames;

        return collect(GameStars::rows($this->season ?: null))
            ->map(function (array $row) use ($players, $names): ?array {
                $player = $players->get($row['player_id']);
                $club = $row['user_id'] !== null ? 'u'.$row['user_id'] : 'b'.$row['bot_id'];
                if (! $player || ! isset($names[$club])) {
                    return null;
                }

                return $row + [
                    'club_key' => $club,
                    'club' => $names[$club]['name'],
                    'owner' => $names[$club]['owner'],
                    'url' => $names[$club]['url'],
                    'bot' => $row['bot_id'] !== null,
                    'name' => $player->name,
                    'number' => $player->number,
                    'position' => $player->position,
                    'academy' => $player->is_academy,
                ];
            })
            ->filter()
            ->values();
    }

    /** Wiersze po filtrach i sortowaniu, z miejscem w tym widoku. */
    #[Computed]
    public function filtered(): Collection
    {
        $needle = mb_strtolower(trim($this->search));
        $me = auth()->id();

        $rows = $this->all
            ->when($this->position !== '', fn ($rows) => $rows->filter(fn ($row) => $row['position']->value === $this->position))
            ->when($this->clubs === 'mine', fn ($rows) => $rows->filter(fn ($row) => $me && $row['user_id'] === (int) $me))
            ->when($this->clubs === 'users', fn ($rows) => $rows->filter(fn ($row) => ! $row['bot']))
            ->when($this->clubs === 'bots', fn ($rows) => $rows->filter(fn ($row) => $row['bot']))
            ->when($this->minGames > 1, fn ($rows) => $rows->filter(fn ($row) => $row['fielded'] >= $this->minGames))
            ->when(mb_strlen($needle) >= 2, fn ($rows) => $rows->filter(fn ($row) => str_contains(mb_strtolower($row['name']), $needle) || str_contains(mb_strtolower($row['club']), $needle)));

        $sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'contribution';
        $sign = $this->dir === 'asc' ? 1 : -1;

        return $rows
            // Remisy: dorobek, potem występy, potem nazwisko.
            ->sort(fn ($a, $b) => $sign * (($a[$sort] ?? -1) <=> ($b[$sort] ?? -1)) ?: [$b['contribution'], $b['fielded'], $a['name']] <=> [$a['contribution'], $a['fielded'], $b['name']])
            ->values()
            ->map(fn ($row, $index) => ['rank' => $index + 1] + $row);
    }

    #[Computed]
    public function pageItems(): LengthAwarePaginator
    {
        $current = max(1, (int) $this->getPage(self::PAGE));

        return new LengthAwarePaginator($this->filtered->forPage($current, $this->perPage)->values(), $this->filtered->count(), $this->perPage, $current, ['pageName' => self::PAGE]);
    }

    /**
     * Liderzy wybranego sezonu (bez innych filtrów): gole, asysty, czyste konta, dorobek, procent wygranych (min. 5 występów).
     *
     * @return array<int, array{label: string, icon: string, value: string, row: array<string, mixed>}>
     */
    #[Computed]
    public function leaders(): array
    {
        $out = [];
        $pick = [
            ['goals', __('Top scorer'), 'fire', fn ($row) => (string) $row['goals']],
            ['assists', __('Top assister'), 'hand-raised', fn ($row) => (string) $row['assists']],
            ['clean_sheets', __('Most clean sheets'), 'shield-check', fn ($row) => (string) $row['clean_sheets']],
            ['contribution', __('Best contribution'), 'star', fn ($row) => \App\Support\ClubStats::format($row['contribution'])],
            ['win_pct', __('Best win rate'), 'trophy', fn ($row) => \App\Support\ClubStats::format($row['win_pct']).'%'],
        ];
        foreach ($pick as [$key, $label, $icon, $format]) {
            $best = $this->all
                ->when($key === 'win_pct', fn ($rows) => $rows->filter(fn ($row) => $row['games'] >= 5))
                ->sort(fn ($a, $b) => [$b[$key] ?? -1, $b['fielded']] <=> [$a[$key] ?? -1, $a['fielded']])
                ->first();
            if ($best && ($best[$key] ?? 0) > 0) {
                $out[] = ['label' => $label, 'icon' => $icon, 'value' => $format($best), 'row' => $best];
            }
        }

        return $out;
    }

    public function isFiltered(): bool
    {
        return $this->position !== '' || $this->clubs !== 'all' || $this->minGames > 1 || mb_strlen(trim($this->search)) >= 2;
    }
}; ?>

@php
    $me = auth()->id();
    // Statystyki kluczowe dla pozycji (wyróżnione w tabeli), jak w „Mojej kadrze”
    $keyStats = [
        'goalkeeper' => ['clean_sheets', 'defense'],
        'defender' => ['clean_sheets', 'defense', 'goals'],
        'midfielder' => ['goals', 'assists', 'attack'],
        'forward' => ['goals', 'assists', 'attack'],
    ];
    $posTone = [
        'goalkeeper' => 'bg-amber-400 text-amber-950',
        'defender' => 'bg-sky-400 text-sky-950',
        'midfielder' => 'bg-emerald-400 text-emerald-950',
        'forward' => 'bg-rose-400 text-rose-950',
    ];
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
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('Lech players in the clubs')" :title="__('Game stars')"
        :subtitle="__('Compare your Lech players with those of other clubs: every club has its own numbers, counted from the matchdays in which it fielded the player.')">
        <x-slot:aside>
            <div class="grid grid-cols-2 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->all->count() }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Players in clubs') }}</div>
                </div>
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->all->sum('goals') }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Goals') }}</div>
                </div>
            </div>
        </x-slot:aside>
    </x-page-banner>

    @if ($this->all->isEmpty() && ! $season)
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
                        <a href="{{ $row['url'] }}" wire:navigate class="{{ $row['bot'] ? 'italic' : '' }} relative block truncate text-xs text-white/70 hover:text-white hover:underline">{{ $row['club'] }}</a>
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
                {{-- Kluby --}}
                <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-white/10">
                    @foreach (array_filter(['all' => __('All clubs'), 'mine' => auth()->check() ? __('My players') : null, 'users' => __('Players'), 'bots' => __('Bots')]) as $clubKey => $clubLabel)
                        <button type="button" wire:click="$set('clubs', '{{ $clubKey }}')"
                            class="{{ $clubs === $clubKey ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} rounded-full px-3 py-1 text-xs font-bold transition">{{ $clubLabel }}</button>
                    @endforeach
                </div>
                <span class="ms-auto text-sm text-zinc-500">{{ trans_choice(':count player found|:count players found', $this->filtered->count(), ['count' => $this->filtered->count()]) }}</span>
                @if ($this->isFiltered())
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @endif
            </div>
        </div>

        {{-- Tabela --}}
        <x-lech-panel :title="__('Game stars') . ' – ' . $seasonTitle" :subtitle="__('Key statistics for the position are highlighted. Click a column to sort.')" icon="star" flush>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[50rem] text-sm">
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
                            <th class="px-1.5 py-2 text-center" title="{{ __('Position') }}">{{ __('Pos.') }}</th>
                            @foreach ($cols as $col => [$short, $long])
                                <th class="{{ $col === 'contribution' ? 'bg-lech-100/60 dark:bg-lech-900/30' : '' }} px-1.5 py-2 text-right last:pe-4" title="{{ $long }}">
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
                                $key = $keyStats[$row['position']->value] ?? [];
                                $pct = $row['win_pct'];
                                $pctTone = $pct === null ? 'text-zinc-400' : ($pct > 65 ? 'text-green-600 dark:text-green-400' : ($pct >= 35 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400'));
                            @endphp
                            <tr wire:key="star-{{ $row['club_key'] }}-{{ $row['player_id'] }}" class="{{ $mine ? 'bg-amber-50 dark:bg-amber-500/10' : '' }} transition hover:bg-lech-50/70 dark:hover:bg-white/5">
                                <td class="ps-4 pe-2 py-2">
                                    <span class="{{ $row['rank'] <= 3 ? 'bg-amber-400 text-amber-950' : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }} grid size-7 place-items-center rounded-full text-[11px] font-black tabular-nums">{{ $row['rank'] }}</span>
                                </td>
                                <td class="px-1.5 py-2 text-center">
                                    <span class="mx-auto grid size-7 place-items-center rounded-lg bg-lech-800 text-xs font-black tabular-nums text-white">{{ $row['number'] ?? '–' }}</span>
                                </td>
                                <td class="px-1.5 py-2">
                                    <div class="flex items-center gap-1.5 font-semibold text-zinc-900 dark:text-white">
                                        {{ $row['name'] }}
                                        @if ($row['academy'])
                                            <span class="rounded-full bg-lech-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-lech-700 dark:bg-lech-900/60 dark:text-lech-200" title="{{ __('Academy') }}">{{ __('Acad.') }}</span>
                                        @endif
                                        @if ($mine)
                                            <span class="rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold text-amber-950">{{ __('You') }}</span>
                                        @endif
                                    </div>
                                    <a href="{{ $row['url'] }}" wire:navigate class="{{ $row['bot'] ? 'italic' : '' }} block max-w-44 truncate text-xs text-zinc-500 hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $row['club'] }}</a>
                                </td>
                                <td class="px-1.5 py-2 text-center">
                                    <span class="{{ $posTone[$row['position']->value] ?? '' }} inline-grid size-7 place-items-center rounded-full text-[11px] font-black" title="{{ $row['position']->label() }}">{{ $row['position']->short() }}</span>
                                </td>
                                @foreach ($cols as $col => $label)
                                    @php
                                        $value = $row[$col];
                                        $isKey = in_array($col, $key, true);
                                    @endphp
                                    <td class="{{ $col === 'contribution' ? 'bg-lech-50 font-black text-lech-800 dark:bg-lech-900/20 dark:text-lech-200' : ($isKey ? 'font-bold text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400') }} px-1.5 py-2 text-right tabular-nums last:pe-4">
                                        @if ($col === 'win_pct')
                                            <span class="{{ $pctTone }} font-bold" title="{{ __(':won of :games', ['won' => $row['won'], 'games' => $row['games']]) }}">{{ $value === null ? '—' : $fmt($value) . '%' }}</span>
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
                                <td colspan="13" class="px-4 py-8 text-center text-sm text-zinc-500">{{ __('No players match the filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-lech-panel>

        <flux:pagination :paginator="$this->pageItems" />
        <flux:text class="text-xs">{{ __('Appearances count matches that took place (Legends League: each duel). Win % = won matches of the club in the competition type in which the player was fielded; a cup draw decided by tip time counts as a win or a loss.') }}</flux:text>
    @endif
</div>
