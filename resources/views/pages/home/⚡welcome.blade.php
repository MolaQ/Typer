<?php

use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\MatchEvent;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\Tip;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Strona główna z banerem sezonu (etap 13): duża cyfra rzymska, hasło sezonu i sponsor („powered by”,
 * logo z linkiem do strony sponsora), a pod spodem najbliższa kolejka z odliczaniem do pierwszego gwizdka.
 * Bez aktywnego sezonu: „Sezon wkrótce”, jeśli kolejny sezon jest już w przygotowaniu, w przeciwnym razie nic.
 */
new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(config('app.name'));
    }

    #[Computed]
    public function season(): ?Season
    {
        return Season::current();
    }

    /** Sezon w przygotowaniu (szkic albo zatwierdzony), pokazywany jako „wkrótce”, gdy nie ma aktywnego. */
    #[Computed]
    public function upcoming(): ?Season
    {
        return $this->season
            ? null
            : Season::whereIn('status', [SeasonStatus::Draft->value, SeasonStatus::Approved->value])->orderBy('number')->first();
    }

    /** Najbliższa kolejka bez wyniku (z rywalem i datą), a gdy wszystkie rozegrane, to ostatnia. */
    #[Computed]
    public function matchday(): ?Matchday
    {
        if (!$this->season) {
            return null;
        }

        $matchdays = Matchday::where('season_id', $this->season->id)->orderBy('number')->get();

        return $matchdays->first(fn($m) => $m->status !== MatchdayStatus::Played && $m->isFilled())
            ?? $matchdays->last(fn($m) => $m->status === MatchdayStatus::Played);
    }

    /** Ostatnia rozegrana kolejka, gdy w tablicy jest już następna (wynik i protokół pod spodem). */
    #[Computed]
    public function lastPlayed(): ?Matchday
    {
        if (!$this->matchday || $this->matchday->status === MatchdayStatus::Played) {
            return null;
        }

        return Matchday::where('season_id', $this->matchday->season_id)->where('status', MatchdayStatus::Played->value)->orderByDesc('number')->first();
    }

    /**
     * Protokół meczu Lecha (gole, kartki, zmiany zawodników Lecha) rozegranej kolejki.
     *
     * @return \Illuminate\Support\Collection<int, MatchEvent>
     */
    public function protocolOf(?Matchday $matchday)
    {
        if (!$matchday || $matchday->status !== MatchdayStatus::Played || !$matchday->lech_match_id) {
            return collect();
        }

        return MatchEvent::with(['player:id,name', 'other:id,name'])->where('lech_match_id', $matchday->lech_match_id)->orderBy('id')->get();
    }

    /** Liczby serwisu do paska pod banerem: gracze, zakończone sezony, rozegrane kolejki, zdobyte trofea. */
    #[Computed]
    public function numbers(): array
    {
        return [
            ['icon' => 'users', 'value' => \App\Support\Players::eligible()->count(), 'label' => __('Players')],
            ['icon' => 'calendar', 'value' => Season::where('status', SeasonStatus::Finished->value)->count(), 'label' => __('Seasons played')],
            ['icon' => 'flag', 'value' => Matchday::where('status', MatchdayStatus::Played->value)->count(), 'label' => __('Lech matches tipped')],
            ['icon' => 'trophy', 'value' => \App\Models\HallOfFameAward::whereNotNull('trophy')->count(), 'label' => __('Trophies won')],
        ];
    }

    /** Ostatni mistrzowie najważniejszych rozgrywek (App\Support\CompetitionHistory). */
    #[Computed]
    public function champions(): array
    {
        return \App\Support\CompetitionHistory::latestChampions();
    }

    /** Typ zalogowanego gracza na tę kolejkę (null, gdy brak albo gość). */
    #[Computed]
    public function myTip(): ?Tip
    {
        return $this->matchday && auth()->check()
            ? Tip::where('matchday_id', $this->matchday->id)->where('user_id', auth()->id())->first()
            : null;
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    @if ($this->season)
        @php
            $season = $this->season;
        @endphp

        {{-- Baner sezonu (wzór dla banerów innych stron: x-page-banner) --}}
        <x-page-banner :title="$season->title" :subtitle="filled($season->slogan) ? $season->slogan : null">
            <x-slot:aside>
                @if ($season->sponsor_logo_url || filled($season->sponsor_name))
                    @php
                        $sponsorTag = filled($season->sponsor_url) ? 'a' : 'div';
                    @endphp
                    <{{ $sponsorTag }}
                        @if (filled($season->sponsor_url)) href="{{ $season->sponsor_url }}" target="_blank" rel="noopener sponsored" @endif
                        class="flex shrink-0 items-center gap-3 self-start rounded-xl bg-white/10 px-4 py-3 backdrop-blur transition hover:bg-white/20 md:self-auto">
                        <span class="text-xs uppercase tracking-widest text-lech-200">powered by</span>
                        @if ($season->sponsor_logo_url)
                            <img src="{{ $season->sponsor_logo_url }}" alt="{{ $season->sponsor_name ?? '' }}"
                                class="h-12 w-12 rounded-md bg-white object-contain p-1">
                        @endif
                        @if (filled($season->sponsor_name))
                            <span class="font-semibold">{{ $season->sponsor_name }}</span>
                        @endif
                    </{{ $sponsorTag }}>
                @endif
            </x-slot:aside>
        </x-page-banner>

        {{-- Najbliższa kolejka (albo ostatnia, gdy wszystkie rozegrane): tablica wyników z odliczaniem do pierwszego gwizdka --}}
        @if ($this->matchday)
            @php
                $matchday = $this->matchday;
                $played = $matchday->status === \App\Enums\MatchdayStatus::Played;
                $open = !$played && $matchday->isOpenForTips() && $matchday->kickoff_at;
                // Gospodarz po lewej jak w prawdziwym meczu, Lech zawsze wyróżniony.
                $sides = $matchday->is_home
                    ? [['name' => 'Lech Poznań', 'lech' => true, 'goals' => $matchday->lech_goals], ['name' => $matchday->opponent, 'lech' => false, 'goals' => $matchday->opponent_goals]]
                    : [['name' => $matchday->opponent, 'lech' => false, 'goals' => $matchday->opponent_goals], ['name' => 'Lech Poznań', 'lech' => true, 'goals' => $matchday->lech_goals]];
                $lechWon = $played && $matchday->lech_goals > $matchday->opponent_goals;
                $lechLost = $played && $matchday->lech_goals < $matchday->opponent_goals;
            @endphp
            <section class="lech-bar-shadow overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                {{-- Belka: rodzaj kolejki, numer i rozgrywki, stan --}}
                <div class="lech-bar flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <flux:icon :name="$played ? 'flag' : 'clock'" variant="mini" class="text-lech-200" />
                        <span class="text-sm font-bold uppercase tracking-[0.15em]">{{ $played ? __('Last matchday') : __('Next matchday') }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="rounded-full bg-white/15 px-2.5 py-1 font-semibold">{{ __('Matchday :number', ['number' => $matchday->number]) }}</span>
                        @if ($matchday->competitionLabel())
                            <span class="rounded-full bg-white/10 px-2.5 py-1 text-lech-100">{{ $matchday->competitionLabel() }}</span>
                        @endif
                        <span class="{{ $played ? 'bg-zinc-200 text-zinc-800' : ($open ? 'bg-green-500 text-white' : 'bg-amber-400 text-amber-950') }} rounded-full px-2.5 py-1 font-bold uppercase">
                            {{ $played ? __('Full time') : ($open ? __('Tipping open') : __('Tipping closed')) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-5 px-5 py-6 sm:px-8">
                    {{-- Tablica: gospodarz, wynik albo „vs”, gość --}}
                    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6">
                        @foreach ($sides as $index => $side)
                            @if ($index === 1)
                                <div class="flex flex-col items-center gap-1">
                                    @if ($played)
                                        <div class="{{ $lechWon ? 'bg-green-500 text-white' : ($lechLost ? 'bg-red-500 text-white' : 'bg-yellow-300 text-yellow-950') }} flex items-center gap-2 rounded-xl px-4 py-2 text-4xl font-black tabular-nums sm:text-5xl">
                                            <span>{{ $sides[0]['goals'] }}</span><span class="opacity-60">:</span><span>{{ $sides[1]['goals'] }}</span>
                                        </div>
                                    @else
                                        <div class="lech-bar rounded-xl px-4 py-2 text-2xl font-black uppercase tracking-widest sm:text-3xl">vs</div>
                                    @endif
                                </div>
                            @endif
                            <div class="{{ $index === 0 ? 'items-end text-right' : 'items-start text-left' }} flex min-w-0 flex-col gap-1">
                                <span class="{{ $side['lech'] ? 'text-lech-700 dark:text-lech-300' : 'text-zinc-800 dark:text-zinc-100' }} truncate text-lg font-black sm:text-2xl">{{ $side['name'] }}</span>
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800">{{ $index === 0 ? __('HOME') : __('AWAY') }}</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- Protokół meczu (tylko zawodnicy Lecha) --}}
                    @php
                        $protocol = $this->protocolOf($played ? $matchday : null);
                    @endphp
                    @if ($protocol->isNotEmpty())
                        <div class="mx-auto w-full max-w-md rounded-xl border border-zinc-100 px-3 py-1 dark:border-zinc-800">
                            @include('partials.match-protocol', ['events' => $protocol])
                        </div>
                    @endif

                    @if ($matchday->kickoff_at)
                        <div class="flex items-center justify-center gap-1.5 text-sm text-zinc-500">
                            <flux:icon.calendar-days variant="mini" />
                            <span>{{ $matchday->kickoff_at->translatedFormat('l, j F Y, H:i') }}</span>
                        </div>
                    @endif

                    {{-- Odliczanie do pierwszego gwizdka (koniec typowania): kafelki dni, godzin, minut, sekund --}}
                    @if ($open)
                        <div x-data="{
                                end: {{ $matchday->kickoff_at->getTimestamp() * 1000 }},
                                parts: [0, 0, 0, 0],
                                tick() {
                                    let s = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
                                    this.parts = [Math.floor(s / 86400), Math.floor(s % 86400 / 3600), Math.floor(s % 3600 / 60), s % 60];
                                },
                            }" x-init="tick(); setInterval(() => tick(), 1000)" class="space-y-2 text-center">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">{{ __('Tipping closes in') }}</div>
                            <div class="flex justify-center gap-2 sm:gap-3">
                                @foreach ([__('days'), __('hours'), __('min'), __('sec')] as $unit)
                                    <div class="lech-bar w-16 rounded-xl py-2 sm:w-20">
                                        <div class="text-2xl font-black tabular-nums sm:text-3xl" x-text="String(parts[{{ $loop->index }}]).padStart(2, '0')"></div>
                                        <div class="text-[10px] uppercase tracking-wider text-lech-200">{{ $unit }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Akcje: typowanie albo wyniki --}}
                    <div class="flex flex-wrap items-center justify-center gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        @if ($played)
                            <flux:button :href="route('results')" wire:navigate icon="trophy">{{ __('Results and tables') }}</flux:button>
                        @elseif (!auth()->check())
                            <flux:button variant="primary" :href="route('login')" wire:navigate>{{ __('Log in to tip') }}</flux:button>
                        @elseif ($matchday->isOpenForTips())
                            @if ($this->myTip)
                                <flux:badge color="green">{{ __('Your tip: :score', ['score' => $this->myTip->score()]) }}</flux:badge>
                            @endif
                            <flux:button variant="primary" icon="pencil-square"
                                :href="route('tips', ['matchday_slug' => 'kolejka-' . $matchday->number])" wire:navigate>
                                {{ $this->myTip ? __('Change tip') : __('Tip now') }}
                            </flux:button>
                        @else
                            @if ($this->myTip)
                                <flux:badge color="zinc">{{ __('Your tip: :score', ['score' => $this->myTip->score()]) }}</flux:badge>
                            @endif
                            <flux:button :href="route('results')" wire:navigate icon="trophy">{{ __('Results and tables') }}</flux:button>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Ostatni mecz Lecha (gdy w tablicy jest już następna kolejka): wynik i protokół --}}
            @if ($last = $this->lastPlayed)
                @php
                    $lastProtocol = $this->protocolOf($last);
                    $lastScore = $last->is_home ? $last->lech_goals . ':' . $last->opponent_goals : $last->opponent_goals . ':' . $last->lech_goals;
                @endphp
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                        <span class="text-xs font-bold uppercase tracking-[0.15em] text-zinc-500">{{ __('Last matchday') }} · {{ __('Matchday :number', ['number' => $last->number]) }}</span>
                        <span class="text-sm font-bold">
                            {{ $last->is_home ? 'Lech Poznań' : $last->opponent }}
                            <span class="lech-bar mx-1.5 rounded-md px-2 py-0.5 tabular-nums">{{ $lastScore }}</span>
                            {{ $last->is_home ? $last->opponent : 'Lech Poznań' }}
                        </span>
                    </div>
                    @if ($lastProtocol->isNotEmpty())
                        <div class="mx-auto max-w-md px-5 py-2">
                            @include('partials.match-protocol', ['events' => $lastProtocol])
                        </div>
                    @endif
                </section>
            @endif
        @endif
    @else
        {{-- Bez aktywnego sezonu: duży baner zapraszający do gry (z zapowiedzią sezonu w przygotowaniu) --}}
        <section class="lech-banner relative overflow-hidden rounded-3xl px-6 py-12 sm:px-12 sm:py-16">
            <div class="pointer-events-none absolute -right-16 -top-16 size-80 rounded-full bg-lech-500/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 left-1/3 size-72 rounded-full bg-sky-400/10 blur-3xl"></div>
            <x-app-logo-icon class="pointer-events-none absolute -right-6 bottom-0 hidden size-72 text-white opacity-[0.07] md:block" />
            <div class="relative max-w-2xl space-y-5">
                @if ($this->upcoming)
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-bold uppercase tracking-widest text-lech-100">
                        <span class="size-2 animate-pulse rounded-full bg-green-400"></span>
                        {{ $this->upcoming->title }} · {{ __('Coming soon') }}
                    </span>
                @else
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-lech-200">LechTYPER</p>
                @endif
                <h1 class="text-4xl font-black leading-tight tracking-tight sm:text-6xl">{{ __('Tip Lech. Pick your squad. Win trophies.') }}</h1>
                <p class="text-lg text-lech-100 sm:text-xl">{{ __('One tip per Lech match decides your games in a league, the Polish Cup, European cups and Liga Legend. Pick Lech players, collect experience and climb from Liga podwórkowa to Ekstraklasa.') }}</p>
                <div class="flex flex-wrap gap-3 pt-2">
                    @guest
                        <a href="{{ route('register') }}" class="rounded-full bg-white px-6 py-3 text-sm font-black uppercase tracking-wide text-lech-900 shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{{ __('Join the game') }}</a>
                    @else
                        <a href="{{ route('tips') }}" wire:navigate class="rounded-full bg-white px-6 py-3 text-sm font-black uppercase tracking-wide text-lech-900 shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{{ __('My LechTYPER') }}</a>
                    @endguest
                    <a href="{{ route('rules') }}" wire:navigate class="rounded-full border border-white/30 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">{{ __('Rules of the game') }}</a>
                    <a href="{{ route('history') }}" wire:navigate class="rounded-full border border-white/30 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">{{ __('History and records') }}</a>
                </div>
            </div>
        </section>
    @endif

    {{-- Liczby serwisu --}}
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($this->numbers as $number)
            <div class="group flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                <span class="lech-banner grid size-11 shrink-0 place-items-center rounded-xl shadow">
                    <flux:icon :icon="$number['icon']" class="size-5 text-white" />
                </span>
                <div class="min-w-0">
                    <div class="text-2xl font-black leading-none tabular-nums text-lech-800 dark:text-lech-200">{{ number_format($number['value'], 0, ',', ' ') }}</div>
                    <div class="text-[11px] font-semibold uppercase leading-tight tracking-wide text-zinc-500">{{ $number['label'] }}</div>
                </div>
            </div>
        @endforeach
    </section>

    @unless ($this->season)
        {{-- Jak to działa: trzy kroki --}}
        <section class="grid gap-3 md:grid-cols-3">
            @foreach ([
                ['pencil-square', __('Tip the score'), __('Before kickoff tip the score of the Lech match. One tip counts in every competition you play.')],
                ['user-group', __('Pick Lech players'), __('Field Lech players for each competition: goals and assists add attack, clean sheets stop the rival.')],
                ['trophy', __('Win trophies'), __('Climb the leagues, go deep in the cups and collect Hall of Fame points season after season.')],
            ] as $index => [$icon, $title, $text])
                <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <span class="pointer-events-none absolute -right-2 -top-6 select-none text-8xl font-black text-lech-700/5 dark:text-white/5">{{ $index + 1 }}</span>
                    <flux:icon :icon="$icon" class="size-7 text-lech-700 dark:text-lech-300" />
                    <h3 class="mt-3 text-lg font-black">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                </div>
            @endforeach
        </section>
    @endunless

    {{-- Ostatni mistrzowie w barwach rozgrywek --}}
    @if ($this->champions['rows'] !== [])
        @php
            $trophyIcons = \App\Support\HallOfFame::iconUrls();
        @endphp
        <section class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-lech-700 dark:text-lech-300">{{ $this->champions['season']->title }}</p>
                    <h2 class="text-2xl font-black">{{ __('Latest champions') }}</h2>
                </div>
                <a href="{{ route('history') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-lech-700 hover:underline dark:text-lech-300">
                    {{ __('Full history') }} <flux:icon.arrow-right variant="micro" />
                </a>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->champions['rows'] as $row)
                    @php
                        $ck = \App\Support\CompetitionHistory::colorKey($row['key']);
                    @endphp
                    <a href="{{ route('history', \App\Support\CompetitionHistory::slug($row['key'])) }}" wire:navigate
                        class="comp-banner group flex items-center gap-4 rounded-2xl px-4 py-4 shadow-sm transition hover:-translate-y-1 hover:shadow-xl" style="{{ \App\Support\CompetitionColors::style($ck) }}">
                        <span class="comp-badge relative z-10 grid size-14 shrink-0 place-items-center rounded-full shadow-lg">
                            @if (isset($trophyIcons[$row['key']]))
                                <img src="{{ $trophyIcons[$row['key']] }}" alt="" class="size-10 object-contain">
                            @else
                                <flux:icon.trophy class="size-7" />
                            @endif
                        </span>
                        <span class="relative z-10 min-w-0">
                            <span class="comp-accent block text-[11px] font-bold uppercase tracking-widest">{{ $row['label'] }}</span>
                            <span class="{{ $row['team']['bot'] ? 'italic' : '' }} block truncate text-lg font-black">{{ $row['team']['name'] }}</span>
                            <span class="block text-xs opacity-75">{{ $row['line'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Biuro skarg i wniosków (/office): odwołania, wnioski o nazwy botów z głosowaniem graczy, inne sprawy --}}
    @php
        $voting = \App\Models\OfficeRequest::where('status', \App\Models\OfficeRequest::VOTING)->count();
    @endphp
    <section class="lech-banner relative overflow-hidden rounded-2xl p-5 text-white shadow-lg sm:p-6">
        <flux:icon.inbox class="pointer-events-none absolute -right-6 -top-6 size-40 text-white/5" />
        <div class="relative grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="space-y-3">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-lech-200">{{ __('Contact the administrator') }}</p>
                <h2 class="text-2xl font-black tracking-tight">{{ __('Complaints and requests office') }}</h2>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ([['no-symbol', __('Ban appeal')], ['moon', __('Inactive status appeal')], ['cpu-chip', __('New bot name (premium, players vote)')]] as [$icon, $label])
                        <div class="flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-sm">
                            <flux:icon :name="$icon" variant="mini" class="shrink-0 text-lech-200" />{{ $label }}
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-col items-start gap-2 lg:items-end">
                <flux:button variant="primary" icon="inbox" :href="route('office')" wire:navigate>{{ __('Go to the office') }}</flux:button>
                @if ($voting > 0)
                    <span class="text-xs text-lech-100">{{ trans_choice(':count bot name is being voted on|:count bot names are being voted on', $voting, ['count' => $voting]) }}</span>
                @endif
            </div>
        </div>
    </section>

    {{-- Newsy z panelu z ocenami kciukami: siatka 3 x 5 z paginacją --}}
    <livewire:pages::home.news />

</div>
