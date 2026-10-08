{{-- Okna na stronie wyników: zespół (statystyki sezonu, część premium) i mecz (szczegóły zależne od etapu kolejki). --}}
@php
    $fmt = fn($v, $d = 1) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
    $formClasses = ['W' => 'bg-green-600', 'D' => 'bg-zinc-400', 'L' => 'bg-red-600'];
@endphp

<flux:modal name="team-details" class="w-full max-w-xl">
    @if ($card = $this->teamCard)
        @php
            $team = $card['team'];
            $stats = $card['stats'];
        @endphp
        <div class="space-y-5">
            {{-- Nagłówek z gradientem w barwach Lecha --}}
            <div class="-mx-6 -mt-6 rounded-t-xl bg-linear-to-br from-lech-700 to-lech-950 px-6 py-5 text-white">
                <div class="flex items-start gap-3">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white/15 text-lg font-bold">
                        {{ mb_strtoupper(mb_substr($team->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="truncate text-lg font-semibold">{{ $team->name }}</div>
                        <div class="flex flex-wrap items-center gap-2 text-sm text-white/75">
                            @if ($team->user)
                                <span>{{ $team->user->name }}</span>
                                @if ($card['owner_premium'])
                                    <x-premium-badge />
                                @endif
                            @else
                                <span>{{ __('Bot') }}</span>
                            @endif
                        </div>
                        @if ($card['place'])
                            <div class="text-sm text-white/90">
                                {{ __(':place. place of :count', ['place' => $card['place'], 'count' => $card['count']]) }}
                                &middot; {{ $this->competition?->name }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Podstawowe statystyki (dla wszystkich) --}}
            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Season statistics') }}</flux:heading>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @php
                        $basic = [
                            [__('Matches'), $stats['won'] . '–' . $stats['drawn'] . '–' . $stats['lost'], __('W–D–L')],
                            [__('Goals'), $stats['for'] . ':' . $stats['against'], __('Record: :goals in a match', ['goals' => $stats['record_goals']])],
                            [__('Unbeaten run'), $stats['unbeaten'], __('Best: :count', ['count' => $stats['unbeaten_best']])],
                        ];
                        if ($team->user) {
                            $basic[] = [__('Tips'), $stats['tips'] . '/' . $stats['scored'], __('Matchdays with a tip')];
                            $basic[] = [__('Accuracy'), $stats['accuracy'] !== null ? $stats['accuracy'] . '%' : '—', __('Correct outcomes')];
                            $basic[] = [__('Exact tips'), $stats['exact'], __('Average :points pts for a tip', ['points' => $fmt($stats['avg_tip'], 2)])];
                        }
                    @endphp
                    @foreach ($basic as [$label, $value, $hint])
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="text-xs text-zinc-500">{{ $label }}</div>
                            <div class="text-lg font-semibold tabular-nums">{{ $value }}</div>
                            <div class="text-xs text-zinc-500">{{ $hint }}</div>
                        </div>
                    @endforeach
                </div>

                @if (count($stats['form']) > 0)
                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                        {{ __('Form') }}:
                        @foreach ($stats['form'] as $outcome)
                            <span class="{{ $formClasses[$outcome] }} flex size-5 items-center justify-center rounded text-[10px] font-bold text-white">{{ __($outcome) }}</span>
                        @endforeach
                    </div>
                @endif

                @if (!$team->user)
                    <flux:text size="sm">{{ __('Bot: random tips and no bonus questions.') }}</flux:text>
                @endif
            </div>

            {{-- Statystyki premium --}}
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <flux:heading size="sm">{{ __('Premium statistics') }}</flux:heading>
                    <x-premium-badge />
                </div>

                @if ($card['premium'])
                    @php
                        $extra = [];
                        if ($team->user) {
                            $extra[] = [__('Offensive questions'), $stats['q_offense'] !== null ? $stats['q_offense'] . '%' : '—', __('Correct answers')];
                            $extra[] = [__('Defensive questions'), $stats['q_defense'] !== null ? $stats['q_defense'] . '%' : '—', __('Correct answers')];
                            $extra[] = [__('Optimist or pessimist'), $stats['optimism'] === null ? '—' : ($stats['optimism'] > 0 ? '+' : '') . $fmt($stats['optimism'], 2), __('Lech goals: tips compared with the real matches')];
                            $extra[] = [__('Tipping ahead'), $stats['lead_hours'] === null ? '—' : $fmt($stats['lead_hours'], 1) . ' h', __('Average time before kick-off')];
                            $extra[] = [__('Best matchday'), $stats['best_matchday'] ? __(':points pts', ['points' => $stats['best_matchday']['points']]) : '—', $stats['best_matchday'] ? __('Matchday :number', ['number' => $stats['best_matchday']['number']]) : ''];
                            $extra[] = [__('Favourite tip'), $card['favourite']['score'] ?? '—', $card['favourite'] ? __(':count of :total tips', ['count' => $card['favourite']['count'], 'total' => $card['favourite']['total']]) : ''];
                        }
                        $extra[] = [__('Winning run'), $stats['wins'], __('Best: :count', ['count' => $stats['wins_best']])];
                    @endphp
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($extra as [$label, $value, $hint])
                            <div class="rounded-lg border border-purple-200 bg-purple-50/60 p-3 dark:border-purple-500/30 dark:bg-purple-500/10">
                                <div class="text-xs text-zinc-500">{{ $label }}</div>
                                <div class="text-lg font-semibold tabular-nums">{{ $value }}</div>
                                <div class="text-xs text-zinc-500">{{ $hint }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if ($card['h2h'])
                        @php
                            $h2h = $card['h2h'];
                        @endphp
                        <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <div class="font-medium">
                                @if (count($h2h['meetings']) === 0)
                                    {{ __('You have not met this team yet.') }}
                                @else
                                    {{ __('Head to head: :won W, :drawn D, :lost L', ['won' => $h2h['won'], 'drawn' => $h2h['drawn'], 'lost' => $h2h['lost']]) }}
                                @endif
                            </div>
                            @foreach (array_slice($h2h['meetings'], 0, 5) as $meeting)
                                <div class="mt-1 flex items-center gap-2 text-xs">
                                    <span class="{{ $formClasses[$meeting['outcome']] }} flex size-5 items-center justify-center rounded text-[10px] font-bold text-white">{{ __($meeting['outcome']) }}</span>
                                    <span class="w-10 font-semibold tabular-nums">{{ $meeting['score'] }}</span>
                                    <span class="text-zinc-500">{{ $meeting['season'] }} &middot; {{ $meeting['competition'] }} &middot; {{ __('Matchday :number', ['number' => $meeting['round']]) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="relative overflow-hidden rounded-lg border border-purple-200 p-4 dark:border-purple-500/30">
                        <div class="grid select-none grid-cols-3 gap-2 blur-sm" aria-hidden="true">
                            @foreach (range(1, 6) as $i)
                                <div class="h-14 rounded bg-purple-100 dark:bg-purple-500/20"></div>
                            @endforeach
                        </div>
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-white/60 p-4 text-center dark:bg-zinc-900/60">
                            <flux:text size="sm">{{ __('Question accuracy, favourite tip, head to head and more with premium.') }}</flux:text>
                            <flux:button size="sm" variant="primary" :href="route('support')" wire:navigate>{{ __('From 5 zł a week') }}</flux:button>
                        </div>
                    </div>
                @endif
            </div>

            @if ($team->user)
                <div class="flex justify-end">
                    <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('team.show', $team->user)" wire:navigate>
                        {{ __('Team profile') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</flux:modal>

<flux:modal name="fixture-details" class="w-full max-w-2xl">
    @if ($card = $this->fixtureCard)
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
                    <flux:heading size="lg">{{ $this->competition?->name }}</flux:heading>
                    <flux:text size="sm">
                        {{ $this->isCup ? \App\Support\CupBracket::roundName($card['round']) : __('Matchday :number', ['number' => $card['round']]) }}
                    </flux:text>
                </div>
                <flux:badge :color="$phaseBadge[0]">{{ $phaseBadge[1] }}</flux:badge>
            </div>

            {{-- Tablica wyników --}}
            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 rounded-xl bg-linear-to-br from-lech-700 to-lech-950 px-4 py-5 text-white">
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
                                @if ($side['winner'] && $this->isCup)
                                    &middot; {{ __('goes through') }}
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <flux:text size="sm">{{ $phaseInfo }}</flux:text>

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
                                @if ($side['tipped_at'] && $this->isCup)
                                    <div class="text-xs text-zinc-500">{{ __('Tip saved: :time', ['time' => $side['tipped_at']->translatedFormat('j F, H:i:s')]) }}</div>
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
        </div>
    @endif
</flux:modal>
