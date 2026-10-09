{{--
    Pojedynek dwóch zespołów #LechTYPER (okno meczu na stronie wyników): rozgrywki i runda, tablica wyniku,
    mecz Lecha z tej kolejki, rozliczenie obu stron i informacja o widoczności na dole.
    Parametry: $card (Rivals::fixture + round + matchday), $competitionName, $roundLabel, $isCup.
    Wzór do wyświetlania każdego pojedynku między zespołami.
--}}
@php
    $phase = $card['phase'];
    [$home, $away] = $card['sides'];
    $phaseBadge = match ($phase) {
        \App\Support\Rivals::OPEN => ['green', __('Tipping open')],
        \App\Support\Rivals::CLOSED => ['amber', __('Tipping closed')],
        default => ['blue', __('Settled')],
    };
    $phaseInfo = match ($phase) {
        \App\Support\Rivals::OPEN => __('Everyone sees who has tipped. Premium also sees the outcome and the risk in the bonus questions.'),
        \App\Support\Rivals::CLOSED => __('Everyone sees the risk in the bonus questions. Premium also sees the exact tip.'),
        default => __('Full settlement: tip, points for the tip and bonuses.'),
    };
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <flux:heading size="lg">{{ $competitionName }}</flux:heading>
            <flux:text size="sm">
                {{ $roundLabel }}
            </flux:text>
        </div>
        <flux:badge :color="$phaseBadge[0]">{{ $phaseBadge[1] }}</flux:badge>
    </div>

    {{-- Tablica wyników --}}
    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 lech-banner rounded-xl px-4 py-5">
        @foreach ([$home, null, $away] as $side)
            @if ($side === null)
                <div class="text-center text-3xl font-bold tabular-nums">{{ $card['score'] ?? '–' }}</div>
            @else
                <div class="min-w-0 {{ $loop->first ? 'text-right' : '' }}">
                    @if ($side['season_team_id'])
                        <button type="button" wire:click="showTeam({{ $side['season_team_id'] }})" class="max-w-full truncate font-semibold hover:underline">{{ $side['name'] }}</button>
                    @else
                        <div class="truncate font-semibold italic">{{ $side['name'] }}</div>
                    @endif
                    <div class="truncate text-xs text-white/70">
                        {{ $side['owner'] ?? ($side['bot'] ? __('Bot') : '') }}
                        @if ($side['winner'] && $isCup)
                            &middot; {{ __('goes through') }}
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Mecz Lecha, z którego liczy się ten pojedynek: wynik albo zapowiedź (kiedy i w jakich rozgrywkach) --}}
    @if ($lech = $card['matchday'] ?? null)
        @php
            $lechPlayed = $lech->lech_goals !== null && $lech->opponent_goals !== null;
            $lechSides = $lech->is_home
                ? [['Lech Poznań', $lech->lech_goals, true], [$lech->opponent, $lech->opponent_goals, false]]
                : [[$lech->opponent, $lech->opponent_goals, false], ['Lech Poznań', $lech->lech_goals, true]];
        @endphp
        <div class="rounded-xl border border-lech-200 bg-lech-50/60 px-4 py-3 dark:border-lech-800 dark:bg-lech-950/40">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500">
                <span class="font-semibold uppercase tracking-wide text-lech-700 dark:text-lech-300">{{ __('Lech match') }} &middot; {{ __('Matchday :number', ['number' => $lech->number]) }}</span>
                @if ($lech->competitionLabel())
                    <flux:badge size="sm" color="zinc">{{ $lech->competitionLabel() }}</flux:badge>
                @endif
            </div>
            @if (filled($lech->opponent))
                <div class="mt-2 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                    @foreach ($lechSides as $i => [$lechName, $lechGoals, $isLech])
                        @if ($i === 1)
                            <div class="rounded-lg bg-white px-3 py-1 text-center text-2xl font-black tabular-nums text-lech-900 shadow-xs dark:bg-zinc-900 dark:text-white">
                                {{ $lechPlayed ? $lechSides[0][1] . ':' . $lechSides[1][1] : '–:–' }}
                            </div>
                        @endif
                        <div class="truncate {{ $i === 0 ? 'text-right' : '' }} {{ $isLech ? 'font-bold text-lech-800 dark:text-lech-200' : 'font-semibold' }}">{{ $lechName }}</div>
                    @endforeach
                </div>
            @else
                <div class="mt-2 text-sm text-zinc-500">{{ __('Opponent not set yet.') }}</div>
            @endif
            @if ($lech->kickoff_at)
                <div class="mt-2 text-center text-xs text-zinc-500">
                    {{ $lechPlayed ? __('Played on :date', ['date' => $lech->kickoff_at->translatedFormat('l, j F Y, H:i')]) : __('Kick-off: :date', ['date' => $lech->kickoff_at->translatedFormat('l, j F Y, H:i')]) }}
                </div>
            @endif
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([[$home, $away], [$away, $home]] as [$side, $other])
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="side-{{ $loop->index }}">
                <div class="truncate font-semibold">{{ $side['name'] }}</div>

                @if ($side['virtual'])
                    <flux:text size="sm">{{ __('Scores as many as Lech in the real match, minus the player\'s defence.') }}</flux:text>
                @elseif ($side['bot'] && !$side['score'])
                    <flux:text size="sm">{{ __('Bot: random tip at the kick-off.') }}</flux:text>
                @elseif ($side['score'])
                    @php
                        $score = $side['score'];
                        $rivalDefense = $other['score']?->defense_bonus ?? 0;
                        $hits = [[__('Outcome'), $score->outcome_hit], [__('Goal difference'), $score->diff_hit], [__('Exact score'), $score->exact_hit]];
                    @endphp
                    @if (!$score->has_tip)
                        <flux:badge color="zinc" size="sm">{{ __('No tip') }}</flux:badge>
                    @endif
                    <dl class="space-y-1.5">
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Tip') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $side['tip'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Points for the tip') }}</dt>
                            <dd class="tabular-nums">
                                <span class="font-semibold">{{ $score->tip_points }}</span>/3
                            </dd>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($hits as [$hitLabel, $hit])
                                <span class="{{ $hit ? 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700/50' }} rounded px-1.5 py-0.5 text-xs">
                                    {{ $hit ? '✓' : '✗' }} {{ $hitLabel }}
                                </span>
                            @endforeach
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Offensive bonus') }}</dt>
                            <dd class="tabular-nums">
                                +{{ $score->offense_bonus }}
                                @if ($score->offense_zeroed)
                                    <span class="text-xs text-red-600">({{ __('bonus zeroed') }})</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">{{ __('Defensive bonus') }}</dt>
                            <dd class="tabular-nums">
                                {{ $score->defense_bonus }}
                                @if ($score->defense_zeroed)
                                    <span class="text-xs text-red-600">({{ __('bonus zeroed') }})</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2 border-t border-zinc-100 pt-1.5 dark:border-zinc-700">
                            <dt class="text-zinc-500">{{ __('Attack (tip + offensive bonus)') }}</dt>
                            <dd class="tabular-nums">{{ $score->offense }}</dd>
                        </div>
                        @if (!$other['virtual'])
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500">{{ __('Rival\'s defence') }}</dt>
                                <dd class="tabular-nums">−{{ $rivalDefense }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-2 font-semibold">
                            <dt>{{ __('Goals') }}</dt>
                            <dd class="tabular-nums">{{ $side['goals'] ?? '—' }}</dd>
                        </div>
                        @if ($side['tipped_at'] && $isCup)
                            <div class="text-xs text-zinc-500">{{ __('Tip saved: :time', ['time' => $side['tipped_at']->translatedFormat('j F, H:i:s.v')]) }}</div>
                        @endif
                    </dl>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($side['tip'])
                            <flux:badge>{{ __('Tip: :score', ['score' => $side['tip']]) }}</flux:badge>
                        @elseif ($side['outcome'])
                            <flux:badge>{{ $side['outcome'] }}</flux:badge>
                        @elseif ($side['tipped'])
                            <flux:badge color="green" size="sm">{{ __('Tipped') }}</flux:badge>
                        @elseif ($side['tipped'] === false)
                            <flux:badge color="zinc" size="sm">{{ __('No tip') }}</flux:badge>
                        @endif
                    </div>
                    @if ($side['offense'] !== null)
                        <div class="flex flex-col gap-1">
                            <x-risk-stars :count="$side['offense']" :label="__('Offensive')" />
                            <x-risk-stars :count="$side['defense']" :label="__('Defensive')" />
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if (!$card['premium'] && $phase !== \App\Support\Rivals::PLAYED)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-purple-50 px-3 py-2 text-sm dark:bg-purple-500/10">
            <span class="flex items-center gap-2"><x-premium-badge /> {{ __('Premium shows the rival\'s tip and risk before the results.') }}</span>
            <flux:link :href="route('support')" wire:navigate>{{ __('From 5 zł a week') }}</flux:link>
        </div>
    @endif

    {{-- Szczegóły widoczności rozliczenia: na samym dole, kursywą --}}
    <p class="text-xs italic text-zinc-500">{{ $phaseInfo }}</p>
</div>
