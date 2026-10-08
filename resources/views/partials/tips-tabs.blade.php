{{-- Zakładki strony „Moje typy” poza typowaniem: kolejki, rozgrywki, statystyki, historia (App\Support\PlayerStats). --}}
@php
    $isPremium = \App\Support\Premium::isActive(auth()->user());
@endphp

@if ($tab === 'matchdays')
    <flux:card class="space-y-3">
        <flux:heading>{{ __('My matchdays') }}</flux:heading>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach ($this->myMatchdays as $row)
                @php
                    $matchday = $row['matchday'];
                @endphp
                <div class="flex flex-wrap items-center gap-3 py-2 text-sm" wire:key="md-{{ $matchday->id }}">
                    <span class="w-6 text-right tabular-nums text-zinc-500">{{ $matchday->number }}.</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ filled($matchday->opponent) ? $matchday->fixture : __('not set yet') }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ $matchday->kickoff_at ? $matchday->kickoff_at->translatedFormat('j F, H:i') : '' }}
                            @if ($matchday->status === \App\Enums\MatchdayStatus::Played)
                                &middot; {{ $matchday->lech_goals }}:{{ $matchday->opponent_goals }}
                            @endif
                        </div>
                    </div>

                    @if ($row['tip'])
                        <flux:badge size="sm">{{ $row['tip'] }}{{ $row['default'] ? ' (' . __('default') . ')' : '' }}</flux:badge>
                    @elseif ($row['open'])
                        <flux:badge size="sm" color="amber">{{ __('Waiting for your tip') }}</flux:badge>
                    @elseif ($row['missing'])
                        <flux:badge size="sm" color="red">{{ __('No tip') }}</flux:badge>
                    @endif

                    @if ($row['points'] !== null)
                        <span class="w-16 text-right font-semibold tabular-nums">
                            {{ __(':points pts', ['points' => $row['points']]) }}@if ($row['exact']) ★@endif
                        </span>
                    @endif

                    <flux:button size="xs" variant="ghost" icon="arrow-right" wire:click="openMatchday({{ $matchday->number }})"
                        :aria-label="__('Open')" />
                </div>
            @endforeach
        </div>
    </flux:card>
@elseif ($tab === 'competitions')
    <flux:card class="space-y-3">
        <flux:heading>{{ __('Progress in competitions') }}</flux:heading>
        @php
            $trophyIcons = \App\Support\HallOfFame::iconUrls();
        @endphp
        @forelse ($this->myCompetitions as $row)
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-700" wire:key="comp-{{ $loop->index }}">
                <span class="flex min-w-0 flex-1 items-center gap-2 font-medium">
                    {{-- Trofeum do zdobycia w tych rozgrywkach --}}
                    @if (isset($trophyIcons[$row['trophy']]))
                        <img src="{{ $trophyIcons[$row['trophy']] }}" alt="" class="size-6 object-contain" title="{{ __('Trophy to win') }}">
                    @else
                        <flux:icon.trophy variant="micro" class="text-amber-500" />
                    @endif
                    {{ $row['name'] }}
                </span>
                <span>{{ $row['status'] }}</span>
                @if ($row['points'] !== null)
                    <span class="text-xs text-zinc-500">
                        {{ __(':won W, :drawn D, :lost L', ['won' => $row['won'], 'drawn' => $row['drawn'], 'lost' => $row['lost']]) }}
                    </span>
                    <span class="w-14 text-right font-semibold tabular-nums">{{ __(':points pts', ['points' => $row['points']]) }}</span>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No competitions yet.') }}</flux:text>
        @endforelse
        <flux:link :href="route('results')" wire:navigate class="text-sm">{{ __('Results and tables') }}</flux:link>
    </flux:card>
