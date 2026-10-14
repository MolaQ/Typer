{{--
    Zawodnicy klubu (Support\ClubStats::players): tabela dorobku w bieżącym sezonie (doświadczenie malejąco, filtr pozycji B/O/P/N),
    klik w wiersz pokazuje sumę wszystkich sezonów, a pod nią sezony od ostatniego, każdy rozwijany z meczami od pierwszej kolejki.
    Używane w zakładce „Moja kadra” na /tips i na profilu bota.
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
    $fmt = fn ($v) => \App\Support\ClubStats::format($v);
@endphp
<div class="space-y-3" x-data="{ open: null, season: null, pos: 'all' }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="lg">{{ $clubTitle ?? __('Club players') }}</flux:heading>
        {{-- Filtr pozycji --}}
        <div class="flex gap-1">
            @foreach (['all' => __('Everyone')] + collect(\App\Enums\PlayerPosition::cases())->mapWithKeys(fn ($pos) => [$pos->value => $pos->short()])->all() as $posKey => $posLabel)
                <button type="button" x-on:click="pos = '{{ $posKey }}'" title="{{ $posKey === 'all' ? $posLabel : \App\Enums\PlayerPosition::from($posKey)->label() }}"
                    x-bind:class="pos === '{{ $posKey }}' ? 'border-lech-700 bg-lech-700 text-white' : 'border-zinc-300 bg-white text-zinc-700 hover:border-lech-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200'"
                    class="min-w-8 rounded-md border px-2 py-1 text-xs font-bold transition">{{ $posLabel }}</button>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">{{ __('Player') }} <span class="font-normal">({{ __('this season') }})</span></th>
                    @foreach ($statCols as [$short, $long])
                        <th class="px-2 py-2 text-center font-medium" title="{{ $long }}">{{ $short }}</th>
                    @endforeach
                    <th class="px-2 py-2 text-center font-medium">{{ __('Cards') }}</th>
                </tr>
            </thead>
            @foreach ($clubPlayers as $club)
                @php
                    $p = $club['player'];
                @endphp
                <tbody class="border-t border-zinc-100 dark:border-white/5" wire:key="club-player-{{ $p->id }}" x-show="pos === 'all' || pos === '{{ $p->position->value }}'">
                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5" x-on:click="open = open === {{ $p->id }} ? null : {{ $p->id }}">
                        <td class="px-3 py-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-5 shrink-0 text-right text-xs tabular-nums text-zinc-400">{{ $p->number }}</span>
                                <span class="w-4 shrink-0 text-[10px] font-semibold uppercase text-zinc-500">{{ $p->position->short() }}</span>
                                <span class="truncate font-medium">{{ $p->name }}</span>
                                @if ($club['free_at'])
                                    <span class="{{ \App\Support\Squad::lockColor(\App\Support\Squad::hoursLeft($club['free_at'])) }} shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold">
                                        {{ __('free from :date', ['date' => \Illuminate\Support\Carbon::createFromTimestamp($club['free_at'], config('app.timezone'))->format('d.m, H:i')]) }}
                                    </span>
                                @endif
                                <flux:icon.chevron-down variant="micro" class="ms-auto shrink-0 text-zinc-400 transition" x-bind:class="open === {{ $p->id }} && 'rotate-180'" />
                            </div>
                        </td>
                        @foreach (array_keys($statCols) as $col)
                            <td class="px-2 py-1.5 text-center tabular-nums {{ $col === 'experience' ? 'font-semibold' : '' }} {{ $club['season'][$col] ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $col === 'experience' ? \App\Support\ClubStats::experience($club['season'][$col]) : $fmt($club['season'][$col]) }}</td>
                        @endforeach
                        <td class="px-2 py-1.5 text-center text-xs tabular-nums">
                            @include('partials.card-counts', ['yellow' => $club['season']['yellow'], 'red' => $club['season']['red']])
                        </td>
                    </tr>

                    {{-- Suma wszystkich sezonów --}}
                    <tr x-show="open === {{ $p->id }}" x-cloak class="bg-zinc-50 text-xs font-semibold dark:bg-white/5">
                        <td class="px-3 py-1 ps-14 text-zinc-600 dark:text-zinc-300">{{ __('All seasons') }}</td>
                        @foreach (array_keys($statCols) as $col)
                            <td class="px-2 py-1 text-center tabular-nums {{ $club['career'][$col] ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $col === 'experience' ? \App\Support\ClubStats::experience($club['career'][$col]) : $fmt($club['career'][$col]) }}</td>
                        @endforeach
                        <td class="px-2 py-1 text-center tabular-nums">
                            @include('partials.card-counts', ['yellow' => $club['career']['yellow'], 'red' => $club['career']['red']])
                        </td>
                    </tr>

                    @if (count($club['seasons']) === 0)
                        <tr x-show="open === {{ $p->id }}" x-cloak>
                            <td colspan="{{ count($statCols) + 2 }}" class="bg-zinc-50 px-3 py-2 ps-14 dark:bg-white/5">
                                <flux:text class="text-xs">{{ __('Not fielded yet.') }}</flux:text>
                            </td>
                        </tr>
                    @endif

                    {{-- Sezony od ostatniego: suma, klik rozwija mecze od pierwszej kolejki do ostatniej --}}
                    @foreach ($club['seasons'] as $seasonBlock)
                        @php
                            $seasonKey = $p->id . '-' . $loop->index;
                        @endphp
                        <tr x-show="open === {{ $p->id }}" x-cloak class="cursor-pointer bg-zinc-50 text-xs hover:bg-zinc-100 dark:bg-white/5 dark:hover:bg-white/10"
                            x-on:click="season = season === '{{ $seasonKey }}' ? null : '{{ $seasonKey }}'">
                            <td class="px-3 py-1 ps-14 text-zinc-600 dark:text-zinc-300">
                                <span class="flex items-center gap-1">
                                    <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition" x-bind:class="season === '{{ $seasonKey }}' && 'rotate-90'" />
                                    {{ $seasonBlock['title'] }}
                                    @if ($seasonBlock['current'])
                                        <span class="text-zinc-400">({{ __('this season') }})</span>
                                    @endif
                                </span>
                            </td>
                            @foreach (array_keys($statCols) as $col)
                                <td class="px-2 py-1 text-center tabular-nums {{ $seasonBlock['sums'][$col] ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $col === 'experience' ? \App\Support\ClubStats::experience($seasonBlock['sums'][$col]) : $fmt($seasonBlock['sums'][$col]) }}</td>
                            @endforeach
                            <td class="px-2 py-1 text-center tabular-nums">
                                @include('partials.card-counts', ['yellow' => $seasonBlock['sums']['yellow'], 'red' => $seasonBlock['sums']['red']])
                            </td>
                        </tr>
                        <tr x-show="open === {{ $p->id }} && season === '{{ $seasonKey }}'" x-cloak>
                            <td colspan="{{ count($statCols) + 2 }}" class="bg-white px-3 py-2 ps-14 dark:bg-zinc-900">
                                <ul class="space-y-1 text-xs">
                                    @foreach ($seasonBlock['matches'] as $m)
                                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span class="w-20 shrink-0 whitespace-nowrap font-semibold">{{ __('Matchday :number', ['number' => $m['matchday']]) }}</span>
                                            <span class="text-zinc-500">{{ $m['fixture'] }}</span>
                                            <span class="rounded bg-lech-100 px-1.5 py-0.5 text-[10px] font-semibold text-lech-800 dark:bg-lech-900/60 dark:text-lech-100">{{ $m['set'] }}@if ($m['times'] > 1) ×{{ $m['times'] }}@endif</span>
                                            @if (! $m['counted'])
                                                <span class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ __('Forfeited (rest)') }}</span>
                                            @elseif ($m['play_time'] <= 0)
                                                <span class="text-zinc-500">{{ __('Did not play') }}</span>
                                                @if ($m['experience'] > 0)<span class="text-zinc-500">{{ __('Exp.') }} {{ \App\Support\ClubStats::experience($m['experience']) }}</span>@endif
                                            @else
                                                <span class="tabular-nums" title="{{ __('Playing time in halves') }}">{{ __('Time') }} {{ $fmt($m['play_time']) }}</span>
                                                @if ($m['goals'])<span>⚽ {{ $m['goals'] }}</span>@endif
                                                @if ($m['assists'])<span>{{ __('Assist') }} {{ $m['assists'] }}</span>@endif
                                                @if ($m['own_goals'])<span class="text-red-600">{{ __('Own goal') }} {{ $m['own_goals'] }}</span>@endif
                                                @if ($m['clean_sheet'] === true)<span class="text-green-600">{{ __('Clean sheet') }}</span>@endif
                                                @include('partials.card-counts', ['yellow' => $m['yellow'], 'red' => (int) $m['red']])
                                                <span class="text-zinc-500">{{ __('Exp.') }} {{ \App\Support\ClubStats::experience($m['experience']) }}</span>
                                                @if ($m['rest_hours'])<span class="text-zinc-500">{{ __('Rest: :count h', ['count' => $m['rest_hours']]) }}</span>@endif
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
    <flux:text class="text-xs">{{ __('Each club has its own Lech players: the numbers come only from matches that took place in matchdays in which this club fielded the player. Click a player to see all seasons, then a season to see its matches.') }}</flux:text>
</div>
