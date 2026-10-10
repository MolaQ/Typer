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
use Livewire\Component;

/**
 * Typowanie gracza: wynik meczu Lecha + odpowiedzi tak/nie na pytania bonusowe (po jednym zestawie
 * 5+5 na każdy typ rozgrywek, w którym gracz gra). Zapis możliwy do godziny pierwszego gwizdka.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    public int $number = 0;

    /** Zakładka: tip (typowanie), matchdays (kolejki), competitions (rozgrywki), stats (statystyki), history (historia), scalps (moje skalpy). */
    public string $tab = 'tip';

    /** Zakładki w adresie po polsku: /tips/kolejka-3/statystyki (typowanie bez części zakładki). */
    private const TAB_SLUGS = [
        'tip' => 'typ', 'matchdays' => 'kolejki', 'competitions' => 'rozgrywki',
        'stats' => 'statystyki', 'history' => 'historia', 'scalps' => 'skalpy',
    ];

    /** Wpisany wynik (teksty, bo pole formularza może być puste). */
    public string $lech = '';
    public string $opponent = '';

    /** matchday_question_id => '1' | '0' | '' */
    public array $answers = [];

    /** Godzina ostatniego automatycznego zapisu (do napisu „Zapisano” w pasku na dole). */
    public string $savedTime = '';

    /** Przyjazny adres albo stary z parametrami (?matchday=3&tab=stats), np. z zapisanych linków. */
    public function mount(?string $matchday_slug = null, ?string $tab_slug = null): void
    {
        $this->number = (int) str_replace('kolejka-', '', $matchday_slug ?? (string) request()->query('matchday', '0'));
        $tab = $tab_slug !== null ? array_search($tab_slug, self::TAB_SLUGS, true) : request()->query('tab', 'tip');
        $this->tab = is_string($tab) && isset(self::TAB_SLUGS[$tab]) ? $tab : 'tip';

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

    /** Adres bieżącej kolejki i zakładki. */
    private function pageUrl(): string
    {
        $params = ['matchday_slug' => 'kolejka-'.$this->number];

        if ($this->tab !== 'tip') {
            $params['tab_slug'] = self::TAB_SLUGS[$this->tab] ?? 'typ';
        }

        return $this->number > 0 ? route('tips', $params) : route('tips');
    }

    /** Podmienia adres w pasku przeglądarki bez przeładowania strony. */
    private function syncUrl(): void
    {
        $this->js('history.replaceState(history.state, "", '.json_encode($this->pageUrl()).')');
    }

    public function updatedTab(): void
    {
        $this->syncUrl();
    }

    public function updatedNumber(): void
    {
        $this->syncUrl();

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
        $this->savedTime = '';

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

    /**
     * Kafelki kolejek: liczba typów graczy i odsetek trafionych rozstrzygnięć (po wyniku) dla każdej kolejki sezonu.
     *
     * @return array<int, array{tips: int, outcome: int|null}>
     */
    #[Computed]
    public function matchdayTipStats(): array
    {
        if (!$this->season) {
            return [];
        }

        $rows = Tip::query()
            ->join('matchdays', 'matchdays.id', '=', 'tips.matchday_id')
            ->where('matchdays.season_id', $this->season->id)
            ->groupBy('tips.matchday_id')
            ->selectRaw('tips.matchday_id, COUNT(*) AS tips_count, SUM(CASE
                WHEN tips.lech_goals > tips.opponent_goals AND matchdays.lech_goals > matchdays.opponent_goals THEN 1
                WHEN tips.lech_goals = tips.opponent_goals AND matchdays.lech_goals = matchdays.opponent_goals THEN 1
                WHEN tips.lech_goals < tips.opponent_goals AND matchdays.lech_goals < matchdays.opponent_goals THEN 1
                ELSE 0 END) AS outcome_hits')
            ->toBase()
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row->matchday_id] = ['tips' => (int) $row->tips_count, 'hits' => (int) $row->outcome_hits];
        }

        $out = [];
        foreach ($this->matchdays as $matchday) {
            $count = $stats[$matchday->id]['tips'] ?? 0;
            $played = $matchday->status === \App\Enums\MatchdayStatus::Played && $matchday->lech_goals !== null;
            $out[$matchday->id] = [
                'tips' => $count,
                'outcome' => $played && $count > 0 ? (int) round(100 * ($stats[$matchday->id]['hits'] ?? 0) / $count) : null,
            ];
        }

        return $out;
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

    /*
     * Zapis automatyczny (bez przycisku): Livewire woła metody updated<Własność>() po każdej zmianie pola
     * powiązanego przez wire:model albo $set (przyciski Tak / Brak odpowiedzi / Nie w x-answer-toggle).
     */

    /** Zmiana liczby goli Lecha: poprawiamy wartość i zapisujemy typ. */
    public function updatedLech(): void
    {
        $this->autosave();
    }

    /** Zmiana liczby goli rywala: poprawiamy wartość i zapisujemy typ. */
    public function updatedOpponent(): void
    {
        $this->autosave();
    }

    /** Odpowiedź na pytanie bonusowe: zapis od razu (bez wpisanego wyniku typ dostaje 0:0). */
    public function updatedAnswers(): void
    {
        $this->autosave();
    }

    /**
     * Gole z formularza do zapisu: puste pole -> 0 (typ 0:0, gdy gracz odpowiada bez wyniku),
     * liczba ujemna -> wartość bezwzględna (abs), wszystko, co dalej nie spełnia TipRules::goals()
     * (tekst, ułamek, więcej niż TipRules::MAX_GOALS), -> 0.
     */
    private static function normalizeGoals(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '0';
        }

        if (preg_match('/^-\d+$/', $value)) {
            $value = (string) abs((int) $value);
        }

        return (string) (\App\Support\TipRules::goals($value) ?? 0);
    }

    /** Zapisuje typ i odpowiedzi przez SaveTip (te same zasady co wcześniej przycisk „Zapisz typ”). */
    private function autosave(): void
    {
        $matchday = $this->matchday;

        if (!$matchday || !$this->isOpen) {
            return;
        }

        $this->lech = self::normalizeGoals($this->lech);
        $this->opponent = self::normalizeGoals($this->opponent);

        try {
            app(SaveTip::class)->handle(auth()->user(), $matchday, $this->lech, $this->opponent, $this->answers);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        // Odświeżamy wyliczone dane (typ, zestawy), ale nie pola formularza, żeby nie przeskakiwały pod kursorem.
        unset($this->tip, $this->sets);
        $this->savedTime = now()->format('H:i:s');
    }
}; ?>
<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    {{-- Baner jak na stronie głównej --}}
    <x-page-banner :eyebrow="$this->season?->title ?? 'LechTYPER'" title="LechTYPER"
        :subtitle="__('Tip the score of the Lech match and answer the bonus questions. You can change your tip until kickoff.')" />

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
    {{--
        Wybór kolejki: siatka 3 x 3. Kolejki z wynikiem w kolorze meczu Lecha (zielony wygrana, żółty remis,
        czerwony porażka), bez wyniku szare z ciemnoszarą obwódką. Kropka: zielona = typowanie otwarte.
    --}}
    <div class="grid grid-cols-3 gap-2">
        @foreach ($this->matchdays as $option)
            @php
                $hasResult = $option->status === \App\Enums\MatchdayStatus::Played && $option->lech_goals !== null;
                $tileClass = !$hasResult
                    ? 'border-zinc-500 bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'
                    : match ($option->lech_goals <=> $option->opponent_goals) {
                        1 => 'border-green-500 bg-green-100 text-green-950',
                        0 => 'border-yellow-500 bg-yellow-100 text-yellow-950',
                        default => 'border-red-500 bg-red-100 text-red-950',
                    };
                $selected = $option->number === $this->number;
            @endphp
            <button type="button" wire:click="openMatchday({{ $option->number }})" wire:key="md-{{ $option->id }}"
                class="{{ $tileClass }} {{ $selected ? 'ring-2 ring-lech-600 ring-offset-2 dark:ring-lech-400 dark:ring-offset-zinc-900' : 'hover:shadow-md' }} flex min-w-0 flex-col items-start gap-0.5 rounded-xl border px-3 py-2 text-left transition">
                <span class="flex w-full items-center justify-between gap-1.5 text-xs font-semibold opacity-80">
                    <span>{{ __('Matchday :number', ['number' => $option->number]) }}</span>
                    <span class="flex items-center gap-1.5">
                        @if (!$hasResult && $option->kickoff_at)
                            <span class="font-normal">{{ $option->kickoff_at->translatedFormat('j M, H:i') }}</span>
                        @endif
                        @if (!$hasResult && $option->isOpenForTips())
                            <span class="size-2 rounded-full bg-green-500" title="{{ __('Tipping open') }}"></span>
                        @endif
                    </span>
                </span>
                {{-- Rywal Lecha i wynik w jednej linii; bez przypisanego meczu informacja i „?:?” --}}
                <span class="flex w-full items-baseline justify-between gap-2">
                    <span class="{{ filled($option->opponent) ? 'font-bold' : 'italic opacity-70' }} min-w-0 truncate text-base">{{ filled($option->opponent) ? $option->opponent : __('Match not assigned yet') }}</span>
                    <span class="shrink-0 text-xl font-black tabular-nums">{{ $hasResult ? $option->lech_goals . ':' . $option->opponent_goals : '?:?' }}</span>
                </span>
                {{-- Liczba typów graczy i odsetek trafionych rozstrzygnięć --}}
                @php
                    $tipStat = $this->matchdayTipStats[$option->id] ?? ['tips' => 0, 'outcome' => null];
                @endphp
                <span class="flex w-full items-center justify-between gap-2 text-[11px] font-semibold opacity-80">
                    <span class="flex items-center gap-1" title="{{ __('Tips') }}"><flux:icon.users variant="micro" />{{ $tipStat['tips'] }}</span>
                    @if ($tipStat['outcome'] !== null)
                        <span class="flex items-center gap-1" title="{{ __('Correct outcomes') }}"><flux:icon.check-circle variant="micro" />{{ $tipStat['outcome'] }}%</span>
                    @endif
                </span>
            </button>
        @endforeach
    </div>

    {{-- Tablica meczu i typ wyniku --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <div class="text-sm text-zinc-500">
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Matchday :number', ['number' => $this->matchday->number]) }}</span>
                &middot; {{ $this->matchday->kickoff_at ? $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') : __('not set yet') }}
                @if ($this->matchday->competitionLabel()) &middot; {{ $this->matchday->competitionLabel() }} @endif
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
                        <input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.live.debounce.500ms="lech" @disabled(! $this->isOpen)
                            aria-label="Lech Poznań"
                            class="h-16 w-20 rounded-xl border border-zinc-300 bg-zinc-50 text-center text-3xl font-bold tabular-nums shadow-inner focus:border-lech-500 focus:outline-none focus:ring-4 focus:ring-lech-500/20 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                    <span class="pt-7 text-3xl font-bold text-zinc-400">:</span>
                    <div class="flex flex-col items-start gap-2">
                        <span class="truncate text-sm font-semibold sm:text-base">{{ $this->matchday->opponent }}</span>
                        <input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model.live.debounce.500ms="opponent" @disabled(! $this->isOpen)
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

    {{-- Pasek na dole ekranu: postęp odpowiedzi i stan zapisu automatycznego (bez przycisku) --}}
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
            <div class="flex items-center gap-2 text-sm">
                <span wire:loading class="text-zinc-500">{{ __('Saving...') }}</span>
                <span wire:loading.remove class="flex items-center gap-1 font-medium text-green-700 dark:text-green-400">
                    @if ($savedTime !== '')
                        <flux:icon.check-circle variant="mini" /> {{ __('Saved at :time', ['time' => $savedTime]) }}
                    @else
                        <span class="text-zinc-500">{{ __('Changes are saved automatically.') }}</span>
                    @endif
                </span>
            </div>
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
