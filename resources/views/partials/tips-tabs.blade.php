{{-- Zakładki strony „Moje typy” poza typowaniem: rozgrywki, statystyki, historia (App\Support\PlayerStats). --}}
@php
    $isPremium = \App\Support\Premium::isActive(auth()->user());
@endphp

@if ($tab === 'competitions')
    @php
        $trophyIcons = \App\Support\HallOfFame::iconUrls();
        // Kolor kafelka meczu według punktów za typ w tej kolejce: 3 niebieski, 2 zielony, 1 żółty, 0 czerwony.
        $tipClasses = [
            3 => 'border-blue-300 bg-blue-50 dark:border-blue-500/40 dark:bg-blue-500/10',
            2 => 'border-green-300 bg-green-50 dark:border-green-500/40 dark:bg-green-500/10',
            1 => 'border-yellow-300 bg-yellow-50 dark:border-yellow-500/40 dark:bg-yellow-500/10',
            0 => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10',
        ];
        // Forma w nagłówku: zielona W (wygrana), żółta R (remis), czerwona P (porażka).
        $formClasses = ['W' => 'bg-green-500 text-white', 'D' => 'bg-yellow-400 text-yellow-950', 'L' => 'bg-red-600 text-white'];
        $formLetters = ['W' => 'W', 'D' => 'R', 'L' => 'P'];
    @endphp

    {{--
        Kafelki meczów: kolumna na kolejkę (9 w rzędzie na dużym ekranie, po 3 na mniejszych), w Lidze Legend dwa mecze
        jedna pod drugim. Kolejki bez meczu: szare „odpadł” po odpadnięciu, inaczej puste miejsce na przyszły mecz.
        Puchar Polski na końcu listy.
    --}}
    @forelse (collect($this->myCompetitions)->sortBy(fn ($row) => $row['type'] === \App\Enums\CompetitionType::Cup ? 1 : 0)->values() as $row)
        @php
            $perRound = $row['type'] === \App\Enums\CompetitionType::Legends ? 2 : 1;
            $byRound = collect($row['matches'])->groupBy('round');
        @endphp
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
                        {{-- Forma: plakietka na każdy rozegrany mecz w kolejności kolejek (W wygrana, R remis, P porażka) --}}
                        <div class="mt-1 flex flex-wrap justify-end gap-1">
                            @foreach ($row['matches'] as $formMatch)
                                @if ($formMatch['outcome'])
                                    <span class="{{ $formClasses[$formMatch['outcome']] }} flex size-5 items-center justify-center rounded text-[11px] font-black"
                                        title="{{ $row['type']->stageLabel($formMatch['round']) }}: {{ $formMatch['score'] }}">{{ $formLetters[$formMatch['outcome']] }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-3 gap-2 p-3 lg:grid-cols-9">
                @foreach (range(1, \App\Models\Matchday::PER_SEASON) as $round)
                    @php
                        $roundMatches = $byRound->get($round, collect())->values();
                        $gone = $row['eliminated_round'] && $round > $row['eliminated_round'];
                    @endphp
                    <div class="flex min-w-0 flex-col gap-1.5" wire:key="cr-{{ $loop->parent->index }}-{{ $round }}">
                        <div class="truncate text-center text-[11px] font-semibold uppercase tracking-wide text-zinc-500" title="{{ $row['type']->stageLabel($round) }}">{{ $row['type']->stageLabel($round) }}</div>
                        @foreach (range(0, $perRound - 1) as $slot)
                            @php
                                $match = $roundMatches[$slot] ?? null;
                            @endphp
                            @if ($match)
                                {{-- Kliknięcie otwiera uniwersalne okno pojedynku (pages::home.duel-modal) --}}
                                <button type="button" wire:click="$dispatch('show-duel', { id: {{ $match['fixture_id'] }} })" title="{{ __('Match details') }}"
                                    class="{{ $match['tip_points'] !== null ? $tipClasses[$match['tip_points']] : 'border-zinc-200 dark:border-zinc-700' }} flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-xl border px-1.5 py-2 text-center text-sm transition hover:shadow-md">
                                    <span class="w-full truncate text-xs font-medium">{{ $match['rival'] }}</span>
                                    <span class="flex items-center gap-1">
                                        @if ($match['outcome'])
                                            <span class="{{ $formClasses[$match['outcome']] }} flex size-5 items-center justify-center rounded text-[10px] font-black">{{ $formLetters[$match['outcome']] }}</span>
                                        @endif
                                        <span class="font-bold tabular-nums">{{ $match['score'] ?? '–:–' }}</span>
                                    </span>
                                    @if ($match['tip_points'] !== null)
                                        <span class="text-[11px] text-zinc-500">{{ __(':points pts', ['points' => $match['tip_points']]) }}</span>
                                    @endif
                                </button>
                            @elseif ($gone)
                                {{-- Zespół już odpadł z rozgrywek (puchary, Liga Legend) --}}
                                <div class="flex flex-1 flex-col items-center justify-center gap-0.5 rounded-xl border border-zinc-400 bg-zinc-100 px-1.5 py-2 text-center text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800">
                                    <flux:icon.x-circle variant="micro" />
                                    <span class="w-full truncate text-[11px] font-medium">{{ __('Team eliminated') }}</span>
                                </div>
                            @else
                                {{-- Mecz jeszcze nieznany (przyszła kolejka albo runda) --}}
                                <div class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-zinc-300 px-1.5 py-2 text-zinc-300 dark:border-zinc-700 dark:text-zinc-600">
                                    <span class="font-bold">–:–</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
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
        $fmt = fn($v, $d = 1) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
        $extras = $this->myExtras;
        $trophyIcons = \App\Support\HallOfFame::iconUrls();
    @endphp

    @include('partials.player-stats-tiles', ['data' => $data, 'user' => auth()->user()])

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
    <div class="grid gap-4 md:grid-cols-3">
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

        {{-- Premium: najczęstszy typ w rozgrywkach --}}
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
    </div>

    {{-- Premium na dole: najtrudniejsze i najłatwiejsze pytania (po 5, QuestionDifficulty) --}}
    <div class="grid gap-4 md:grid-cols-2">
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

        <flux:card class="space-y-3">
            <div class="flex items-center gap-2">
                <flux:heading>{{ __('The easiest questions') }}</flux:heading>
                <x-premium-badge />
            </div>
            @if (!$isPremium)
                <flux:text size="sm">
                    {{ __('Premium shows the questions with the most correct answers.') }}
                    <flux:link :href="route('support')" wire:navigate>{{ __('See premium') }}</flux:link>
                </flux:text>
            @else
                @forelse ($extras['easiest'] as $row)
                    <div class="flex items-start gap-3 text-sm" wire:key="easy-{{ $row['question']->id }}">
                        <span class="min-w-0 flex-1">{{ $row['question']->text }}</span>
                        <span class="shrink-0 font-semibold tabular-nums text-green-600 dark:text-green-400">{{ $fmt($row['rate'], 1) }}%</span>
                    </div>
                @empty
                    <flux:text>{{ __('Not enough answers yet.') }}</flux:text>
                @endforelse
                <flux:text size="sm" class="text-xs text-zinc-500">{{ __('Share of correct answers.') }}</flux:text>
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
            <x-premium-lock :title="__('My scalps')" :text="__('With premium you see the rivals you beat more often than they beat you, all seasons and competitions together.')" />
        @elseif (count($this->myScalps) === 0)
            <flux:card>
                <flux:text>{{ __('No scalps yet. Win more matches than you lose against a player and they will appear here.') }}</flux:text>
            </flux:card>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->myScalps as $scalp)
                    @php
                        $scalpHref = $scalp['url'];
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
@elseif ($tab === 'blacklist')
    {{-- Czarna lista (premium): rywale, z którymi mam ujemny bilans bezpośredni, od najgorszego bilansu. --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="space-y-1">
                <flux:heading size="lg" class="flex items-center gap-2">
                    <flux:icon.no-symbol class="text-zinc-700 dark:text-zinc-300" />
                    {{ __('Blacklist') }}
                </flux:heading>
                <flux:text size="sm">{{ __('Rivals (players and bots) you have a worse head-to-head record against, from the worst record.') }}</flux:text>
            </div>
            <x-premium-badge />
        </div>

        @if (!\App\Support\Premium::isActive(auth()->user()))
            <x-premium-lock :title="__('Blacklist')" :text="__('With premium you see the rivals you lose to more often than you beat them, all seasons and competitions together.')" />
        @elseif (count($this->myBlacklist) === 0)
            <flux:card>
                <flux:text>{{ __('Your blacklist is empty. Nobody has a better head-to-head record against you.') }}</flux:text>
            </flux:card>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->myBlacklist as $rival)
                    @php
                        $rivalHref = $rival['url'];
                    @endphp
                    <a @if ($rivalHref) href="{{ $rivalHref }}" wire:navigate @endif wire:key="black-{{ $loop->index }}"
                        class="group flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-3 shadow-xs transition hover:-translate-y-0.5 hover:border-zinc-400 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-zinc-600 to-zinc-900 text-sm font-bold text-white">
                            {{ $loop->iteration }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">{{ $rival['name'] }}</span>
                            <span class="block truncate text-xs text-zinc-500">
                                {{ $rival['bot'] ? __('Bot') : $rival['owner'] }} &middot; {{ __('Hall of Fame: :points pts', ['points' => \App\Support\HallOfFame::format($rival['hof'])]) }}
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-xs font-semibold tabular-nums">
                            <span class="text-green-600">{{ $rival['won'] }}</span>–<span>{{ $rival['drawn'] }}</span>–<span class="text-red-600">{{ $rival['lost'] }}</span>
                            <span class="block font-normal text-zinc-500">{{ __('W–D–L') }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endif