@elseif ($tab === 'stats')
    @php
        $data = $this->myStats;
        $stats = $data['mine'];
        $avg = $data['average'];
        $fmt = fn($v, $d = 1) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
        $optimismLabel = match (true) {
            $stats['optimism'] === null => __('Optimist or pessimist'),
            $stats['optimism'] > 0.25 => __('Optimist'),
            $stats['optimism'] < -0.25 => __('Pessimist'),
            default => __('Realist'),
        };
        $extras = $this->myExtras;
        $trophyIcons = \App\Support\HallOfFame::iconUrls();
        // [etykieta, wartość, podpis, wartość do porównania, klucz średniej]
        $tiles = [
            [__('Tips'), $stats['tips'] . ' / ' . $stats['scored'], __('No tip: :count', ['count' => $stats['missing']]), $stats['tips_ratio'], 'tips_ratio'],
            [__('Perfect tips (Koziołki)'), $stats['exact'], __('Exact scores'), $stats['exact'], 'exact'],
            [__('Accuracy'), $stats['accuracy'] !== null ? $stats['accuracy'] . '%' : '—', __('Correct outcomes: :count', ['count' => $stats['outcome']]), $stats['accuracy'], 'accuracy'],
            [__('Goal differences'), $stats['diff'], __('Correct goal differences'), $stats['diff'], 'diff'],
            [__('Average for the tip'), $fmt($stats['avg_tip'], 2), __('Points: :points', ['points' => $stats['tip_points']]), $stats['avg_tip'], 'avg_tip'],
            [__('Average offensive bonus'), $fmt($stats['avg_offense'], 2), __('Per question set'), $stats['avg_offense'], 'avg_offense'],
            [__('Average defensive bonus'), $fmt($stats['avg_defense'], 2), __('Zeroed sets: :count', ['count' => $stats['zeroed']]), $stats['avg_defense'], 'avg_defense'],
            [__('Outcome streak'), $stats['outcome_streak'], __('Best: :count', ['count' => $stats['outcome_streak_best']]), $stats['outcome_streak_best'], 'outcome_streak_best'],
            [__('Matches'), $stats['won'] . '–' . $stats['drawn'] . '–' . $stats['lost'], __('Won, drawn, lost'), $stats['win_ratio'], 'win_ratio'],
            [__('Goals'), $stats['for'] . ':' . $stats['against'], __('Most in a match: :count', ['count' => $stats['record_goals']]), $stats['for'], 'for'],
            [__('Unbeaten run'), $stats['unbeaten'], __('Best: :count', ['count' => $stats['unbeaten_best']]), $stats['unbeaten_best'], 'unbeaten_best'],
            [__('Winning streak'), $stats['wins'], __('Best: :count', ['count' => $stats['wins_best']]), $stats['wins_best'], 'wins_best'],
            [__('Offensive questions'), $stats['q_offense'] !== null ? $stats['q_offense'] . '%' : '—', __('Correct answers'), $stats['q_offense'], 'q_offense'],
            [__('Defensive questions'), $stats['q_defense'] !== null ? $stats['q_defense'] . '%' : '—', __('Correct answers'), $stats['q_defense'], 'q_defense'],
            // Kafelki bez porównania ze średnią (tu „więcej” nie znaczy „lepiej”), dlatego bez koloru.
            [$optimismLabel, $stats['optimism'] === null ? '—' : ($stats['optimism'] > 0 ? '+' : '') . $fmt($stats['optimism'], 2), __('Lech goals: your tips compared with the real matches'), null, 'n-optimism'],
            [__('Tipping ahead'), $stats['lead_hours'] === null ? '—' : $fmt($stats['lead_hours'], 1) . ' h', __('Average time before kick-off (players: :hours h)', ['hours' => $fmt($avg['lead_hours'] ?? null, 1)]), null, 'n-lead'],
            [__('Best matchday'), $stats['best_matchday'] ? __(':points pts', ['points' => $stats['best_matchday']['points']]) : '—', $stats['best_matchday'] ? __('Matchday :number, tip and bonuses', ['number' => $stats['best_matchday']['number']]) : __('Tip and bonuses'), null, 'n-best'],
        ];
        $tileClasses = [
            'green' => 'border-green-300 bg-green-50 dark:border-green-500/40 dark:bg-green-500/10',
            'yellow' => 'border-yellow-300 bg-yellow-50 dark:border-yellow-500/40 dark:bg-yellow-500/10',
            'red' => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10',
            'zinc' => 'border-zinc-200 dark:border-zinc-700',
        ];
    @endphp

    <flux:text size="sm">{{ __('Colours compare you with the average of all players: green above, yellow within 15%, red below.') }}</flux:text>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($tiles as [$label, $value, $hint, $compare, $avgKey])
            @php
                $color = $stats['scored'] > 0 ? \App\Support\PlayerStats::color($compare === null ? null : (float) $compare, $avg[$avgKey] ?? null) : 'zinc';
            @endphp
            <div class="{{ $tileClasses[$color] }} space-y-1 rounded-xl border p-4" wire:key="tile-{{ $avgKey }}">
                <flux:text size="sm">{{ $label }}</flux:text>
                <p class="text-2xl font-bold tabular-nums">{{ $value }}</p>
                <flux:text size="sm" class="text-zinc-500">{{ $hint }}</flux:text>
            </div>
        @endforeach
    </div>

    <flux:card class="space-y-2">
        <div class="flex items-center gap-2">
            <flux:heading>{{ __('Place among the players') }}</flux:heading>
            <x-premium-badge />
        </div>
        @if (!$data['rank'])
            <flux:text>{{ __('Available after the first settled matchday.') }}</flux:text>
        @elseif ($isPremium)
            <p class="text-2xl font-bold">
                {{ __(':place. of :count', ['place' => $data['rank']['place'], 'count' => $data['rank']['count']]) }}
            </p>
            <flux:text size="sm">{{ __('By points for tips in this season.') }}</flux:text>
        @else
            <flux:text size="sm">
                {{ __('Your exact place among the players is a premium feature.') }}
                <flux:link :href="route('support')" wire:navigate>{{ __('Premium') }}</flux:link>
            </flux:text>
        @endif

        @if (count($stats['form']) > 0)
            <div class="flex items-center gap-1 pt-2">
                <flux:text size="sm" class="me-2">{{ __('Form') }}:</flux:text>
                @foreach ($stats['form'] as $result)
                    @php
                        $formClass = ['W' => 'bg-green-600', 'D' => 'bg-zinc-400', 'L' => 'bg-red-600'][$result];
                    @endphp
                    <span class="{{ $formClass }} flex size-6 items-center justify-center rounded text-xs font-bold text-white">{{ __($result) }}</span>
                @endforeach
            </div>
        @endif
    </flux:card>
    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Favourite tips (all seasons)') }}</flux:heading>
            @foreach (['mine' => __('Yours'), 'all' => __('All players')] as $key => $label)
                @php
                    $fav = $extras['favourites'][$key];
                @endphp
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span>{{ $label }}</span>
                    @if ($fav)
                        <span class="flex items-center gap-2">
                            <flux:badge>{{ $fav['score'] }}</flux:badge>
                            <span class="text-xs text-zinc-500">{{ __(':count of :total tips', ['count' => $fav['count'], 'total' => $fav['total']]) }}</span>
                        </span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </div>
            @endforeach
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading>{{ __('Best season') }}</flux:heading>
            @if ($extras['best_season'])
                <p class="text-2xl font-bold">{{ $extras['best_season']['season']?->title }}</p>
                <flux:text size="sm">{{ __(':points pts for tips', ['points' => $extras['best_season']['points']]) }}</flux:text>
            @else
                <flux:text>{{ __('Available after the first settled matchday.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    {{-- Statystyki premium: najczęstszy typ w rozgrywkach i najtrudniejsze pytania --}}
    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="space-y-3">
            <div class="flex items-center gap-2">
                <flux:heading>{{ __('Favourite tip in each competition') }}</flux:heading>
                <x-premium-badge />
            </div>
            @if (!$isPremium)
                <flux:text size="sm">
                    {{ __('Premium shows the most popular tip in every competition of the season.') }}
                    <flux:link :href="route('support')" wire:navigate>{{ __('See premium') }}</flux:link>
                </flux:text>
            @else
                @forelse ($extras['competitions'] as $row)
                    <div class="flex items-center gap-2 text-sm" wire:key="cfav-{{ $loop->index }}">
                        @if (isset($trophyIcons[$row['trophy']]))
                            <img src="{{ $trophyIcons[$row['trophy']] }}" alt="" class="size-5 object-contain">
                        @else
                            <flux:icon.trophy variant="micro" class="text-amber-500" />
                        @endif
                        <span class="min-w-0 flex-1 truncate">{{ $row['name'] }}</span>
                        <flux:badge size="sm">{{ $row['score'] }}</flux:badge>
                        <span class="w-24 text-right text-xs text-zinc-500">{{ __(':count of :total tips', ['count' => $row['count'], 'total' => $row['total']]) }}</span>
                    </div>
                @empty
                    <flux:text>{{ __('No tips yet.') }}</flux:text>
                @endforelse
            @endif
        </flux:card>

        <flux:card class="space-y-3">
            <div class="flex items-center gap-2">
                <flux:heading>{{ __('The hardest questions') }}</flux:heading>
                <x-premium-badge />
            </div>
            @if (!$isPremium)
                <flux:text size="sm">
                    {{ __('Premium shows the questions with the fewest correct answers. They go to Liga Legend first.') }}
                    <flux:link :href="route('support')" wire:navigate>{{ __('See premium') }}</flux:link>
                </flux:text>
            @else
                @forelse ($extras['hardest'] as $row)
                    <div class="flex items-start gap-3 text-sm" wire:key="hard-{{ $row['question']->id }}">
                        <span class="min-w-0 flex-1">{{ $row['question']->text }}</span>
                        <span class="shrink-0 font-semibold tabular-nums text-red-600 dark:text-red-400">{{ $fmt($row['rate'], 1) }}%</span>
                    </div>
                @empty
                    <flux:text>{{ __('Not enough answers yet.') }}</flux:text>
                @endforelse
                <flux:text size="sm" class="text-xs text-zinc-500">{{ __('Share of correct answers. These questions go to Liga Legend first.') }}</flux:text>
            @endif
        </flux:card>
    </div>
@elseif ($tab === 'history')
    @php
        $history = $this->myHistory;
        $records = $history['records'];
        $recordTiles = [
            [__('Matches'), $records['played']],
            [__('Won, drawn, lost'), $records['won'] . '–' . $records['drawn'] . '–' . $records['lost']],
            [__('Most goals in a match'), $records['record_goals']],
            [__('Longest unbeaten run'), $records['unbeaten_best']],
            [__('Longest winning streak'), $records['wins_best']],
            [__('Goals'), $records['for'] . ':' . $records['against']],
        ];
    @endphp

    <flux:card class="space-y-3">
        <flux:heading>{{ __('Personal records (all seasons)') }}</flux:heading>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($recordTiles as [$label, $value])
                <div>
                    <flux:text size="sm">{{ $label }}</flux:text>
                    <p class="text-xl font-bold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <flux:heading>{{ __('History in competitions') }}</flux:heading>
        @forelse ($history['competitions'] as $row)
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-700">
                <span class="min-w-0 flex-1 font-medium">{{ $row['label'] }}</span>
                <span class="text-xs text-zinc-500">{{ trans_choice(':count season|:count seasons', $row['seasons'], ['count' => $row['seasons']]) }}</span>
                <span>{{ __('Best: :place.', ['place' => $row['best']]) }}</span>
                @if ($row['titles'] > 0)
                    <flux:badge size="sm" color="amber">{{ __('Titles: :count', ['count' => $row['titles']]) }}</flux:badge>
                @endif
                <span class="w-20 text-right tabular-nums">{{ __(':points pts', ['points' => $row['points']]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('History appears after the first finished season.') }}</flux:text>
        @endforelse
        <flux:link :href="route('team.show', auth()->user())" wire:navigate class="text-sm">{{ __('Trophy cabinet') }}</flux:link>
    </flux:card>
@endif
