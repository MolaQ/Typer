{{--
    Kafelki statystyk sezonu gracza (zakładka Statystyki na /tips i profil zespołu).
    Parametry: $data (PlayerStats::season), $user (właściciel statystyk).
    Kolor kafelka z procentu (PlayerStats::percent / percentColor): czerwony poniżej 35%, żółty 35-65%, zielony powyżej 65%.
    Procent to udział (oddane typy, trafność, % wygranych) albo wynik względem najlepszego gracza w serwisie.
--}}
@php
    $stats = $data['mine'];
    $avg = $data['average'];
    $max = $data['max'];
    $fmt = fn($v, $d = 1) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
    $rel = fn(string $key, bool $inverse = false) => \App\Support\PlayerStats::percent($stats[$key] ?? null, $max[$key] ?? null, $inverse);
    $optimismLabel = match (true) {
        $stats['optimism'] === null => __('Optimist or pessimist'),
        $stats['optimism'] > 0.25 => __('Optimist'),
        $stats['optimism'] < -0.25 => __('Pessimist'),
        default => __('Realist'),
    };
    // Skalpy i czarna lista: liczba rywali (gracze i boty), procent wśród innych graczy.
    $humanRivals = \App\Support\PlayerStats::humanRivals($user);
    $scalpList = \App\Support\PlayerStats::scalps($user);
    $blackList = \App\Support\PlayerStats::scalps($user, blacklist: true);
    $scalpPercent = $humanRivals > 0 ? (int) round(collect($scalpList)->where('bot', false)->count() / $humanRivals * 100) : 0;
    $blackPercent = $humanRivals > 0 ? (int) round(collect($blackList)->where('bot', false)->count() / $humanRivals * 100) : 0;
    $played = $stats['won'] + $stats['drawn'] + $stats['lost'];
    $share = fn(int $count) => $played > 0 ? (int) round($count / $played * 100) : 0;
    // [etykieta, wartość, podpis, procent do koloru]
    $tiles = [
        [__('Tips'), $stats['tips'] . ' / ' . $stats['scored'], __('No tip: :count', ['count' => $stats['missing']]), $stats['tips_ratio'] !== null ? $stats['tips_ratio'] * 100 : null],
        [__('Perfect tips (Koziołki)'), $stats['exact'], __('Exact scores'), $rel('exact')],
        [__('Accuracy'), $stats['accuracy'] !== null ? $stats['accuracy'] . '%' : '—', __('Correct outcomes: :count', ['count' => $stats['outcome']]), $stats['accuracy']],
        [__('Goal differences'), $stats['diff'], __('Correct goal differences'), $rel('diff')],
        [__('Average for the tip'), $fmt($stats['avg_tip'], 2), __('Points: :points', ['points' => $stats['tip_points']]), $rel('avg_tip')],
        [__('Average offensive bonus'), $fmt($stats['avg_offense'], 2), __('Per competition'), $rel('avg_offense')],
        [__('Average defensive bonus'), $fmt($stats['avg_defense'], 2), __('Per competition'), $rel('avg_defense')],
        [__('Outcome streak'), $stats['outcome_streak'], __('Best: :count', ['count' => $stats['outcome_streak_best']]), $rel('outcome_streak_best')],
        [__('Matches'), $stats['won'] . '–' . $stats['drawn'] . '–' . $stats['lost'], __(':won% wins, :drawn% draws, :lost% losses', ['won' => $share($stats['won']), 'drawn' => $share($stats['drawn']), 'lost' => $share($stats['lost'])]), $stats['win_ratio'] !== null ? $stats['win_ratio'] * 100 : null],
        [__('Goals'), $stats['for'] . ':' . $stats['against'], __('Most in a match: :count', ['count' => $stats['record_goals']]), $rel('for')],
        [__('Unbeaten run'), $stats['unbeaten'], __('Best: :count', ['count' => $stats['unbeaten_best']]), $rel('unbeaten_best')],
        [__('Winning streak'), $stats['wins'], __('Best: :count', ['count' => $stats['wins_best']]), $rel('wins_best')],
        // Optymista: im bliżej prawdziwych wyników (0), tym lepiej, więc procent z odchylenia względem największego w serwisie.
        [$optimismLabel, $stats['optimism'] === null ? '—' : ($stats['optimism'] > 0 ? '+' : '') . $fmt($stats['optimism'], 2), __('Lech goals: your tips compared with the real matches'), $stats['optimism'] === null ? null : \App\Support\PlayerStats::percent(abs($stats['optimism']), $max['optimism'] ?? null, inverse: true)],
        [__('Tipping ahead'), \App\Support\PlayerStats::formatLead($stats['lead_minutes']), __('Average before kick-off (players: :time)', ['time' => \App\Support\PlayerStats::formatLead(isset($avg['lead_minutes']) ? (int) round($avg['lead_minutes']) : null)]), $rel('lead_minutes')],
        [__('Best matchday'), $stats['best_matchday'] ? __(':points pts', ['points' => $stats['best_matchday']['points']]) : '—', $stats['best_matchday'] ? __('Matchday :number: tip + offensive and defensive bonus', ['number' => $stats['best_matchday']['number']]) : __('Tip + offensive and defensive bonus'), $rel('best_points')],
        [__('My scalps'), count($scalpList), __(':percent% of the players', ['percent' => $scalpPercent]), $humanRivals > 0 ? $scalpPercent : null],
        // Czarna lista: rywale z lepszym bilansem bezpośrednim, mniej znaczy lepiej.
        [__('Blacklist'), count($blackList), __(':percent% of the players', ['percent' => $blackPercent]), $humanRivals > 0 ? 100 - $blackPercent : null],
        [__('Iron fist'), $stats['iron'], __('Your offensive bonus beat the rival\'s tip and both bonuses'), $rel('iron')],
        [__('Bricklayer'), $stats['mason'], __('Wins where your defensive bonus beat the rival\'s tip points and offensive bonus'), $rel('mason')],
        [__('Wizard'), $stats['wizard'], __('Wins despite 0 points for the tip'), $rel('wizard')],
        [__('Unlucky'), $stats['unlucky'], __('Losses despite a Koziołek'), $rel('unlucky', inverse: true)],
    ];
    $tileClasses = [
        'green' => 'border-green-300 bg-green-50 dark:border-green-500/40 dark:bg-green-500/10',
        'yellow' => 'border-yellow-300 bg-yellow-50 dark:border-yellow-500/40 dark:bg-yellow-500/10',
        'red' => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10',
        'zinc' => 'border-zinc-200 dark:border-zinc-700',
    ];
@endphp

<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
    @foreach ($tiles as [$label, $value, $hint, $percent])
        @php
            $color = $stats['scored'] > 0 ? \App\Support\PlayerStats::percentColor($percent === null ? null : (float) $percent) : 'zinc';
        @endphp
        <div class="{{ $tileClasses[$color] }} space-y-1 rounded-xl border p-4" wire:key="tile-{{ $loop->index }}">
            <flux:text size="sm">{{ $label }}</flux:text>
            <p class="text-2xl font-bold tabular-nums">{{ $value }}</p>
            <flux:text size="sm" class="text-zinc-500">{{ $hint }}</flux:text>
        </div>
    @endforeach
</div>

<flux:text size="sm" class="text-xs">{{ __('Colours: red below 35%, yellow from 35% to 65%, green above 65%. The percentage is a share (e.g. 3 of 4 tips = 75%) or the result compared with the best player in the game (e.g. 1 Koziołek when the best has 3 = 33%).') }}</flux:text>
