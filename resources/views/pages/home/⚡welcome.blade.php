<?php

use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
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
        @endif
    @elseif ($this->upcoming)
        {{-- Brak aktywnego sezonu, ale kolejny jest w przygotowaniu --}}
        <x-page-banner :title="$this->upcoming->title" :subtitle="__('Coming soon')" />
    @else
        <div class="py-16">
            <flux:heading size="xl" level="1">{{ __('Welcome') }}</flux:heading>
            <flux:subheading>{{ __('This is the public home page.') }}</flux:subheading>
        </div>
    @endif

    {{-- Newsy z panelu z ocenami kciukami: siatka 3 x 5 z paginacją --}}
    <livewire:pages::home.news />
</div>
