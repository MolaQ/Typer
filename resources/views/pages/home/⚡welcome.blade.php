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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    @if ($this->season)
        @php
            $season = $this->season;
        @endphp

        {{-- Baner sezonu --}}
        <section
            class="relative overflow-hidden rounded-2xl bg-linear-to-br from-lech-950 via-lech-800 to-lech-600 px-6 py-10 text-white shadow-lg sm:px-10">
            {{-- Duża cyfra rzymska w tle --}}
            <span aria-hidden="true"
                class="pointer-events-none absolute -right-4 -top-10 select-none text-[10rem] font-black leading-none text-white/10 sm:text-[14rem]">{{ $season->roman_number }}</span>

            <div class="relative flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div class="space-y-3">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-lech-200">LechTYPER</p>
                    <h1 class="text-4xl font-black tracking-tight sm:text-5xl">{{ $season->title }}</h1>
                    @if (filled($season->slogan))
                        <p class="max-w-xl text-lg text-lech-100 sm:text-xl">{{ $season->slogan }}</p>
                    @endif
                </div>

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
            </div>
        </section>

        {{-- Najbliższa kolejka --}}
        @if ($this->matchday)
            @php
                $matchday = $this->matchday;
                $played = $matchday->status === \App\Enums\MatchdayStatus::Played;
            @endphp
            <flux:card class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="space-y-1">
                    <flux:text size="sm">
                        {{ $played ? __('Last matchday') : __('Next matchday') }}:
                        {{ __('Matchday :number', ['number' => $matchday->number]) }}
                        @if ($matchday->competition) · {{ $matchday->competition }} @endif
                    </flux:text>
                    <flux:heading size="lg">
                        {{ $matchday->fixture }}
                        @if ($played) <span class="tabular-nums">({{ $matchday->lech_goals }}:{{ $matchday->opponent_goals }})</span> @endif
                    </flux:heading>
                    @if ($matchday->kickoff_at)
                        <flux:text>{{ $matchday->kickoff_at->translatedFormat('l, j F Y, H:i') }}</flux:text>
                    @endif

                    {{-- Odliczanie do pierwszego gwizdka (koniec typowania) --}}
                    @if (!$played && $matchday->isOpenForTips() && $matchday->kickoff_at)
                        <div x-data="{
                                end: {{ $matchday->kickoff_at->getTimestamp() * 1000 }},
                                left: '',
                                tick() {
                                    let s = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
                                    const d = Math.floor(s / 86400); s %= 86400;
                                    const h = Math.floor(s / 3600); s %= 3600;
                                    const m = Math.floor(s / 60); s %= 60;
                                    this.left = (d ? d + ' {{ __('d') }} ' : '') + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                                },
                            }" x-init="tick(); setInterval(() => tick(), 1000)"
                            class="pt-1 text-sm font-medium text-lech-700 dark:text-lech-300">
                            {{ __('Tipping closes in') }} <span class="tabular-nums" x-text="left"></span>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col items-start gap-2 md:items-end">
                    @if ($played)
                        <flux:button :href="route('results')" wire:navigate icon="trophy">{{ __('Results and tables') }}</flux:button>
                    @elseif (!auth()->check())
                        <flux:button variant="primary" :href="route('login')" wire:navigate>{{ __('Log in to tip') }}</flux:button>
                    @elseif ($matchday->isOpenForTips())
                        @if ($this->myTip)
                            <flux:badge color="green">{{ __('Your tip: :score', ['score' => $this->myTip->score()]) }}</flux:badge>
                        @endif
                        <flux:button variant="primary" icon="pencil-square"
                            :href="route('tips', ['matchday' => $matchday->number])" wire:navigate>
                            {{ $this->myTip ? __('Change tip') : __('Tip now') }}
                        </flux:button>
                    @else
                        <flux:badge color="zinc">{{ __('Tipping for this matchday is closed.') }}</flux:badge>
                        <flux:button :href="route('results')" wire:navigate icon="trophy">{{ __('Results and tables') }}</flux:button>
                    @endif
                </div>
            </flux:card>
        @endif
    @elseif ($this->upcoming)
        {{-- Brak aktywnego sezonu, ale kolejny jest w przygotowaniu --}}
        <section
            class="relative overflow-hidden rounded-2xl bg-linear-to-br from-zinc-900 via-zinc-800 to-lech-900 px-6 py-10 text-white sm:px-10">
            <span aria-hidden="true"
                class="pointer-events-none absolute -right-4 -top-10 select-none text-[10rem] font-black leading-none text-white/10 sm:text-[14rem]">{{ $this->upcoming->roman_number }}</span>
            <div class="relative space-y-3">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-zinc-300">LechTYPER</p>
                <h1 class="text-4xl font-black tracking-tight sm:text-5xl">{{ $this->upcoming->title }}</h1>
                <p class="text-lg text-zinc-200">{{ __('Coming soon') }}</p>
            </div>
        </section>
    @else
        <div class="py-16">
            <flux:heading size="xl" level="1">{{ __('Welcome') }}</flux:heading>
            <flux:subheading>{{ __('This is the public home page.') }}</flux:subheading>
        </div>
    @endif

    {{-- Wyszukiwarka zespołów (premium) --}}
    @auth
        <livewire:pages::home.team-search />
    @endauth

    {{-- Hall of Fame: skrócony ranking, otwarty na stronie z zalogowanym graczem --}}
    <section class="space-y-3">
        <div class="flex items-end justify-between gap-3">
            <flux:heading size="lg">{{ __('Hall of Fame') }}</flux:heading>
            <flux:link :href="route('hall-of-fame')" wire:navigate class="text-sm">{{ __('Full ranking') }}</flux:link>
        </div>
        <livewire:pages::home.hall-of-fame-ranking :per-page="10" :compact="true" />
    </section>

    {{-- Zasady gry i FAQ w akordeonach (pełne wersje pod /rules i /faq) --}}
    <section class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-3">
            <div class="flex items-end justify-between gap-3">
                <flux:heading size="lg">{{ __('Rules of the game') }}</flux:heading>
                <flux:link :href="route('rules')" wire:navigate class="text-sm">{{ __('All rules') }}</flux:link>
            </div>
            <x-accordion name="home-rules" :items="array_slice(\App\Support\Guide::rules(), 0, 5)" />
        </div>
        <div class="space-y-3">
            <div class="flex items-end justify-between gap-3">
                <flux:heading size="lg">{{ __('FAQ') }}</flux:heading>
                <flux:link :href="route('faq')" wire:navigate class="text-sm">{{ __('All questions') }}</flux:link>
            </div>
            <x-accordion name="home-faq" :items="array_slice(\App\Support\Guide::faq(), 0, 5)" />
        </div>
    </section>
</div>
