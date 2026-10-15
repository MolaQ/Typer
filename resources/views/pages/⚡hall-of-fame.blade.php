<?php

use App\Enums\RoleName;
use App\Models\Bot;
use App\Models\HallOfFameAward;
use App\Models\User;
use App\Support\HallOfFame;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hall of Fame (etap 14): ranking wszech czasów według punktów za sukcesy (mecze na bieżąco, tytuły po sezonie),
 * osobno dla graczy i botów. Podium najlepszej trójki, filtry: nazwa zespołu, trofeum, sortowanie, liczba na stronie.
 * Miejsce w rankingu zawsze z kolejności punktów (filtr i sortowanie go nie zmieniają). Domyślnie otwiera się strona
 * z zalogowanym graczem. Kliknięcie zespołu prowadzi do profilu z gablotą i historią sezonów.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    /** Parametr strony w adresie (?hof=3). */
    private const PAGE = 'hof';

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** 'users' (gracze) albo 'bots'. */
    public string $kind = 'users';

    public string $search = '';

    /** '' = wszyscy, 'any' = z dowolnym trofeum, inaczej klucz trofeum. */
    public string $trophy = '';

    /** 'points', 'trophies' albo 'seasons'. */
    public string $sort = 'points';

    public int $perPage = 25;

    public function mount(): void
    {
        if (request()->query(self::PAGE) === null) {
            $this->jumpToMe();
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Hall of Fame'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['kind', 'search', 'trophy', 'sort', 'perPage'], true)) {
            if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
                $this->perPage = 25;
            }
            $this->resetPage(self::PAGE);
            unset($this->ranking, $this->filtered, $this->pageItems);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'trophy', 'sort');
        $this->resetPage(self::PAGE);
        unset($this->filtered, $this->pageItems);
    }

    /** Strona z zespołem zalogowanego gracza (czyści filtry). */
    public function jumpToMe(): void
    {
        $user = auth()->user();
        if (! $user || $user->hasRole(RoleName::Banned->value)) {
            return;
        }

        $this->kind = 'users';
        $this->reset('search', 'trophy', 'sort');
        unset($this->ranking, $this->filtered, $this->pageItems);

        $index = $this->filtered->search(fn ($row) => $row['id'] === $user->id);
        if ($index !== false) {
            $this->setPage((int) floor($index / $this->perPage) + 1, self::PAGE);
            unset($this->pageItems);
        }
    }

    /**
     * Pełny ranking wybranej grupy: miejsce (z punktów), id, nazwa, punkty, trofea (klucz => liczba), sezony.
     *
     * @return Collection<int, array{rank: int, id: int, name: string, url: string, bot: bool, total: float, trophies: array<string, int>, trophy_count: int, seasons: int}>
     */
    #[Computed]
    public function ranking(): Collection
    {
        $column = $this->kind === 'bots' ? 'bot_id' : 'user_id';

        $sums = HallOfFameAward::query()
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("$column as owner, sum(points) as total, count(distinct season_id) as seasons")
            ->toBase()
            ->get();

        $trophies = HallOfFameAward::query()
            ->whereNotNull($column)
            ->whereNotNull('trophy')
            ->get([$column, 'trophy'])
            ->groupBy($column)
            // Ikony w kolejności ważności trofeów (HallOfFame::trophies()).
            ->map(fn ($rows) => $rows->countBy('trophy')->sortBy(fn ($count, $key) => HallOfFame::trophyRank((string) $key))->all());

        $ids = $sums->pluck('owner')->map(fn ($id) => (int) $id)->all();
        $names = $this->kind === 'bots'
            ? Bot::whereIn('id', $ids)->get(['id', 'name'])->keyBy('id')
            : User::whereIn('id', $ids)->get(['id', 'name', 'team_name'])->keyBy('id');

        return $sums
            ->map(function ($sum) use ($trophies, $names): array {
                $id = (int) $sum->owner;
                $model = $names->get($id);
                $list = $trophies->get($id, []);

                return [
                    'id' => $id,
                    'name' => $model instanceof User ? ($model->team_name ?: $model->name) : ($model->name ?? '—'),
                    'url' => $model instanceof User ? route('team.show', $model) : ($model ? route('bot.show', $model) : ''),
                    'bot' => $this->kind === 'bots',
                    'total' => (float) $sum->total,
                    'trophies' => $list,
                    'trophy_count' => (int) array_sum($list),
                    'seasons' => (int) $sum->seasons,
                ];
            })
            // Ranking: suma punktów, przy remisie więcej trofeów, potem kolejność kont.
            ->sort(fn ($a, $b) => [$b['total'], $b['trophy_count'], $a['id']] <=> [$a['total'], $a['trophy_count'], $b['id']])
            ->values()
            ->map(fn ($row, $index) => ['rank' => $index + 1] + $row);
    }

    /** Ranking po filtrach i sortowaniu (miejsca zostają z pełnego rankingu). */
    #[Computed]
    public function filtered(): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        $rows = $this->ranking
            ->when(mb_strlen($needle) >= 2, fn ($rows) => $rows->filter(fn ($row) => str_contains(mb_strtolower($row['name']), $needle)))
            ->when($this->trophy === 'any', fn ($rows) => $rows->filter(fn ($row) => $row['trophy_count'] > 0))
            ->when($this->trophy !== '' && $this->trophy !== 'any', fn ($rows) => $rows->filter(fn ($row) => isset($row['trophies'][$this->trophy])));

        return match ($this->sort) {
            'trophies' => $rows->sort(fn ($a, $b) => [$b['trophy_count'], $a['rank']] <=> [$a['trophy_count'], $b['rank']])->values(),
            'seasons' => $rows->sort(fn ($a, $b) => [$b['seasons'], $a['rank']] <=> [$a['seasons'], $b['rank']])->values(),
            default => $rows->values(),
        };
    }

    #[Computed]
    public function pageItems(): LengthAwarePaginator
    {
        $current = max(1, (int) $this->getPage(self::PAGE));
        $items = $this->filtered->forPage($current, $this->perPage)->values();

        return new LengthAwarePaginator($items, $this->filtered->count(), $this->perPage, $current, ['pageName' => self::PAGE]);
    }

    public function isFiltered(): bool
    {
        return mb_strlen(trim($this->search)) >= 2 || $this->trophy !== '' || $this->sort !== 'points';
    }

    /** Trofea do filtra: tylko te, które ktoś z wybranej grupy zdobył. @return array<string, string> */
    #[Computed]
    public function trophyOptions(): array
    {
        $won = $this->ranking->flatMap(fn ($row) => array_keys($row['trophies']))->unique()->all();

        return array_filter(HallOfFame::trophies(), fn ($key) => in_array($key, $won, true), ARRAY_FILTER_USE_KEY);
    }

    #[Computed]
    public function icons(): array
    {
        return HallOfFame::iconUrls();
    }
}; ?>

