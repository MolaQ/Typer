{{--
    „Twoje mecze w Lidze Legend” / „Twoje mecze w Europie”: kolumna na kolejkę (etykieta etapu jako link do kolejki),
    w niej karta każdego meczu zespołu gracza (Liga Legend: dwie). Pasek koloru: zielony = wygrana (awans),
    szary = remis, czerwony = porażka (odpadnięcie), bursztynowy = przed wynikiem. Kliknięcie karty otwiera okno pojedynku.

    Parametry: $title, $status, $rounds (liczba kolejek), $perRound (meczów w kolejce), $path (kolejka => lista meczów),
    $entryId (wpis gracza), $eliminated (kolejka odpadnięcia albo null), $stageLabel (fn(int): string), $current (wybrana kolejka).
--}}
<section class="lech-bar-shadow overflow-hidden rounded-2xl border border-lech-950/20 bg-white dark:border-lech-900 dark:bg-zinc-900">
    <header class="lech-bar flex flex-wrap items-center justify-between gap-2 px-5 py-3">
        <h3 class="flex items-center gap-2 font-bold">
            <flux:icon.trophy class="size-5 text-lech-200" />
            {{ $title }}
        </h3>
        <span class="text-sm font-semibold text-lech-200">{{ $status }}</span>
    </header>
    <div class="overflow-x-auto p-4">
        <ol class="grid gap-2" style="grid-template-columns: repeat({{ $rounds }}, minmax(6.5rem, 1fr));">
            @foreach (range(1, $rounds) as $stage)
                @php
                    $stageFixtures = $path[$stage] ?? [];
                    $gone = $eliminated !== null && $stage > $eliminated;
                @endphp
                <li class="flex flex-col gap-1.5" wire:key="path-{{ $stage }}">
                    <a href="{{ $this->pageUrl($stage) }}" wire:navigate
                        class="{{ $stage === $current ? 'text-lech-700 dark:text-lech-300' : 'text-zinc-500 hover:text-lech-700' }} truncate text-center text-xs font-semibold uppercase tracking-wide">
                        {{ $stageLabel($stage) }}
                    </a>
                    @foreach (range(0, $perRound - 1) as $slot)
                        @php
                            $fx = $stageFixtures[$slot] ?? null;
                            $isHome = $fx && $fx->home_entry_id === $entryId;
                            $opponent = $fx ? ($isHome ? $fx->away : $fx->home) : null;
                            $myGoals = $fx ? ($isHome ? $fx->home_goals : $fx->away_goals) : null;
                            $theirGoals = $fx ? ($isHome ? $fx->away_goals : $fx->home_goals) : null;
                            [$strip, $stateClass, $stateText] = match (true) {
                                $fx === null && $gone => ['bg-zinc-400 dark:bg-zinc-600', 'text-zinc-500', __('Team eliminated')],
                                $fx === null => ['bg-zinc-200 dark:bg-zinc-700', 'text-zinc-400', '—'],
                                !$fx->isPlayed() => ['bg-amber-400', 'text-amber-700 dark:text-amber-300', __('Not played yet')],
                                $fx->winner_entry_id === null => ['bg-zinc-400', 'text-zinc-600 dark:text-zinc-300', __('Tie')],
                                $fx->winner_entry_id === $entryId => ['bg-green-500', 'text-green-700 dark:text-green-400', $fx->decided_by_time ? __('Through on penalties') : __('Victory')],
                                default => ['bg-red-500', 'text-red-600 dark:text-red-400', $fx->decided_by_time ? __('Out on penalties') : __('Defeat')],
                            };
                        @endphp
                        @if ($fx)
                            <button type="button" wire:click="showFixture({{ $fx->id }})" title="{{ __('Match details') }}"
                                class="{{ $stage === $current ? 'ring-2 ring-lech-600 dark:ring-lech-400' : '' }} flex flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-center transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="{{ $strip }} block h-1.5 w-full"></span>
                                <span class="flex w-full flex-1 flex-col items-center gap-0.5 px-2 py-2">
                                    <span class="text-2xl font-black tabular-nums text-lech-900 dark:text-white">
                                        {{ $fx->isPlayed() ? $myGoals . ':' . $theirGoals : '–' }}
                                    </span>
                                    <span class="w-full truncate text-xs text-zinc-600 dark:text-zinc-300">{{ $opponent?->seasonTeam->name ?? __('Opponent not known yet') }}</span>
                                    <span class="{{ $stateClass }} text-xs font-semibold">{{ $stateText }}</span>
                                </span>
                            </button>
                        @else
                            <div class="{{ $gone ? 'bg-zinc-100 dark:bg-zinc-800' : 'opacity-60' }} flex flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 text-center dark:border-zinc-700">
                                <span class="{{ $strip }} block h-1.5"></span>
                                <span class="flex flex-1 flex-col items-center justify-center gap-0.5 px-2 py-2">
                                    <span class="text-2xl font-black text-zinc-300 dark:text-zinc-600">–</span>
                                    <span class="{{ $stateClass }} text-xs font-semibold">{{ $stateText }}</span>
                                </span>
                            </div>
                        @endif
                    @endforeach
                </li>
            @endforeach
        </ol>
    </div>
</section>
