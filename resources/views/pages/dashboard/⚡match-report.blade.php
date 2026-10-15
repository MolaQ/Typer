<?php

use App\Actions\Results\SaveMatchdayResult;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechMatch;
use App\Models\LechPlayer;
use App\Models\MatchAppearance;
use App\Models\MatchEvent;
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
 *  - 2/3: kadra w czterech kolumnach (B, O, P, N) według doświadczenia ze wszystkich raportów; klik dodaje zawodnika
 *    w kolejności trenera: pierwsze 11 do wyjściowej jedenastki, kolejni na ławkę (także ci, którzy nie weszli),
 *  - pod spodem 1/4: protokół meczu jak w relacjach na żywo (gol z asystą, kartka, zmiana, samobój; czas tylko przy zmianie,
 *    drugiej żółtej i czerwonej, punkty osi MatchReport::OFF_LABELS), 3/4: tabela zbiorcza liczona z protokołu
 *    (czas gry, gole, asysty, samobóje, kartki, czyste konto, połowy, odpoczynek, doświadczenie; MatchReport::store).
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

    /** Nowy wpis protokołu: rodzaj (MatchReport::EVENTS), zawodnik, drugi zawodnik (asysta, wchodzący), kartka, czas. */
    public string $eventType = MatchReport::EVENT_GOAL;

    public ?int $eventPlayer = null;

    public ?int $eventOther = null;

    public string $eventCard = 'yellow';

    public ?int $eventSlot = null;

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

    /** Protokół meczu w kolejności wpisu. */
    #[Computed]
    public function events()
    {
        return MatchEvent::with(['player:id,name,number', 'other:id,name,number'])->where('lech_match_id', $this->lechMatchId)->orderBy('id')->get();
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

        // Bez dogrywki zdarzenia z dogrywki przesuwają się na koniec 90 minut (MatchReport::protocol i tak przycina czas).
        if (!$on) {
            MatchEvent::where('lech_match_id', $match->id)->where('slot', '>', MatchReport::END_REGULAR)->update(['slot' => MatchReport::END_REGULAR]);
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
            MatchEvent::where('lech_match_id', $this->lechMatchId)
                ->where(fn($q) => $q->where('lech_player_id', $playerId)->orWhere('other_player_id', $playerId))
                ->delete();
        } else {
            $started = $this->appearances->where('started', true)->count() < $this::STARTERS;
            MatchAppearance::create([
                'lech_match_id' => $this->lechMatchId,
                'lech_player_id' => $playerId,
                'started' => $started,
                // Ławka gra dopiero po zmianie w protokole.
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

        $a->update(['started' => !$a->started]);
        $this->afterReport();
    }

    /* ==================================================================
     | PROTOKÓŁ
     * ================================================================*/

    public function setEventType(string $type): void
    {
        if (in_array($type, MatchReport::EVENTS, true)) {
            $this->eventType = $type;
            $this->eventPlayer = null;
            $this->eventOther = null;
            $this->eventSlot = null;
        }
    }

    /**
     * Zawodnicy do wyboru w protokole: 'played' (zagrał: gol, asysta, samobój), 'pitch' (jest na boisku do końca: schodzący),
     * 'bench' (rezerwowy, który jeszcze nie wszedł), 'squad' (cały skład meczu: kartki).
     *
     * @return array<int, int> id zawodników w kolejności składu
     */
    public function eventChoices(string $group): array
    {
        $end = $this->end();

        return $this->appearances
            ->filter(fn(MatchAppearance $a) => match ($group) {
                'played' => $a->started || $a->on_slot !== null,
                'pitch' => ($a->started || $a->on_slot !== null) && $a->off_slot >= $end,
                'bench' => !$a->started && $a->on_slot === null,
                default => true,
            })
            ->keys()->all();
    }

    /** Dodaje wpis do protokołu (kolejność wpisu = kolejność zdarzeń w relacji). */
    public function addEvent(): void
    {
        if (!$this->guard()) {
            return;
        }

        $type = $this->eventType;
        $player = $this->eventPlayer;
        $other = $this->eventOther;
        $needsSlot = $type === MatchReport::EVENT_SUB || ($type === MatchReport::EVENT_CARD && $this->eventCard !== 'yellow');
        $group = match ($type) {
            MatchReport::EVENT_SUB => 'pitch',
            MatchReport::EVENT_CARD => 'squad',
            default => 'played',
        };

        $error = match (true) {
            $player === null || !in_array($player, $this->eventChoices($group), true) => __('Choose a player.'),
            $type === MatchReport::EVENT_CARD && !in_array($this->eventCard, MatchReport::CARDS, true) => __('Choose a card.'),
            $needsSlot && ($this->eventSlot === null || !isset(MatchReport::OFF_LABELS[$this->eventSlot]) || $this->eventSlot > $this->end()) => __('Choose when it happened.'),
            $other !== null && $type === MatchReport::EVENT_GOAL && ($other === $player || !in_array($other, $this->eventChoices('played'), true)) => __('Choose a player.'),
            $other !== null && $type === MatchReport::EVENT_SUB && !in_array($other, $this->eventChoices('bench'), true) => __('Choose a player.'),
            default => null,
        };

        if ($error) {
            Flux::toast(variant: 'danger', text: $error);

            return;
        }

        MatchEvent::create([
            'lech_match_id' => $this->lechMatchId,
            'type' => $type,
            'lech_player_id' => $player,
            'other_player_id' => in_array($type, [MatchReport::EVENT_GOAL, MatchReport::EVENT_SUB], true) ? $other : null,
            'card' => $type === MatchReport::EVENT_CARD ? $this->eventCard : null,
            'slot' => $needsSlot ? $this->eventSlot : null,
        ]);

        $this->eventPlayer = null;
        $this->eventOther = null;
        $this->eventSlot = null;
        $this->afterReport();
    }

    public function deleteEvent(int $eventId): void
    {
        if (!$this->guard()) {
            return;
        }

        MatchEvent::where('lech_match_id', $this->lechMatchId)->whereKey($eventId)->delete();
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
        unset($this->lechMatch, $this->matchday, $this->editable, $this->appearances, $this->events, $this->totals, $this->columns, $this->playersById, $this->warnings);
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
                                :description="__('For information only. Add the shootout goals to the protocol as goals.')" />

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
                        {{ __('Bench') }}: {{ $subs->count() }}
                    </span>
                    <flux:text class="text-xs">{{ __('Click players in the coach\'s order: the first eleven start, the next ones sit on the bench.') }}</flux:text>
                </div>

                <div class="grid grid-cols-2 gap-3 2xl:grid-cols-4">
                    @foreach ($this->columns as $positionValue => $columnPlayers)
                        @php
                            $position = \App\Enums\PlayerPosition::from($positionValue);
                        @endphp
                        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:key="col-{{ $positionValue }}">
                            <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-zinc-600 dark:border-zinc-700 dark:bg-white/5 dark:text-zinc-300">
                                <span>{{ $position->label() }}</span>
                                <span class="tabular-nums">{{ $appearances->filter(fn ($a) => ($players[$a->lech_player_id] ?? null)?->position === $position)->count() }}</span>
                            </div>
                            <ul>
                                @foreach ($columnPlayers as $player)
                                    @php
                                        $a = $appearances->get($player->id);
                                    @endphp
                                    <li wire:key="pick-{{ $player->id }}">
                                        {{-- Wybrany: jasne tło i znacznik XI (jedenastka) albo Ł (ławka) --}}
                                        <button type="button" wire:click="togglePlayer({{ $player->id }})" @disabled(!$editable)
                                            class="{{ $a ? 'border-lech-600 bg-lech-50 font-semibold text-lech-900 dark:bg-lech-500/10 dark:text-lech-100' : 'border-transparent text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5' }} flex w-full items-center gap-2 border-l-4 px-2 py-1.5 text-left text-sm transition disabled:cursor-default">
                                            <span class="w-5 shrink-0 text-right text-xs tabular-nums text-zinc-400">{{ $player->number }}</span>
                                            <span class="min-w-0 flex-1 truncate">{{ $player->name }}</span>
                                            @if ($a)
                                                <span class="{{ $a->started ? 'bg-lech-700 text-white' : 'bg-zinc-300 text-zinc-800 dark:bg-zinc-600 dark:text-white' }} w-6 shrink-0 rounded text-center text-[10px] font-bold leading-4">{{ $a->started ? __('XI') : __('Sub') }}</span>
                                            @else
                                                <span class="w-8 shrink-0 text-right text-[10px] tabular-nums text-zinc-400" title="{{ __('Experience') }}">{{ \App\Support\ClubStats::experience($totals[$player->id] ?? 0) }}</span>
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

        {{-- Pod spodem: 1/4 protokół meczu, 3/4 tabela zbiorcza liczona z protokołu --}}
        @if ($appearances->isNotEmpty())
            @php
                $type = $this->eventType;
                $needsSlot = $type === \App\Support\MatchReport::EVENT_SUB || ($type === \App\Support\MatchReport::EVENT_CARD && $this->eventCard !== 'yellow');
                $mainGroup = match ($type) {
                    \App\Support\MatchReport::EVENT_SUB => 'pitch',
                    \App\Support\MatchReport::EVENT_CARD => 'squad',
                    default => 'played',
                };
                $mainLabel = match ($type) {
                    \App\Support\MatchReport::EVENT_GOAL => __('Scorer'),
                    \App\Support\MatchReport::EVENT_SUB => __('Coming off'),
                    default => __('Footballer'),
                };
                $otherGroup = match ($type) {
                    \App\Support\MatchReport::EVENT_GOAL => 'played',
                    \App\Support\MatchReport::EVENT_SUB => 'bench',
                    default => null,
                };
                $otherLabel = $type === \App\Support\MatchReport::EVENT_GOAL ? __('Assist (optional)') : __('Coming on (optional)');
                $typeLabels = [
                    \App\Support\MatchReport::EVENT_GOAL => __('Goal'),
                    \App\Support\MatchReport::EVENT_CARD => __('Card'),
                    \App\Support\MatchReport::EVENT_SUB => __('Substitution'),
                    \App\Support\MatchReport::EVENT_OWN_GOAL => __('Own goal'),
                ];
                $cardLabels = ['yellow' => __('Yellow card'), 'second_yellow' => __('Second yellow card'), 'red' => __('Red card')];
                $chipOn = 'border-lech-700 bg-lech-700 text-white';
                $chipOff = 'border-zinc-300 bg-white text-zinc-700 hover:border-lech-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
                $chip = 'rounded-md border px-1.5 py-0.5 text-xs font-semibold transition active:scale-95';
                $stepLabel = 'text-[10px] font-bold uppercase tracking-wide text-zinc-500';
                $th = 'px-2 py-2 text-center font-semibold';
            @endphp
            <div class="mt-6 grid items-start gap-6 lg:grid-cols-4">
                {{-- 1/4: protokół --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="lech-bar px-3 py-2 text-xs font-bold uppercase tracking-wide">{{ __('Match protocol') }}</div>

                    @if ($editable)
                        <div class="space-y-3 border-b border-zinc-100 p-3 dark:border-zinc-800">
                            <div class="grid grid-cols-2 gap-1">
                                @foreach ($typeLabels as $typeKey => $typeLabel)
                                    <button type="button" wire:click="setEventType('{{ $typeKey }}')"
                                        class="{{ $type === $typeKey ? $chipOn : $chipOff }} flex items-center justify-center gap-1.5 rounded-lg border px-2 py-1.5 text-xs font-bold transition">
                                        @if ($typeKey === \App\Support\MatchReport::EVENT_GOAL)
                                            <x-ball />
                                        @elseif ($typeKey === \App\Support\MatchReport::EVENT_OWN_GOAL)
                                            <x-ball own />
                                        @elseif ($typeKey === \App\Support\MatchReport::EVENT_CARD)
                                            <span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-yellow-400"></span>
                                        @else
                                            <flux:icon.arrows-right-left variant="micro" />
                                        @endif
                                        {{ $typeLabel }}
                                    </button>
                                @endforeach
                            </div>

                            @if ($type === \App\Support\MatchReport::EVENT_CARD)
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($cardLabels as $cardKey => $cardLabel)
                                        <button type="button" wire:click="$set('eventCard', '{{ $cardKey }}')" class="{{ $chip }} {{ $this->eventCard === $cardKey ? $chipOn : $chipOff }}">{{ $cardLabel }}</button>
                                    @endforeach
                                </div>
                            @endif

                            @foreach (array_filter([['eventPlayer', $mainGroup, $mainLabel, $this->eventPlayer], $otherGroup ? ['eventOther', $otherGroup, $otherLabel, $this->eventOther] : null]) as [$field, $group, $groupLabel, $chosen])
                                <div class="space-y-1">
                                    <div class="{{ $stepLabel }}">{{ $groupLabel }}</div>
                                    @php
                                        $choices = $this->eventChoices($group);
                                    @endphp
                                    @if ($choices === [])
                                        <flux:text class="text-xs">{{ __('Nobody to choose.') }}</flux:text>
                                    @endif
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($choices as $choiceId)
                                            @php
                                                $choice = $players[$choiceId] ?? null;
                                            @endphp
                                            @if ($choice)
                                                <button type="button" wire:click="$set('{{ $field }}', {{ $chosen === $choiceId ? 'null' : $choiceId }})" title="{{ $choice->name }}"
                                                    class="{{ $chip }} {{ $chosen === $choiceId ? $chipOn : $chipOff }}">
                                                    <span class="opacity-60">{{ $choice->number }}</span> {{ \App\Support\MatchSheet::surname($choice->name) }}
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            @if ($needsSlot)
                                <div class="space-y-1">
                                    <div class="{{ $stepLabel }}">{{ __('When') }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (\App\Support\MatchReport::OFF_LABELS as $slot => $slotLabel)
                                            @continue($slot > $end)
                                            <button type="button" wire:click="$set('eventSlot', {{ $slot }})" title="{{ \App\Support\MatchReport::phase($slot, long: true) }}"
                                                class="{{ $chip }} px-2 {{ $this->eventSlot === $slot ? $chipOn : $chipOff }}"><x-match-clock :to="$slot" /></button>
                                        @endforeach
                                    </div>
                                    @include('partials.match-clock-legend')
                                </div>
                            @endif

                            <flux:button class="w-full" size="sm" variant="primary" icon="plus" wire:click="addEvent" :disabled="$this->eventPlayer === null || ($needsSlot && $this->eventSlot === null)">
                                {{ __('Add to the protocol') }}
                            </flux:button>
                        </div>
                    @endif

                    <div class="px-3 py-2">
                        @if ($this->events->isEmpty())
                            <flux:text class="py-2 text-xs">{{ __('No events yet. The starting eleven plays the whole match, the bench does not come on.') }}</flux:text>
                        @else
                            @include('partials.match-protocol', ['events' => $this->events, 'deletable' => $editable])
                        @endif
                    </div>
                </div>

                {{-- 3/4: tabela zbiorcza (tylko odczyt, liczona z protokołu) --}}
                <div class="lg:col-span-3">
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <table class="w-full min-w-[46rem] text-sm">
                            <thead class="lech-bar text-xs uppercase tracking-wide">
                                <tr>
                                    <th class="{{ $th }} w-8">#</th>
                                    <th class="px-2 py-2 text-left font-semibold">{{ __('Player') }}</th>
                                    <th class="{{ $th }}">{{ __('Playing time') }}</th>
                                    <th class="{{ $th }}">{{ __('Goals') }}</th>
                                    <th class="{{ $th }}">{{ __('Assists') }}</th>
                                    <th class="{{ $th }}" title="{{ __('Own goals') }}">{{ __('OG') }}</th>
                                    <th class="{{ $th }}">{{ __('Cards') }}</th>
                                    <th class="{{ $th }}" title="{{ __('Clean sheet') }}">{{ __('CS') }}</th>
                                    <th class="{{ $th }}" title="{{ __('Playing time in halves') }}">{{ __('Time') }}</th>
                                    <th class="{{ $th }}" title="{{ __('Rest after the match') }}">{{ __('Rest') }}</th>
                                    <th class="{{ $th }}">{{ __('Exp.') }}</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            @foreach ([[__('Starting eleven'), $starters], [__('Bench'), $subs]] as [$groupTitle, $group])
                                @if ($group->isNotEmpty())
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        <tr class="bg-zinc-50 dark:bg-white/5">
                                            <td colspan="12" class="px-3 py-1 text-xs font-bold uppercase tracking-wide text-zinc-500">{{ $groupTitle }} ({{ $group->count() }})</td>
                                        </tr>
                                        @foreach ($group as $a)
                                            @php
                                                $player = $players[$a->lech_player_id] ?? null;
                                                $on = $a->started ? 0 : $a->on_slot;
                                                $span = \App\Support\MatchReport::spanLabel($on, $a->off_slot);
                                            @endphp
                                            @if ($player)
                                                <tr wire:key="row-{{ $a->id }}" class="{{ $span === null ? 'text-zinc-400' : '' }}">
                                                    <td class="px-2 py-1.5 text-right text-xs tabular-nums text-zinc-400">{{ $player->number }}</td>
                                                    <td class="px-2 py-1.5">
                                                        <div class="flex items-center gap-1.5 whitespace-nowrap">
                                                            <span class="w-4 text-[10px] font-semibold uppercase text-zinc-400">{{ $player->position->short() }}</span>
                                                            <span class="font-medium">{{ $player->name }}</span>
                                                            @if ($editable)
                                                                <button type="button" wire:click="toggleRole({{ $a->lech_player_id }})" class="rounded px-1 text-[10px] font-semibold text-lech-700 hover:bg-lech-50 dark:text-lech-300"
                                                                    title="{{ $a->started ? __('To the bench') : __('To the eleven') }}">{{ $a->started ? '↓' . __('Sub') : '↑' . __('XI') }}</button>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-1.5 text-center"><x-match-clock :from="$on ?? -1" :to="$a->off_slot" :title="$span ?? __('did not come on')" /></td>
                                                    @foreach (['goals', 'assists', 'own_goals'] as $field)
                                                        <td class="px-2 py-1.5 text-center tabular-nums">
                                                            @if ($a->{$field} > 0)
                                                                <span class="{{ $field === 'own_goals' ? 'bg-red-600' : 'bg-lech-700' }} inline-flex size-6 items-center justify-center rounded-full text-xs font-bold text-white">{{ $a->{$field} }}</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="px-2 py-1.5">
                                                        <div class="flex items-center justify-center gap-1">
                                                            @if ($a->yellow === 2)
                                                                <span class="relative inline-block h-3.5 w-3.5" title="{{ __('Two yellow cards') }}"><span class="absolute left-0 top-0 h-3.5 w-2.5 rounded-[2px] bg-yellow-400"></span><span class="absolute left-1 top-0 h-3.5 w-2.5 rounded-[2px] bg-red-500 ring-1 ring-white dark:ring-zinc-900"></span></span>
                                                            @elseif ($a->yellow === 1)
                                                                <span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-yellow-400" title="{{ __('Yellow card') }}"></span>
                                                            @endif
                                                            @if ($a->red)
                                                                <span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-red-500" title="{{ __('Red card') }}"></span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-1.5 text-center text-xs font-bold">
                                                        @if ($a->clean_sheet !== null)
                                                            <span class="{{ $a->clean_sheet ? 'text-green-600' : 'text-zinc-400' }}">{{ $a->clean_sheet ? 1 : 0 }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1.5 text-center tabular-nums">{{ $fmt($a->play_time) }}</td>
                                                    <td class="px-2 py-1.5 text-center">
                                                        @if ($a->rest_hours)
                                                            <span class="{{ \App\Support\Squad::lockColor($a->rest_hours) }} inline-block rounded px-1 text-[11px] font-bold tabular-nums">{{ $a->rest_hours }} h</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1.5 text-center font-bold tabular-nums text-lech-700 dark:text-lech-300">{{ \App\Support\ClubStats::experience($a->experience) }}</td>
                                                    <td class="px-1 py-1.5 text-center">
                                                        @if ($editable)
                                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="togglePlayer({{ $a->lech_player_id }})" :aria-label="__('Remove')" />
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                @endif
                            @endforeach
                        </table>
                    </div>

                    <flux:text class="mt-2 text-xs">
                        {{ __('The table is calculated from the protocol. Experience: starting eleven 1, bench 0.5 (also without coming on), a whole half 1 and part of a half 0.5, a clean sheet (goalkeeper, defender who played, Lech conceded nothing) 1, each goal (also in a penalty shootout) and assist 1. Points are set in the line-up settings.') }}
                    </flux:text>
                </div>
            </div>
        @endif
    @endif
</div>