@php
    $trophyNames = \App\Support\HallOfFame::trophies();
    $leader = (float) ($this->ranking->first()['total'] ?? 0);
    $me = auth()->id();
    $podium = $this->ranking->take(3)->values();
    $medal = [
        1 => ['ring' => 'ring-amber-400', 'badge' => 'bg-amber-400 text-amber-950', 'order' => 'sm:order-2 sm:-translate-y-3'],
        2 => ['ring' => 'ring-zinc-300', 'badge' => 'bg-zinc-300 text-zinc-800', 'order' => 'sm:order-1'],
        3 => ['ring' => 'ring-orange-300', 'badge' => 'bg-orange-300 text-orange-950', 'order' => 'sm:order-3'],
    ];
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('All-time ranking')" :title="__('Hall of Fame')"
        :subtitle="__('All-time ranking: points for won matches and cup rounds are added after every matchday, titles, promotions and trophies at the end of the season.')">
        <x-slot:aside>
            <div class="grid grid-cols-2 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->ranking->count() }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ $kind === 'bots' ? __('Bots') : __('Teams') }}</div>
                </div>
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $this->ranking->sum('trophy_count') }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Trophies') }}</div>
                </div>
            </div>
        </x-slot:aside>
    </x-page-banner>

    {{-- Gracze / boty --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-full border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach (['users' => [__('Players'), 'user-group'], 'bots' => [__('Bots'), 'cpu-chip']] as $value => [$label, $icon])
                <button type="button" wire:click="$set('kind', '{{ $value }}')"
                    class="{{ $kind === $value ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300' }} inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold transition">
                    <flux:icon :name="$icon" variant="micro" />{{ $label }}
                </button>
            @endforeach
        </div>
        @auth
            @if ($kind === 'users' && $this->ranking->contains('id', $me))
                <flux:button size="sm" icon="map-pin" wire:click="jumpToMe">{{ __('My place') }}</flux:button>
            @endif
        @endauth
    </div>

    @if ($this->ranking->isEmpty())
        <flux:card>
            <flux:text>{{ __('The Hall of Fame fills up after the first finished season.') }}</flux:text>
        </flux:card>
    @else
        {{-- Podium --}}
        @unless ($this->isFiltered())
            <div class="grid gap-3 pt-3 sm:grid-cols-3 sm:items-end">
                @foreach ($podium as $row)
                    @php $m = $medal[$row['rank']]; @endphp
                    <a href="{{ $row['url'] }}" wire:navigate wire:key="podium-{{ $row['id'] }}"
                        class="{{ $m['order'] }} lech-banner group relative overflow-hidden rounded-2xl p-4 text-white shadow-lg ring-2 {{ $m['ring'] }} transition hover:-translate-y-1">
                        <span class="pointer-events-none absolute -right-2 -top-6 text-[7rem] font-black leading-none text-white/10">{{ $row['rank'] }}</span>
                        <div class="relative flex items-center gap-3">
                            <span class="{{ $m['badge'] }} grid size-10 shrink-0 place-items-center rounded-full text-lg font-black shadow">{{ $row['rank'] }}</span>
                            <div class="min-w-0">
                                <div class="{{ $row['bot'] ? 'italic' : '' }} truncate text-lg font-bold group-hover:underline">{{ $row['name'] }}</div>
                                <div class="text-xs text-white/70">{{ trans_choice(':count season|:count seasons', $row['seasons'], ['count' => $row['seasons']]) }}</div>
                            </div>
                        </div>
                        <div class="relative mt-3 flex items-end justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-1">
                                @foreach (array_slice($row['trophies'], 0, 4, true) as $key => $count)
                                    <span class="inline-flex items-center" title="{{ $trophyNames[$key] ?? $key }}">
                                        @if (isset($this->icons[$key]))
                                            <img src="{{ $this->icons[$key] }}" alt="" class="size-7 object-contain drop-shadow">
                                        @else
                                            <flux:icon.trophy variant="mini" class="text-amber-300" />
                                        @endif
                                        @if ($count > 1)<span class="text-[10px] font-bold text-white/80">×{{ $count }}</span>@endif
                                    </span>
                                @endforeach
                            </div>
                            <div class="text-right">
                                <div class="whitespace-nowrap text-2xl font-black tabular-nums">{{ \App\Support\HallOfFame::format($row['total']) }}</div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/70">{{ __('Points') }}</div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endunless

        {{-- Filtry --}}
        <div class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1fr)_auto] dark:border-zinc-700 dark:bg-zinc-900">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable :placeholder="__('Search team (min. 2 letters)')" />
            <flux:select wire:model.live="trophy">
                <flux:select.option value="">{{ __('All teams') }}</flux:select.option>
                <flux:select.option value="any">{{ __('With any trophy') }}</flux:select.option>
                @foreach ($this->trophyOptions as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="sort">
                <flux:select.option value="points">{{ __('Sort: points') }}</flux:select.option>
                <flux:select.option value="trophies">{{ __('Sort: trophies') }}</flux:select.option>
                <flux:select.option value="seasons">{{ __('Sort: seasons') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="perPage" class="lg:w-24">
                @foreach ([25, 50, 100] as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($this->isFiltered())
                <div class="flex items-center justify-between gap-2 text-sm sm:col-span-2 lg:col-span-4">
                    <span class="text-zinc-500">{{ trans_choice(':count team found|:count teams found', $this->filtered->count(), ['count' => $this->filtered->count()]) }}</span>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                </div>
            @endif
        </div>

        {{-- Ranking --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="lech-bar hidden grid-cols-[3rem_minmax(0,1fr)_minmax(0,1fr)_4rem_7rem] gap-3 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-white sm:grid">
                <span>#</span><span>{{ __('Team') }}</span><span>{{ __('Trophies') }}</span><span class="text-right">{{ __('Seasons') }}</span><span class="text-right">{{ __('Points') }}</span>
            </div>
            <ol class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($this->pageItems as $row)
                    @php
                        $mine = ! $row['bot'] && $me && $row['id'] === (int) $me;
                        $share = $leader > 0 ? max(2, round($row['total'] / $leader * 100)) : 0;
                    @endphp
                    <li wire:key="hof-{{ $kind }}-{{ $row['id'] }}"
                        class="{{ $mine ? 'bg-amber-50 dark:bg-amber-500/10' : '' }} grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:grid-cols-[3rem_minmax(0,1fr)_minmax(0,1fr)_4rem_7rem]">
                        <span class="{{ $row['rank'] <= 3 ? ($medal[$row['rank']]['badge'] ?? '') : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }} grid size-8 place-items-center rounded-full text-xs font-black tabular-nums">{{ $row['rank'] }}</span>
                        <a href="{{ $row['url'] }}" wire:navigate class="{{ $row['bot'] ? 'italic' : '' }} truncate font-semibold hover:text-lech-700 hover:underline dark:hover:text-lech-300">
                            {{ $row['name'] }}
                            @if ($mine)<span class="ms-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold not-italic text-amber-950">{{ __('You') }}</span>@endif
                        </a>
                        {{-- Punkty: na telefonie w pierwszym wierszu, na komputerze w ostatniej kolumnie --}}
                        <div class="text-right sm:order-last">
                            <div class="whitespace-nowrap font-black tabular-nums">{{ \App\Support\HallOfFame::format($row['total']) }}</div>
                            <div class="ms-auto mt-1 h-1 w-20 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                <div class="h-full rounded-full bg-gradient-to-r from-lech-400 to-lech-700" style="width: {{ $share }}%"></div>
                            </div>
                        </div>
                        <div class="col-span-3 col-start-2 flex flex-wrap items-center gap-1 sm:col-span-1 sm:col-start-auto">
                            @forelse ($row['trophies'] as $key => $count)
                                <span class="inline-flex items-center gap-0.5" title="{{ $trophyNames[$key] ?? $key }}">
                                    @if (isset($this->icons[$key]))
                                        <img src="{{ $this->icons[$key] }}" alt="{{ $trophyNames[$key] ?? $key }}" class="size-6 object-contain">
                                    @else
                                        <flux:icon.trophy variant="micro" class="text-amber-500" />
                                    @endif
                                    @if ($count > 1)<span class="text-xs text-zinc-500">×{{ $count }}</span>@endif
                                </span>
                            @empty
                                <span class="hidden text-xs text-zinc-400 sm:inline">—</span>
                            @endforelse
                            <span class="text-xs text-zinc-500 sm:hidden">· {{ trans_choice(':count season|:count seasons', $row['seasons'], ['count' => $row['seasons']]) }}</span>
                        </div>
                        <span class="hidden text-right tabular-nums text-zinc-600 sm:block dark:text-zinc-300">{{ $row['seasons'] }}</span>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-zinc-500">{{ __('No teams match the filters.') }}</li>
                @endforelse
            </ol>
        </div>

        <flux:pagination :paginator="$this->pageItems" />
    @endif
</div>
