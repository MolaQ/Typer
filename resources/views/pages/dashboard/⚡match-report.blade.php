<?php

use App\Actions\Results\SaveMatchdayResult;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechMatch;
use App\Models\LechPlayer;
use App\Models\MatchAppearance;
use App\Models\Matchday;
use App\Support\MatchReport;
use App\Support\TipRules;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Wynik i raport meczu Lecha (desygnowanie zawodników), wszystko klikane, pod telefon:
 *  - 1/3: tablica wyniku jak w /tips (+ / −, szybkie wyniki), dogrywka z golami, seria karnych (informacyjnie),
 *    przy kolejce przeliczenie wyników,
 *  - 2/3: kadra w czterech kolumnach (B, O, P, N) według doświadczenia ze wszystkich raportów; klik dodaje zawodnika,
 *    pierwsze 11 do wyjściowej jedenastki, kolejni jako rezerwowi,
 *  - pod spodem lista meczu: wejście i zejście (punkty osi meczu, MatchReport::ON_LABELS/OFF_LABELS), gole, asysty,
 *    samobóje (+ / −), kartki (żółta, dwie żółte, czerwona), czyste konto, czas gry, blokada i doświadczenie liczone same.
 *
 * Kolejka: wynik zapisuje SaveMatchdayResult (przeliczona kolejka przelicza się od razu), zmiany raportu po przycisku
 * „Przelicz ponownie”. Mecz archiwalny z kalendarza (matchdayId = 0): wynik tylko w raporcie.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new class extends Component {
    #[Locked]
    public int $lechMatchId = 0;

    #[Locked]
    public int $matchdayId = 0;

    /** Wyjściowa jedenastka. */
    public const STARTERS = 11;

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function lechMatch(): ?LechMatch
    {
        return LechMatch::find($this->lechMatchId);
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdayId ? Matchday::find($this->matchdayId) : null;
    }

    #[Computed]
    public function editable(): bool
    {
        if (!$this->lechMatch || !auth()->user()?->can(Permission::SeasonEdit->value)) {
            return false;
        }

        return !$this->matchday || SaveMatchdayResult::canEdit($this->matchday);
    }

    /** Występy w kolejności dodania, klucz = id zawodnika. */
    #[Computed]
    public function appearances()
    {
        return MatchAppearance::where('lech_match_id', $this->lechMatchId)->orderBy('id')->get()->keyBy('lech_player_id');
    }

    /** Doświadczenie zawodników ze wszystkich raportów (kolejność w kolumnach). */
    #[Computed]
    public function totals(): array
    {
        return MatchReport::totalExperience();
    }

    /**
     * Kadra do wyboru według pozycji: dostępni i ci, którzy już są w raporcie, od największego doświadczenia.
     *
     * @return array<string, \Illuminate\Support\Collection<int, LechPlayer>>
     */
    #[Computed]
    public function columns(): array
    {
        $inReport = $this->appearances->keys()->all();
        $totals = $this->totals;
        $players = LechPlayer::query()
            ->where(fn($q) => $q->where('status', '!=', PlayerStatus::Excluded->value)->orWhereIn('id', $inReport))
            ->get()
            ->sortBy(fn(LechPlayer $p) => [-($totals[$p->id] ?? 0), $p->number ?? 999, $p->name]);

        $columns = [];
        foreach (PlayerPosition::cases() as $position) {
            $columns[$position->value] = $players->where('position', $position)->values();
        }

        return $columns;
    }

    /** @return array<int, LechPlayer> */
    #[Computed]
    public function playersById(): array
    {
        return collect($this->columns)->flatten()->keyBy('id')->all();
    }

    /** @return array<int, string> */
    #[Computed]
    public function warnings(): array
    {
        $list = [];
        $starters = $this->appearances->where('started', true)->count();

        if ($this->appearances->isNotEmpty() && $starters !== $this::STARTERS) {
            $list[] = __('The starting eleven has :count players.', ['count' => $starters]);
        }

        $noEntry = $this->appearances->where('started', false)->whereNull('on_slot')->count();
        if ($noEntry > 0) {
            $list[] = trans_choice('Mark when :count substitute came on.|Mark when :count substitutes came on.', $noEntry, ['count' => $noEntry]);
        }

        return $list;
    }

    /* ==================================================================
     | WYNIK
     * ================================================================*/

    /**
     * + / − przy wyniku: 'lech' i 'opponent' (po 90 minutach), 'et_lech' i 'et_opponent' (gole w dogrywce),
     * 'pen_lech' i 'pen_opponent' (seria rzutów karnych).
     */
    public function bump(string $side, int $delta): void
    {
        $match = $this->lechMatch;
        if (!$this->guard() || !$match) {
            return;
        }

        $step = $delta > 0 ? 1 : -1;
        $clamp = fn(?int $v) => max(0, min(TipRules::MAX_GOALS, (int) $v + $step));

        match ($side) {
            'lech' => $this->saveScore($clamp($match->lech_goals), (int) $match->opponent_goals),
            'opponent' => $this->saveScore((int) $match->lech_goals, $clamp($match->opponent_goals)),
            'et_lech' => $match->update(['et_lech_goals' => $clamp($match->et_lech_goals)]),
            'et_opponent' => $match->update(['et_opponent_goals' => $clamp($match->et_opponent_goals)]),
            'pen_lech' => $match->update(['pen_lech_goals' => $clamp($match->pen_lech_goals)]),
            'pen_opponent' => $match->update(['pen_opponent_goals' => $clamp($match->pen_opponent_goals)]),
            default => null,
        };

        $this->afterReport();
    }

    /** Szybki wynik jednym kliknięciem (np. 2:1). */
    public function pick(int $lech, int $opponent): void
    {
        if (!$this->guard()) {
            return;
        }

        $this->saveScore(max(0, min(TipRules::MAX_GOALS, $lech)), max(0, min(TipRules::MAX_GOALS, $opponent)));
        $this->afterReport();
    }

    /**
     * Wynik po 90 minutach: przy kolejce przez SaveMatchdayResult (kopiuje go do meczu, przeliczona kolejka przelicza się od razu),
     * mecz archiwalny tylko w kalendarzu.
     */
    private function saveScore(int $lech, int $opponent): void
    {
        if (!$this->matchday) {
            $this->lechMatch->update(['lech_goals' => $lech, 'opponent_goals' => $opponent]);

            return;
        }

        $action = app(SaveMatchdayResult::class);

        try {
            $action->saveScore($this->matchday, $lech, $opponent);

            if ($this->matchday->fresh()->status === MatchdayStatus::Played) {
                $stats = $action->recalculate($this->matchday->fresh());
                Flux::toast(variant: 'success', text: __('Saved and the matchday recalculated. Matches settled: :count.', ['count' => $stats['fixtures']]));
            }
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->dispatch('match-report-saved');
    }

    /** Dogrywka: kto grał do końca 90 minut, gra do końca dogrywki (i odwrotnie po wyłączeniu). */
    public function toggleExtraTime(): void
    {
        $match = $this->lechMatch;
        if (!$this->guard() || !$match) {
            return;
        }

        $on = !$match->extra_time;
        $match->update(['extra_time' => $on] + ($on ? [] : ['penalties' => false]));

        foreach ($this->appearances as $a) {
            if ($on && $a->off_slot === MatchReport::END_REGULAR) {
                $a->update(['off_slot' => MatchReport::END_EXTRA]);
            } elseif (!$on) {
                $a->update([
                    'off_slot' => min($a->off_slot, MatchReport::END_REGULAR),
                    'on_slot' => $a->on_slot === null ? null : min($a->on_slot, MatchReport::END_REGULAR - 1),
                ]);
            }
        }

        $this->afterReport();
    }

    /** Seria rzutów karnych po dogrywce (informacyjnie, gole z serii admin dopisuje strzelcom). */
    public function togglePenalties(): void
    {
        $match = $this->lechMatch;
        if (!$this->guard() || !$match) {
            return;
        }

        $on = !$match->penalties;
        $match->update(['penalties' => $on, 'pen_lech_goals' => $on ? 0 : null, 'pen_opponent_goals' => $on ? 0 : null]);
        $this->afterReport();
    }

    /** Przycisk „Przelicz wyniki” / „Przelicz ponownie” (tylko kolejka). */
    public function recalculate(SaveMatchdayResult $action): void
    {
        if (!$this->guard() || !$this->matchday) {
            return;
        }

        try {
            $stats = $action->recalculate($this->matchday);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $text = __('Matchday recalculated. Matches settled: :count.', ['count' => $stats['fixtures']]);

        // Liga podwórkowa losuje się sama po przeliczeniu (SaveMatchdayResult::recalculate).
        if (($stats['swiss'] ?? 0) > 0) {
            $text .= ' ' . __('Liga podwórkowa: the next round has been drawn (:count matches).', ['count' => $stats['swiss']]);
        }

        $this->refresh();
        $this->dispatch('match-report-saved');
        Flux::toast(variant: 'success', text: $text);
    }

    /* ==================================================================
     | SKŁAD
     * ================================================================*/

    /** Klik w kolumnie: dodaje zawodnika (pierwsze 11 do wyjściowej jedenastki, potem rezerwowi) albo go usuwa. */
    public function togglePlayer(int $playerId): void
    {
        if (!$this->guard() || !isset($this->playersById[$playerId])) {
            return;
        }

        if ($existing = $this->appearances->get($playerId)) {
            $existing->delete();
        } else {
            $started = $this->appearances->where('started', true)->count() < $this::STARTERS;
            MatchAppearance::create([
                'lech_match_id' => $this->lechMatchId,
                'lech_player_id' => $playerId,
                'started' => $started,
                // Rezerwowy: wejście zaznacza admin (nie wiadomo, kiedy wszedł). Zejście domyślnie koniec meczu.
                'on_slot' => $started ? 0 : null,
                'off_slot' => $this->end(),
            ]);
        }

        $this->afterReport();
    }

    /** Przeniesienie między wyjściową jedenastką a rezerwowymi. */
    public function toggleRole(int $playerId): void
    {
        $a = $this->appearance($playerId);
        if (!$a) {
            return;
        }

        $a->update(['started' => !$a->started, 'on_slot' => $a->started ? null : 0]);
        $this->afterReport();
    }

    /** Wejście rezerwowego (punkt osi meczu); zejście przed wejściem wraca na koniec meczu. */
    public function setOn(int $playerId, int $slot): void
    {
        $a = $this->appearance($playerId);
        if (!$a || $a->started || !isset(MatchReport::ON_LABELS[$slot]) || $slot >= $this->end()) {
            return;
        }

        $offOk = $a->off_slot > $slot || ($a->off_slot === $slot && $slot % 2 === 1);
        $a->update(['on_slot' => $a->on_slot === $slot ? null : $slot, 'off_slot' => $offOk ? $a->off_slot : $this->end()]);
        $this->afterReport();
    }

    public function setOff(int $playerId, int $slot): void
    {
        $a = $this->appearance($playerId);
        if (!$a || !isset(MatchReport::OFF_LABELS[$slot]) || $slot > $this->end()) {
            return;
        }

        $a->update(['off_slot' => $slot]);
        $this->afterReport();
    }

    /** + / − przy golach, asystach i samobójach (nie mniej niż 0). */
    public function bumpStat(int $playerId, string $field, int $delta): void
    {
        $a = $this->appearance($playerId);
        if (!$a || !in_array($field, ['goals', 'assists', 'own_goals'], true)) {
            return;
        }

        $a->update([$field => max(0, min(20, $a->{$field} + ($delta > 0 ? 1 : -1)))]);
        $this->afterReport();
    }

    /** Kartki: 'yellow' (żółta), 'double' (dwie żółte, wyłącza pojedynczą), 'red' (czerwona). */
    public function card(int $playerId, string $kind): void
    {
        $a = $this->appearance($playerId);
        if (!$a) {
            return;
        }

        match ($kind) {
            'yellow' => $a->update(['yellow' => $a->yellow === 1 ? 0 : 1]),
            'double' => $a->update(['yellow' => $a->yellow === 2 ? 0 : 2]),
            'red' => $a->update(['red' => !$a->red]),
            default => null,
        };

        $this->afterReport();
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function appearance(int $playerId): ?MatchAppearance
    {
        return $this->guard() ? $this->appearances->get($playerId) : null;
    }

    /** Koniec meczu na osi: 90 minut albo koniec dogrywki. */
    private function end(): int
    {
        return $this->lechMatch?->extra_time ? MatchReport::END_EXTRA : MatchReport::END_REGULAR;
    }

    /** Po każdej zmianie: kolumny wyliczane od nowa. */
    private function afterReport(): void
    {
        if ($match = LechMatch::find($this->lechMatchId)) {
            MatchReport::store($match);
        }

        $this->refresh();
    }

    private function guard(): bool
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);

        if (!$this->editable) {
            Flux::toast(variant: 'danger', text: __('This match report cannot be changed now.'));

            return false;
        }

        return true;
    }

    private function refresh(): void
    {
        unset($this->lechMatch, $this->matchday, $this->editable, $this->appearances, $this->totals, $this->columns, $this->playersById, $this->warnings);
    }
}; ?>

<div>
    @if (!$this->lechMatch)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('This matchday has no Lech match from the calendar yet.')" />
    @else
        @php
            $match = $this->lechMatch;
            $md = $this->matchday;
            $editable = $this->editable;
            $appearances = $this->appearances;
            $players = $this->playersById;
            $totals = $this->totals;
            $end = $match->extra_time ? \App\Support\MatchReport::END_EXTRA : \App\Support\MatchReport::END_REGULAR;
            $starters = $appearances->where('started', true);
            $subs = $appearances->where('started', false);
            $fmt = fn ($v) => \App\Support\ClubStats::format($v);

            $boardBtn = 'flex h-10 w-16 items-center justify-center rounded-xl border border-zinc-300 bg-white text-xl font-black text-lech-800 shadow-sm transition active:scale-95 hover:bg-lech-50 disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-800 dark:text-lech-200 dark:hover:bg-zinc-700';
            $miniBtn = 'flex size-7 items-center justify-center rounded-lg border border-zinc-300 bg-white text-sm font-bold text-zinc-700 transition active:scale-95 hover:bg-zinc-50 disabled:opacity-30 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
            // Przyciski osi meczu: stała szerokość.
            $slotBtn = 'h-8 w-full shrink-0 sm:w-11 rounded-md border text-xs font-bold tabular-nums transition active:scale-95 disabled:cursor-default disabled:opacity-30';
            $slotOn = 'border-lech-700 bg-lech-700 text-white';
            $slotOff = 'border-zinc-300 bg-white text-zinc-700 hover:border-lech-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
            $quickScores = [[0, 0], [1, 0], [2, 0], [2, 1], [3, 1], [1, 1], [2, 2], [0, 1], [1, 2]];
        @endphp

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- 1/3: wynik --}}
            <div class="space-y-4">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="space-y-4 px-4 py-5">
                        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                            @foreach (['lech' => 'Lech Poznań', 'opponent' => $match->opponent ?: __('Opponent')] as $side => $team)
                                @if ($side === 'opponent')
                                    <span class="pt-6 text-3xl font-black text-zinc-400">:</span>
                                @endif
                                @php
                                    $goals = $side === 'lech' ? $match->lech_goals : $match->opponent_goals;
                                @endphp
                                <div class="flex flex-col items-center gap-2" wire:key="board-{{ $side }}">
                                    <span class="w-full truncate text-center text-sm font-bold">{{ $team }}</span>
                                    <button type="button" wire:click="bump('{{ $side }}', 1)" @disabled(!$editable) class="{{ $boardBtn }}" aria-label="{{ __('One goal more') }}">+</button>
                                    <span class="lech-banner flex size-16 items-center justify-center rounded-2xl text-4xl font-black tabular-nums shadow-inner {{ $goals === null ? 'opacity-50' : '' }}">{{ $goals ?? 0 }}</span>
                                    <button type="button" wire:click="bump('{{ $side }}', -1)" @disabled(!$editable || !$goals) class="{{ $boardBtn }}" aria-label="{{ __('One goal less') }}">−</button>
                                </div>
                            @endforeach
                        </div>

                        @if ($editable)
                            <div class="flex flex-wrap justify-center gap-1.5">
                                @foreach ($quickScores as [$quickLech, $quickOpp])
                                    @php
                                        $quickOn = $match->lech_goals === $quickLech && $match->opponent_goals === $quickOpp;
                                    @endphp
                                    <button type="button" wire:click="pick({{ $quickLech }}, {{ $quickOpp }})"
                                        class="{{ $quickOn ? 'border-lech-600 bg-lech-700 text-white' : 'border-zinc-300 text-zinc-700 hover:border-lech-400 dark:border-zinc-600 dark:text-zinc-200' }} rounded-full border px-3 py-1 text-sm font-bold tabular-nums transition">
                                        {{ $quickLech }}:{{ $quickOpp }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <flux:text class="text-center text-xs">{{ __('Score after 90 minutes.') }}</flux:text>
                    </div>

                    {{-- Dogrywka i karne --}}
                    <div class="space-y-3 border-t border-zinc-100 px-4 py-4 dark:border-zinc-800">
                        <flux:checkbox :checked="$match->extra_time" wire:click="toggleExtraTime" :disabled="! $editable" :label="__('Extra time')"
                            :description="__('Players who played until the 90th minute play until the 120th.')" />

                        @if ($match->extra_time)
                            @include('partials.score-pair', ['label' => __('Goals in extra time'), 'lechKey' => 'et_lech', 'oppKey' => 'et_opponent', 'lechValue' => $match->et_lech_goals, 'oppValue' => $match->et_opponent_goals])

                            <flux:checkbox :checked="$match->penalties" wire:click="togglePenalties" :disabled="! $editable" :label="__('Penalty shootout')"
                                :description="__('For information only. Add the shootout goals to the scorers below.')" />

                            @if ($match->penalties)
                                @include('partials.score-pair', ['label' => __('Penalty shootout'), 'lechKey' => 'pen_lech', 'oppKey' => 'pen_opponent', 'lechValue' => (int) $match->pen_lech_goals, 'oppValue' => (int) $match->pen_opponent_goals])
                            @endif
                        @endif
                    </div>

                    {{-- Przeliczenie kolejki --}}
                    @if ($md && auth()->user()->can(\App\Enums\Permission::SeasonEdit->value))
                        @php
                            $played = $md->status === \App\Enums\MatchdayStatus::Played;
                        @endphp
                        <div class="space-y-2 border-t border-zinc-100 px-4 py-4 dark:border-zinc-800">
                            <flux:text class="text-xs">
                                @if ($md->lech_goals === null)
                                    {{ __('Enter the score, the line-up and the events, then recalculate the matchday.') }}
                                @elseif (!$played)
                                    {{ __('The result is saved, but the matchday is not recalculated yet.') }}
                                @else
                                    {{ __('Score changes recalculate the matchday at once. After line-up changes, recalculate again.') }}
                                @endif
                            </flux:text>
                            <flux:button class="w-full" :variant="$played ? 'filled' : 'primary'" icon="calculator" wire:click="recalculate" wire:loading.attr="disabled"
                                :disabled="$md->lech_goals === null || ! $editable">
                                <span wire:loading.remove wire:target="recalculate">{{ $played ? __('Recalculate again') : __('Recalculate results') }}</span>
                                <span wire:loading wire:target="recalculate">{{ __('Recalculating...') }}</span>
                            </flux:button>
                        </div>
                    @endif
                </div>

                @foreach ($this->warnings as $warning)
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="$warning" />
                @endforeach
            </div>

            {{-- 2/3: kadra w czterech kolumnach --}}
            <div class="space-y-3 lg:col-span-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-bold tabular-nums {{ $starters->count() === $this::STARTERS ? 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }}">
                        {{ __('Starting eleven') }}: {{ $starters->count() }}/{{ $this::STARTERS }}
                    </span>
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-bold tabular-nums text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        {{ __('Substitutes') }}: {{ $subs->count() }}
                    </span>
                    <flux:text class="text-xs">{{ __('Click players in order: the first eleven start, the next ones came off the bench.') }}</flux:text>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($this->columns as $positionValue => $columnPlayers)
                        @php
                            $position = \App\Enums\PlayerPosition::from($positionValue);
                        @endphp
                        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:key="col-{{ $positionValue }}">
                            <div class="lech-bar flex items-center justify-between px-3 py-1.5 text-xs font-bold uppercase tracking-wide">
                                <span>{{ $position->label() }}</span>
                                <span class="tabular-nums">{{ $appearances->filter(fn ($a) => ($players[$a->lech_player_id] ?? null)?->position === $position)->count() }}</span>
                            </div>
                            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($columnPlayers as $player)
                                    @php
                                        $a = $appearances->get($player->id);
                                    @endphp
                                    <li wire:key="pick-{{ $player->id }}">
                                        <button type="button" wire:click="togglePlayer({{ $player->id }})" @disabled(!$editable)
                                            class="{{ $a ? 'bg-lech-700 text-white' : 'hover:bg-zinc-50 dark:hover:bg-white/5' }} flex w-full items-center gap-1.5 px-2 py-1.5 text-left text-sm transition disabled:cursor-default">
                                            <span class="w-5 shrink-0 text-right text-xs tabular-nums {{ $a ? 'text-lech-100' : 'text-zinc-400' }}">{{ $player->number }}</span>
                                            <span class="min-w-0 flex-1 truncate {{ $a ? 'font-bold' : '' }}">{{ $player->name }}</span>
                                            @if ($a)
                                                <span class="shrink-0 rounded bg-white/20 px-1 text-[10px] font-bold">{{ $a->started ? __('XI') : __('Sub') }}</span>
                                            @else
                                                <span class="shrink-0 text-[10px] tabular-nums text-zinc-400" title="{{ __('Experience') }}">{{ $fmt($totals[$player->id] ?? 0) }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Lista meczu: wyjściowa jedenastka, przerwa, rezerwowi --}}
        @if ($appearances->isNotEmpty())
            <div class="mt-6 space-y-6">
                @foreach ([[__('Starting eleven'), $starters], [__('Substitutes'), $subs]] as [$groupTitle, $group])
                    @if ($group->isNotEmpty())
                        <section class="space-y-2">
                            <flux:heading size="lg">{{ $groupTitle }} <span class="text-sm font-normal tabular-nums text-zinc-500">({{ $group->count() }})</span></flux:heading>

                            @foreach ($group as $a)
                                @php
                                    $player = $players[$a->lech_player_id] ?? null;
                                @endphp
                                @if ($player)
                                    <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" wire:key="row-{{ $a->id }}">
                                        {{-- Zawodnik, wyliczenia i akcje --}}
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="w-6 text-right text-xs tabular-nums text-zinc-400">{{ $player->number }}</span>
                                            <span class="w-5 text-[10px] font-semibold uppercase text-zinc-500">{{ $player->position->short() }}</span>
                                            <span class="font-semibold">{{ $player->name }}</span>
                                            @if ($a->clean_sheet !== null)
                                                <span class="{{ $a->clean_sheet ? 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' }} rounded px-1.5 py-0.5 text-[10px] font-bold"
                                                    title="{{ __('Clean sheet') }}">{{ __('CS') }} {{ $a->clean_sheet ? 1 : 0 }}</span>
                                            @endif
                                            <span class="ms-auto flex items-center gap-2 text-xs tabular-nums text-zinc-500">
                                                <span title="{{ __('Playing time in halves') }}">{{ __('Time') }} <b class="text-zinc-800 dark:text-zinc-100">{{ $fmt($a->play_time) }}</b></span>
                                                @if ($a->lock)
                                                    <span class="{{ \App\Support\Squad::lockColor($a->lock) }} rounded px-1.5 py-0.5 text-[10px] font-bold" title="{{ __('Rest after the match') }}">{{ trans_choice(':count matchday|:count matchdays', $a->lock, ['count' => $a->lock]) }}</span>
                                                @endif
                                                <span title="{{ __('Experience') }}">{{ __('Exp.') }} <b class="text-lech-700 dark:text-lech-300">{{ $fmt($a->experience) }}</b></span>
                                            </span>
                                            @if ($editable)
                                                <flux:button size="xs" variant="ghost" wire:click="toggleRole({{ $a->lech_player_id }})">{{ $a->started ? __('To the bench') : __('To the eleven') }}</flux:button>
                                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="togglePlayer({{ $a->lech_player_id }})" :aria-label="__('Remove')" />
                                            @endif
                                        </div>

                                        {{-- Wejście (rezerwowi) i zejście --}}
                                        @if (!$a->started)
                                            <div class="flex items-center gap-2">
                                                <span class="w-12 shrink-0 text-xs font-semibold text-zinc-500">{{ __('Came on') }}</span>
                                                <div class="grid flex-1 grid-cols-4 gap-1 sm:flex sm:flex-none">
                                                    @foreach (\App\Support\MatchReport::ON_LABELS as $slot => $slotLabel)
                                                        @continue($slot >= $end)
                                                        <button type="button" wire:click="setOn({{ $a->lech_player_id }}, {{ $slot }})" @disabled(!$editable)
                                                            class="{{ $slotBtn }} {{ $a->on_slot === $slot ? $slotOn : $slotOff }}">{{ $slotLabel }}</button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-2">
                                            <span class="w-12 shrink-0 text-xs font-semibold text-zinc-500">{{ __('Came off') }}</span>
                                            <div class="grid flex-1 grid-cols-4 gap-1 sm:flex sm:flex-none">
                                                @foreach (\App\Support\MatchReport::OFF_LABELS as $slot => $slotLabel)
                                                    @continue($slot > $end)
                                                    @php
                                                        $on = $a->started ? 0 : $a->on_slot;
                                                        $valid = $on === null || $slot > $on || ($slot === $on && $on % 2 === 1);
                                                    @endphp
                                                    <button type="button" wire:click="setOff({{ $a->lech_player_id }}, {{ $slot }})" @disabled(!$editable || !$valid)
                                                        class="{{ $slotBtn }} {{ $a->off_slot === $slot ? $slotOn : $slotOff }}">{{ $slotLabel }}</button>
                                                @endforeach
                                            </div>
                                        </div>

                                        {{-- Gole, asysty, samobóje i kartki --}}
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                            @foreach (['goals' => __('Goals'), 'assists' => __('Assists'), 'own_goals' => __('Own goals')] as $field => $fieldLabel)
                                                <div class="flex items-center gap-1">
                                                    <span class="text-xs font-semibold text-zinc-500">{{ $fieldLabel }}</span>
                                                    <button type="button" wire:click="bumpStat({{ $a->lech_player_id }}, '{{ $field }}', -1)" @disabled(!$editable || $a->{$field} === 0) class="{{ $miniBtn }}" aria-label="−">−</button>
                                                    <span class="w-5 text-center text-sm font-bold tabular-nums {{ $a->{$field} ? ($field === 'own_goals' ? 'text-red-600' : 'text-lech-700 dark:text-lech-300') : 'text-zinc-400' }}">{{ $a->{$field} }}</span>
                                                    <button type="button" wire:click="bumpStat({{ $a->lech_player_id }}, '{{ $field }}', 1)" @disabled(!$editable) class="{{ $miniBtn }}" aria-label="+">+</button>
                                                </div>
                                            @endforeach

                                            <div class="flex items-center gap-1" role="group" aria-label="{{ __('Cards') }}">
                                                @php
                                                    $cards = [
                                                        'yellow' => [__('Yellow card'), $a->yellow === 1, '<span class="inline-block h-4 w-3 rounded-[2px] bg-yellow-400"></span>'],
                                                        'double' => [__('Two yellow cards'), $a->yellow === 2, '<span class="relative inline-block h-4 w-4"><span class="absolute left-0 top-0 h-4 w-3 rounded-[2px] bg-yellow-400"></span><span class="absolute left-1.5 top-0 h-4 w-3 rounded-[2px] bg-red-500 ring-1 ring-white dark:ring-zinc-900"></span></span>'],
                                                        'red' => [__('Red card'), $a->red, '<span class="inline-block h-4 w-3 rounded-[2px] bg-red-500"></span>'],
                                                    ];
                                                @endphp
                                                @foreach ($cards as $kind => [$cardLabel, $cardOn, $cardIcon])
                                                    <button type="button" wire:click="card({{ $a->lech_player_id }}, '{{ $kind }}')" @disabled(!$editable) title="{{ $cardLabel }}" aria-label="{{ $cardLabel }}"
                                                        class="{{ $cardOn ? 'border-lech-700 bg-lech-50 ring-2 ring-lech-600 dark:bg-lech-500/10' : 'border-zinc-300 bg-white opacity-50 hover:opacity-100 dark:border-zinc-600 dark:bg-zinc-800' }} flex h-8 w-10 items-center justify-center rounded-md border transition">
                                                        {!! $cardIcon !!}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </section>
                    @endif
                @endforeach

                <flux:text class="text-xs">
                    {{ __('Experience: starting eleven 1 or substitute 0.5, a whole half 1 and part of a half 0.5, a clean sheet (goalkeeper, defender) 1, each goal (also in a penalty shootout) and assist 1. Points are set in the line-up settings.') }}
                </flux:text>
            </div>
        @endif
    @endif
</div>
