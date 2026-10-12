{{--
    Puchary europejskie na stronie wyników (App\Support\EuropeanCup):
     - pasek etapów: faza grupowa (kolejki 1-5), 1/8 finału, ćwierćfinał, półfinał, finał (linki do kolejek),
     - kolejki 1-5: tabele grup A-D (miejsca 1-4 awansują, 5-6 odpadają) i mecze grupy w wybranej kolejce,
     - kolejki 6-9: karty meczów jak w Pucharze Polski (partials/cup-fixture-card), zdobywca po finale.
    Parametry: $round. Komponent: pages::results ($this->competition, $this->groupTables, $this->matches).
--}}
@php
    $euroRounds = \App\Support\EuropeanCup::ROUNDS;
    $groupStage = \App\Support\EuropeanCup::isGroupRound($round);
    $colorStyle = \App\Support\CompetitionColors::style($this->competition->trophyKey());
    $fixtures = $this->matches ? collect($this->matches->items()) : collect();
    $groupMatches = $groupStage ? $fixtures->groupBy(fn ($f) => \App\Support\EuropeanCup::groupOf((int) $f->home_seat)) : collect();
    $final = $round === $euroRounds ? $fixtures->first() : null;
    $advance = \App\Support\EuropeanCup::ADVANCE;
@endphp
<div class="space-y-4">
    {{-- Pasek etapów: Faza grupowa 1-5 → 1/8 finału → Ćwierćfinał → Półfinał → Finał --}}
    <nav class="flex flex-wrap items-center gap-1 text-xs" aria-label="{{ __('Rounds') }}">
        <span class="me-1 font-semibold uppercase tracking-wide text-zinc-500">{{ __('Group stage') }}</span>
        @foreach (range(1, $euroRounds) as $stage)
            @php
                $stageClass = $stage === $round
                    ? 'lech-bar font-semibold'
                    : ($stage < $round ? 'bg-lech-100 text-lech-800 hover:bg-lech-200 dark:bg-lech-900/40 dark:text-lech-200' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800');
            @endphp
            <a href="{{ $this->pageUrl($stage) }}" wire:navigate title="{{ \App\Support\EuropeanCup::stageLabel($stage) }}"
                class="{{ $stageClass }} rounded-full px-2.5 py-1 tabular-nums transition">
                {{ \App\Support\EuropeanCup::isGroupRound($stage) ? $stage : \App\Support\EuropeanCup::stageLabel($stage) }}
            </a>
            @if ($stage < $euroRounds)
                @if ($stage >= \App\Support\EuropeanCup::GROUP_ROUNDS)
                    <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                @endif
            @endif
        @endforeach
    </nav>

    {{-- Zdobywca po rozegranym finale --}}
    @if ($final && $final->winner_entry_id)
        @php
            $champion = $final->winner_entry_id === $final->home_entry_id ? $final->home : $final->away;
        @endphp
        <div class="flex items-center gap-3 rounded-xl border-2 px-4 py-3" style="{{ $colorStyle }} border-color: var(--c-accent); background-image: linear-gradient(120deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink);">
            <flux:icon.trophy class="comp-accent size-8 shrink-0" />
            <div class="min-w-0">
                <div class="text-xs uppercase tracking-wide opacity-80">{{ __('Winner') }}</div>
                <button type="button" wire:click="showTeam({{ $champion?->season_team_id ?? 0 }})" class="truncate text-lg font-bold hover:underline {{ $champion?->seasonTeam?->is_bot ? 'italic' : '' }}">{{ $champion?->seasonTeam->name }}</button>
            </div>
        </div>
    @endif

    @if ($groupStage)
        {{-- Tabele grup A-D z meczami wybranej kolejki --}}
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->groupTables as $group => $table)
                {{--
                    Karta grupy w barwach rozgrywek: kolorowa ramka obejmuje nagłówek („GRUPA” i litera w kółku po prawej),
                    nagłówek tabeli i stopkę z wynikami kolejki; jasne są tylko wiersze tabeli.
                --}}
                <section class="overflow-hidden rounded-2xl p-1 shadow-md" wire:key="group-{{ $group }}"
                    style="{{ $colorStyle }} background-image: linear-gradient(140deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink); border: 2px solid var(--c-accent);">
                    <header class="flex items-center justify-end gap-2.5 px-3 pb-1.5 pt-2">
                        <h3 class="text-sm font-black uppercase tracking-[0.25em]">{{ __('Group') }}</h3>
                        <span class="comp-badge flex size-9 shrink-0 items-center justify-center rounded-full text-base font-black shadow">{{ $group }}</span>
                    </header>

                    <table class="w-full text-sm">
                        <thead class="text-[11px] font-semibold uppercase tracking-wide opacity-80">
                            <tr>
                                <th class="px-3 py-1.5 text-right">#</th>
                                <th class="px-2 py-1.5 text-left">{{ __('Team') }}</th>
                                <th class="px-1.5 py-1.5 text-right" title="{{ __('Matches played') }}">{{ __('P') }}</th>
                                <th class="px-1.5 py-1.5 text-right" title="{{ __('Won') }}">{{ __('W') }}</th>
                                <th class="px-1.5 py-1.5 text-right" title="{{ __('Drawn') }}">{{ __('D') }}</th>
                                <th class="px-1.5 py-1.5 text-right" title="{{ __('Lost') }}">{{ __('L') }}</th>
                                <th class="px-1.5 py-1.5 text-right" title="{{ __('Goals') }}">{{ __('Goals') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Pts') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white text-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                            @foreach ($table->values() as $index => $row)
                                @php
                                    $zoneClass = $index < $advance ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-zinc-400 text-zinc-500 dark:text-zinc-400';
                                @endphp
                                <tr wire:key="group-{{ $group }}-{{ $row['entry_id'] }}"
                                    class="{{ $zoneClass }} border-b border-zinc-100 last:border-b-0 dark:border-zinc-700/50 {{ $this->highlighted($row['team']->id) ? 'bg-amber-50 font-semibold dark:bg-amber-900/20' : '' }}">
                                    <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $index + 1 }}.</td>
                                    <td class="max-w-44 truncate px-2 py-1.5">
                                        <button type="button" wire:click="showTeam({{ $row['team']->id }})" class="truncate hover:text-lech-700 hover:underline dark:hover:text-lech-300 {{ $row['team']->is_bot ? 'italic' : '' }}">{{ $row['team']->name }}</button>
                                        <x-champion-trophies :team="$row['team']" class="ms-1" />
                                    </td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['played'] }}</td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['won'] }}</td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['drawn'] }}</td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['lost'] }}</td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['for'] }}:{{ $row['against'] }}</td>
                                    <td class="px-3 py-1.5 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    {{-- Stopka: mecze grupy w wybranej kolejce, wynik w wyróżnionym kafelku na środku --}}
                    <footer class="space-y-1.5 px-2 pb-2 pt-2.5">
                        <div class="comp-accent px-1 text-[11px] font-bold uppercase tracking-[0.2em]">{{ __('Matchday :number', ['number' => $round]) }}</div>
                        @forelse ($groupMatches->get($group, collect()) as $fixture)
                            @php
                                $mine = $this->highlighted($fixture->home?->season_team_id) || $this->highlighted($fixture->away?->season_team_id);
                                $homeWon = $fixture->isPlayed() && $fixture->home_goals > $fixture->away_goals;
                                $awayWon = $fixture->isPlayed() && $fixture->away_goals > $fixture->home_goals;
                            @endphp
                            <button type="button" wire:click="showFixture({{ $fixture->id }})" title="{{ __('Match details') }}" wire:key="gfx-{{ $fixture->id }}"
                                class="{{ $mine ? 'ring-2 ring-amber-400' : '' }} grid w-full grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-lg bg-black/20 px-2 py-1.5 text-sm transition hover:bg-black/30">
                                <span class="flex min-w-0 items-center justify-end gap-1 {{ $homeWon ? 'font-bold' : 'opacity-85' }}"><x-champion-trophies :team="$fixture->home?->seasonTeam" :size="14" /><span class="truncate {{ $fixture->home?->seasonTeam?->is_bot ? 'italic' : '' }}">{{ $fixture->home?->seasonTeam->name }}</span></span>
                                @if ($fixture->isPlayed())
                                    <span class="comp-badge min-w-14 rounded-md px-2 py-0.5 text-center text-base font-black tabular-nums shadow">{{ $fixture->home_goals }}:{{ $fixture->away_goals }}</span>
                                @else
                                    <span class="min-w-14 rounded-md border border-dashed border-current px-2 py-0.5 text-center text-base font-bold tabular-nums opacity-60">–:–</span>
                                @endif
                                <span class="flex min-w-0 items-center gap-1 {{ $awayWon ? 'font-bold' : 'opacity-85' }}"><span class="truncate {{ $fixture->away?->seasonTeam?->is_bot ? 'italic' : '' }}">{{ $fixture->away?->seasonTeam->name }}</span><x-champion-trophies :team="$fixture->away?->seasonTeam" :size="14" /></span>
                            </button>
                        @empty
                            <div class="px-1 text-sm opacity-80">{{ __('No matches yet.') }}</div>
                        @endforelse
                    </footer>
                </section>
            @endforeach
        </div>

        <div class="space-y-1 px-1 text-xs italic text-zinc-500">
            <p>{{ __('Four groups of six teams, everyone plays everyone once. Points and tie-breakers as in the leagues.') }}</p>
            <p>{{ __('The top four of each group go through to the round of 16, the fifth and sixth places are eliminated. Teams from the same group place are ranked against each other (the best group winner, the second best group winner …) and that decides the seeding in the round of 16.') }}</p>
            <p class="flex flex-wrap gap-4 not-italic">
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-green-500"></span>{{ __('Round of 16') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-zinc-400"></span>{{ __('Eliminated') }}</span>
            </p>
        </div>
    @else
        {{-- Faza pucharowa: karty meczów (zwycięzca przejmuje lepsze miejsce pary) --}}
        <flux:card class="space-y-3">
            <flux:heading>{{ \App\Support\EuropeanCup::stageLabel($round) }}</flux:heading>
            @if ($fixtures->isNotEmpty())
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($fixtures as $fixture)
                        <div wire:key="euro-cell-{{ $fixture->id }}">
                            @include('partials.cup-fixture-card', [
                                'fixture' => $fixture,
                                'mine' => $this->highlighted($fixture->home?->season_team_id) || $this->highlighted($fixture->away?->season_team_id),
                            ])
                            @if ($round === \App\Support\EuropeanCup::GROUP_ROUNDS + 1)
                                <div class="mt-1 px-1 text-[11px] text-zinc-500">
                                    {{ \App\Support\EuropeanCup::seatLabel((int) $fixture->home_seat) }} – {{ \App\Support\EuropeanCup::seatLabel((int) $fixture->away_seat) }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <flux:text>{{ __('The round of 16 is drawn after the last group matchday.') }}</flux:text>
            @endif
        </flux:card>

        <div class="space-y-1 px-1 text-xs italic text-zinc-500">
            <p>
                {{ __('Seeds 1-4 are the group winners, 5-8 the runners-up, 9-12 the third places and 13-16 the fourth places, each ranked against each other. The best group winner can meet the second best only in the final.') }}
            </p>
            <p>
                {{ __('The favourite plays on the left and the winner takes the better seed of the pair. A draw goes to the team that saved its tip earlier, shown as “after penalties”.') }}
            </p>
        </div>
    @endif
</div>
