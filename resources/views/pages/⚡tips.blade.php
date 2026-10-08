<?php

use App\Actions\Tips\SaveTip;
use App\Enums\QuestionSide;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Support\PlayerCompetitions;
use App\Support\Players;
use App\Support\TipRules;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Typowanie gracza: wynik meczu Lecha + odpowiedzi tak/nie na pytania bonusowe (po jednym zestawie
 * 5+5 na każdy typ rozgrywek, w którym gracz gra). Zapis możliwy do godziny pierwszego gwizdka.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    #[Url(as: 'matchday', except: 0)]
    public int $number = 0;

    /** Zakładka: tip (typowanie), matchdays (kolejki), competitions (rozgrywki), stats (statystyki), history (historia), scalps (moje skalpy). */
    #[Url(as: 'tab', except: 'tip')]
    public string $tab = 'tip';

    /** Wpisany wynik (teksty, bo pole formularza może być puste). */
    public string $lech = '';
    public string $opponent = '';

    /** matchday_question_id => '1' | '0' | '' */
    public array $answers = [];

    public function mount(): void
    {
        if ($this->number < 1 || !$this->matchdays->firstWhere('number', $this->number)) {
            // Domyślnie pierwsza kolejka, na którą można jeszcze typować; w razie braku pierwsza.
            $open = $this->matchdays->first(fn($m) => $m->isOpenForTips());
            $this->number = $open?->number ?? ($this->matchdays->first()?->number ?? 0);
        }

        $this->loadTip();
    }

    public function render(): View
    {
        return $this->view()->title('LechTYPER');
    }

    public function updatedNumber(): void
    {
        unset($this->matchday, $this->tip, $this->isOpen, $this->sets, $this->scores);
        $this->loadTip();

        // Panel rywali po prawej pokazuje tę samą kolejkę.
        $this->dispatch('matchday-selected', number: $this->number);
    }

    /** Wczytuje zapisany typ i odpowiedzi gracza do pól formularza. */
    private function loadTip(): void
    {
        $this->lech = '';
        $this->opponent = '';
        $this->answers = [];

        $matchday = $this->matchday;

        if (!$matchday) {
            return;
        }

        $tip = Tip::where('matchday_id', $matchday->id)->where('user_id', auth()->id())->first();

        if ($tip) {
            $this->lech = (string) $tip->lech_goals;
            $this->opponent = (string) $tip->opponent_goals;
        }

        $saved = TipAnswer::where('matchday_id', $matchday->id)->where('user_id', auth()->id())
            ->pluck('answer', 'matchday_question_id')
            ->map(fn($a) => $a ? '1' : '0')
            ->all();

        // Domyślnie „brak odpowiedzi” ('') na każde pytanie z zestawów gracza.
        foreach ($this->sets as $set) {
            foreach ($set['sides'] as $items) {
                foreach ($items as $item) {
                    $this->answers[$item->id] = $saved[$item->id] ?? '';
                }
            }
        }
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function season(): ?Season
    {
        return Season::current();
    }

    /** Czy to konto w ogóle może grać w tym sezonie (rola, brak bana, jest na liście)? */
    #[Computed]
    public function canPlay(): bool
    {
        $user = auth()->user();

        return $this->season
            && Players::canPlay($user)
            && SeasonTeam::where('season_id', $this->season->id)->where('user_id', $user->id)->exists();
    }

    #[Computed]
    public function matchdays()
    {
        return $this->season
            ? Matchday::where('season_id', $this->season->id)->orderBy('number')->get()
            : collect();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdays->firstWhere('number', $this->number);
    }

    #[Computed]
    public function isOpen(): bool
    {
        return $this->matchday?->isOpenForTips() ?? false;
    }

    #[Computed]
    public function tip(): ?Tip
    {
        return $this->matchday
            ? Tip::where('matchday_id', $this->matchday->id)->where('user_id', auth()->id())->first()
            : null;
    }

    /**
     * Zestawy pytań gracza: lista [typ, [strona => miejsca]] tylko dla typów, w których gra
     * (bez pucharu i Ligi Legend po odpadnięciu).
     *
     * @return array<int, array{type: \App\Enums\CompetitionType, sides: array<string, \Illuminate\Support\Collection>}>
     */
    #[Computed]
    public function sets(): array
    {
        if (!$this->matchday || !$this->canPlay) {
            return [];
        }

        $types = PlayerCompetitions::types(auth()->id(), $this->season->id, $this->matchday->number);
        $all = MatchdayQuestion::with('question:id,text')
            ->where('matchday_id', $this->matchday->id)
            ->orderBy('position')
            ->get();

        $sets = [];
        foreach ($types as $type) {
            $own = $all->where('competition_type', $type);
            if ($own->isEmpty()) {
                continue;
            }
            $sets[] = [
                'type' => $type,
                'sides' => [
                    QuestionSide::Offensive->value => $own->where('side', QuestionSide::Offensive)->values(),
                    QuestionSide::Defensive->value => $own->where('side', QuestionSide::Defensive)->values(),
                ],
            ];
        }

        return $sets;
    }

    /* Zakładki ze statystykami (App\Support\PlayerStats). */

    #[Computed]
    public function myMatchdays(): array
    {
        return \App\Support\PlayerStats::matchdays(auth()->user(), $this->season);
    }

    #[Computed]
    public function myCompetitions(): array
    {
        return \App\Support\PlayerStats::competitions(auth()->user(), $this->season);
    }

    #[Computed]
    public function myStats(): array
    {
        return \App\Support\PlayerStats::season(auth()->user(), $this->season);
    }

    /** Ciekawostki: ulubione typy, najlepszy sezon, a dla premium typy w rozgrywkach i najtrudniejsze pytania. */
    #[Computed]
    public function myExtras(): array
    {
        $premium = \App\Support\Premium::isActive(auth()->user());

        return [
            'favourites' => \App\Support\PlayerStats::favourites(auth()->user()),
            'best_season' => \App\Support\PlayerStats::bestSeason(auth()->user()),
            'competitions' => $premium && $this->season ? \App\Support\PlayerStats::competitionFavourites($this->season) : [],
            'hardest' => $premium ? \App\Support\QuestionDifficulty::hardest(5) : collect(),
        ];
    }

    #[Computed]
    public function myHistory(): array
    {
        return \App\Support\PlayerStats::history(auth()->user());
    }

    public function openMatchday(int $number): void
    {
        $this->tab = 'tip';
        $this->number = $number;
        $this->updatedNumber();
    }

    /** Moje skalpy (premium): gracze z gorszym bilansem bezpośrednim, od najwyżej w rankingu. */
    #[Computed]
    public function myScalps(): array
    {
        return \App\Support\Premium::isActive(auth()->user()) ? \App\Support\PlayerStats::scalps(auth()->user()) : [];
    }

    /** Rozliczenie kolejki po wpisaniu wyniku: zestaw pytań (wartość typu) => TeamScore. */
    #[Computed]
    public function scores()
    {
        if (!$this->matchday || $this->matchday->status !== \App\Enums\MatchdayStatus::Played) {
            return collect();
        }

        $teamId = SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id');

        return TeamScore::where('matchday_id', $this->matchday->id)->where('season_team_id', $teamId)->get()
            ->keyBy(fn($score) => $score->question_set->value);
    }

    /** Ile pytań łącznie i ile już ma odpowiedź (do paska postępu). */
    public function progress(): array
    {
        $ids = collect($this->sets)->flatMap(fn($s) => collect($s['sides'])->flatten(1)->pluck('id'));
        $answered = $ids->filter(fn($id) => in_array($this->answers[$id] ?? '', ['0', '1'], true))->count();

        return [$answered, $ids->count()];
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    public function save(SaveTip $action): void
    {
        $matchday = $this->matchday;

        if (!$matchday) {
            return;
        }

        try {
            $action->handle(auth()->user(), $matchday, $this->lech, $this->opponent, $this->answers);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->tip, $this->sets);
        $this->loadTip();

        Flux::toast(variant: 'success', text: __('Tip saved.'));
    }
}; ?>
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    {{-- Nagłówek w barwach Lecha --}}
    <div class="relative overflow-hidden rounded-2xl bg-linear-to-br from-lech-700 via-lech-800 to-lech-950 px-6 py-6 text-white shadow-lg">
        <div class="pointer-events-none absolute -end-10 -top-10 size-48 rounded-full bg-white/10 blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-16 end-24 size-40 rounded-full bg-lech-400/20 blur-2xl"></div>
        <div class="relative space-y-1">
            <div class="text-xs font-semibold uppercase tracking-widest text-white/60">{{ $this->season?->title }}</div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">LechTYPER</h1>
            <p class="max-w-2xl text-sm text-white/80">
                {{ __('Tip the score of the Lech match and answer the bonus questions. You can change your tip until kickoff.') }}
            </p>
        </div>
    </div>

    @if (!$this->season)
        <flux:card>
            <flux:text>{{ __('There is no active season right now.') }}</flux:text>
        </flux:card>
    @elseif (!$this->canPlay)
        <flux:card class="space-y-1">
            <flux:heading>{{ __('You are not taking part in this season') }}</flux:heading>
            <flux:text>
                {{ __('Your account needs a role (without a ban) and a place on the team list. Contact the administrator.') }}
            </flux:text>
        </flux:card>
    @elseif ($this->matchdays->isEmpty() || !$this->matchday)
        <flux:card>
            <flux:text>{{ __('No matchdays yet.') }}</flux:text>
        </flux:card>
    @else
    @php
        $tabs = [
            'tip' => ['pencil-square', __('Tipping')],
            'matchdays' => ['calendar-days', __('My matchdays')],
            'competitions' => ['trophy', __('Competitions')],
            'stats' => ['chart-bar', __('Statistics')],
            'history' => ['clock', __('History and records')],
            'scalps' => ['fire', __('My scalps')],
        ];
        $missingCount = collect($this->myMatchdays)->where('open', true)->where('missing', true)->count();
        $played = $this->matchday->status === \App\Enums\MatchdayStatus::Played;
    @endphp

    {{-- Zakładki: segmentowy przełącznik --}}
    <nav class="flex gap-1 overflow-x-auto rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
        @foreach ($tabs as $key => [$icon, $label])
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="{{ $tab === $key ? 'bg-white text-lech-800 shadow-sm dark:bg-zinc-900 dark:text-lech-300' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }} flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition">
                <flux:icon :name="$icon" variant="micro" />
                {{ $label }}
                @if ($key === 'matchdays' && $missingCount > 0)
                    <span class="rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">{{ $missingCount }}</span>
                @endif
                @if ($key === 'scalps')
                    <flux:icon.sparkles variant="micro" class="text-purple-500" />
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'tip')
    {{-- Wybór kolejki: kafelki z kolorem stanu --}}
    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
        @foreach ($this->matchdays as $option)
            @php
                $state = $option->status === \App\Enums\MatchdayStatus::Played ? 'played' : ($option->isOpenForTips() ? 'open' : 'closed');
                $dot = ['played' => 'bg-lech-500', 'open' => 'bg-green-500', 'closed' => 'bg-zinc-400'][$state];
                $selected = $option->number === $this->number;
            @endphp
            <button type="button" wire:click="openMatchday({{ $option->number }})" wire:key="md-{{ $option->id }}"
                class="{{ $selected ? 'border-lech-600 bg-lech-50 ring-2 ring-lech-500/30 dark:border-lech-400 dark:bg-lech-500/10' : 'border-zinc-200 bg-white hover:border-lech-300 dark:border-zinc-700 dark:bg-zinc-900' }} flex w-28 shrink-0 flex-col items-start gap-0.5 rounded-xl border px-3 py-2 text-left transition">
                <span class="flex items-center gap-1.5 text-xs font-semibold text-zinc-500">
                    <span class="{{ $dot }} size-2 rounded-full"></span>
                    {{ __('Matchday :number', ['number' => $option->number]) }}
                </span>
                <span class="w-full truncate text-sm font-medium">{{ filled($option->opponent) ? $option->opponent : __('not set yet') }}</span>
                @if ($option->status === \App\Enums\MatchdayStatus::Played)
                    <span class="text-xs font-semibold tabular-nums text-lech-700 dark:text-lech-300">{{ $option->lech_goals }}:{{ $option->opponent_goals }}</span>
                @elseif ($option->kickoff_at)
                    <span class="text-xs text-zinc-500">{{ $option->kickoff_at->translatedFormat('j M, H:i') }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Tablica meczu i typ wyniku --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <div class="text-sm text-zinc-500">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Matchday :number', ['number' => $this->matchday->number]) }}</span>
                &middot; {{ $this->matchday->kickoff_at ? $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') : __('not set yet') }}
                @if ($this->matchday->competition) &middot; {{ $this->matchday->competition }} @endif
            </div>
            @if ($this->isOpen)
                <flux:badge color="green" icon="lock-open">{{ __('Open') }}</flux:badge>
            @elseif ($played)
                <flux:badge color="blue" icon="check-circle">{{ __('Settled') }}</flux:badge>
            @else
                <flux:badge color="zinc" icon="lock-closed">{{ __('Closed') }}</flux:badge>
            @endif
        </div>

        <div class="space-y-4 px-5 py-6">
            @if (!$this->matchday->isFilled())
                <flux:text>{{ __('The opponent and date are not set yet, so tipping is not open.') }}</flux:text>
            @else
                {{-- Wynik: zawsze z perspektywy Lecha (Lech : rywal), bez względu na gospodarza. --}}
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6">
                    <div class="flex flex-col items-end gap-2 text-right">
                        <span class="text-sm font-semibold sm:text-base">Lech Poznań</span>
                        <input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="lech" @disabled(! $this->isOpen)
                            aria-label="Lech Poznań"
                            class="h-16 w-20 rounded-xl border border-zinc-300 bg-zinc-50 text-center text-3xl font-bold tabular-nums shadow-inner focus:border-lech-500 focus:outline-none focus:ring-4 focus:ring-lech-500/20 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                    <span class="pt-7 text-3xl font-bold text-zinc-400">:</span>
                    <div class="flex flex-col items-start gap-2">
                        <span class="truncate text-sm font-semibold sm:text-base">{{ $this->matchday->opponent }}</span>
                        <input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="opponent" @disabled(! $this->isOpen)
                            aria-label="{{ $this->matchday->opponent }}"
                            class="h-16 w-20 rounded-xl border border-zinc-300 bg-zinc-50 text-center text-3xl font-bold tabular-nums shadow-inner focus:border-lech-500 focus:outline-none focus:ring-4 focus:ring-lech-500/20 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                </div>

                @if ($played)
                    <div class="flex flex-wrap items-center justify-center gap-2 text-sm">
                        <span class="rounded-full bg-lech-50 px-3 py-1 font-semibold text-lech-800 ring-1 ring-lech-200 dark:bg-lech-500/10 dark:text-lech-200 dark:ring-lech-500/30">
                            {{ __('Final score: :score.', ['score' => 'Lech ' . $this->matchday->lech_goals . ':' . $this->matchday->opponent_goals . ' ' . $this->matchday->opponent]) }}
                        </span>
                        @if ($first = $this->scores->first())
                            <span class="rounded-full bg-amber-100 px-3 py-1 font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                {{ __('Points for the tip: :points of 3.', ['points' => $first->tip_points]) }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="text-center">
                    @if ($this->tip && $this->tip->is_default)
                        <flux:text size="sm">{{ __('Your premium default tip was used: :score.', ['score' => $this->tip->score()]) }}</flux:text>
                    @elseif ($this->tip)
                        <flux:text size="sm">
                            {{ __('Saved tip: :score, last change :time.', ['score' => $this->tip->score(), 'time' => $this->tip->saved_at->translatedFormat('j F, H:i:s')]) }}
                        </flux:text>
                    @else
                        <flux:text size="sm">{{ __('You have not tipped this matchday yet.') }}</flux:text>
                        @if (\App\Support\Premium::isActive(auth()->user()))
                            @php
                                $defaultTip = \App\Support\Premium::defaultTip(auth()->user());
                            @endphp
                            <flux:text size="sm" class="mt-1 flex flex-wrap items-center justify-center gap-1">
                                <x-premium-badge />
                                {{ __('Premium: without a tip your default tip :score will be used.', ['score' => $defaultTip[0] . ':' . $defaultTip[1]]) }}
                                <flux:link :href="route('support')" wire:navigate>{{ __('Change') }}</flux:link>
                            </flux:text>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($this->matchday->isFilled())
    @if (count($this->sets) === 0)
        <flux:card>
            <flux:text>{{ __('The bonus questions for this matchday are not ready yet.') }}</flux:text>
        </flux:card>
    @else
    @php
        $progress = $this->progress();
        $percent = $progress[1] > 0 ? round($progress[0] / $progress[1] * 100) : 0;
    @endphp

    @foreach ($this->sets as $set)
    <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="set-{{ $set['type']->value }}">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800/40">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <flux:icon.question-mark-circle variant="mini" class="text-lech-600 dark:text-lech-300" />
                {{ $set['type']->questionSetLabel() }}
            </h2>

            @if ($score = $this->scores->get($set['type']->value))
                <div class="flex flex-wrap gap-2">
                    <flux:badge color="green">
                        {{ __('Offence: :points', ['points' => $score->offense]) }}{{ $score->offense_zeroed ? ' (' . __('bonus zeroed') . ')' : '' }}
                    </flux:badge>
                    <flux:badge color="blue">
                        {{ __('Defence: :points', ['points' => $score->defense_bonus]) }}{{ $score->defense_zeroed ? ' (' . __('bonus zeroed') . ')' : '' }}
                    </flux:badge>
                </div>
            @endif
        </div>

        <div class="grid gap-6 p-5 md:grid-cols-2">
            @foreach ($set['sides'] as $sideValue => $items)
            @php
                $side = \App\Enums\QuestionSide::from($sideValue);
            @endphp
            <div class="space-y-3">
                <flux:badge :color="$side->color()">{{ $side->label() }}</flux:badge>

                @foreach ($items as $item)
                    <div class="space-y-2 rounded-xl border border-zinc-200 p-3 transition hover:border-lech-300 dark:border-zinc-700 dark:hover:border-lech-500/50"
                        wire:key="q-{{ $item->id }}">
                        <div class="flex gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-100">
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs text-zinc-500 dark:bg-zinc-800">{{ $item->position }}</span>
                            <span>{{ $item->question->text }}</span>
                        </div>
                        @if ($item->correct_answer === null && $played)
                            <flux:text size="sm" class="text-amber-600 dark:text-amber-400">{{ __('Question cancelled: answers do not count.') }}</flux:text>
                        @endif
                        {{-- Po rozliczeniu: wybrana odpowiedź zielona (dobra) albo czerwona (zła). --}}
                        <x-answer-toggle :model="'answers.' . $item->id" :value="$answers[$item->id] ?? ''"
                            :disabled="! $this->isOpen"
                            :result="$played ? $item->correct_answer : null" />
                    </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </section>
    @endforeach

    {{-- Pasek zapisu: postęp odpowiedzi i przycisk, przyklejony do dołu ekranu --}}
    <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-4 rounded-2xl border border-zinc-200 bg-white/90 px-5 py-3 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90">
        <div class="min-w-48 flex-1 space-y-1">
            <div class="text-xs text-zinc-500">
                {{ __('Answered :done of :total questions. Unanswered questions score nothing.', ['done' => $progress[0], 'total' => $progress[1]]) }}
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-linear-to-r from-lech-500 to-lech-700 transition-all" style="width: {{ $percent }}%"></div>
            </div>
        </div>
        @if ($this->isOpen)
            <flux:button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('Save tip') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </flux:button>
        @else
            <flux:text size="sm">{{ __('Tipping for this matchday is closed.') }}</flux:text>
        @endif
    </div>
    @endif
    @endif
    @else
        @include('partials.tips-tabs')
    @endif
    @endif
</div>
