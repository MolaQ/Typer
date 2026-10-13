<?php

use App\Actions\Results\SaveMatchdayResult;
use App\Enums\Permission;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechPlayer;
use App\Models\MatchAppearance;
use App\Models\Matchday;
use App\Models\MatchGoal;
use App\Support\MatchReport;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Raport meczu kolejki (desygnowanie zawodników): admin klika skład, zmiany, gole i kartki,
 * a Support\MatchReport liczy minuty, połowy, blokady (R6), czyste konta (R4), „mecz zawodnika” i doświadczenie.
 * Każde kliknięcie zapisuje się od razu. Osadzony w dashboard/results pod wynikiem.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new class extends Component {
    #[Locked]
    public int $matchdayId = 0;

    /** Szybkie minuty zmian (46 = od przerwy). */
    public const QUICK_MINUTES = [46, 60, 70, 80];

    public function mount(): void
    {
        $this->fillGoalsFromScore();
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function matchday(): ?Matchday
    {
        return Matchday::with('season')->find($this->matchdayId);
    }

    /** @return \Illuminate\Support\Collection<int, LechPlayer> */
    #[Computed]
    public function squad()
    {
        $order = array_flip(PlayerPosition::values());

        return LechPlayer::orderBy('number')->orderBy('name')->get()
            ->sortBy(fn (LechPlayer $p) => [$order[$p->position->value], $p->status === PlayerStatus::Excluded ? 1 : 0, $p->number ?? 999])
            ->values();
    }

    /** Występy, klucz = id zawodnika. */
    #[Computed]
    public function appearances()
    {
        return MatchAppearance::where('matchday_id', $this->matchdayId)->get()->keyBy('lech_player_id');
    }

    #[Computed]
    public function goals()
    {
        return MatchGoal::where('matchday_id', $this->matchdayId)
            ->orderByRaw('minute is null')->orderBy('minute')->orderBy('id')->get();
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function report(): array
    {
        return $this->matchday ? MatchReport::forMatchday($this->matchday) : [];
    }

    #[Computed]
    public function editable(): bool
    {
        if (!$this->matchday || !auth()->user()?->can(Permission::SeasonEdit->value)) {
            return false;
        }

        try {
            SaveMatchdayResult::ensureEditable($this->matchday);
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    /**
     * Uwagi do uzupełnienia (nie blokują zapisu).
     *
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $md = $this->matchday;
        $list = [];

        $starters = $this->appearances->where('started', true)->count();

        if ($this->appearances->isNotEmpty() && $starters !== 11) {
            $list[] = __('The starting eleven has :count players.', ['count' => $starters]);
        }

        if ($this->appearances->where('started', false)->whereNull('on_minute')->isNotEmpty()) {
            $list[] = __('Some substitutes have no minute of coming on.');
        }

        if ($this->appearances->where('red', true)->whereNull('off_minute')->isNotEmpty()) {
            $list[] = __('Set the minute of the red card as the minute of going off.');
        }

        if ($this->goals->whereNull('minute')->isNotEmpty()) {
            $list[] = __('Some goals have no minute.');
        }

        // Wynik kolejki jest po 90 minutach, gole z dogrywki (minuta 91+) liczą się osobno.
        if ($md && $md->lech_goals !== null) {
            foreach ([MatchGoal::LECH => $md->lech_goals, MatchGoal::OPPONENT => $md->opponent_goals] as $side => $score) {
                $regular = $this->goals->where('side', $side)->filter(fn (MatchGoal $g) => $g->minute === null || $g->minute <= 90)->count();

                if ($regular !== $score) {
                    $list[] = $side === MatchGoal::LECH
                        ? __('Lech goals in 90 minutes: :count, but the score says :score.', ['count' => $regular, 'score' => $score])
                        : __('Opponent goals in 90 minutes: :count, but the score says :score.', ['count' => $regular, 'score' => $score]);
                }
            }
        }

        if ($md && !$md->extra_time && $this->goals->where('minute', '>', 90)->isNotEmpty()) {
            $list[] = __('There are goals after the 90th minute, but extra time is off.');
        }

        return $list;
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    /** Brakujące gole z wyniku po 90 minutach dopisujemy jako puste wiersze do kliknięcia. */
    private function fillGoalsFromScore(): void
    {
        $md = $this->matchday;

        if (!$md || $md->lech_goals === null || !$this->editable) {
            return;
        }

        foreach ([MatchGoal::LECH => $md->lech_goals, MatchGoal::OPPONENT => (int) $md->opponent_goals] as $side => $score) {
            $missing = $score - MatchGoal::where('matchday_id', $md->id)->where('side', $side)->count();

            for ($i = 0; $i < $missing; $i++) {
                MatchGoal::create(['matchday_id' => $md->id, 'side' => $side]);
            }
        }

        unset($this->goals);
    }

    /** Stan zawodnika w meczu: none (nie grał), start (od początku), sub (z ławki). */
    public function setRole(int $playerId, string $role): void
    {
        if (!$this->guard() || !LechPlayer::whereKey($playerId)->exists()) {
            return;
        }

        $appearance = $this->appearances->get($playerId);

        if ($role === 'none') {
            $appearance?->delete();
            // Gole i asysty zawodnika, który nie grał, tracą przypisanie.
            MatchGoal::where('matchday_id', $this->matchdayId)->where('scorer_id', $playerId)->update(['scorer_id' => null]);
            MatchGoal::where('matchday_id', $this->matchdayId)->where('assist_id', $playerId)->update(['assist_id' => null]);
        } elseif (in_array($role, ['start', 'sub'], true)) {
            MatchAppearance::updateOrCreate(
                ['matchday_id' => $this->matchdayId, 'lech_player_id' => $playerId],
                ['started' => $role === 'start', 'on_minute' => $role === 'start' ? null : $appearance?->on_minute],
            );
        }

        $this->refresh();
    }

    /** Minuta wejścia (on) albo zejścia (off); pusta = do końca meczu. */
    public function setMinute(int $playerId, string $field, mixed $value): void
    {
        if (!$this->guard()) {
            return;
        }

        $appearance = $this->appearances->get($playerId);

        if (!$appearance || !in_array($field, ['on', 'off'], true)) {
            return;
        }

        $appearance->update([$field . '_minute' => $this->minute($value)]);
        $this->refresh();
    }

    public function toggleCard(int $playerId, string $card): void
    {
        if (!$this->guard()) {
            return;
        }

        $appearance = $this->appearances->get($playerId);

        if (!$appearance || !in_array($card, ['yellow', 'red'], true)) {
            return;
        }

        $appearance->update([$card => !$appearance->{$card}]);
        $this->refresh();
    }

    public function toggleExtraTime(): void
    {
        if (!$this->guard()) {
            return;
        }

        $this->matchday->update(['extra_time' => !$this->matchday->extra_time]);
        $this->refresh();
    }

    public function addGoal(string $side): void
    {
        if (!$this->guard()) {
            return;
        }

        if (in_array($side, [MatchGoal::LECH, MatchGoal::OPPONENT], true)) {
            MatchGoal::create(['matchday_id' => $this->matchdayId, 'side' => $side]);
        }

        $this->refresh();
    }

    public function removeGoal(int $goalId): void
    {
        if (!$this->guard()) {
            return;
        }

        MatchGoal::where('matchday_id', $this->matchdayId)->whereKey($goalId)->delete();
        $this->refresh();
    }

    /** Minuta, strzelec albo asystent gola (pusta wartość czyści pole). */
    public function setGoal(int $goalId, string $field, mixed $value): void
    {
        if (!$this->guard()) {
            return;
        }

        $goal = MatchGoal::where('matchday_id', $this->matchdayId)->find($goalId);

        if (!$goal || !in_array($field, ['minute', 'scorer_id', 'assist_id'], true)) {
            return;
        }

        if ($field === 'minute') {
            $goal->update(['minute' => $this->minute($value)]);
        } else {
            $id = (int) $value;
            $goal->update([$field => $id > 0 && $this->appearances->has($id) ? $id : null]);
        }

        $this->refresh();
    }

    private function minute(mixed $value): ?int
    {
        $minute = (int) $value;

        return $minute >= 1 && $minute <= 120 ? $minute : null;
    }

    /** Zapis tylko z uprawnieniem i dopóki kolejkę można poprawiać (SaveMatchdayResult::ensureEditable). */
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
        unset($this->matchday, $this->appearances, $this->goals, $this->report, $this->warnings);
    }
}; ?>

<div>
    @if ($this->matchday)
        @php
            $md = $this->matchday;
            $editable = $this->editable;
            $report = $this->report;
            $appearances = $this->appearances;
            $played = $this->squad->filter(fn($p) => $appearances->has($p->id));
            $starters = $appearances->where('started', true)->count();
            $subs = $appearances->where('started', false)->count();
            $chip = 'rounded-md px-2.5 py-1 text-xs font-medium transition';
            $chipOff = 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:hover:bg-white/10';
            $minuteInput = 'w-14 rounded-md border border-zinc-200 bg-white px-1.5 py-1 text-center text-xs tabular-nums dark:border-white/10 dark:bg-zinc-800';
        @endphp

        <flux:card class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Match report') }}</flux:heading>
                    <flux:text>{{ __('Click the line-up, substitutions, goals and cards. Minutes, locks, clean sheets and experience are counted automatically.') }}</flux:text>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge size="sm" :color="$starters === 11 ? 'green' : 'zinc'">{{ __('Starting XI') }}: {{ $starters }}/11</flux:badge>
                    <flux:badge size="sm" color="zinc">{{ __('Substitutes') }}: {{ $subs }}</flux:badge>
                    <flux:button size="sm" :variant="$md->extra_time ? 'primary' : 'filled'" icon="clock" wire:click="toggleExtraTime" :disabled="! $editable">
                        {{ $md->extra_time ? __('Extra time: yes') : __('Extra time: no') }}
                    </flux:button>
                </div>
            </div>

            @if ($this->warnings)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('To complete') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc space-y-0.5 ps-4">
                            @foreach ($this->warnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            {{-- 1. Skład: klik „Od początku” / „Z ławki”, minuta zmiany, kartki --}}
            <div class="space-y-2">
                <flux:heading>{{ __('Line-up') }}</flux:heading>

                <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                    @foreach ($this->squad as $player)
                        @php
                            $a = $appearances->get($player->id);
                            $role = $a ? ($a->started ? 'start' : 'sub') : 'none';
                            $excluded = $player->status === \App\Enums\PlayerStatus::Excluded;
                        @endphp
                        <div wire:key="player-{{ $player->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2 {{ $a ? '' : 'opacity-70' }}">
                            <div class="flex w-56 min-w-0 items-center gap-2">
                                <span class="w-7 shrink-0 text-right text-xs tabular-nums text-zinc-400">{{ $player->number }}</span>
                                <span class="w-7 shrink-0 text-[10px] font-semibold uppercase text-zinc-500">{{ $player->position->short() }}</span>
                                <span class="truncate text-sm font-medium {{ $excluded ? 'line-through' : '' }}">{{ $player->name }}</span>
                            </div>

                            <div class="flex gap-1">
                                @foreach (['none' => __('Did not play'), 'start' => __('From the start'), 'sub' => __('Came on')] as $value => $label)
                                    <button type="button" wire:click="setRole({{ $player->id }}, '{{ $value }}')" @disabled(! $editable)
                                        class="{{ $chip }} {{ $role === $value ? ($value === 'none' ? 'bg-zinc-500 text-white' : 'bg-lech-700 text-white') : $chipOff }} disabled:cursor-default">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>

                            @if ($a)
                                <div class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                                    @if (! $a->started)
                                        <span>{{ __('on') }}</span>
                                        <input type="number" min="1" max="120" value="{{ $a->on_minute }}" placeholder="'"
                                            wire:change="setMinute({{ $player->id }}, 'on', $event.target.value)" @disabled(! $editable)
                                            class="{{ $minuteInput }} {{ $a->on_minute === null ? 'border-amber-400!' : '' }}">
                                        @foreach ($this::QUICK_MINUTES as $quick)
                                            <button type="button" wire:click="setMinute({{ $player->id }}, 'on', {{ $quick }})" @disabled(! $editable)
                                                class="{{ $chip }} {{ $a->on_minute === $quick ? 'bg-lech-100 text-lech-800 dark:bg-lech-900 dark:text-lech-100' : $chipOff }}">{{ $quick === 46 ? __('HT') : $quick . "'" }}</button>
                                        @endforeach
                                    @endif

                                    <span class="ms-1">{{ __('off') }}</span>
                                    <input type="number" min="1" max="120" value="{{ $a->off_minute }}" placeholder="–"
                                        wire:change="setMinute({{ $player->id }}, 'off', $event.target.value)" @disabled(! $editable)
                                        class="{{ $minuteInput }}">
                                    @if ($a->started)
                                        @foreach ($this::QUICK_MINUTES as $quick)
                                            <button type="button" wire:click="setMinute({{ $player->id }}, 'off', {{ $quick }})" @disabled(! $editable)
                                                class="{{ $chip }} {{ $a->off_minute === $quick ? 'bg-lech-100 text-lech-800 dark:bg-lech-900 dark:text-lech-100' : $chipOff }}">{{ $quick === 46 ? __('HT') : $quick . "'" }}</button>
                                        @endforeach
                                    @endif
                                    @if ($a->off_minute !== null)
                                        <button type="button" wire:click="setMinute({{ $player->id }}, 'off', '')" @disabled(! $editable)
                                            class="{{ $chip }} {{ $chipOff }}" title="{{ __('Played to the end') }}">✕</button>
                                    @endif
                                </div>

                                <div class="ms-auto flex gap-1">
                                    <button type="button" wire:click="toggleCard({{ $player->id }}, 'yellow')" @disabled(! $editable) title="{{ __('Yellow card') }}"
                                        class="h-6 w-4.5 rounded-sm border-2 {{ $a->yellow ? 'border-yellow-500 bg-yellow-400' : 'border-zinc-300 dark:border-white/20' }}"></button>
                                    <button type="button" wire:click="toggleCard({{ $player->id }}, 'red')" @disabled(! $editable) title="{{ __('Red card') }}"
                                        class="h-6 w-4.5 rounded-sm border-2 {{ $a->red ? 'border-red-600 bg-red-500' : 'border-zinc-300 dark:border-white/20' }}"></button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 2. Gole: wiersze z wyniku, klik strzelca i asystenta --}}
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ([\App\Models\MatchGoal::LECH => 'Lech Poznań', \App\Models\MatchGoal::OPPONENT => $md->opponent ?: __('Opponent')] as $side => $team)
                    @php
                        $sideGoals = $this->goals->where('side', $side);
                    @endphp
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading>{{ __('Goals') }}: {{ $team }} ({{ $sideGoals->count() }})</flux:heading>
                            <flux:button size="xs" icon="plus" wire:click="addGoal('{{ $side }}')" :disabled="! $editable">{{ __('Goal') }}</flux:button>
                        </div>

                        @forelse ($sideGoals as $goal)
                            <div wire:key="goal-{{ $goal->id }}" class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-white/10">
                                <input type="number" min="1" max="120" value="{{ $goal->minute }}" placeholder="'"
                                    wire:change="setGoal({{ $goal->id }}, 'minute', $event.target.value)" @disabled(! $editable)
                                    class="{{ $minuteInput }} {{ $goal->minute === null ? 'border-amber-400!' : '' }}">

                                @if ($side === \App\Models\MatchGoal::LECH)
                                    <select wire:change="setGoal({{ $goal->id }}, 'scorer_id', $event.target.value)" @disabled(! $editable)
                                        class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-white/10 dark:bg-zinc-800">
                                        <option value="">{{ __('Own goal / unknown') }}</option>
                                        @foreach ($played as $p)
                                            <option value="{{ $p->id }}" @selected($goal->scorer_id === $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                    <select wire:change="setGoal({{ $goal->id }}, 'assist_id', $event.target.value)" @disabled(! $editable)
                                        class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-white/10 dark:bg-zinc-800">
                                        <option value="">{{ __('No assist') }}</option>
                                        @foreach ($played as $p)
                                            <option value="{{ $p->id }}" @selected($goal->assist_id === $p->id)>{{ __('Assist') }}: {{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <select wire:change="setGoal({{ $goal->id }}, 'scorer_id', $event.target.value)" @disabled(! $editable)
                                        class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-white/10 dark:bg-zinc-800">
                                        <option value="">{{ __('Opponent goal') }}</option>
                                        @foreach ($played as $p)
                                            <option value="{{ $p->id }}" @selected($goal->scorer_id === $p->id)>{{ __('Own goal') }}: {{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                @endif

                                @if ($editable)
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeGoal({{ $goal->id }})" :tooltip="__('Delete')" />
                                @endif
                            </div>
                        @empty
                            <flux:text class="text-sm">{{ __('No goals.') }}</flux:text>
                        @endforelse
                    </div>
                @endforeach
            </div>

            {{-- 3. Rozliczenie: wszystko liczone automatycznie z raportu --}}
            @if ($played->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading>{{ __('Settlement') }}</flux:heading>
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">{{ __('Player') }}</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Minutes') }}">{{ __('Min') }}</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Whole halves + part halves') }}">{{ __('Halves') }}</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Matchdays of lock') }}">{{ __('Lock') }}</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Goals') }}">G</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Assists') }}">A</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Own goals') }}">{{ __('OG') }}</th>
                                    <th class="px-2 py-2 text-center font-medium">{{ __('Defence') }}</th>
                                    <th class="px-2 py-2 text-center font-medium" title="{{ __('Goals while on the pitch and the player match points') }}">{{ __('Player match') }}</th>
                                    <th class="px-2 py-2 text-center font-medium">{{ __('Experience') }}</th>
                                    <th class="px-2 py-2 text-center font-medium">{{ __('Cards') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach ($played as $player)
                                    @php
                                        $r = $report[$player->id] ?? null;
                                    @endphp
                                    @if ($r)
                                        <tr wire:key="report-{{ $player->id }}">
                                            <td class="px-3 py-1.5">
                                                <span class="me-1 text-[10px] font-semibold uppercase text-zinc-500">{{ $player->position->short() }}</span>
                                                {{ $player->name }}
                                            </td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['minutes'] }}</td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['full_halves'] }}{{ $r['partial_halves'] ? ' + ' . $r['partial_halves'] : '' }}</td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['lock'] ?: '–' }}</td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['goals'] ?: '' }}</td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['assists'] ?: '' }}</td>
                                            <td class="px-2 py-1.5 text-center tabular-nums text-red-600">{{ $r['own_goals'] ?: '' }}</td>
                                            <td class="px-2 py-1.5 text-center">
                                                @if ($r['clean_sheet'] === true)
                                                    <flux:badge size="sm" color="green">{{ __('Clean sheet') }}</flux:badge>
                                                @elseif ($r['clean_sheet'] === false)
                                                    <flux:badge size="sm" color="red">{{ __('Conceded') }}</flux:badge>
                                                @endif
                                            </td>
                                            <td class="px-2 py-1.5 text-center tabular-nums">{{ $r['for'] }}:{{ $r['against'] }} <span class="text-zinc-500">({{ $r['match_points'] }})</span></td>
                                            <td class="px-2 py-1.5 text-center font-semibold tabular-nums">{{ $r['experience'] }}</td>
                                            <td class="px-2 py-1.5 text-center">
                                                <span class="inline-flex gap-0.5">
                                                    @if ($r['yellow'])
                                                        <span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-yellow-400"></span>
                                                    @endif
                                                    @if ($r['red'])
                                                        <span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-red-500"></span>
                                                    @endif
                                                </span>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <flux:text class="text-xs">
                        {{ __('Experience: :full per whole half, :part per part of a half, :start for the starting eleven, plus the player match (goals while on the pitch: win :win, draw :draw, loss :loss).', [
                            'full' => \App\Support\Designation::value('exp_full_half'),
                            'part' => \App\Support\Designation::value('exp_partial_half'),
                            'start' => \App\Support\Designation::value('exp_start'),
                            'win' => \App\Support\Designation::value('exp_win'),
                            'draw' => \App\Support\Designation::value('exp_draw'),
                            'loss' => \App\Support\Designation::value('exp_loss'),
                        ]) }}
                    </flux:text>
                </div>
            @endif
        </flux:card>
    @endif
</div>
