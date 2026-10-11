<?php

use App\Enums\CompetitionType;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\CupBracket;
use App\Support\EuropeanCup;
use App\Support\PlayerStats;
use App\Support\Premium;
use App\Support\Rivals;
use App\Support\Standings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Wyniki i tabele sezonu (dla wszystkich, także gości). Domyślnie sezon aktywny, a gdy go nie ma, ostatnio zakończony.
 * Strzałki w banerze przesuwają o jeden sezon wstecz albo do przodu (archiwum: sezony aktywne i zakończone).
 * Ligi i Liga podwórkowa: tabela + mecze wybranej kolejki. Puchar Polski: pasek rund, „Twoja droga”, karty meczów rundy
 * (partials/cup-fixture-card), objaśnienia i legenda jak w Lidze Legend. Puchary europejskie: pasek etapów, w kolejkach 1-5
 * tabele grup A-D z meczami kolejki, od 1/8 finału karty meczów jak w Pucharze Polski (EuropeanCup).
 * Kliknięcie zespołu otwiera okno ze statystykami (podstawowe i premium), kliknięcie meczu okno ze szczegółami
 * (widoczność jak w podglądzie rywali: przed zamknięciem typowania, po zamknięciu i po wynikach).
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    // Wybrane rozgrywki: stały klucz Competition::slug() (ekstraklasa, i-liga, puchar-polski, liga-legend …).
    public string $key = '';

    public int $round = 0;

    // Numer oglądanego sezonu; 0 = domyślny (aktywny albo ostatnio zakończony).
    public int $seasonNumber = 0;

    /** Zespół (season_teams.id) i mecz pokazywane w oknach. */
    public ?int $teamId = null;

    /** Szukany zespół (season_teams.id, parametr ?team= z profilu zespołu): wyróżniony, a lista meczów otwiera się na jego stronie. */
    public ?int $focusTeamId = null;

    /**
     * Przyjazny adres: /results/sezon-2/ekstraklasa/kolejka-9. Stare adresy z parametrami (?c=league-1&round=9&s=2,
     * np. w informacjach systemowych) też działają, a po pierwszej zmianie w formularzu adres zmienia się na nowy.
     */
    public function mount(?string $season_slug = null, ?string $competition_slug = null, ?string $round_slug = null): void
    {
        $this->seasonNumber = (int) str_replace('sezon-', '', $season_slug ?? (string) request()->query('s', '0'));
        $this->round = (int) str_replace('kolejka-', '', $round_slug ?? (string) request()->query('round', '0'));
        $this->key = $competition_slug ?? $this->legacyKey((string) request()->query('c', ''));

        $this->normalize();

        $team = (int) request()->query('team', '0');
        if ($team > 0) {
            $this->focusTeamId = $team;
            $this->openFocusPage();
        }
    }

    /** Strona listy meczów (po 32), na której gra szukany zespół w wybranej kolejce. */
    private function openFocusPage(): void
    {
        if (!$this->focusTeamId || !$this->competition) {
            return;
        }

        $ids = Fixture::where('competition_id', $this->competition->id)->where('round', $this->round)->orderBy('id')->pluck('id')->all();
        $own = Fixture::where('competition_id', $this->competition->id)->where('round', $this->round)
            ->where(fn($q) => $q->whereHas('home', fn($h) => $h->where('season_team_id', $this->focusTeamId))
                ->orWhereHas('away', fn($a) => $a->where('season_team_id', $this->focusTeamId)))
            ->value('id');
        $index = $own === null ? false : array_search($own, $ids, true);

        if ($index !== false) {
            $this->setPage(intdiv((int) $index, 32) + 1, 'matchesPage');
        }
    }

    /** Czy zespół wyróżniamy: zespół zalogowanego gracza albo szukany z profilu. */
    public function highlighted(?int $teamId): bool
    {
        return $teamId !== null && in_array($teamId, array_filter([$this->myTeamId, $this->focusTeamId]), true);
    }

    /** Stare klucze z parametru c (league-N, cup, swiss, c-ID) na klucze z adresu. */
    private function legacyKey(string $key): string
    {
        if ($key === '' || !$this->season) {
            return '';
        }

        $competition = Competition::where('season_id', $this->season->id)->get()->first(fn(Competition $c) => match ($c->type) {
            CompetitionType::League => $key === 'league-' . $c->tier,
            CompetitionType::Cup => $key === 'cup',
            CompetitionType::Swiss => $key === 'swiss',
            default => $key === 'c-' . $c->id,
        });

        return $competition?->slug() ?? '';
    }

    /** Adres strony dla sezonu, rozgrywek i kolejki (domyślnie bieżący wybór). */
    public function pageUrl(?int $round = null, ?int $seasonNumber = null): string
    {
        $number = $seasonNumber ?? $this->season?->number;

        if (!$number || $this->key === '') {
            return route('results');
        }

        return route('results', [
            'season_slug' => 'sezon-' . $number,
            'competition_slug' => $this->key,
            'round_slug' => 'kolejka-' . ($round ?? $this->round),
        ]);
    }

    /** Po zmianie wyboru podmieniamy adres w pasku przeglądarki bez przeładowania strony. */
    private function syncUrl(): void
    {
        $this->js('history.replaceState(history.state, "", ' . json_encode($this->pageUrl()) . ')');
        // Panel meczu Lecha w prawej kolumnie pokazuje wybraną kolejkę.
        $this->dispatch('results-matchday', id: (int) $this->matchday?->id);
    }

    /** Poprawia wybór rozgrywek i kolejki po wejściu na stronę albo po zmianie sezonu. */
    private function normalize(): void
    {
        // Domyślnie Ekstraklasa (pierwsza w kolejności CompetitionColors::order()).
        if (!array_key_exists($this->key, $this->options)) {
            $this->key = array_key_first($this->options) ?? '';
        }

        if ($this->round < 1 || $this->round > Matchday::PER_SEASON) {
            $this->round = max(1, (int) Matchday::where('season_id', $this->season?->id ?? 0)
                ->where('status', \App\Enums\MatchdayStatus::Played)->max('number'));
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Results and tables'));
    }

    public function updatedKey(): void
    {
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    public function updatedRound(): void
    {
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    /**
     * Numery sezonów do przeglądania (aktywny i zakończone), rosnąco.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function seasonNumbers(): array
    {
        return Season::whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Finished->value])
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($number) => (int) $number)
            ->all();
    }

    /** Sezon domyślny: aktywny, a gdy go nie ma, ostatnio zakończony. */
    #[Computed]
    public function defaultSeasonNumber(): int
    {
        $active = Season::current()?->number;

        return (int) ($active ?? Season::where('status', SeasonStatus::Finished->value)->max('number'));
    }

    #[Computed]
    public function season(): ?Season
    {
        $number = in_array($this->seasonNumber, $this->seasonNumbers, true) ? $this->seasonNumber : $this->defaultSeasonNumber;

        return $number > 0 ? Season::where('number', $number)->first() : null;
    }

    /** Numer sezonu o $step dalej na liście archiwum (−1 starszy, 1 nowszy) albo null na krańcu. */
    public function neighbourSeason(int $step): ?int
    {
        $index = array_search($this->season?->number, $this->seasonNumbers, true);

        return $index === false ? null : ($this->seasonNumbers[$index + $step] ?? null);
    }

    /**
     * Strzałki w banerze: przejście o jeden sezon. Rozgrywki i kolejka zostają (klucz rozgrywek jest taki sam
     * w każdym sezonie), więc można porównać ten sam etap w różnych sezonach.
     */
    public function shiftSeason(int $step): void
    {
        $target = $this->neighbourSeason($step <=> 0);

        if ($target === null) {
            return;
        }

        $this->seasonNumber = $target;
        $this->teamId = null;

        unset($this->season, $this->options, $this->competition, $this->isCup, $this->hasTable, $this->table,
            $this->isEuropean, $this->groupTables, $this->isLegends, $this->myEntry, $this->myPath, $this->cupPath, $this->cupPlayed, $this->matchday, $this->matches, $this->myTeamId);

        $this->normalize();
        $this->resetPage('matchesPage');
        $this->syncUrl();
    }

    /**
     * Strzałki w banerze: poprzednie albo następne rozgrywki w kolejności. Sezon i kolejka zostają.
     */
    public function shiftCompetition(int $step): void
    {
        $keys = array_keys($this->options);
        $index = array_search($this->key, $keys, true);
        $target = $index === false ? null : ($keys[$index + ($step <=> 0)] ?? null);

        if ($target === null) {
            return;
        }

        $this->key = $target;
        $this->teamId = null;
        unset($this->competition, $this->isCup, $this->hasTable, $this->table, $this->isEuropean, $this->groupTables, $this->isLegends, $this->myEntry,
            $this->myPath, $this->cupPath, $this->cupPlayed, $this->matches);
        $this->updatedKey();
    }

    /** Strzałki w banerze: poprzednia albo następna kolejka (runda). Sezon i rozgrywki zostają. */
    public function shiftRound(int $step): void
    {
        $target = $this->round + ($step <=> 0);

        if ($target < 1 || $target > Matchday::PER_SEASON) {
            return;
        }

        $this->round = $target;
        unset($this->table, $this->cupPlayed, $this->matchday, $this->matches);
        $this->updatedRound();
    }

    /** klucz => nazwa rozgrywek sezonu */
    #[Computed]
    public function options(): array
    {
        if (!$this->season) {
            return [];
        }

        // Kolejność: ligi od Ekstraklasy do podwórkowej, Puchar Polski, LM, LE, LK, Liga Legend.
        $options = [];
        $competitions = Competition::where('season_id', $this->season->id)->orderBy('id')->get()
            ->sortBy(fn(Competition $c) => \App\Support\CompetitionColors::rank($c->trophyKey()));
        foreach ($competitions as $competition) {
            $options[$this->keyOf($competition)] = $competition->name;
        }

        return $options;
    }

    /** klucz rozgrywek => klucz barw (Competition::trophyKey()) do przycisków pod banerem */
    #[Computed]
    public function optionColors(): array
    {
        if (!$this->season) {
            return [];
        }

        return Competition::where('season_id', $this->season->id)->get()
            ->mapWithKeys(fn(Competition $c) => [$this->keyOf($c) => $c->trophyKey()])->all();
    }

    #[Computed]
    public function competition(): ?Competition
    {
        if (!$this->season) {
            return null;
        }

        return Competition::with('sponsor')->where('season_id', $this->season->id)->get()->first(fn(Competition $c) => $this->keyOf($c) === $this->key);
    }

    #[Computed]
    public function isCup(): bool
    {
        return $this->competition?->type === CompetitionType::Cup;
    }

    #[Computed]
    public function hasTable(): bool
    {
        return $this->competition !== null && !$this->isCup && !$this->isEuropean;
    }

    /** Liga Mistrzów, Europy albo Konferencji: grupy A-D, potem faza pucharowa (EuropeanCup). */
    #[Computed]
    public function isEuropean(): bool
    {
        return (bool) $this->competition?->type->isEuropean();
    }

    /**
     * Puchary europejskie: tabele grup z meczów fazy grupowej (litera => wiersze Standings).
     *
     * @return array<string, \Illuminate\Support\Collection<int, array<string, mixed>>>
     */
    #[Computed]
    public function groupTables(): array
    {
        return $this->isEuropean ? EuropeanCup::groupTables($this->competition) : [];
    }

    #[Computed]
    public function table()
    {
        return $this->hasTable ? Standings::for($this->competition) : collect();
    }

    #[Computed]
    public function isLegends(): bool
    {
        return $this->competition?->type === CompetitionType::Legends;
    }

    /** Wpis zespołu zalogowanego gracza w wybranych rozgrywkach (Liga Legend, puchary europejskie). */
    #[Computed]
    public function myEntry(): ?\App\Models\CompetitionEntry
    {
        if (!$this->competition || !$this->myTeamId) {
            return null;
        }

        return \App\Models\CompetitionEntry::where('competition_id', $this->competition->id)->where('season_team_id', $this->myTeamId)->first();
    }

    /**
     * Ligi, Liga podwórkowa, Liga Legend i puchary europejskie: mecze zespołu gracza w kolejnych kolejkach
     * (kolejka => lista meczów, w Lidze Legend dwa na kolejkę). Do bloków „Twoje mecze ligowe”,
     * „Twoje mecze w Lidze Legend” i „Twoje mecze w Europie”.
     *
     * @return array<int, list<Fixture>>
     */
    #[Computed]
    public function myPath(): array
    {
        if (!$this->myEntry || !($this->hasTable || $this->isEuropean)) {
            return [];
        }

        $entryId = $this->myEntry->id;
        $path = [];

        $fixtures = Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where(fn ($q) => $q->where('home_entry_id', $entryId)->orWhere('away_entry_id', $entryId))
            ->orderBy('round')
            ->orderBy('id')
            ->get();

        foreach ($fixtures as $fixture) {
            $path[$fixture->round][] = $fixture;
        }

        return $path;
    }

    /**
     * Liga Legend: id wpisu => kolejka, po której zespół odpadł (wyniki zostają w tabeli, wiersz jest wyszarzony).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function legendsOut(): array
    {
        if (!$this->isLegends) {
            return [];
        }

        return \App\Models\CompetitionEntry::where('competition_id', $this->competition->id)
            ->whereNotNull('eliminated_round')
            ->pluck('eliminated_round', 'id')
            ->map(fn ($round) => (int) $round)
            ->all();
    }

    /**
     * Puchar Polski: mecze zespołu zalogowanego gracza w kolejnych rundach (runda => mecz).
     * Do paska „Twoja droga” i przypiętego meczu nad drabinką.
     *
     * @return array<int, Fixture>
     */
    #[Computed]
    public function cupPath(): array
    {
        if (!$this->isCup || !$this->myTeamId) {
            return [];
        }

        $entryId = \App\Models\CompetitionEntry::where('competition_id', $this->competition->id)
            ->where('season_team_id', $this->myTeamId)
            ->value('id');

        if (!$entryId) {
            return [];
        }

        return Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where(fn ($q) => $q->where('home_entry_id', $entryId)->orWhere('away_entry_id', $entryId))
            ->get()
            ->keyBy('round')
            ->all();
    }

    /** Puchar Polski: ile meczów wybranej rundy ma już wynik. */
    #[Computed]
    public function cupPlayed(): int
    {
        if (!$this->isCup) {
            return 0;
        }

        return Fixture::where('competition_id', $this->competition->id)->where('round', $this->round)->whereNotNull('home_goals')->count();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->season ? Matchday::where('season_id', $this->season->id)->where('number', $this->round)->first() : null;
    }

    #[Computed]
    public function matches()
    {
        if (!$this->competition) {
            return null;
        }

        return Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where('round', $this->round)
            ->orderBy('id')
            ->paginate(32, pageName: 'matchesPage');
    }

    /** Id zespołu zalogowanego gracza na liście sezonu (do wyróżnienia w tabeli). */
    #[Computed]
    public function myTeamId(): ?int
    {
        if (!auth()->check() || !$this->season) {
            return null;
        }

        return SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id');
    }

    /* ==================================================================
     | OKNA: ZESPÓŁ I MECZ
     * ================================================================*/

    public function showTeam(int $id): void
    {
        $this->teamId = $id;
        unset($this->teamCard);
        Flux::modal('duel-details')->close();
        Flux::modal('team-details')->show();
    }

    /** Szczegóły pojedynku w uniwersalnym oknie (pages::home.duel-modal w layoucie publicznym). */
    public function showFixture(int $id): void
    {
        $this->dispatch('show-duel', id: $id);
    }

    /** Statystyki zespołu w sezonie; część tylko dla premium (oglądającego). */
    #[Computed]
    public function teamCard(): ?array
    {
        if (!$this->teamId || !$this->season) {
            return null;
        }

        $team = SeasonTeam::with(['user:id,name,team_name,premium_until', 'bot:id,name'])
            ->where('season_id', $this->season->id)
            ->find($this->teamId);

        if (!$team) {
            return null;
        }

        $viewer = auth()->user();
        $premium = $viewer !== null && Premium::isActive($viewer);
        $index = $this->hasTable ? $this->table->search(fn($row) => $row['team']->id === $team->id) : false;

        return [
            'team' => $team,
            'place' => $index === false ? null : $index + 1,
            'count' => $this->table->count(),
            'stats' => PlayerStats::teamSeason($team, $this->season),
            'premium' => $premium,
            'owner_premium' => $team->user !== null && Premium::isActive($team->user),
            'favourite' => $premium && $team->user ? PlayerStats::favourites($team->user)['mine'] : null,
            'h2h' => $premium && $team->user_id !== $viewer?->id ? Rivals::headToHead($viewer, $team) : null,
        ];
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function keyOf(Competition $competition): string
    {
        return $competition->slug();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    @php
        $older = $this->neighbourSeason(-1);
        $newer = $this->neighbourSeason(1);
        $archived = $this->season && $this->season->status !== \App\Enums\SeasonStatus::Active;
    @endphp
    @if ($this->competition)
        {{--
            Baner rozgrywek w ich barwach (App\Support\CompetitionColors): trofeum w odznace, nazwa rozgrywek i sezon,
            etap (kolejka albo runda) i strzałki: rozgrywki, kolejka, sezon. Zmiana jednego zostawia dwa pozostałe.
        --}}
        @php
            $colorKey = $this->competition->trophyKey();
            $dashed = \App\Support\CompetitionColors::dashed($colorKey) ? 'comp-dashed' : '';
            $bannerIcons = \App\Support\HallOfFame::iconUrls();
            $optionKeys = array_keys($this->options);
            $keyIndex = array_search($this->key, $optionKeys, true);
            $sponsor = $this->competition->sponsor;
            $arrowClass = 'flex size-8 items-center justify-center rounded-full bg-white/15 transition hover:bg-white/25 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/15';
            $navs = [
                [__('Competition'), 'shiftCompetition', $keyIndex !== false && $keyIndex > 0, $keyIndex !== false && $keyIndex < count($optionKeys) - 1],
                [$this->isCup ? __('Round') : __('Matchday'), 'shiftRound', $round > 1, $round < \App\Models\Matchday::PER_SEASON],
                [__('Season'), 'shiftSeason', (bool) $older, (bool) $newer],
            ];
        @endphp
        <section class="comp-banner {{ $dashed }} rounded-2xl px-5 py-6 sm:px-8" style="{{ \App\Support\CompetitionColors::style($colorKey) }}">
            <div class="relative z-10 flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="comp-badge {{ $dashed }} flex size-20 shrink-0 items-center justify-center rounded-full shadow-lg" title="{{ __('Trophy to win') }}">
                        @if (isset($bannerIcons[$colorKey]))
                            <img src="{{ $bannerIcons[$colorKey] }}" alt="" class="size-14 object-contain">
                        @else
                            <flux:icon.trophy class="size-9" />
                        @endif
                    </div>
                    <div class="min-w-0 space-y-1">
                        <p class="comp-accent text-xs font-bold uppercase tracking-[0.2em]">
                            {{ $this->season->title }} – {{ $this->competition->type->stageLabel($round) }}
                            @if ($archived) · {{ __('Season archive') }} @endif
                        </p>
                        <h1 class="text-3xl font-black uppercase tracking-tight sm:text-4xl">
                            {{ $this->competition->name ?: $this->competition->type->label() }}
                        </h1>
                        @if ($sponsor)
                            <a @if ($sponsor->url) href="{{ $sponsor->url }}" target="_blank" rel="noopener sponsored" @endif
                                class="inline-flex items-center gap-2 text-xs opacity-80 hover:opacity-100">
                                <span class="uppercase tracking-widest">powered by</span>
                                @if ($sponsor->logoUrl())
                                    <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="h-7 max-w-28 rounded bg-white object-contain p-0.5">
                                @else
                                    <span class="font-semibold">{{ $sponsor->name }}</span>
                                @endif
                            </a>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($navs as [$navLabel, $navMethod, $canBack, $canForward])
                        <div class="flex items-center gap-1.5 rounded-full bg-black/15 px-1.5 py-1">
                            <button type="button" wire:click="{{ $navMethod }}(-1)" @disabled(!$canBack) class="{{ $arrowClass }}"
                                aria-label="{{ $navLabel }}: {{ __('Previous') }}" title="{{ __('Previous') }}">
                                <flux:icon.chevron-left variant="mini" />
                            </button>
                            <span class="min-w-16 text-center text-[11px] font-bold uppercase tracking-wider">{{ $navLabel }}</span>
                            <button type="button" wire:click="{{ $navMethod }}(1)" @disabled(!$canForward) class="{{ $arrowClass }}"
                                aria-label="{{ $navLabel }}: {{ __('Next') }}" title="{{ __('Next') }}">
                                <flux:icon.chevron-right variant="mini" />
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @else
    <x-page-banner :title="__('Results and tables')" :subtitle="$this->season?->title"
            :eyebrow="$archived ? __('Season archive') : 'LechTYPER'">
            {{-- Dyskretne strzałki archiwum: o jeden sezon wstecz albo do przodu --}}
            @if ($older || $newer)
                <x-slot:aside>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="shiftSeason(-1)" @disabled(!$older)
                            title="{{ __('Previous season') }}" aria-label="{{ __('Previous season') }}"
                            class="flex size-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10">
                            <flux:icon.chevron-left variant="mini" />
                        </button>
                        <button type="button" wire:click="shiftSeason(1)" @disabled(!$newer)
                            title="{{ __('Next season') }}" aria-label="{{ __('Next season') }}"
                            class="flex size-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10">
                            <flux:icon.chevron-right variant="mini" />
                        </button>
                    </div>
                </x-slot:aside>
            @endif
        </x-page-banner>
    @endif

    @if (!$this->season || count($this->options) === 0)
        <flux:card>
            <flux:text>{{ __('There is no season to show yet.') }}</flux:text>
        </flux:card>
    @else
        {{--
            Wybór rozgrywek: jeden wąski pasek w kolorach strony. Ligi (Ekstraklasa … podwórkowa) zwinięte w menu,
            pozostałe rozgrywki jako zakładki. Barwa rozgrywek (CompetitionColors) tylko jako mała kropka.
            Zmiana rozgrywek zostawia kolejkę i sezon.
        --}}
        @php
            $leagueOptions = [];
            $otherOptions = [];
            foreach ($this->options as $optionKey => $optionName) {
                $optionColor = $this->optionColors[$optionKey] ?? 'league_1';
                if (str_starts_with($optionColor, 'league_')) {
                    $leagueOptions[$optionKey] = [$optionName, $optionColor];
                } else {
                    $otherOptions[$optionKey] = [$optionName, $optionColor];
                }
            }
            $leagueActive = array_key_exists($this->key, $leagueOptions);
            $pill = 'inline-flex shrink-0 items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-semibold transition';
            // Aktywna opcja: tylko obwódka w kolorze strony (lech), bez ciemnego tła.
            $pillOn = 'text-lech-800 ring-2 ring-inset ring-lech-600 dark:text-lech-200 dark:ring-lech-400';
            $pillOff = 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white';
        @endphp
        <nav class="-mt-2 flex flex-wrap items-center gap-1 rounded-3xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Competition') }}">
            @if (count($leagueOptions) > 0)
                <flux:dropdown position="bottom" align="start">
                    <button type="button" class="{{ $pill }} {{ $leagueActive ? $pillOn : $pillOff }}">
                        @if ($leagueActive)
                            <span class="size-3 shrink-0 rounded-full" style="{{ \App\Support\CompetitionColors::dotStyle($leagueOptions[$this->key][1]) }}"></span>
                            {{ $leagueOptions[$this->key][0] }}
                        @else
                            {{ __('Leagues') }}
                        @endif
                        <flux:icon.chevron-down variant="micro" />
                    </button>
                    <flux:menu>
                        @foreach ($leagueOptions as $optionKey => [$optionName, $optionColor])
                            <flux:menu.item wire:click="$set('key', '{{ $optionKey }}')" wire:key="lg-opt-{{ $optionKey }}">
                                <span class="flex items-center gap-2">
                                    <span class="size-3 shrink-0 rounded-full" style="{{ \App\Support\CompetitionColors::dotStyle($optionColor) }}"></span>
                                    <span class="{{ $optionKey === $this->key ? 'font-bold' : '' }}">{{ $optionName }}</span>
                                </span>
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                <span class="mx-1 h-5 w-px shrink-0 bg-zinc-200 dark:bg-zinc-700"></span>
            @endif
            @foreach ($otherOptions as $optionKey => [$optionName, $optionColor])
                <button type="button" wire:click="$set('key', '{{ $optionKey }}')" wire:key="opt-{{ $optionKey }}"
                    class="{{ $pill }} {{ $optionKey === $this->key ? $pillOn : $pillOff }}"
                    @if ($optionKey === $this->key) aria-current="true" @endif>
                    <span class="size-3 shrink-0 rounded-full" style="{{ \App\Support\CompetitionColors::dotStyle($optionColor) }}"></span>
                    {{ $optionName }}
                </button>
            @endforeach
        </nav>


        {{-- Ligi, podwórkowa, Liga Legend i puchary europejskie: mecze zespołu gracza w kolejnych kolejkach (jak „Twoja droga” w pucharze) --}}
        @if ($this->myEntry && ($this->hasTable || $this->isEuropean))
            @php
                $pathEntry = $this->myEntry;
                $pathOut = $pathEntry->eliminated_round;
                if ($this->hasTable) {
                    $pathIndex = $this->table->search(fn ($row) => $row['entry_id'] === $pathEntry->id);
                    $pathStatus = $pathOut !== null
                        ? __('Knocked out after matchday :number', ['number' => $pathOut])
                        : ($pathIndex === false ? '' : __(':place. place of :count', ['place' => $pathIndex + 1, 'count' => $this->table->count()]));
                } else {
                    $pathStatus = match (true) {
                        $pathOut === \App\Support\EuropeanCup::GROUP_ROUNDS => __('Knocked out in the group stage'),
                        $pathOut !== null => __('Knocked out in: :round', ['round' => \App\Support\EuropeanCup::stageLabel($pathOut)]),
                        default => __('Group :group', ['group' => \App\Support\EuropeanCup::groupOf($pathEntry->seed)]),
                    };
                }
                $pathType = $this->competition->type;
            @endphp
            @include('partials.my-matches-path', [
                'title' => match (true) {
                    $this->isLegends => __('Your matches in Liga Legend'),
                    $this->isEuropean => __('Your matches in Europe'),
                    default => __('Your league matches'),
                },
                'status' => $pathStatus,
                'rounds' => \App\Models\Matchday::PER_SEASON,
                'perRound' => $this->isLegends ? 2 : 1,
                'path' => $this->myPath,
                'entryId' => $pathEntry->id,
                'eliminated' => $pathOut,
                'stageLabel' => fn (int $stage) => $this->hasTable ? __('Matchday :number', ['number' => $stage]) : \App\Support\EuropeanCup::stageLabel($stage),
                'stageUrl' => fn (int $stage) => $this->pageUrl($stage),
                'current' => $round,
            ])
        @endif

        <div class="grid gap-6 {{ $this->hasTable ? 'lg:grid-cols-[1fr_20rem]' : '' }}">
            {{-- ============ Tabela ============ --}}
            {{--
                Karta tabeli w barwach rozgrywek (jak grupy pucharów europejskich): kolorowa ramka obejmuje nagłówek
                („Tabela” i trofeum w kółku), nagłówek tabeli i stopkę z legendą; jasne są tylko wiersze.
            --}}
            @if ($this->hasTable)
                @php
                    $tableStyle = \App\Support\CompetitionColors::style($colorKey);
                @endphp
                <section class="self-start overflow-hidden rounded-2xl p-1 shadow-md"
                    style="{{ $tableStyle }} background-image: linear-gradient(140deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink); border: 2px solid var(--c-accent);">
                    <header class="flex items-center justify-between gap-2.5 px-3 pb-1.5 pt-2">
                        <h3 class="text-sm font-black uppercase tracking-[0.25em]">{{ __('Table') }}</h3>
                        <span class="comp-badge {{ $dashed }} flex size-9 shrink-0 items-center justify-center rounded-full shadow">
                            @if (isset($bannerIcons[$colorKey]))
                                <img src="{{ $bannerIcons[$colorKey] }}" alt="" class="size-6 object-contain">
                            @else
                                <flux:icon.trophy variant="micro" />
                            @endif
                        </span>
                    </header>

                    <div class="overflow-x-auto">
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
                                    <th class="px-1.5 py-1.5 text-right" title="{{ __('Exact tips') }}">{{ __('Ex.') }}</th>
                                    <th class="px-1.5 py-1.5 text-right" title="{{ __('Bonus') }}">{{ __('Bon.') }}</th>
                                    <th class="px-3 py-1.5 text-right">{{ __('Pts') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white text-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                                @foreach ($this->table as $index => $row)
                                    @php
                                        $zone = \App\Support\Standings::zone($this->competition, $index + 1, $this->table->count());
                                        $zoneClass = match ($zone) {
                                            'up' => 'border-l-4 border-l-green-500',
                                            'down' => 'border-l-4 border-l-red-500',
                                            default => 'border-l-4 border-l-transparent',
                                        };
                                        // Liga Legend: zespół, który odpadł, zostaje w tabeli: czerwony pasek z lewej, ukośne szare paski w tle,
                                        // przekreślona nazwa i plakietka z kolejką odpadnięcia.
                                        $outRound = $this->legendsOut[$row['entry_id']] ?? null;
                                        if ($outRound !== null) {
                                            $zoneClass = 'border-l-4 border-l-red-600 text-zinc-400 dark:text-zinc-500 bg-[repeating-linear-gradient(135deg,rgba(113,113,122,0.10)_0_6px,transparent_6px_12px)]';
                                        }
                                    @endphp
                                    <tr wire:key="row-{{ $row['entry_id'] }}"
                                        class="{{ $zoneClass }} border-b border-zinc-100 last:border-b-0 dark:border-zinc-700/50 {{ $this->highlighted($row['team']->id) ? 'bg-amber-50 font-semibold dark:bg-amber-900/20' : '' }}">
                                        <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $index + 1 }}.</td>
                                        <td class="max-w-56 truncate px-2 py-1.5">
                                            <button type="button" wire:click="showTeam({{ $row['team']->id }})" class="{{ $outRound !== null ? 'line-through decoration-red-500/70 decoration-2' : '' }} truncate hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $row['team']->name }}</button>
                                            @if ($outRound !== null)
                                                <span class="ms-1 inline-flex items-center gap-0.5 rounded-full bg-red-600 px-1.5 py-px align-middle text-[10px] font-bold uppercase tracking-wide text-white no-underline" title="{{ __('Knocked out after matchday :number', ['number' => $outRound]) }}"><flux:icon.x-mark variant="micro" class="size-3" />{{ __('Out (MD :number)', ['number' => $outRound]) }}</span>
                                            @endif
                                        </td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['played'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['won'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['drawn'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['lost'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['for'] }}:{{ $row['against'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['exact'] }}</td>
                                        <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['bonus'] }}</td>
                                        <td class="px-3 py-1.5 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Stopka w barwach rozgrywek: objaśnienia i legenda stref --}}
                    @if ($this->isLegends || in_array($this->competition->type, [\App\Enums\CompetitionType::League, \App\Enums\CompetitionType::Swiss], true))
                        <footer class="space-y-1.5 px-2 pb-1.5 pt-2.5 text-xs">
                            @if ($this->isLegends)
                                @php
                                    $outCount = count($this->legendsOut);
                                @endphp
                                <p class="flex flex-wrap items-center gap-x-4 gap-y-1 opacity-95">
                                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-red-600"></span>{{ __('Knocked out (two losses in one matchday)') }}</span>
                                    <span class="font-semibold">{{ __('Still playing: :left of :count', ['left' => $this->table->count() - $outCount, 'count' => $this->table->count()]) }}</span>
                                </p>
                                <p class="italic opacity-85">
                                    {{ __('Every matchday each team plays two duels: 1-2, 2-3 … and the first with the last (matchday 1 from the list, then by the table). Two losses in one matchday knock the team out; its results stay in the table. After matchday 9 the top of the whole table wins.') }}
                                </p>
                            @else
                                {{-- Legenda tylko dla stref, które są w tej lidze: z Ekstraklasy nie ma awansu, z podwórkowej spadku --}}
                                @php
                                    $isSwiss = $this->competition->type === \App\Enums\CompetitionType::Swiss;
                                    $hasPromotion = $isSwiss || (int) $this->competition->tier > 1;
                                    $hasRelegation = !$isSwiss;
                                @endphp
                                <p class="flex flex-wrap gap-4 opacity-90">
                                    @if ($hasPromotion)
                                        <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-green-500"></span>{{ __('Promotion') }}</span>
                                    @endif
                                    @if ($hasRelegation)
                                        <span class="flex items-center gap-1"><span class="inline-block h-3 w-1 bg-red-500"></span>{{ __('Relegation') }}</span>
                                    @endif
                                </p>
                            @endif
                        </footer>
                    @endif
                </section>
            @endif

            {{-- ============ Puchar Polski: drabinka rundy w kartach meczów ============ --}}
            @if ($this->isCup)
                @php
                    $rounds = \App\Support\CupBracket::ROUNDS;
                    $total = intdiv(\App\Support\CupBracket::seatsInRound($round), 2);
                    $path = $this->cupPath;
                    $myId = $this->myTeamId;
                    $final = $round === $rounds ? $this->matches?->first() : null;
                    // Cztery kolumny tej samej szerokości; półfinał (2 mecze) i finał (1) wyśrodkowane, karty nie rosną.
                    $cupGrid = 'flex flex-wrap justify-center gap-2';
                    $cupCell = 'shrink-0 grow-0';
                    $cupCellStyle = 'width: calc((100% - 1.5rem) / 4); min-width: min(100%, 16rem);';
                    // Etykieta etapu w pasku: liczba zespołów, od ćwierćfinału nazwa etapu (KnockoutStage).
                    $stageLabel = fn (int $stage) => $stage >= \App\Enums\KnockoutStage::QuarterFinal->value
                        ? \App\Enums\KnockoutStage::labelFor($stage)
                        : (string) \App\Support\CupBracket::seatsInRound($stage);
                @endphp
                <div class="space-y-3">
                    {{-- Pasek etapów: 512 → 256 → … → Finał, każdy etap to link do swojej rundy --}}
                    <nav class="flex flex-wrap items-center gap-1 text-xs" aria-label="{{ __('Rounds') }}">
                        @foreach (range(1, $rounds) as $stage)
                            @php
                                $stageClass = $stage === $round
                                    ? 'lech-bar font-semibold'
                                    : ($stage < $round ? 'bg-lech-100 text-lech-800 hover:bg-lech-200 dark:bg-lech-900/40 dark:text-lech-200' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800');
                            @endphp
                            <a href="{{ $this->pageUrl($stage) }}" wire:navigate title="{{ \App\Support\CupBracket::roundName($stage) }}"
                                class="{{ $stageClass }} rounded-full px-2.5 py-1 tabular-nums transition">{{ $stageLabel($stage) }}</a>
                            @if ($stage < $rounds)
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                            @endif
                        @endforeach
                    </nav>

                    {{-- Zdobywca pucharu po rozegranym finale --}}
                    @if ($final && $final->winner_entry_id)
                        @php
                            $champion = $final->winner_entry_id === $final->home_entry_id ? $final->home : $final->away;
                        @endphp
                        <div class="lech-banner lech-bar-shadow flex items-center gap-3 rounded-xl px-4 py-3">
                            <flux:icon.trophy class="size-8 shrink-0 text-amber-300" />
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-80">{{ __('Cup winner') }}</div>
                                <button type="button" wire:click="showTeam({{ $champion?->season_team_id ?? 0 }})" class="truncate text-lg font-bold hover:underline">{{ $champion?->seasonTeam->name }}</button>
                            </div>
                        </div>
                    @endif

                    {{-- Twoja droga w pucharze: karta na rundę z paskiem koloru (zielony = awans, czerwony = odpadnięcie, bursztynowy = przed wynikiem) --}}
                    @if ($path)
                        @php
                            $pathLabel = fn (int $stage) => match ($stage) {
                                $rounds => __('Final'),
                                default => '1/' . intdiv(\App\Support\CupBracket::seatsInRound($stage), 2),
                            };
                            $lastStage = max(array_keys($path));
                            $lastFx = $path[$lastStage];
                            $lastMine = $lastFx->home?->season_team_id === $myId ? $lastFx->home_entry_id : $lastFx->away_entry_id;
                            $pathStatus = match (true) {
                                !$lastFx->isPlayed() => __('Still in: :round', ['round' => \App\Support\CupBracket::roundName($lastStage)]),
                                $lastFx->winner_entry_id !== $lastMine => __('Knocked out: :round', ['round' => \App\Support\CupBracket::roundName($lastStage)]),
                                $lastStage === $rounds => __('Cup winner'),
                                default => __('Through to: :round', ['round' => \App\Support\CupBracket::roundName($lastStage + 1)]),
                            };
                        @endphp
                        <section class="lech-bar-shadow overflow-hidden rounded-2xl border border-lech-950/20 bg-white dark:border-lech-900 dark:bg-zinc-900">
                            <header class="lech-bar flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                                <h3 class="flex items-center gap-2 font-bold">
                                    <flux:icon.trophy class="size-5 text-lech-200" />
                                    {{ __('Your cup path') }}
                                </h3>
                                <span class="text-sm font-semibold text-lech-200">{{ $pathStatus }}</span>
                            </header>
                            <div class="overflow-x-auto p-4">
                                <ol class="grid gap-2" style="grid-template-columns: repeat({{ $rounds }}, minmax(6.5rem, 1fr));">
                                    @foreach (range(1, $rounds) as $stage)
                                        @php
                                            $fx = $path[$stage] ?? null;
                                            $isHome = $fx && $fx->home?->season_team_id === $myId;
                                            $myEntryId = $fx ? ($isHome ? $fx->home_entry_id : $fx->away_entry_id) : null;
                                            $opponent = $fx ? ($isHome ? $fx->away : $fx->home) : null;
                                            $myGoals = $fx ? ($isHome ? $fx->home_goals : $fx->away_goals) : null;
                                            $theirGoals = $fx ? ($isHome ? $fx->away_goals : $fx->home_goals) : null;
                                            [$strip, $stateClass, $stateText] = match (true) {
                                                $fx === null => ['bg-zinc-200 dark:bg-zinc-700', 'text-zinc-400', '—'],
                                                !$fx->isPlayed() => ['bg-amber-400', 'text-amber-700 dark:text-amber-300', __('Not played yet')],
                                                $fx->winner_entry_id === $myEntryId => ['bg-green-500', 'text-green-700 dark:text-green-400', $fx->decided_by_time ? __('Through on penalties') : __('Through')],
                                                default => ['bg-red-500', 'text-red-600 dark:text-red-400', $fx->decided_by_time ? __('Out on penalties') : __('Out')],
                                            };
                                        @endphp
                                        <li>
                                            <a @if ($fx) href="{{ $this->pageUrl($stage) }}" wire:navigate @endif
                                                title="{{ \App\Support\CupBracket::roundName($stage) }}"
                                                class="{{ $stage === $round ? 'ring-2 ring-lech-600 dark:ring-lech-400' : '' }} {{ $fx ? 'hover:shadow-md' : 'opacity-60' }} flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition dark:border-zinc-700 dark:bg-zinc-900">
                                                <span class="{{ $strip }} block h-1.5"></span>
                                                <span class="flex flex-1 flex-col items-center gap-0.5 px-2 py-2 text-center">
                                                    <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $pathLabel($stage) }}</span>
                                                    <span class="text-2xl font-black tabular-nums text-lech-900 dark:text-white">
                                                        {{ $fx && $fx->isPlayed() ? $myGoals . ':' . $theirGoals : '–' }}
                                                    </span>
                                                    <span class="w-full truncate text-xs text-zinc-600 dark:text-zinc-300">{{ $opponent?->seasonTeam->name ?? ($fx ? __('Opponent not known yet') : '') }}</span>
                                                    <span class="{{ $stateClass }} text-xs font-semibold">{{ $stateText }}</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </section>
                    @endif
                    <flux:card class="space-y-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <flux:heading>{{ \App\Support\CupBracket::roundName($round) }}</flux:heading>
                            <flux:text class="text-xs tabular-nums">
                                {{ __(':played of :total matches played', ['played' => $this->cupPlayed, 'total' => $total]) }}
                            </flux:text>
                        </div>
                        {{-- Pasek postępu rundy --}}
                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-lech-600" style="width: {{ $total ? round($this->cupPlayed / $total * 100) : 0 }}%"></div>
                        </div>

                        @if ($this->matches && $this->matches->isNotEmpty())
                            <div class="{{ $cupGrid }}">
                                @foreach ($this->matches as $fixture)
                                    <div class="{{ $cupCell }}" style="{{ $cupCellStyle }}" wire:key="cup-cell-{{ $fixture->id }}">
                                        @include('partials.cup-fixture-card', [
                                            'fixture' => $fixture,
                                            'mine' => $this->highlighted($fixture->home?->season_team_id) || $this->highlighted($fixture->away?->season_team_id),
                                        ])
                                    </div>
                                @endforeach
                            </div>

                            @if ($this->matches->hasPages())
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <flux:button size="sm" variant="ghost" icon="chevron-left"
                                        wire:click="previousPage('matchesPage')" :disabled="$this->matches->onFirstPage()" />
                                    <flux:text class="text-xs tabular-nums">
                                        {{ __('Page :current of :last', ['current' => $this->matches->currentPage(), 'last' => $this->matches->lastPage()]) }}
                                    </flux:text>
                                    <flux:button size="sm" variant="ghost" icon="chevron-right"
                                        wire:click="nextPage('matchesPage')" :disabled="!$this->matches->hasMorePages()" />
                                </div>
                            @endif
                        @else
                            <flux:text>{{ __('No matches yet.') }}</flux:text>
                        @endif
                    </flux:card>

                    {{-- Objaśnienia kursywą pod drabinką i legenda kolorów --}}
                    <div class="space-y-1 px-1 text-xs italic text-zinc-500">
                        <p>
                            {{ __('The favourite plays on the left and the winner takes the better seed of the pair. A draw goes to the team whose tip was saved first (every save gets its own number; bots and missing tips come last, then the higher seed), shown as “after penalties”.') }}
                            {{ __('The number next to the team is its seed in this round.') }}
                        </p>
                        <p class="flex flex-wrap gap-4 not-italic">
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-green-500"></span>{{ __('Goes through') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-red-400"></span>{{ __('Eliminated') }}</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded bg-amber-400"></span>{{ __('Your match / not played yet') }}</span>
                        </p>
                    </div>
                </div>
            @endif

            {{-- ============ Puchary europejskie: grupy A-D, potem faza pucharowa ============ --}}
            @if ($this->isEuropean)
                @include('partials.european-results', ['round' => $round])
            @endif

            {{-- ============ Mecze kolejki (ligi, podwórkowa, Liga Legend) ============ --}}
            {{-- Karta w barwach rozgrywek jak stopka grupy pucharów europejskich: wynik w wyróżnionym kafelku na środku. --}}
            @if (!$this->isCup && !$this->isEuropean)
                <section class="self-start space-y-1.5 overflow-hidden rounded-2xl p-2 shadow-md"
                    style="{{ \App\Support\CompetitionColors::style($colorKey) }} background-image: linear-gradient(140deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink); border: 2px solid var(--c-accent);">
                    <header class="flex items-center justify-between gap-2 px-1 pb-1">
                        <h3 class="text-sm font-black uppercase tracking-[0.25em]">{{ __('Matchday :number', ['number' => $round]) }}</h3>
                        @if ($this->matches && $this->matches->total() > 0)
                            <span class="comp-accent text-[11px] font-bold tabular-nums">{{ trans_choice(':count match|:count matches', $this->matches->total(), ['count' => $this->matches->total()]) }}</span>
                        @endif
                    </header>

                    @if ($this->matches && $this->matches->isNotEmpty())
                        @foreach ($this->matches as $fixture)
                            @php
                                $mine = $this->highlighted($fixture->home?->season_team_id) || $this->highlighted($fixture->away?->season_team_id);
                                $homeWon = $fixture->isPlayed() && $fixture->home_goals > $fixture->away_goals;
                                $awayWon = $fixture->isPlayed() && $fixture->away_goals > $fixture->home_goals;
                            @endphp
                            <button type="button" wire:click="showFixture({{ $fixture->id }})" title="{{ __('Match details') }}" wire:key="fx-{{ $fixture->id }}"
                                class="{{ $mine ? 'ring-2 ring-amber-400' : '' }} grid w-full grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-lg bg-black/20 px-2 py-1.5 text-sm transition hover:bg-black/30">
                                <span class="truncate text-right {{ $homeWon ? 'font-bold' : 'opacity-85' }}">{{ $fixture->home?->seasonTeam->name ?? __('Seat :number', ['number' => $fixture->home_seat]) }}</span>
                                @if ($fixture->isPlayed())
                                    <span class="comp-badge min-w-14 rounded-md px-2 py-0.5 text-center text-base font-black tabular-nums shadow">{{ $fixture->home_goals }}:{{ $fixture->away_goals }}</span>
                                @else
                                    <span class="min-w-14 rounded-md border border-dashed border-current px-2 py-0.5 text-center text-base font-bold tabular-nums opacity-60">–:–</span>
                                @endif
                                <span class="truncate text-left {{ $awayWon ? 'font-bold' : 'opacity-85' }}">
                                    @if ($fixture->away)
                                        {{ $fixture->away->seasonTeam->name }}
                                    @elseif ($fixture->away_seat)
                                        {{ __('Seat :number', ['number' => $fixture->away_seat]) }}
                                    @else
                                        <span class="italic">{{ \App\Models\Fixture::VIRTUAL_OPPONENT }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach

                        @if ($this->matches->hasPages())
                            {{-- Własny, zwarty pager: domyślne linki Laravela nie mieszczą się w wąskiej kolumnie. --}}
                            <div class="flex items-center justify-between gap-2 px-1 pt-1">
                                <button type="button" wire:click="previousPage('matchesPage')" @disabled($this->matches->onFirstPage())
                                    class="flex size-8 items-center justify-center rounded-full bg-black/20 transition hover:bg-black/30 disabled:opacity-30" aria-label="{{ __('Previous') }}">
                                    <flux:icon.chevron-left variant="micro" />
                                </button>
                                <span class="text-xs tabular-nums opacity-85">
                                    {{ __('Page :current of :last', ['current' => $this->matches->currentPage(), 'last' => $this->matches->lastPage()]) }}
                                </span>
                                <button type="button" wire:click="nextPage('matchesPage')" @disabled(!$this->matches->hasMorePages())
                                    class="flex size-8 items-center justify-center rounded-full bg-black/20 transition hover:bg-black/30 disabled:opacity-30" aria-label="{{ __('Next') }}">
                                    <flux:icon.chevron-right variant="micro" />
                                </button>
                            </div>
                        @endif
                    @else
                        <div class="px-1 text-sm opacity-80">{{ __('No matches yet.') }}</div>
                    @endif
                </section>
            @endif
        </div>
    @endif

    @include('partials.results-modals')
</div>
