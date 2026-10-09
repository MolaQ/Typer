{{--
    Pojedynek dwóch zespołów #LechTYPER (uniwersalne okno pages::home.duel-modal, zdarzenie „show-duel”):
    rozgrywki i runda, tablica wyniku, mecz Lecha z tej kolejki, rozliczenie obu stron i informacja o widoczności na dole.
    Parametry: $card (Rivals::fixture + round + matchday), $competitionName, $roundLabel, $isCup.
    Kolory wartości: App\Support\Tone i komponent x-tone-tile (te same progi w całym serwisie).
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
    // Etykiety wierszy rozliczenia: jednolite tło.
    $labelClass = 'rounded-md bg-lech-50 px-2 py-0.5 text-xs font-medium text-lech-800 dark:bg-lech-900/40 dark:text-lech-200';
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <flux:heading size="lg">{{ $competitionName }}</flux:heading>
            <flux:text size="sm">{{ $roundLabel }}</flux:text>
        </div>
        <flux:badge :color="$phaseBadge[0]">{{ $phaseBadge[1] }}</flux:badge>
    </div>

    {{-- Tablica wyników: nazwy zespołów to przyciski do profili --}}
    <div class="lech-banner grid grid-cols-[1fr_auto_1fr] items-center gap-3 rounded-xl px-4 py-5">
        @foreach ([$home, null, $away] as $side)
            @if ($side === null)
                <div class="text-center text-3xl font-bold tabular-nums">{{ $card['score'] ?? '–' }}</div>
            @else
                <div class="min-w-0 space-y-1 {{ $loop->first ? 'text-right' : '' }}">
                    @if ($side['profile'] ?? null)
                        <a href="{{ $side['profile'] }}" wire:navigate
                            class="inline-flex max-w-full truncate rounded-md bg-white/15 px-2.5 py-1 font-semibold text-white ring-1 ring-white/25 transition hover:bg-white/25">{{ $side['name'] }}</a>
                    @else
                        <div class="truncate px-2.5 py-1 font-semibold {{ $side['virtual'] ? 'italic' : '' }}">{{ $side['name'] }}</div>
                    @endif
                    <div class="truncate px-2.5 text-xs text-white/70">
                        {{ $side['owner'] ?? ($side['bot'] ? __('Bot') : '') }}
                        @if ($side['winner'] && $isCup)
                            &middot; {{ __('goes through') }}
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Mecz Lecha z tej kolejki: Lech zawsze po lewej, rywal po prawej, dopisek DOM/WYJAZD --}}
    @if ($lech = $card['matchday'] ?? null)
        @php
            $lechPlayed = $lech->lech_goals !== null && $lech->opponent_goals !== null;
        @endphp
        <div class="rounded-xl border border-lech-200 bg-lech-50/60 px-4 py-3 dark:border-lech-800 dark:bg-lech-950/40">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500">
                <span class="font-semibold uppercase tracking-wide text-lech-700 dark:text-lech-300">{{ __('Lech match') }} &middot; {{ __('Matchday :number', ['number' => $lech->number]) }}</span>
                <span class="flex items-center gap-1.5">
                    @if ($lech->competitionLabel())
                        <flux:badge size="sm" color="zinc">{{ $lech->competitionLabel() }}</flux:badge>
                    @endif
                    @if (filled($lech->opponent))
                        <flux:badge size="sm" :color="$lech->is_home ? 'blue' : 'zinc'">{{ $lech->is_home ? __('HOME') : __('AWAY') }}</flux:badge>
                    @endif
                </span>
            </div>
            @if (filled($lech->opponent))
                <div class="mt-2 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                    <div class="truncate text-right font-bold text-lech-800 dark:text-lech-200">Lech Poznań</div>
                    <div class="rounded-lg bg-white px-3 py-1 text-center text-2xl font-black tabular-nums text-lech-900 shadow-xs dark:bg-zinc-900 dark:text-white">
                        {{ $lechPlayed ? $lech->lech_goals . ':' . $lech->opponent_goals : '–:–' }}
                    </div>
                    <div class="truncate font-semibold">{{ $lech->opponent }}</div>
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

    {{-- Rozliczenie obu stron: lustrzany układ, etykiety przy zewnętrznych krawędziach, kafelki z wartościami w środku --}}
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([[$home, $away], [$away, $home]] as [$side, $other])
            @php
                $mirror = $loop->first ? '' : 'flex-row-reverse';
                $inner = $loop->first ? 'justify-end' : 'justify-start';
            @endphp
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="side-{{ $loop->index }}">
                <div class="flex {{ $inner }}">
                    @if ($side['profile'] ?? null)
                        <a href="{{ $side['profile'] }}" wire:navigate
                            class="max-w-full truncate rounded-md bg-lech-700 px-2.5 py-1 font-semibold text-white transition hover:bg-lech-800 dark:bg-lech-600 dark:hover:bg-lech-500">{{ $side['name'] }}</a>
                    @else
                        <span class="max-w-full truncate rounded-md bg-zinc-200 px-2.5 py-1 font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200 {{ $side['virtual'] ? 'italic' : '' }}">{{ $side['name'] }}</span>
                    @endif
                </div>

                @if ($side['virtual'])
                    <flux:text size="sm" class="{{ $loop->first ? 'text-right' : '' }}">{{ __('Scores as many as Lech in the real match, minus the player\'s defence.') }}</flux:text>
                @elseif ($side['bot'] && !$side['score'])
                    <flux:text size="sm" class="{{ $loop->first ? 'text-right' : '' }}">{{ __('Bot: random tip at the kick-off.') }}</flux:text>
                @elseif ($side['score'])
                    @php
                        $score = $side['score'];
                        $rivalDefense = $other['score']?->defense_bonus ?? 0;
                        $hits = [[__('Outcome'), $score->outcome_hit], [__('Goal difference'), $score->diff_hit], [__('Exact score'), $score->exact_hit]];
                    @endphp
                    <dl class="space-y-1.5">
                        <div class="{{ $mirror }} flex items-center justify-between gap-2">
                            <dt class="{{ $labelClass }}">{{ __('Tip') }}</dt>
                            <dd>
                                @if ($score->has_tip)
                                    <x-tone-tile tone="neutral">{{ $side['tip'] ?? '—' }}</x-tone-tile>
                                @else
                                    <x-tone-tile tone="red" class="text-xs font-semibold">{{ __('No tip') }}</x-tone-tile>
                                @endif
                            </dd>
                        </div>
                        <div class="{{ $mirror }} flex items-center justify-between gap-2">
                            <dt class="{{ $labelClass }}">{{ __('Points for the tip') }}</dt>
                            <dd><x-tone-tile :tone="\App\Support\Tone::tipPoints($score->tip_points)">{{ $score->tip_points }}/3</x-tone-tile></dd>
                        </div>
                        {{-- Za co były punkty w typie: niebieskie trafienia, szare pudła --}}
                        <div class="flex flex-wrap gap-1 {{ $inner }}">
                            @foreach ($hits as [$hitLabel, $hit])
                                <x-tone-tile :tone="$hit ? 'blue' : 'zinc'" class="text-xs font-semibold">{{ $hit ? '✓' : '✗' }} {{ $hitLabel }}</x-tone-tile>
                            @endforeach
                        </div>
                        <div class="{{ $mirror }} flex items-center justify-between gap-2">
                            <dt class="{{ $labelClass }}">{{ __('Offensive bonus') }}</dt>
                            <dd class="{{ $mirror }} flex items-center gap-1.5">
                                <x-tone-tile :tone="\App\Support\Tone::bonus($score->offense_bonus)">+{{ $score->offense_bonus }}</x-tone-tile>
                                @if ($score->offense_zeroed)
                                    <span class="text-xs text-red-600">{{ __('bonus zeroed') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="{{ $mirror }} flex items-center justify-between gap-2">
                            <dt class="{{ $labelClass }}">{{ __('Defensive bonus') }}</dt>
                            <dd class="{{ $mirror }} flex items-center gap-1.5">
                                <x-tone-tile :tone="\App\Support\Tone::bonus($score->defense_bonus)">{{ $score->defense_bonus }}</x-tone-tile>
                                @if ($score->defense_zeroed)
                                    <span class="text-xs text-red-600">{{ __('bonus zeroed') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="{{ $mirror }} flex items-center justify-between gap-2 border-t border-zinc-100 pt-1.5 dark:border-zinc-700">
                            <dt class="{{ $labelClass }}">{{ __('Attack (tip + offensive bonus)') }}</dt>
                            <dd><x-tone-tile :tone="\App\Support\Tone::attack($score->offense)">{{ $score->offense }}</x-tone-tile></dd>
                        </div>
                        @if (!$other['virtual'])
                            <div class="{{ $mirror }} flex items-center justify-between gap-2">
                                <dt class="{{ $labelClass }}">{{ __('Rival\'s defence') }}</dt>
                                <dd><x-tone-tile :tone="\App\Support\Tone::rivalDefense($rivalDefense)">−{{ $rivalDefense }}</x-tone-tile></dd>
                            </div>
                        @endif
                        <div class="{{ $mirror }} flex items-center justify-between gap-2">
                            <dt class="{{ $labelClass }} font-bold">{{ __('Goals') }}</dt>
                            <dd><x-tone-tile :tone="\App\Support\Tone::goals($side['goals'])" class="text-base">{{ $side['goals'] ?? '—' }}</x-tone-tile></dd>
                        </div>
                        @if ($side['tipped_at'] && $isCup)
                            <div class="{{ $mirror }} flex items-center justify-between gap-2 text-xs">
                                <dt class="{{ $labelClass }}">{{ __('Tip saved') }}</dt>
                                <dd class="tabular-nums text-zinc-500">{{ $side['tipped_at']->translatedFormat('j M, H:i:s.v') }}</dd>
                            </div>
                        @endif
                    </dl>
                @else
                    <div class="flex flex-wrap items-center gap-2 {{ $inner }}">
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
                        <div class="flex flex-col gap-1 {{ $loop->first ? 'items-end' : 'items-start' }}">
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
