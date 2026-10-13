{{--
    Zawodnicy klubu (Support\ClubStats::players): tabela dorobku w sezonie albo w karierze, klik w wiersz pokazuje mecze,
    w których klub wystawił zawodnika. Używane w zakładce „Mój klub” na /tips i na profilu bota.
    Zmienne: $clubPlayers, opcjonalnie $clubTitle.
--}}
@php
    $statCols = [
        'fielded' => [__('Fielded'), __('Matchdays in the line-up')],
        'minutes' => [__('Min'), __('Minutes')],
        'goals' => ['G', __('Goals')],
        'assists' => ['A', __('Assists')],
        'clean_sheets' => [__('CS'), __('Clean sheets')],
        'attack' => [__('Att.'), __('Attack points')],
        'defense' => [__('Def.'), __('Defence points')],
        'experience' => [__('Exp.'), __('Experience')],
    ];
@endphp
<div class="space-y-3" x-data="{ scope: 'season', open: null }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="lg">{{ $clubTitle ?? __('Club players') }}</flux:heading>
        <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-xs font-medium dark:bg-zinc-800">
            @foreach (['season' => __('Season'), 'career' => __('Career')] as $scopeKey => $scopeLabel)
                <button type="button" x-on:click="scope = '{{ $scopeKey }}'"
                    x-bind:class="scope === '{{ $scopeKey }}' ? 'bg-white text-lech-800 shadow-sm dark:bg-zinc-900 dark:text-lech-300' : 'text-zinc-500'"
                    class="rounded-md px-3 py-1 transition">{{ $scopeLabel }}</button>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">{{ __('Player') }}</th>
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
                <tbody class="border-t border-zinc-100 dark:border-white/5" wire:key="club-player-{{ $p->id }}">
                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5" x-on:click="open = open === {{ $p->id }} ? null : {{ $p->id }}">
                        <td class="px-3 py-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-5 shrink-0 text-right text-xs tabular-nums text-zinc-400">{{ $p->number }}</span>
                                <span class="w-4 shrink-0 text-[10px] font-semibold uppercase text-zinc-500">{{ $p->position->short() }}</span>
                                <span class="truncate font-medium">{{ $p->name }}</span>
                                @if ($club['lock'] > 0)
                                    <span class="{{ \App\Support\Squad::lockColor($club['lock']) }} shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold">
                                        {{ __('free from :number', ['number' => $club['next'] + $club['lock']]) }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        @foreach (['season', 'career'] as $scopeKey)
                            @foreach (array_keys($statCols) as $col)
                                <td x-show="scope === '{{ $scopeKey }}'" @if ($scopeKey === 'career') x-cloak @endif
                                    class="px-2 py-1.5 text-center tabular-nums {{ $col === 'experience' ? 'font-semibold' : '' }} {{ $club[$scopeKey][$col] ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $club[$scopeKey][$col] }}</td>
                            @endforeach
                            <td x-show="scope === '{{ $scopeKey }}'" @if ($scopeKey === 'career') x-cloak @endif class="px-2 py-1.5 text-center text-xs tabular-nums">
                                @if ($club[$scopeKey]['yellow'])
                                    <span class="inline-block h-3 w-2 rounded-[2px] bg-yellow-400 align-middle"></span> {{ $club[$scopeKey]['yellow'] }}
                                @endif
                                @if ($club[$scopeKey]['red'])
                                    <span class="ms-1 inline-block h-3 w-2 rounded-[2px] bg-red-500 align-middle"></span> {{ $club[$scopeKey]['red'] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr x-show="open === {{ $p->id }}" x-cloak>
                        <td colspan="{{ count($statCols) + 2 }}" class="bg-zinc-50 px-3 py-2 dark:bg-white/5">
                            @if (count($club['matches']) === 0)
                                <flux:text class="text-xs">{{ __('Not fielded yet.') }}</flux:text>
                            @else
                                <ul class="space-y-1 text-xs">
                                    @foreach ($club['matches'] as $m)
                                        <li x-show="scope === 'career' || {{ $m['this_season'] ? 'true' : 'false' }}" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span class="font-semibold">{{ $m['season'] }}, {{ __('Matchday :number', ['number' => $m['matchday']]) }}</span>
                                            <span class="text-zinc-500">{{ $m['fixture'] }}</span>
                                            <span class="rounded bg-lech-100 px-1.5 py-0.5 text-[10px] font-semibold text-lech-800 dark:bg-lech-900/60 dark:text-lech-100">{{ $m['set'] }}</span>
                                            @if (! $m['counted'])
                                                <span class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ __('Forfeited (rest)') }}</span>
                                            @elseif ($m['minutes'] === 0)
                                                <span class="text-zinc-500">{{ __('Did not play') }}</span>
                                            @else
                                                <span class="tabular-nums">{{ $m['minutes'] }}'</span>
                                                @if ($m['goals'])<span>⚽ {{ $m['goals'] }}</span>@endif
                                                @if ($m['assists'])<span>{{ __('Assist') }} {{ $m['assists'] }}</span>@endif
                                                @if ($m['own_goals'])<span class="text-red-600">{{ __('Own goal') }} {{ $m['own_goals'] }}</span>@endif
                                                @if ($m['clean_sheet'] === true)<span class="text-green-600">{{ __('Clean sheet') }}</span>@endif
                                                @if ($m['yellow'])<span class="inline-block h-3 w-2 rounded-[2px] bg-yellow-400"></span>@endif
                                                @if ($m['red'])<span class="inline-block h-3 w-2 rounded-[2px] bg-red-500"></span>@endif
                                                <span class="text-zinc-500">{{ __('Exp.') }} {{ $m['experience'] }}</span>
                                                @if ($m['lock'])<span class="text-zinc-500">{{ __('Rest: :count', ['count' => $m['lock']]) }}</span>@endif
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                </tbody>
            @endforeach
        </table>
    </div>
    <flux:text class="text-xs">{{ __('Each club has its own Lech players: the numbers come only from matchdays in which this club fielded the player. Click a player to see the matches.') }}</flux:text>
</div>
