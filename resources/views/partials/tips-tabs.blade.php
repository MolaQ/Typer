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
    @php
        $trophyIcons = \App\Support\HallOfFame::iconUrls();
        // Kolor kafelka meczu według punktów za typ w tej kolejce: 3 niebieski, 2 zielony, 1 żółty, 0 czerwony.
        $tipClasses = [
            3 => 'border-blue-300 bg-blue-50 dark:border-blue-500/40 dark:bg-blue-500/10',
            2 => 'border-green-300 bg-green-50 dark:border-green-500/40 dark:bg-green-500/10',
            1 => 'border-yellow-300 bg-yellow-50 dark:border-yellow-500/40 dark:bg-yellow-500/10',
            0 => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10',
        ];
        $outcomeClasses = ['W' => 'bg-green-600', 'D' => 'bg-zinc-400', 'L' => 'bg-red-600'];
    @endphp

    <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-500">
        <span>{{ __('Points for the tip in the matchday') }}:</span>
        @foreach ([3 => 'bg-blue-400', 2 => 'bg-green-400', 1 => 'bg-yellow-400', 0 => 'bg-red-400'] as $points => $dot)
            <span class="flex items-center gap-1"><span class="{{ $dot }} size-2.5 rounded-full"></span>{{ $points }}</span>
        @endforeach
        <span class="flex items-center gap-1"><span class="size-2.5 rounded-full bg-zinc-300"></span>{{ __('no tip or not played') }}</span>
    </div>

    @forelse ($this->myCompetitions as $row)
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="comp-{{ $loop->index }}">
            <div class="lech-bar flex flex-wrap items-center gap-3 px-4 py-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white shadow-xs dark:bg-zinc-800">
                    @if (isset($trophyIcons[$row['trophy']]))
                        <img src="{{ $trophyIcons[$row['trophy']] }}" alt="" class="size-7 object-contain" title="{{ __('Trophy to win') }}">
                    @else
                        <flux:icon.trophy class="text-amber-500" />
                    @endif
                </span>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold">{{ $row['name'] }}</div>
                    <div class="text-xs text-lech-200">{{ $row['status'] }}</div>
                </div>
                @if ($row['points'] !== null)
                    <div class="text-right">
                        <div class="text-xl font-bold tabular-nums text-white">{{ __(':points pts', ['points' => $row['points']]) }}</div>
                        <div class="text-xs text-lech-200">
                            @if (isset($row['legends']))
                                {{ __('Legend points') }}
                            @else
                                {{ __(':won W, :drawn D, :lost L', ['won' => $row['won'], 'drawn' => $row['drawn'], 'lost' => $row['lost']]) }}
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            @if (isset($row['legends']))
                {{-- Liga Legend: miejsce po każdej kolejce, punkty Legend z kolejki i suma z tabeli --}}
                @if (count($row['legends']) > 0)
                    <div class="grid grid-cols-2 gap-2 p-4 sm:grid-cols-3">
                        @foreach ($row['legends'] as $stage)
                            <a href="{{ route('results', ['season_slug' => 'sezon-' . $this->season?->number, 'competition_slug' => 'liga-legend', 'round_slug' => 'kolejka-' . $stage['round']]) }}" wire:navigate
                                class="{{ $stage['tip_points'] !== null ? $tipClasses[$stage['tip_points']] : 'border-zinc-200 dark:border-zinc-700' }} block space-y-1 rounded-xl border p-2.5 text-sm transition hover:shadow-md"
                                wire:key="lg-{{ $stage['round'] }}">
                                <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                                    <span>{{ __('Matchday :number', ['number' => $stage['round']]) }}</span>
                                    @if ($stage['points'] !== null)
                                        <span class="font-semibold">+{{ $stage['points'] }}</span>
                                    @endif
                                </div>
                                <div class="flex items-baseline gap-1">
                                    <span class="text-2xl font-black tabular-nums">{{ $stage['place'] ?? '–' }}.</span>
                                    <span class="text-xs text-zinc-500">{{ __('of :count', ['count' => $stage['count']]) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <span class="{{ $stage['through'] ? 'bg-green-600' : 'bg-red-600' }} rounded px-1.5 py-0.5 text-[10px] font-bold text-white">
                                        {{ $stage['through'] ? ($stage['round'] < \App\Support\LegendsRanking::ROUNDS ? __('Goes through') : __('Winner')) : __('Eliminated') }}
                                    </span>
                                    <span class="font-semibold tabular-nums">{{ __(':points pts', ['points' => $stage['total']]) }}</span>
                                </div>
                                @if ($stage['round'] < \App\Support\LegendsRanking::ROUNDS)
                                    <div class="text-[10px] text-zinc-500">{{ __('Top :limit go through', ['limit' => $stage['limit']]) }}</div>
                                @endif
                            </a>
                        @endforeach
                        @if ($row['eliminated_round'])
                            {{-- Kafelek końca przygody: zespół odpadł z tych rozgrywek --}}
                            <div class="flex flex-col items-center justify-center gap-1 rounded-xl border border-red-300 bg-red-50 p-2.5 text-center text-sm dark:border-red-500/40 dark:bg-red-500/10">
                                <flux:icon.x-circle class="size-6 text-red-500" />
                                <div class="font-semibold text-red-700 dark:text-red-300">{{ __('Out of the competition') }}</div>
                                <div class="text-xs text-zinc-500">{{ __('After matchday :number', ['number' => $row['eliminated_round']]) }}</div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="px-4 py-3">
                        <flux:text size="sm">{{ __('No matchday settled yet.') }}</flux:text>
                    </div>
                @endif
            @elseif (count($row['matches']) > 0)
                <div class="grid grid-cols-2 gap-2 p-4 sm:grid-cols-3">
                    @foreach ($row['matches'] as $match)
                        <div class="{{ $match['tip_points'] !== null ? $tipClasses[$match['tip_points']] : 'border-zinc-200 dark:border-zinc-700' }} space-y-1 rounded-xl border p-2.5 text-sm"
                            wire:key="cm-{{ $loop->parent->index }}-{{ $match['round'] }}">
                            <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                                <span>{{ $row['type'] === \App\Enums\CompetitionType::Cup ? \App\Support\CupBracket::roundName($match['round']) : __('Matchday :number', ['number' => $match['round']]) }}</span>
                                @if ($match['tip_points'] !== null)
                                    <span class="font-semibold">{{ __(':points pts', ['points' => $match['tip_points']]) }}</span>
                                @endif
                            </div>
                            <div class="truncate font-medium">{{ $match['rival'] }}</div>
                            <div class="flex items-center gap-2">
                                @if ($match['outcome'])
                                    <span class="{{ $outcomeClasses[$match['outcome']] }} flex size-5 items-center justify-center rounded text-[10px] font-bold text-white">{{ __($match['outcome']) }}</span>
                                @endif
                                <span class="font-semibold tabular-nums">{{ $match['score'] ?? '–' }}</span>
                            </div>
                        </div>
                    @endforeach
                    @if ($row['eliminated_round'])
                        {{-- Kafelek końca przygody: zespół odpadł z tych rozgrywek --}}
                        <div class="flex flex-col items-center justify-center gap-1 rounded-xl border border-red-300 bg-red-50 p-2.5 text-center text-sm dark:border-red-500/40 dark:bg-red-500/10">
                            <flux:icon.x-circle class="size-6 text-red-500" />
                            <div class="font-semibold text-red-700 dark:text-red-300">{{ __('Out of the competition') }}</div>
                            <div class="text-xs text-zinc-500">{{ $row['type'] === \App\Enums\CompetitionType::Cup ? \App\Support\CupBracket::roundName($row['eliminated_round']) : __('After matchday :number', ['number' => $row['eliminated_round']]) }}</div>
                        </div>
                    @endif
                </div>
            @else
                <div class="px-4 py-3">
                    <flux:text size="sm">{{ __('No matches yet.') }}</flux:text>
                </div>
            @endif
        </section>
    @empty
        <flux:card>
            <flux:text>{{ __('No competitions yet.') }}</flux:text>
        </flux:card>
    @endforelse
    <flux:link :href="route('results')" wire:navigate class="text-sm">{{ __('Results and tables') }}</flux:link>
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
        $optimismColor = $stats['optimism'] === null ? 'zinc' : (abs($stats['optimism']) <= 0.25 ? 'green' : (abs($stats['optimism']) <= 0.75 ? 'yellow' : 'red'));
        // Czas typowania przed pierwszym gwizdkiem: dni i godziny, godziny i minuty albo same minuty.
        $leadFmt = function (?float $hours): string {
            if ($hours === null) {
                return '—';
            }
            $minutes = (int) round($hours * 60);

            return match (true) {
                $minutes >= 1440 => intdiv($minutes, 1440) . ' d ' . intdiv($minutes % 1440, 60) . ' h',
                $minutes >= 60 => intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min',
                default => $minutes . ' min',
            };
        };
        // Skalpy: liczba rywali z lepszym bilansem (gracze i boty), procent wśród innych graczy.
        $scalpList = \App\Support\PlayerStats::scalps(auth()->user());
        $scalpCount = count($scalpList);
        $humanRivals = \App\Support\PlayerStats::humanRivals(auth()->user());
        $scalpPercent = $humanRivals > 0 ? (int) round(collect($scalpList)->where('bot', false)->count() / $humanRivals * 100) : 0;
        $scalpColor = $scalpCount === 0 ? 'zinc' : ($scalpPercent >= 30 ? 'green' : ($scalpPercent >= 10 ? 'yellow' : 'red'));
        // [etykieta, wartość, podpis, wartość do porównania, klucz średniej, stały kolor, odwrócone porównanie]
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
            // Optymista: im bliżej prawdziwych wyników (0), tym lepiej, dlatego kolor z odchylenia, nie ze średniej.
            [$optimismLabel, $stats['optimism'] === null ? '—' : ($stats['optimism'] > 0 ? '+' : '') . $fmt($stats['optimism'], 2), __('Lech goals: your tips compared with the real matches'), null, 'optimism', $optimismColor],
            [__('Tipping ahead'), $leadFmt($stats['lead_hours']), __('Average before kick-off (players: :time)', ['time' => $leadFmt($avg['lead_hours'] ?? null)]), $stats['lead_hours'], 'lead_hours'],
            [__('Best matchday'), $stats['best_matchday'] ? __(':points pts', ['points' => $stats['best_matchday']['points']]) : '—', $stats['best_matchday'] ? __('Matchday :number: tip + offensive and defensive bonus', ['number' => $stats['best_matchday']['number']]) : __('Tip + offensive and defensive bonus'), $stats['best_points'], 'best_points'],
            [__('My scalps'), $scalpCount, __(':percent% of the players', ['percent' => $scalpPercent]), null, 'scalps', $scalpColor],
            [__('Iron fist'), $stats['iron'], __('Your offensive bonus beat the rival\'s tip and both bonuses'), $stats['iron'], 'iron'],
            [__('Bricklayer'), $stats['mason'], __('Wins where your defensive bonus beat the rival\'s attack'), $stats['mason'], 'mason'],
            [__('Wizard'), $stats['wizard'], __('Wins despite 0 points for the tip'), $stats['wizard'], 'wizard'],
            // Pechowiec: mniej znaczy lepiej, więc porównanie odwrócone.
            [__('Unlucky'), $stats['unlucky'], __('Losses despite a Koziołek'), -$stats['unlucky'], 'unlucky', null, true],
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
        @foreach ($tiles as $tile)
            @php
                [$label, $value, $hint, $compare, $avgKey] = $tile;
                $average = isset($avg[$avgKey]) ? (($tile[6] ?? false) ? -$avg[$avgKey] : $avg[$avgKey]) : null;
                $color = $tile[5] ?? ($stats['scored'] > 0 ? \App\Support\PlayerStats::color($compare === null ? null : (float) $compare, $average) : 'zinc');
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
@elseif ($tab === 'scalps')
    {{-- Moje skalpy (premium): gracze, z którymi mam lepszy bilans bezpośredni, od najwyżej w rankingu Hall of Fame. --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="space-y-1">
                <flux:heading size="lg" class="flex items-center gap-2">
                    <flux:icon.fire class="text-orange-500" />
                    {{ __('My scalps') }}
                </flux:heading>
                <flux:text size="sm">{{ __('Rivals (players and bots) you have a better head-to-head record against, from the highest in the Hall of Fame ranking.') }}</flux:text>
            </div>
            <x-premium-badge />
        </div>

        @if (!\App\Support\Premium::isActive(auth()->user()))
            <div class="relative overflow-hidden rounded-2xl border border-purple-200 p-6 dark:border-purple-500/30">
                <div class="space-y-2 blur-sm select-none" aria-hidden="true">
                    @foreach (range(1, 4) as $i)
                        <div class="h-12 rounded-xl bg-purple-100 dark:bg-purple-500/20"></div>
                    @endforeach
                </div>
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-white/60 p-6 text-center dark:bg-zinc-900/60">
                    <flux:text>{{ __('See whose scalps you have collected with premium.') }}</flux:text>
                    <flux:button size="sm" variant="primary" :href="route('support')" wire:navigate>{{ __('From 5 zł a week') }}</flux:button>
                </div>
            </div>
        @elseif (count($this->myScalps) === 0)
            <flux:card>
                <flux:text>{{ __('No scalps yet. Win more matches than you lose against a player and they will appear here.') }}</flux:text>
            </flux:card>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->myScalps as $scalp)
                    @php
                        $scalpHref = $scalp['user'] ? route('team.show', $scalp['user']) : null;
                    @endphp
                    <a @if ($scalpHref) href="{{ $scalpHref }}" wire:navigate @endif wire:key="scalp-{{ $loop->index }}"
                        class="group flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-3 shadow-xs transition hover:-translate-y-0.5 hover:border-orange-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-orange-400 to-red-600 text-sm font-bold text-white">
                            {{ $loop->iteration }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">{{ $scalp['name'] }}</span>
                            <span class="block truncate text-xs text-zinc-500">
                                {{ $scalp['bot'] ? __('Bot') : $scalp['owner'] }} &middot; {{ __('Hall of Fame: :points pts', ['points' => \App\Support\HallOfFame::format($scalp['hof'])]) }}
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-xs font-semibold tabular-nums">
                            <span class="text-green-600">{{ $scalp['won'] }}</span>–<span>{{ $scalp['drawn'] }}</span>–<span class="text-red-600">{{ $scalp['lost'] }}</span>
                            <span class="block font-normal text-zinc-500">{{ __('W–D–L') }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endif
