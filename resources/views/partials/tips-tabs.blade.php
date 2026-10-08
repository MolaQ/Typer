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
        @forelse ($this->myCompetitions as $row)
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-700" wire:key="comp-{{ $loop->index }}">
                <span class="min-w-0 flex-1 font-medium">{{ $row['name'] }}</span>
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
        $stats = $this->myStats;
        $m = $stats['matches'];
        $tiles = [
            [__('Tips'), $stats['tips'] . ' / ' . $stats['scored'], __('No tip: :count', ['count' => $stats['missing']])],
            [__('Perfect tips (Koziołki)'), $stats['exact'], __('Exact scores')],
            [__('Accuracy'), $stats['accuracy'] !== null ? $stats['accuracy'] . '%' : '—', __('Correct outcomes: :count', ['count' => $stats['outcome']])],
            [__('Goal differences'), $stats['diff'], __('Correct goal differences')],
            [__('Average for the tip'), $stats['avg_tip'] ?? '—', __('Points: :points', ['points' => $stats['tip_points']])],
            [__('Average offensive bonus'), $stats['avg_offense'] ?? '—', __('Per question set')],
            [__('Average defensive bonus'), $stats['avg_defense'] ?? '—', __('Zeroed sets: :count', ['count' => $stats['zeroed']])],
            [__('Outcome streak'), $stats['outcome_streak'], __('Best: :count', ['count' => $stats['outcome_streak_best']])],
            [__('Matches'), $m['won'] . '–' . $m['drawn'] . '–' . $m['lost'], __('Won, drawn, lost')],
            [__('Goals'), $m['for'] . ':' . $m['against'], __('Most in a match: :count', ['count' => $m['record_goals']])],
            [__('Unbeaten run'), $m['unbeaten'], __('Best: :count', ['count' => $m['unbeaten_best']])],
            [__('Winning streak'), $m['wins'], __('Best: :count', ['count' => $m['wins_best']])],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($tiles as [$label, $value, $hint])
            <flux:card class="space-y-1 p-4">
                <flux:text size="sm">{{ $label }}</flux:text>
                <p class="text-2xl font-bold tabular-nums">{{ $value }}</p>
                <flux:text size="sm" class="text-zinc-500">{{ $hint }}</flux:text>
            </flux:card>
        @endforeach
    </div>

    <flux:card class="space-y-2">
        <flux:heading>{{ __('Place among the players') }}</flux:heading>
        @if (!$stats['rank'])
            <flux:text>{{ __('Available after the first settled matchday.') }}</flux:text>
        @else
            <flux:text>
                {{ $stats['rank']['above'] ? __('You are above the median of points for tips.') : __('You are below the median of points for tips.') }}
                {{ __('Median: :points pts.', ['points' => \App\Support\HallOfFame::format($stats['rank']['median'])]) }}
            </flux:text>
            @if ($isPremium)
                <p class="text-2xl font-bold">
                    {{ __(':place. of :count', ['place' => $stats['rank']['place'], 'count' => $stats['rank']['count']]) }}
                </p>
            @else
                <flux:text size="sm">
                    {{ __('Your exact place among the players is a premium feature.') }}
                    <flux:link :href="route('support')" wire:navigate>{{ __('Premium') }}</flux:link>
                </flux:text>
            @endif
        @endif

        @if (count($m['form']) > 0)
            <div class="flex items-center gap-1 pt-2">
                <flux:text size="sm" class="me-2">{{ __('Form') }}:</flux:text>
                @foreach ($m['form'] as $result)
                    @php
                        $formClass = ['W' => 'bg-green-600', 'D' => 'bg-zinc-400', 'L' => 'bg-red-600'][$result];
                        $formLabel = ['W' => __('W'), 'D' => __('D'), 'L' => __('L')][$result];
                    @endphp
                    <span class="{{ $formClass }} flex size-6 items-center justify-center rounded text-xs font-bold text-white">{{ $formLabel }}</span>
                @endforeach
            </div>
        @endif
    </flux:card>
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
