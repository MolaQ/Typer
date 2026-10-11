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

    /** Zakładka: tip (typowanie), matchdays (kolejki), competitions (rozgrywki), stats (statystyki), history (historia), scalps (moje skalpy), blacklist (czarna lista). */
    public string $tab = 'tip';

    /** Zakładki w adresie po polsku: /tips/kolejka-3/statystyki (typowanie bez części zakładki). */
    private const TAB_SLUGS = [
        'tip' => 'typ', 'matchdays' => 'kolejki', 'competitions' => 'rozgrywki',
        'stats' => 'statystyki', 'history' => 'historia', 'scalps' => 'skalpy', 'blacklist' => 'czarna-lista',
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
        $this->ensureTip();
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
        $this->ensureTip();
    }

    /**
     * Pierwsze otwarcie zakładki Typowanie dla otwartej kolejki zapisuje typ 0:0 (bez odpowiedzi).
     * Gracz zmienia go przyciskami, a numer zapisu (remisy w pucharach) liczy się od ostatniej zmiany.
     */
    private function ensureTip(): void
    {
        if ($this->tab !== 'tip' || !$this->canPlay || !$this->isOpen || $this->tip !== null || !$this->matchday?->isFilled()) {
            return;
        }

        $this->lech = '0';
        $this->opponent = '0';
        $this->autosave();
    }

    public function updatedNumber(): void
    {
        $this->syncUrl();

        unset($this->matchday, $this->tip, $this->isOpen, $this->sets, $this->scores, $this->crowd, $this->crowdAnswers);
        $this->loadTip();
        $this->ensureTip();

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

    /**
     * „Twoje ligowe mecze” pod typowaniem: liga zespołu (1-10 albo podwórkowa) w sezonie i jego mecze w kolejkach,
     * w formacie jak „Twoja droga w pucharze” (partials/my-matches-path).
     *
     * @return array{competition: \App\Models\Competition, entry_id: int, path: array<int, list<\App\Models\Fixture>>, status: string}|null
     */
    #[Computed]
    public function myLeague(): ?array
    {
        $teamId = $this->season ? SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id') : null;

        $entry = $teamId ? \App\Models\CompetitionEntry::with('competition')
            ->where('season_team_id', $teamId)
            ->whereHas('competition', fn ($q) => $q->whereIn('type', [\App\Enums\CompetitionType::League->value, \App\Enums\CompetitionType::Swiss->value]))
            ->first() : null;

        if (!$entry || !$entry->competition) {
            return null;
        }

        $path = [];
        foreach (\App\Models\Fixture::with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $entry->competition_id)
            ->where(fn ($q) => $q->where('home_entry_id', $entry->id)->orWhere('away_entry_id', $entry->id))
            ->orderBy('round')
            ->get() as $fixture) {
            $path[$fixture->round][] = $fixture;
        }

        $table = \App\Support\Standings::for($entry->competition)->values();
        $index = $table->search(fn ($row) => $row['entry_id'] === $entry->id);

        return [
            'competition' => $entry->competition,
            'entry_id' => (int) $entry->id,
            'path' => $path,
            'status' => $index === false ? '' : __(':place. place of :count', ['place' => $index + 1, 'count' => $table->count()]),
        ];
    }

    /** Czarna lista (premium): rywale, z którymi gracz ma ujemny bilans bezpośredni. */
    #[Computed]
    public function myBlacklist(): array
    {
        return \App\Support\Premium::isActive(auth()->user()) ? \App\Support\PlayerStats::scalps(auth()->user(), blacklist: true) : [];
    }

    /** Czy gracz ma aktywne premium (statystyki typów innych graczy). */
    #[Computed]
    public function premium(): bool
    {
        return \App\Support\Premium::isActive(auth()->user());
    }

    /** Premium: jak typują inni gracze (rozkład Lech / remis / rywal, najczęstsze typy), także przed pierwszym gwizdkiem. */
    #[Computed]
    public function crowd(): ?array
    {
        return $this->premium && $this->matchday ? \App\Support\MatchdayInsights::tips($this->matchday) : null;
    }

    /**
     * Premium: odpowiedzi innych graczy na pytania kolejki (id pytania => [odpowiedzi, „tak”]).
     *
     * @return array<int, array{answers: int, yes: int}>
     */
    #[Computed]
    public function crowdAnswers(): array
    {
        return $this->premium && $this->matchday ? \App\Support\MatchdayInsights::answerShares($this->matchday) : [];
    }

    /**
     * Barwy kart zestawów pytań (klucz CompetitionColors): liga gracza, Puchar Polski, jego puchar europejski, Liga Legend.
     *
     * @return array<string, string> wartość typu zestawu => klucz barw
     */
    #[Computed]
    public function setColors(): array
    {
        $colors = [
            \App\Enums\CompetitionType::League->value => 'league_1',
            \App\Enums\CompetitionType::Cup->value => 'cup',
            \App\Enums\CompetitionType::Champions->value => 'champions',
            \App\Enums\CompetitionType::Legends->value => 'legends',
        ];
        $teamId = $this->season ? SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id') : null;

        if ($teamId) {
            foreach (\App\Models\CompetitionEntry::with('competition')->where('season_team_id', $teamId)->get() as $entry) {
                if ($entry->competition) {
                    $colors[$entry->competition->type->questionSet()->value] = $entry->competition->trophyKey();
                }
            }
        }

        return $colors;
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

    /** Przyciski + / − pod liczbą goli Lecha ('lech') albo rywala ('opponent'). */
    public function bump(string $side, int $delta): void
    {
        if (!in_array($side, ['lech', 'opponent'], true) || !$this->isOpen) {
            return;
        }

        $this->{$side} = (string) max(0, min(TipRules::MAX_GOALS, (int) $this->{$side} + ($delta > 0 ? 1 : -1)));
        $this->autosave();
    }

    /** Szybki wynik jednym kliknięciem (np. 2:1). */
    public function pick(int $lech, int $opponent): void
    {
        if (!$this->isOpen) {
            return;
        }

        $this->lech = (string) max(0, min(TipRules::MAX_GOALS, $lech));
        $this->opponent = (string) max(0, min(TipRules::MAX_GOALS, $opponent));
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

        // Odświeżamy wyliczone dane (typ, zestawy, statystyki premium), ale nie pola formularza.
        unset($this->tip, $this->sets, $this->crowd, $this->crowdAnswers);
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
            'blacklist' => ['no-symbol', __('Blacklist')],
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
                @if (in_array($key, ['scalps', 'blacklist'], true))
                    <flux:icon.sparkles variant="micro" class="text-purple-500" />
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'tip')
    @if ($league = $this->myLeague)
        {{--
            Wybór kolejki: „Twoje ligowe mecze” (jak „Twoja droga w pucharze”). Etykieta kolejki wybiera ją do typowania,
            pod nią mecz Lecha (wynik albo termin), karta meczu otwiera okno pojedynku.
        --}}
        @php
            $matchdaysByNumber = $this->matchdays->keyBy('number');
        @endphp
        @include('partials.my-matches-path', [
            'title' => __('Your league matches') . ' · ' . ($league['competition']->name ?: $league['competition']->type->label()),
            'status' => $league['status'],
            'rounds' => \App\Models\Matchday::PER_SEASON,
            'perRound' => 1,
            'path' => $league['path'],
            'entryId' => $league['entry_id'],
            'eliminated' => null,
            'stageLabel' => fn (int $stage) => __('Matchday :number', ['number' => $stage]) . (($matchdaysByNumber[$stage] ?? null)?->isOpenForTips() ? ' •' : ''),
            'stageAction' => 'openMatchday',
            'stageExtra' => fn (int $stage) => match (true) {
                !isset($matchdaysByNumber[$stage]) || blank($matchdaysByNumber[$stage]->opponent) => __('Match not assigned yet'),
                $matchdaysByNumber[$stage]->lech_goals !== null => 'Lech ' . $matchdaysByNumber[$stage]->lech_goals . ':' . $matchdaysByNumber[$stage]->opponent_goals . ' ' . $matchdaysByNumber[$stage]->opponent,
                default => $matchdaysByNumber[$stage]->opponent . ($matchdaysByNumber[$stage]->kickoff_at ? ', ' . $matchdaysByNumber[$stage]->kickoff_at->translatedFormat('j M H:i') : ''),
            },
            'current' => $this->number,
        ])
    @else
    {{--
        Wybór kolejki (gdy gracz nie ma ligi): siatka 3 x 3. Kolejki z wynikiem w kolorze meczu Lecha (zielony wygrana, żółty remis,
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
    @endif

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
                {{--
                    Tablica wyniku jak na stadionie (zawsze Lech : rywal). Bez wpisywania: przyciski + / − nad i pod cyfrą
                    oraz szybkie wyniki jednym kliknięciem. Każde kliknięcie zapisuje typ.
                --}}
                @php
                    $boardBtn = 'flex h-11 w-20 items-center justify-center rounded-xl border border-zinc-300 bg-white text-2xl font-black text-lech-800 shadow-sm transition active:scale-95 hover:bg-lech-50 disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-800 dark:text-lech-200 dark:hover:bg-zinc-700';
                    $quickScores = [[0, 0], [1, 0], [2, 0], [2, 1], [3, 1], [1, 1], [2, 2], [0, 1], [1, 2]];
                @endphp
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6">
                    @foreach (['lech' => 'Lech Poznań', 'opponent' => $this->matchday->opponent] as $boardSide => $boardTeam)
                        @if ($boardSide === 'opponent')
                            <span class="pt-6 text-4xl font-black text-zinc-400">:</span>
                        @endif
                        <div class="flex flex-col items-center gap-2" wire:key="board-{{ $boardSide }}">
                            <span class="w-full truncate text-center text-sm font-bold sm:text-base">{{ $boardTeam }}</span>
                            <button type="button" wire:click="bump('{{ $boardSide }}', 1)" @disabled(!$this->isOpen) class="{{ $boardBtn }}" aria-label="{{ __('One goal more') }}">+</button>
                            <span class="lech-banner flex h-20 w-20 items-center justify-center rounded-2xl text-5xl font-black tabular-nums shadow-inner">{{ $this->{$boardSide} === '' ? 0 : $this->{$boardSide} }}</span>
                            <button type="button" wire:click="bump('{{ $boardSide }}', -1)" @disabled(!$this->isOpen || (int) $this->{$boardSide} === 0) class="{{ $boardBtn }}" aria-label="{{ __('One goal less') }}">−</button>
                        </div>
                    @endforeach
                </div>

                @if ($this->isOpen)
                    <div class="flex flex-wrap justify-center gap-1.5">
                        @foreach ($quickScores as [$quickLech, $quickOpp])
                            @php
                                $quickOn = (int) $this->lech === $quickLech && (int) $this->opponent === $quickOpp;
                            @endphp
                            <button type="button" wire:click="pick({{ $quickLech }}, {{ $quickOpp }})"
                                class="{{ $quickOn ? 'border-lech-600 bg-lech-700 text-white' : 'border-zinc-300 text-zinc-700 hover:border-lech-400 dark:border-zinc-600 dark:text-zinc-200' }} rounded-full border px-3 py-1 text-sm font-bold tabular-nums transition">
                                {{ $quickLech }}:{{ $quickOpp }}
                            </button>
                        @endforeach
                    </div>
                @endif

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
                    @endif
                </div>

                {{-- Premium: jak typują inni gracze (na żywo, także przed pierwszym gwizdkiem) --}}
                @if ($this->premium && $this->crowd)
                    @php
                        $crowd = $this->crowd;
                        $crowdPct = fn (int $part) => $crowd['count'] > 0 ? (int) round($part / $crowd['count'] * 100) : 0;
                    @endphp
                    <div class="space-y-2 rounded-xl border border-purple-200 bg-purple-50/60 p-3 dark:border-purple-500/30 dark:bg-purple-500/10">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-purple-700 dark:text-purple-300">
                            <span class="flex items-center gap-1"><flux:icon.sparkles variant="micro" />{{ __('How the others tip') }}</span>
                            <span class="tabular-nums">{{ trans_choice(':count tip|:count tips', $crowd['count'], ['count' => $crowd['count']]) }}</span>
                        </div>
                        @if ($crowd['count'] > 0)
                            <div class="flex h-6 overflow-hidden rounded-full text-[11px] font-bold text-white">
                                @foreach ([['lech', 'bg-lech-700', 'Lech'], ['draw', 'bg-zinc-400', __('Draw result')], ['opponent', 'bg-zinc-700', $this->matchday->opponent]] as [$crowdPart, $crowdColor, $crowdLabel])
                                    @if ($crowd[$crowdPart] > 0)
                                        <span class="{{ $crowdColor }} flex items-center justify-center" style="width: {{ $crowdPct($crowd[$crowdPart]) }}%" title="{{ $crowdLabel }}: {{ $crowd[$crowdPart] }}">{{ $crowdPct($crowd[$crowdPart]) }}%</span>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                                <span>Lech {{ $crowdPct($crowd['lech']) }}% · {{ __('Draw result') }} {{ $crowdPct($crowd['draw']) }}% · {{ $this->matchday->opponent }} {{ $crowdPct($crowd['opponent']) }}%</span>
                                <span class="flex flex-wrap gap-1">
                                    {{ __('Most popular') }}:
                                    @foreach ($crowd['popular'] as $popular)
                                        <span class="rounded bg-white px-1.5 font-bold tabular-nums dark:bg-zinc-800">{{ $popular['score'] }} ×{{ $popular['count'] }}</span>
                                    @endforeach
                                </span>
                            </div>
                        @endif
                    </div>
                @elseif (!$this->premium)
                    <x-premium-lock :title="__('How the others tip')"
                        :text="__('With premium you see live how the other players tip this match and how they answer each question.')" />
                @endif
            @endif
        </div>
    </div>

    @if ($this->matchday->isFilled())
    @if (count($this->sets) === 0)
        <flux:card>
            <flux:text>{{ __('The bonus questions for this matchday are not ready yet.') }}</flux:text>
        </flux:card>
    @else

    {{--
        Zestawy pytań: najwyżej cztery, po dwa w wierszu (na telefonie jeden pod drugim). Karta w barwach rozgrywek,
        w niej zakładki Atak / Obrona z licznikami, pytanie w jednym wierszu i trzy przyciski ✓ / – / ✗.
        Premium widzi pod pytaniem odsetek odpowiedzi „tak” innych graczy.
    --}}
    <div class="grid gap-4 lg:grid-cols-2">
    @foreach ($this->sets as $set)
        @php
            $setKey = $this->setColors[$set['type']->value] ?? 'league_1';
            $setIds = collect($set['sides'])->flatten(1)->pluck('id');
            $setDone = $setIds->filter(fn ($id) => in_array($answers[$id] ?? '', ['0', '1'], true))->count();
            $setScore = $this->scores->get($set['type']->value);
        @endphp
        <section class="overflow-hidden rounded-2xl p-1 shadow-md" wire:key="set-{{ $set['type']->value }}" x-data="{ side: 'offensive' }"
            style="{{ \App\Support\CompetitionColors::style($setKey) }} background-image: linear-gradient(140deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink); border: 2px solid var(--c-accent);">
            <header class="flex flex-wrap items-center justify-between gap-2 px-3 pb-2 pt-2">
                <h2 class="text-sm font-black uppercase tracking-[0.15em]">{{ $set['type']->questionSetLabel() }}</h2>
                @if ($setScore)
                    <span class="flex gap-1.5 text-xs font-bold">
                        <span class="comp-badge rounded-md px-2 py-0.5">{{ __('Offence: :points', ['points' => $setScore->offense]) }}</span>
                        <span class="comp-badge rounded-md px-2 py-0.5">{{ __('Defence: :points', ['points' => $setScore->defense_bonus]) }}</span>
                    </span>
                @else
                    <span class="comp-accent text-xs font-bold tabular-nums">{{ $setDone }}/{{ $setIds->count() }}</span>
                @endif
            </header>

            {{-- Zakładki Atak / Obrona z licznikiem odpowiedzi --}}
            <div class="flex gap-1 px-2 pb-2">
                @foreach ($set['sides'] as $sideValue => $items)
                    @php
                        $sideDone = $items->filter(fn ($item) => in_array($answers[$item->id] ?? '', ['0', '1'], true))->count();
                    @endphp
                    <button type="button" x-on:click="side = '{{ $sideValue }}'"
                        x-bind:class="side === '{{ $sideValue }}' ? 'comp-badge shadow' : 'bg-black/20 hover:bg-black/30'"
                        class="flex-1 rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide transition">
                        {{ \App\Enums\QuestionSide::from($sideValue)->label() }} <span class="tabular-nums">{{ $sideDone }}/{{ $items->count() }}</span>
                    </button>
                @endforeach
            </div>

            @foreach ($set['sides'] as $sideValue => $items)
                <ul x-show="side === '{{ $sideValue }}'" @if ($sideValue !== 'offensive') x-cloak @endif
                    class="divide-y divide-zinc-100 rounded-xl bg-white text-zinc-800 dark:divide-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                    @foreach ($items as $item)
                        @php
                            $value = (string) ($answers[$item->id] ?? '');
                            $result = $played ? $item->correct_answer : null;
                            $share = $this->crowdAnswers[$item->id] ?? null;
                            $yesPct = $share && $share['answers'] > 0 ? (int) round($share['yes'] / $share['answers'] * 100) : null;
                        @endphp
                        <li class="flex items-center gap-2 px-3 py-2" wire:key="q-{{ $item->id }}">
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-bold text-zinc-500 dark:bg-zinc-800">{{ $item->position }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm leading-snug">{{ $item->question->text }}</div>
                                @if ($item->correct_answer === null && $played)
                                    <div class="text-xs text-amber-600 dark:text-amber-400">{{ __('Question cancelled: answers do not count.') }}</div>
                                @endif
                                @if ($this->premium && $yesPct !== null)
                                    <div class="mt-1 flex items-center gap-1.5 text-[11px] text-purple-700 dark:text-purple-300" title="{{ __('Answers of the other players') }}">
                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-purple-100 dark:bg-purple-500/20"><span class="block h-full bg-purple-500" style="width: {{ $yesPct }}%"></span></span>
                                        {{ __('yes :percent%', ['percent' => $yesPct]) }}
                                    </div>
                                @endif
                            </div>
                            <x-answer-icons :model="'answers.' . $item->id" :value="$value" :disabled="!$this->isOpen" :result="$result" />
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>
    @endforeach
    </div>

    @endif
    @endif

    @else
        @include('partials.tips-tabs')
    @endif
    @endif
</div>
