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
            <div class="lech-banner -mx-6 -mt-6 rounded-t-xl px-6 py-5">
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

