{{--
    Zawodnicy klubu (Support\ClubStats::players) jako tabela do porównań (15.10.2026, wcześniej karty): numer w kolorze pozycji (legenda pod tabelą),
    zawodnik (biret = wychowanek), doświadczenie w klubie łącznie (pasek względem najlepszego) i dorobek w bieżącym sezonie albo w całej karierze (przełącznik).
    Czyste konta tylko przy bramkarzach i obrońcach. Kolumny włączane przyciskami (Alpine `cols`, zapamiętane w localStorage); domyślnie ukryte:
    doświadczenie z sezonu i status (gotowy / wolny od). Sortowanie kliknięciem w nagłówek (atrybuty data-*), filtr pozycji B/O/P/N.
    Klik w wiersz otwiera okno: suma wszystkich sezonów, a pod nią sezony od ostatniego, każdy rozwijany z meczami od pierwszej kolejki.
    Używane w zakładce „Moja kadra” na /tips i na profilu bota. Zmienne: $clubPlayers, opcjonalnie $clubTitle.
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
    // Kolumny tabeli (sezon albo kariera); czas gry tylko w oknie zawodnika
    $tableCols = ['fielded', 'goals', 'assists', 'clean_sheets', 'attack', 'defense', 'experience'];
    // Przełączniki kolumn: klucz => [etykieta, domyślnie widoczna]
    $toggles = [
        'fielded' => [__('Fielded'), true],
        'goals' => [__('Goals'), true],
        'assists' => [__('Assists'), true],
        'clean_sheets' => [__('Clean sheets'), true],
        'attack' => [__('Attack points'), true],
        'defense' => [__('Defence points'), true],
        'cards' => [__('Cards'), true],
        'experience' => [__('Experience (season or career)'), false],
        'status' => [__('Status'), false],
    ];
    // Czyste konto liczy się tylko bramkarzom i obrońcom
    $sheetPositions = ['goalkeeper', 'defender'];
    $fmt = fn ($v) => \App\Support\ClubStats::format($v);
    $exp = fn ($v) => \App\Support\ClubStats::experience($v);
    $maxExp = max(1, collect($clubPlayers)->max(fn ($c) => $c['career']['experience']) ?: 1);
    // Barwy pozycji (B/O/P/N): kolor kwadratu z numerem
    $posTone = [
        'goalkeeper' => 'bg-amber-400 text-amber-950',
        'defender' => 'bg-sky-400 text-sky-950',
        'midfielder' => 'bg-emerald-400 text-emerald-950',
        'forward' => 'bg-rose-400 text-rose-950',
    ];
    $posOrder = array_flip(\App\Enums\PlayerPosition::values());
    $defaults = collect($toggles)->map(fn ($t) => $t[1])->all();
@endphp
<div class="space-y-3" x-on:keydown.escape.window="open = null"
    x-data="{
        open: null, season: null, pos: 'all', scope: 'season', sort: 'total', dir: -1,
        cols: Object.assign({{ json_encode($defaults) }}, (() => { try { return JSON.parse(localStorage.getItem('club-cols') || '{}') } catch (e) { return {} } })()),
        toggle(key) {
            this.cols[key] = ! this.cols[key];
            try { localStorage.setItem('club-cols', JSON.stringify(this.cols)) } catch (e) {}
        },
        sortBy(key) {
            if (this.sort === key) { this.dir = -this.dir } else { this.sort = key; this.dir = ['name', 'number', 'pos'].includes(key) ? 1 : -1 }
            this.apply();
        },
        attr(key) { return ['total', 'name', 'number', 'pos'].includes(key) ? key : this.scope + '-' + key },
        apply() {
            const body = this.$refs.body, attr = 'data-' + this.attr(this.sort), dir = this.dir;
            [...body.children].sort((a, b) => {
                const x = a.getAttribute(attr), y = b.getAttribute(attr);
                const cmp = isNaN(parseFloat(x)) ? x.localeCompare(y, 'pl') : parseFloat(x) - parseFloat(y);
                return cmp * dir || parseFloat(b.dataset.total) - parseFloat(a.dataset.total);
            }).forEach(row => body.appendChild(row));
        },
    }" x-effect="scope; $nextTick(() => apply())">
    <x-lech-panel :title="$clubTitle ?? __('Club players')" :subtitle="__('Click a column to sort, click a player to see all seasons and matches.')" icon="user-group" flush>
        <x-slot:actions>
            {{-- Sezon albo kariera --}}
            <div class="inline-flex rounded-full bg-white/10 p-1">
                @foreach (['season' => __('This season'), 'career' => __('All seasons')] as $scopeKey => $scopeLabel)
                    <button type="button" x-on:click="scope = '{{ $scopeKey }}'"
                        x-bind:class="scope === '{{ $scopeKey }}' ? 'bg-white text-lech-800 shadow' : 'text-white/80 hover:text-white'"
                        class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold transition">{{ $scopeLabel }}</button>
                @endforeach
            </div>
        </x-slot:actions>

        {{-- Filtr pozycji i przełączniki kolumn --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-700">
            <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-white/10">
                @foreach (['all' => __('Everyone')] + collect(\App\Enums\PlayerPosition::cases())->mapWithKeys(fn ($pos) => [$pos->value => $pos->short()])->all() as $posKey => $posLabel)
                    <button type="button" x-on:click="pos = '{{ $posKey }}'" title="{{ $posKey === 'all' ? $posLabel : \App\Enums\PlayerPosition::from($posKey)->label() }}"
                        x-bind:class="pos === '{{ $posKey }}' ? 'bg-lech-700 text-white shadow' : 'text-zinc-600 hover:text-lech-700 dark:text-zinc-300'"
                        class="min-w-9 rounded-full px-3 py-1 text-xs font-bold transition">{{ $posLabel }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <span class="me-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Columns') }}</span>
                @foreach ($toggles as $colKey => [$colLabel, $on])
                    <button type="button" x-on:click="toggle('{{ $colKey }}')"
                        x-bind:class="cols['{{ $colKey }}'] ? 'border-lech-600 bg-lech-50 text-lech-800 dark:border-lech-400 dark:bg-lech-900/40 dark:text-lech-100' : 'border-zinc-200 text-zinc-400 line-through dark:border-zinc-700'"
                        class="rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition">{{ $colLabel }}</button>
                @endforeach
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-[11px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-white/5">
                    <tr>
                        @foreach (['number' => ['#', __('Number'), 'text-center w-12'], 'name' => [__('Footballer'), __('Footballer'), 'text-left'], 'total' => [__('Total exp.'), __('Experience in the club, all seasons'), 'text-left w-32']] as $sortKey => [$short, $long, $align])
                            <th class="{{ $align }} whitespace-nowrap px-2 py-2 first:ps-4" title="{{ $long }}">
                                <button type="button" x-on:click="sortBy('{{ $sortKey }}')" class="inline-flex items-center gap-1 uppercase hover:text-lech-700 dark:hover:text-lech-300"
                                    x-bind:class="sort === '{{ $sortKey }}' && 'text-lech-700 dark:text-lech-300'">
                                    {{ $short }}<span x-show="sort === '{{ $sortKey }}'" x-text="dir > 0 ? '▲' : '▼'" class="text-[9px]"></span>
                                </button>
                            </th>
                        @endforeach
                        @foreach ($tableCols as $col)
                            <th x-show="cols['{{ $col }}']" class="whitespace-nowrap px-2 py-2 text-right" title="{{ $statCols[$col][1] }}">
                                <button type="button" x-on:click="sortBy('{{ $col }}')" class="inline-flex items-center gap-1 uppercase hover:text-lech-700 dark:hover:text-lech-300"
                                    x-bind:class="sort === '{{ $col }}' && 'text-lech-700 dark:text-lech-300'">
                                    <span x-show="sort === '{{ $col }}'" x-text="dir > 0 ? '▲' : '▼'" class="text-[9px]"></span>{{ $statCols[$col][0] }}
                                </button>
                            </th>
                        @endforeach
                        <th x-show="cols.cards" class="px-2 py-2 text-center" title="{{ __('Yellow and red cards') }}">{{ __('Cards') }}</th>
                        <th x-show="cols.status" x-cloak class="px-4 py-2 text-right">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody x-ref="body" class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($clubPlayers as $club)
                        @php
                            $p = $club['player'];
                            $careerExp = $club['career']['experience'];
                            $share = (int) round(100 * $careerExp / $maxExp);
                            $idle = $club['career']['fielded'] == 0;
                            $sheets = in_array($p->position->value, $sheetPositions, true);
                        @endphp
                        <tr wire:key="club-player-{{ $p->id }}" data-club-player x-show="pos === 'all' || pos === '{{ $p->position->value }}'"
                            x-on:click="open = {{ $p->id }}; season = null"
                            data-total="{{ $careerExp }}" data-number="{{ $p->number ?? 999 }}" data-name="{{ $p->name }}" data-pos="{{ $posOrder[$p->position->value] ?? 9 }}"
                            @foreach ($tableCols as $col) data-season-{{ $col }}="{{ in_array($col, ['clean_sheets', 'defense'], true) && ! $sheets ? -1 : $club['season'][$col] }}" data-career-{{ $col }}="{{ in_array($col, ['clean_sheets', 'defense'], true) && ! $sheets ? -1 : $club['career'][$col] }}" @endforeach
                            class="{{ $idle ? 'text-zinc-400 dark:text-zinc-500' : 'text-zinc-700 dark:text-zinc-200' }} cursor-pointer transition hover:bg-lech-50/70 dark:hover:bg-white/5">
                            <td class="ps-4 pe-2 py-2 text-center">
                                <span class="{{ $posTone[$p->position->value] ?? 'bg-zinc-200 text-zinc-800' }} grid size-8 place-items-center rounded-lg text-xs font-black tabular-nums" title="{{ $p->position->label() }}">{{ $p->number ?? '–' }}</span>
                            </td>
                            <td class="px-2 py-2">
                                <span class="flex items-center gap-1.5 whitespace-nowrap font-semibold {{ $idle ? '' : 'text-zinc-900 dark:text-white' }}">
                                    {{ $p->name }}
                                    @if ($p->is_academy)
                                        <flux:icon.academic-cap variant="micro" class="shrink-0 text-lech-600 dark:text-lech-300" title="{{ __('Academy') }}" />
                                    @endif
                                </span>
                            </td>
                            <td class="px-2 py-2">
                                <div class="font-semibold tabular-nums text-lech-700 dark:text-lech-300">{{ $exp($careerExp) }}</div>
                                <div class="mt-1 h-1 w-24 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-gradient-to-r from-lech-400 to-lech-800" style="width: {{ $share }}%"></div>
                                </div>
                            </td>
                            @foreach ($tableCols as $col)
                                <td x-show="cols['{{ $col }}']" @if ($col === 'experience') x-cloak @endif class="px-2 py-2 text-right tabular-nums">
                                    @if (in_array($col, ['clean_sheets', 'defense'], true) && ! $sheets)
                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                    @else
                                        @foreach (['season', 'career'] as $scopeKey)
                                            @php
                                                $v = $club[$scopeKey][$col];
                                            @endphp
                                            <span x-show="scope === '{{ $scopeKey }}'" @if ($scopeKey === 'career') x-cloak @endif class="{{ $v ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $col === 'experience' ? $exp($v) : $fmt($v) }}</span>
                                        @endforeach
                                    @endif
                                </td>
                            @endforeach
                            <td x-show="cols.cards" class="px-2 py-2 text-center text-xs tabular-nums">
                                <span x-show="scope === 'season'">@include('partials.card-counts', ['yellow' => $club['season']['yellow'], 'red' => $club['season']['red']])</span>
                                <span x-show="scope === 'career'" x-cloak>@include('partials.card-counts', ['yellow' => $club['career']['yellow'], 'red' => $club['career']['red']])</span>
                            </td>
                            <td x-show="cols.status" x-cloak class="px-4 py-2 text-right text-xs">
                                @if ($club['free_at'])
                                    <span class="{{ \App\Support\Squad::lockColor(\App\Support\Squad::hoursLeft($club['free_at'])) }} whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold">
                                        {{ __('free from :date', ['date' => \Illuminate\Support\Carbon::createFromTimestamp($club['free_at'], config('app.timezone'))->format('d.m, H:i')]) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap font-semibold text-green-600 dark:text-green-400"><span class="size-1.5 rounded-full bg-green-500"></span>{{ __('Ready to play') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Legenda: kolor numeru = pozycja, biret = wychowanek --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-zinc-200 px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-700">
            @foreach (\App\Enums\PlayerPosition::cases() as $pos)
                <span class="inline-flex items-center gap-1.5"><span class="{{ $posTone[$pos->value] }} size-3 rounded"></span>{{ $pos->label() }}</span>
            @endforeach
            <span class="inline-flex items-center gap-1.5"><flux:icon.academic-cap variant="micro" class="text-lech-600 dark:text-lech-300" />{{ __('Academy') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="text-zinc-300">·</span>{{ __('Clean sheets and defence points count only for goalkeepers and defenders') }}</span>
        </div>
    </x-lech-panel>
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
