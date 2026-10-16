{{--
    Historia zespołu sezon po sezonie (App\Support\TeamHistory): wiersz na rozgrywki w barwach rozgrywek,
    wynik (miejsce, etap, zwycięzca), zmiana ligi w następnym sezonie i punkty Hall of Fame.
    Filtr typów rozgrywek w Alpine (hg). Używane na profilu zespołu i bota.
    Zmienne: $history.
--}}
@php
    $groupLabels = [
        'all' => __('All competitions'),
        'league' => __('Leagues'),
        'cup' => 'Puchar Polski',
        'europe' => __('European cups'),
        'legends' => 'Liga Legend',
    ];
    $moveTone = [
        'up' => 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
        'down' => 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
        'stay' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
    ];
@endphp
<section class="space-y-4" x-data="{ hg: 'all' }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Season history') }}</flux:heading>
        <div class="inline-flex flex-wrap gap-1 rounded-full bg-zinc-100 p-1 dark:bg-white/10">
            @foreach ($groupLabels as $groupKey => $groupLabel)
                <button type="button" x-on:click="hg = '{{ $groupKey }}'"
                    x-bind:class="hg === '{{ $groupKey }}' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300'"
                    class="rounded-full px-3 py-1 text-xs font-bold transition">{{ $groupLabel }}</button>
            @endforeach
        </div>
    </div>

    @forelse ($history as $item)
        @php
            $groups = collect($item['rows'])->pluck('group')->unique()->values()->all();
        @endphp
        <article class="lech-bar-shadow overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
            wire:key="history-{{ $item['season']->id }}" x-show="hg === 'all' || @js($groups).includes(hg)">
            <header class="lech-bar flex flex-wrap items-center gap-3 px-5 py-3">
                <h3 class="text-lg font-black tracking-wide">{{ $item['season']->title }}</h3>
                @if ($item['trophies'] !== [])
                    <x-champion-trophies :keys="$item['trophies']" :size="20" framed />
                @endif
                <span class="ms-auto rounded-full bg-white/15 px-3 py-0.5 text-sm font-bold tabular-nums">{{ __(':points pts', ['points' => \App\Support\HallOfFame::format($item['points'])]) }}</span>
            </header>
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($item['rows'] as $row)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-sm" x-show="hg === 'all' || hg === '{{ $row['group'] }}'">
                        <span class="comp-badge {{ \App\Support\CompetitionColors::dashed($row['key']) ? 'comp-dashed' : '' }} size-3 shrink-0 rounded-full" style="{{ \App\Support\CompetitionColors::style($row['key']) }}"></span>
                        <span class="min-w-0 flex-1 font-medium sm:flex-none sm:basis-48">{{ $row['name'] }}</span>
                        <span class="{{ $row['winner'] ? 'text-amber-600 dark:text-amber-400' : '' }} flex items-center gap-1 font-bold">
                            @if ($row['winner'])
                                <flux:icon.trophy variant="micro" />
                            @endif
                            {{ $row['result'] }}
                        </span>
                        @if ($row['move'])
                            <span class="{{ $moveTone[$row['move_tone']] ?? '' }} rounded-full px-2 py-0.5 text-xs font-semibold">
                                {{ $row['move_tone'] === 'up' ? '▲' : ($row['move_tone'] === 'down' ? '▼' : '•') }} {{ $row['move'] }}
                            </span>
                        @endif
                        <span class="ms-auto w-16 text-right text-xs tabular-nums text-zinc-500" title="{{ __('Hall of Fame points') }}">{{ $row['points'] ? '+' . \App\Support\HallOfFame::format($row['points']) : '' }}</span>
                    </li>
                @empty
                    <li class="px-5 py-3 text-sm text-zinc-500">{{ __('Did not play this season.') }}</li>
                @endforelse
            </ul>
        </article>
    @empty
        <flux:text>{{ __('No finished seasons yet.') }}</flux:text>
    @endforelse
</section>
