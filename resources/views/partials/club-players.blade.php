{{--
    Zawodnicy klubu (Support\ClubStats::players) jako karty: numer, pozycja, doświadczenie w klubie łącznie (kolejność, pasek względem najlepszego)
    i dorobek w bieżącym sezonie (filtr pozycji B/O/P/N). Klik w kartę otwiera okno: suma wszystkich sezonów, a pod nią sezony od ostatniego,
    każdy rozwijany z meczami od pierwszej kolejki. Używane w zakładce „Moja kadra” na /tips i na profilu bota.
    Zmienne: $clubPlayers, opcjonalnie $clubTitle.
--}}
@php
    $statCols = [
        'fielded' => [__('Fielded'), __('Matchdays in the line-up')],
        'play_time' => [__('Time'), __('Playing time in halves')],
        'goals' => ['G', __('Goals')],
        'assists' => ['A', __('Assists')],
        'clean_sheets' => [__('CS'), __('Clean sheets')],
        'attack' => [__('Att.'), __('Attack points')],
        'defense' => [__('Def.'), __('Defence points')],
        'experience' => [__('Exp.'), __('Experience')],
    ];
    // Kafelki na karcie: najważniejsze liczby sezonu
    $cardCols = ['fielded', 'play_time', 'goals', 'assists', 'clean_sheets'];
    $fmt = fn ($v) => \App\Support\ClubStats::format($v);
    $exp = fn ($v) => \App\Support\ClubStats::experience($v);
    $maxExp = max(1, collect($clubPlayers)->max(fn ($c) => $c['career']['experience']) ?: 1);
    // Barwy pozycji (B/O/P/N)
    $posTone = [
        'goalkeeper' => 'bg-amber-400 text-amber-950',
        'defender' => 'bg-sky-400 text-sky-950',
        'midfielder' => 'bg-emerald-400 text-emerald-950',
        'forward' => 'bg-rose-400 text-rose-950',
    ];
@endphp
<div class="space-y-4" x-data="{ open: null, season: null, pos: 'all' }" x-on:keydown.escape.window="open = null">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ $clubTitle ?? __('Club players') }}</flux:heading>
            <flux:text class="text-xs">{{ __('Ordered by experience in the club') }}</flux:text>
        </div>
        {{-- Filtr pozycji --}}
        <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-white/10">
            @foreach (['all' => __('Everyone')] + collect(\App\Enums\PlayerPosition::cases())->mapWithKeys(fn ($pos) => [$pos->value => $pos->short()])->all() as $posKey => $posLabel)
                <button type="button" x-on:click="pos = '{{ $posKey }}'" title="{{ $posKey === 'all' ? $posLabel : \App\Enums\PlayerPosition::from($posKey)->label() }}"
                    x-bind:class="pos === '{{ $posKey }}' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300'"
                    class="min-w-9 rounded-full px-3 py-1 text-xs font-bold transition">{{ $posLabel }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($clubPlayers as $club)
            @php
                $p = $club['player'];
                $s = $club['season'];
                $careerExp = $club['career']['experience'];
                $share = (int) round(100 * $careerExp / $maxExp);
                $idle = $club['career']['fielded'] == 0;
            @endphp
            <button type="button" data-club-player wire:key="club-player-{{ $p->id }}" x-show="pos === 'all' || pos === '{{ $p->position->value }}'"
                x-on:click="open = {{ $p->id }}; season = null"
                class="{{ $idle ? 'opacity-60 hover:opacity-100' : '' }} group flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-lech-500 dark:border-zinc-700 dark:bg-zinc-900">
                {{-- Nagłówek karty: numer, pozycja, nazwisko --}}
                <div class="lech-banner relative overflow-hidden px-4 pb-3 pt-3">
                    <span class="pointer-events-none absolute -right-2 -top-5 select-none text-8xl font-black leading-none text-white/10 tabular-nums transition group-hover:text-white/20">{{ $p->number }}</span>
                    <div class="relative flex items-center gap-2">
                        <span class="{{ $posTone[$p->position->value] ?? 'bg-white text-zinc-900' }} grid size-7 place-items-center rounded-full text-xs font-black shadow" title="{{ $p->position->label() }}">{{ $p->position->short() }}</span>
                        <span class="text-sm font-bold tabular-nums text-white/70">#{{ $p->number }}</span>
                        @if ($p->is_academy)
                            <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/80">{{ __('Academy') }}</span>
                        @endif
                    </div>
                    <div class="relative mt-2 truncate text-lg font-extrabold leading-tight text-white">{{ $p->name }}</div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    {{-- Doświadczenie łącznie i w sezonie --}}
                    <div class="flex items-end justify-between gap-2">
                        <div title="{{ __('Experience in the club, all seasons') }}">
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Total exp.') }}</div>
                            <div class="text-3xl font-black leading-none tabular-nums text-lech-700 dark:text-lech-300">{{ $exp($careerExp) }}</div>
                        </div>
                        <div class="text-right" title="{{ __('Experience') }} ({{ __('this season') }})">
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('This season') }}</div>
                            <div class="text-lg font-bold leading-none tabular-nums {{ $s['experience'] ? 'text-zinc-800 dark:text-zinc-100' : 'text-zinc-300 dark:text-zinc-600' }}">+{{ $exp($s['experience']) }}</div>
                        </div>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-gradient-to-r from-lech-400 via-lech-600 to-lech-800" style="width: {{ $share }}%"></div>
                    </div>

                    {{-- Sezon w liczbach --}}
                    <div class="grid grid-cols-5 gap-1">
                        @foreach ($cardCols as $col)
                            <div class="rounded-lg bg-zinc-50 px-1 py-1.5 text-center dark:bg-white/5" title="{{ $statCols[$col][1] }} ({{ __('this season') }})">
                                <div class="text-sm font-bold tabular-nums {{ $s[$col] ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $fmt($s[$col]) }}</div>
                                <div class="truncate text-[9px] font-semibold uppercase text-zinc-500">{{ $col === 'fielded' ? __('Matches') : $statCols[$col][0] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2 text-xs">
                        <span class="tabular-nums">@include('partials.card-counts', ['yellow' => $s['yellow'], 'red' => $s['red']])</span>
                        @if ($club['free_at'])
                            <span class="{{ \App\Support\Squad::lockColor(\App\Support\Squad::hoursLeft($club['free_at'])) }} rounded-full px-2 py-0.5 text-[10px] font-bold">
                                {{ __('free from :date', ['date' => \Illuminate\Support\Carbon::createFromTimestamp($club['free_at'], config('app.timezone'))->format('d.m, H:i')]) }}
                            </span>
                        @else
                            <span class="flex items-center gap-1 font-semibold text-green-600 dark:text-green-400"><span class="size-1.5 rounded-full bg-green-500"></span>{{ __('Ready to play') }}</span>
                        @endif
                    </div>
                </div>
            </button>
        @endforeach
    </div>
    <flux:text class="text-xs">{{ __('Each club has its own Lech players: the numbers come only from matches that took place in matchdays in which this club fielded the player. Click a player to see all seasons, then a season to see its matches.') }}</flux:text>

    {{-- Okno zawodnika: wszystkie sezony i mecze --}}
    @foreach ($clubPlayers as $club)
        @php
            $p = $club['player'];
        @endphp
        <template x-teleport="body" wire:key="club-player-modal-{{ $p->id }}">
            <div x-show="open === {{ $p->id }}" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
                <div x-show="open === {{ $p->id }}" x-transition.opacity class="absolute inset-0 bg-zinc-950/60 backdrop-blur-sm" x-on:click="open = null"></div>
                <div x-show="open === {{ $p->id }}" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
                    class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl dark:bg-zinc-900">
                    <div class="lech-banner relative overflow-hidden px-5 py-4">
                        <span class="pointer-events-none absolute -bottom-6 right-12 select-none text-[9rem] font-black leading-none text-white/10 tabular-nums">{{ $p->number }}</span>
                        <div class="relative flex items-start gap-3">
                            <span class="{{ $posTone[$p->position->value] ?? 'bg-white text-zinc-900' }} grid size-10 shrink-0 place-items-center rounded-full text-sm font-black shadow">{{ $p->position->short() }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-semibold uppercase tracking-wider text-white/60">#{{ $p->number }} · {{ $p->position->label() }}</div>
                                <div class="truncate text-2xl font-extrabold text-white">{{ $p->name }}</div>
                            </div>
                            <button type="button" x-on:click="open = null" class="rounded-full p-1.5 text-white/70 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('Close') }}">
                                <flux:icon.x-mark class="size-5" />
                            </button>
                        </div>
                        {{-- Suma wszystkich sezonów --}}
                        <div class="relative mt-4 grid grid-cols-4 gap-2 sm:grid-cols-8">
                            @foreach ($statCols as $col => [$short, $long])
                                <div class="{{ $col === 'experience' ? 'bg-white text-lech-800' : 'bg-white/10 text-white' }} rounded-xl px-1 py-2 text-center" title="{{ $long }} ({{ __('All seasons') }})">
                                    <div class="text-lg font-black leading-none tabular-nums">{{ $col === 'experience' ? $exp($club['career'][$col]) : $fmt($club['career'][$col]) }}</div>
                                    <div class="{{ $col === 'experience' ? 'text-lech-600' : 'text-white/60' }} mt-1 text-[10px] font-semibold uppercase">{{ $short }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="relative mt-2 flex items-center justify-between text-xs text-white/70">
                            <span>{{ __('All seasons') }}</span>
                            <span class="rounded-full bg-white/90 px-2 py-0.5 font-semibold text-zinc-800">@include('partials.card-counts', ['yellow' => $club['career']['yellow'], 'red' => $club['career']['red']])</span>
                        </div>
                    </div>

                    <div class="flex-1 space-y-2 overflow-y-auto p-4">
                        @forelse ($club['seasons'] as $seasonBlock)
                            @php
                                $seasonKey = $p->id . '-' . $loop->index;
                                $sums = $seasonBlock['sums'];
                            @endphp
                            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition hover:bg-zinc-50 dark:hover:bg-white/5"
                                    x-on:click="season = season === '{{ $seasonKey }}' ? null : '{{ $seasonKey }}'">
                                    <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition" x-bind:class="season === '{{ $seasonKey }}' && 'rotate-90'" />
                                    <span class="whitespace-nowrap font-bold">{{ $seasonBlock['title'] }}</span>
                                    @if ($seasonBlock['current'])
                                        <span class="hidden whitespace-nowrap rounded-full bg-lech-100 px-2 py-0.5 text-[10px] font-semibold text-lech-800 sm:inline dark:bg-lech-900/60 dark:text-lech-100">{{ __('this season') }}</span>
                                    @endif
                                    <span class="ms-auto flex flex-wrap items-center justify-end gap-x-3 gap-y-1 text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                        <span class="hidden sm:inline" title="{{ __('Matchdays in the line-up') }}">{{ $fmt($sums['fielded']) }} {{ __('Fielded') }}</span>
                                        @if ($sums['goals'])<span title="{{ __('Goals') }}">⚽ {{ $fmt($sums['goals']) }}</span>@endif
                                        @if ($sums['assists'])<span title="{{ __('Assists') }}">A {{ $fmt($sums['assists']) }}</span>@endif
                                        @if ($sums['clean_sheets'])<span title="{{ __('Clean sheets') }}">{{ __('CS') }} {{ $fmt($sums['clean_sheets']) }}</span>@endif
                                        @include('partials.card-counts', ['yellow' => $sums['yellow'], 'red' => $sums['red']])
                                        <span class="rounded-full bg-lech-700 px-2 py-0.5 font-bold text-white">{{ $exp($sums['experience']) }}</span>
                                    </span>
                                </button>
                                <ol x-show="season === '{{ $seasonKey }}'" x-collapse x-cloak class="divide-y divide-zinc-100 border-t border-zinc-200 bg-zinc-50/60 text-xs dark:divide-white/5 dark:border-zinc-700 dark:bg-white/5">
                                    @foreach ($seasonBlock['matches'] as $m)
                                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-white text-[11px] font-black tabular-nums shadow-sm dark:bg-zinc-800" title="{{ __('Matchday :number', ['number' => $m['matchday']]) }}">{{ $m['matchday'] }}</span>
                                            <span class="min-w-0 flex-1 truncate font-medium">{{ $m['fixture'] }}</span>
                                            <span class="rounded-full bg-lech-100 px-2 py-0.5 text-[10px] font-semibold text-lech-800 dark:bg-lech-900/60 dark:text-lech-100">{{ $m['set'] }}@if ($m['times'] > 1) ×{{ $m['times'] }}@endif</span>
                                            <span class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 ps-10 sm:w-auto sm:ps-0">
                                                @if (! $m['counted'])
                                                    <span class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold text-white">{{ __('Forfeited (rest)') }}</span>
                                                @elseif ($m['play_time'] <= 0)
                                                    <span class="text-zinc-500">{{ __('Did not play') }}</span>
                                                    @if ($m['experience'] > 0)<span class="font-semibold text-lech-700 dark:text-lech-300">+{{ $exp($m['experience']) }}</span>@endif
                                                @else
                                                    <span class="tabular-nums text-zinc-500" title="{{ __('Playing time in halves') }}">{{ __('Time') }} {{ $fmt($m['play_time']) }}</span>
                                                    @if ($m['goals'])<span title="{{ __('Goals') }}">⚽ {{ $m['goals'] }}</span>@endif
                                                    @if ($m['assists'])<span>{{ __('Assist') }} {{ $m['assists'] }}</span>@endif
                                                    @if ($m['own_goals'])<span class="text-red-600">{{ __('Own goal') }} {{ $m['own_goals'] }}</span>@endif
                                                    @if ($m['clean_sheet'] === true)<span class="text-green-600">{{ __('Clean sheet') }}</span>@endif
                                                    @include('partials.card-counts', ['yellow' => $m['yellow'], 'red' => (int) $m['red']])
                                                    @if ($m['rest_hours'])<span class="text-zinc-500">{{ __('Rest: :count h', ['count' => $m['rest_hours']]) }}</span>@endif
                                                    <span class="font-semibold text-lech-700 dark:text-lech-300" title="{{ __('Experience') }}">+{{ $exp($m['experience']) }}</span>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @empty
                            <div class="py-8 text-center">
                                <flux:text>{{ __('Not fielded yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </template>
    @endforeach
</div>
