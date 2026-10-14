<?php

use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Support\MatchSheet;
use App\Support\PlayerCompetitions;
use App\Support\Squad;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Desygnowanie zawodników na /tips (zakładka Typowanie). Dwie części tego samego komponentu (`part`), zsynchronizowane zdarzeniami:
 *  - 'select' (obok tablicy typu, 3/4 szerokości): wybór typu rozgrywek, do którego dodajemy (kafelki z licznikiem),
 *    dostępni zawodnicy według doświadczenia w klubie gracza (filtr B / O / P / N, wystawieni w barwach swojego typu),
 *  - 'cards' (pod spodem, cała szerokość): karty typów rozgrywek z wystawionymi, po rozliczeniu protokoły meczów
 *    (Support\MatchSheet) z legendą, niżej odpoczywający (kolor według godzin do końca odpoczynku).
 * Klik w zawodnika: dodaje do wybranego typu, przenosi z innego typu albo zdejmuje (zapis od razu). Zasady: Support\Squad.
 */
new class extends Component {
    #[Locked]
    public int $matchdayId = 0;

    /** Część: 'select' (wybór zawodników) albo 'cards' (karty typów, protokoły, odpoczywający). */
    #[Locked]
    public string $part = 'select';

    /** Typ rozgrywek, do którego dodajemy kliknięciem (wartość CompetitionType). */
    public string $active = '';

    public function mount(): void
    {
        $this->active = ($this->sets[0] ?? null)?->value ?? '';
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return Matchday::find($this->matchdayId);
    }

    #[Computed]
    public function isOpen(): bool
    {
        return (bool) $this->matchday?->isOpenForTips() && $this->matchday->isFilled();
    }

    /** @return array<int, CompetitionType> */
    #[Computed]
    public function sets(): array
    {
        $md = $this->matchday;

        return $md && auth()->id() ? PlayerCompetitions::types(auth()->id(), $md->season_id, $md->number) : [];
    }

    /** Kadra do wyboru według doświadczenia zawodnika w klubie gracza (remis: pozycja i numer). */
    #[Computed]
    public function squad()
    {
        $experience = Squad::clubExperience(auth()->id(), null);

        return Squad::available()->sortByDesc(fn ($p) => $experience[$p->id] ?? 0)->values()
            ->each(fn ($p) => $p->setAttribute('club_experience', $experience[$p->id] ?? 0.0));
    }

    /** Protokoły meczów rozliczonej kolejki: typ rozgrywek => mecze. */
    #[Computed]
    public function sheets(): array
    {
        $user = auth()->user();

        return $user && $this->matchday && $this->part === 'cards' ? MatchSheet::forUser($user, $this->matchday) : [];
    }

    /** @return array<string, array<int, int>> typ rozgrywek => id zawodników */
    #[Computed]
    public function picks(): array
    {
        return $this->matchday ? Squad::picks(auth()->id(), null, $this->matchday) : [];
    }

    /** @return array<int, int> id zawodnika => wolny od (timestamp) */
    #[Computed]
    public function locks(): array
    {
        return $this->matchday ? Squad::locks(auth()->id(), null, $this->matchday) : [];
    }

    /**
     * Barwy kart (klucz CompetitionColors): liga gracza, Puchar Polski, jego puchar europejski, Liga Legend.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function setColors(): array
    {
        $colors = [
            CompetitionType::League->value => 'league_1',
            CompetitionType::Cup->value => 'cup',
            CompetitionType::Champions->value => 'champions',
            CompetitionType::Legends->value => 'legends',
        ];
        $teamId = $this->matchday ? SeasonTeam::where('season_id', $this->matchday->season_id)->where('user_id', auth()->id())->value('id') : null;

        if ($teamId) {
            foreach (CompetitionEntry::with('competition')->where('season_team_id', $teamId)->get() as $entry) {
                if ($entry->competition) {
                    $colors[$entry->competition->type->scoreSet()->value] = $entry->competition->trophyKey();
                }
            }
        }

        return $colors;
    }

    /** Wybór typu rozgrywek (kafelek albo karta), wspólny dla obu części. */
    #[On('designation-active')]
    public function selectSet(string $set): void
    {
        if (CompetitionType::tryFrom($set) && in_array(CompetitionType::from($set), $this->sets, true) && $this->active !== $set) {
            $this->active = $set;
            $this->dispatch('designation-active', set: $set);
        }
    }

    /** Skład zmieniony w drugiej części. */
    #[On('designation-changed')]
    public function refreshPicks(): void
    {
        unset($this->picks, $this->squad);
    }

    /** Klik w zawodnika: do wybranego typu rozgrywek (albo podanego, np. zdjęcie z karty typu). */
    public function toggle(int $playerId, string $set = ''): void
    {
        $type = CompetitionType::tryFrom($set !== '' ? $set : $this->active);
        $user = auth()->user();

        if (!$type || !$user || !$this->matchday) {
            return;
        }

        try {
            Squad::toggle($user, $this->matchday, $type, $playerId);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        unset($this->picks);
        $this->dispatch('designation-changed');
    }
}; ?>

<div>
    @if ($this->matchday && count($this->sets) > 0)
        @php
            $max = \App\Support\Designation::value('max_players');
            $picks = $this->picks;
            $locks = $this->locks;
            $squad = $this->squad;
            $colors = $this->setColors;
            $sets = collect($this->sets)->keyBy(fn ($set) => $set->value);
            // Gdzie wystawiony jest zawodnik: id => typ rozgrywek (wartość).
            $pickedIn = [];
            foreach ($picks as $setValue => $ids) {
                foreach ($ids as $id) {
                    $pickedIn[$id] = $setValue;
                }
            }
            $open = $this->isOpen;
            $active = $sets->get($this->active);
            $fmt = fn ($v) => \App\Support\ClubStats::experience($v);
        @endphp

        @if ($part === 'select')
            @php
                $available = $squad->filter(fn ($p) => !isset($locks[$p->id]));
            @endphp
            <div class="space-y-3">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <flux:heading size="lg">{{ __('Your line-ups') }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ __('Field up to :count Lech players in each competition type. Their goals and assists add attack points, clean sheets take points from the rival.', ['count' => $max]) }}
                        </flux:text>
                    </div>
                    @if (!$open)
                        <flux:badge color="zinc" icon="lock-closed">{{ __('Line-ups closed') }}</flux:badge>
                    @endif
                </div>

                {{-- Wybór typu rozgrywek, do którego trafia kliknięty zawodnik --}}
                @if ($open)
                    <div class="space-y-1.5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Add players to') }}</div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ($sets as $setValue => $set)
                                @php
                                    $isActive = $setValue === $this->active;
                                    $count = count($picks[$setValue] ?? []);
                                @endphp
                                <button type="button" wire:click="selectSet('{{ $setValue }}')" wire:key="pick-set-{{ $setValue }}"
                                    style="{{ \App\Support\CompetitionColors::style($colors[$setValue] ?? 'league_1') }}"
                                    class="{{ $isActive ? 'comp-chip shadow-md ring-2 ring-offset-2 ring-lech-600 ring-offset-white dark:ring-offset-zinc-900' : 'comp-chip opacity-60 hover:opacity-100' }} flex items-center justify-between gap-2 rounded-lg px-2.5 py-1 text-left transition">
                                    <span class="truncate text-xs font-black uppercase tracking-wide">{{ $set->scoreSetLabel() }}</span>
                                    <span class="shrink-0 text-xs font-bold tabular-nums">{{ $count }}/{{ $max }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Dostępni zawodnicy według doświadczenia w klubie: barwa = typ rozgrywek, w którym są wystawieni --}}
                <section class="space-y-2 rounded-2xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ pos: '' }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading>{{ __('Available players') }} <span class="font-normal text-zinc-500">({{ $available->count() }})</span></flux:heading>
                        <div class="flex gap-1">
                            <button type="button" x-on:click="pos = ''" x-bind:class="pos === '' ? 'bg-lech-700 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'"
                                class="rounded-full px-2.5 py-0.5 text-xs font-bold">{{ __('All') }}</button>
                            @foreach (\App\Enums\PlayerPosition::cases() as $position)
                                <button type="button" x-on:click="pos = '{{ $position->value }}'" title="{{ $position->label() }}"
                                    x-bind:class="pos === '{{ $position->value }}' ? 'bg-lech-700 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'"
                                    class="rounded-full px-2.5 py-0.5 text-xs font-bold">{{ $position->short() }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-1 sm:grid-cols-3">
                        @foreach ($available as $player)
                            @php
                                $in = $pickedIn[$player->id] ?? null;
                                $inSet = $in ? $sets->get($in) : null;
                                $full = $active && $in !== $this->active && count($picks[$this->active] ?? []) >= $max;
                                $title = ($inSet ? $inSet->scoreSetLabel() . '. ' : '') . (!$open ? '' : ($in === $this->active ? __('Remove') : ($full ? __('You can field at most :count players here.', ['count' => $max]) : ($in ? __('Move to :set', ['set' => $active?->scoreSetLabel()]) : __('Add to :set', ['set' => $active?->scoreSetLabel()])))));
                            @endphp
                            <button type="button" wire:click="toggle({{ $player->id }})" wire:key="avail-{{ $player->id }}" @disabled(!$open || $full)
                                x-show="pos === '' || pos === '{{ $player->position->value }}'" title="{{ $title }}"
                                @if ($in) style="{{ \App\Support\CompetitionColors::style($colors[$in] ?? 'league_1') }}" @endif
                                class="{{ $in ? 'comp-chip font-bold shadow-sm' : 'border border-zinc-200 hover:border-lech-400 hover:bg-lech-50 dark:border-zinc-700 dark:hover:bg-white/5' }} {{ $full && !$in ? 'opacity-50' : '' }} flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1 text-left text-xs transition disabled:cursor-default">
                                <span class="w-4 shrink-0 text-[10px] font-semibold uppercase opacity-70">{{ $player->position->short() }}</span>
                                <span class="w-4 shrink-0 text-right text-[10px] tabular-nums opacity-60">{{ $player->number }}</span>
                                <span class="min-w-0 flex-1 truncate">
                                    {{ $player->name }}
                                    @if ($player->is_academy)
                                        <flux:icon.academic-cap variant="micro" class="inline opacity-70" />
                                    @endif
                                </span>
                                @if ($inSet)
                                    <flux:icon.check-circle variant="micro" class="shrink-0" />
                                @endif
                                <span class="shrink-0 text-[10px] tabular-nums opacity-70" title="{{ __('Experience in your club') }}">{{ $fmt($player->club_experience) }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
                <flux:text class="text-xs">{{ __('The number next to a player is his experience in your club. A player can be in only one competition type per matchday: clicking him with another type selected moves him there.') }}</flux:text>
            </div>
        @else
            @php
                $resting = $squad->filter(fn ($p) => isset($locks[$p->id]))->sortBy(fn ($p) => $locks[$p->id]);
                $sheets = $this->sheets;
                $cols = match (true) {
                    $sets->count() >= 4 => 'sm:grid-cols-2 lg:grid-cols-4',
                    $sets->count() === 3 => 'sm:grid-cols-3',
                    default => 'sm:grid-cols-2',
                };
            @endphp
            <div class="space-y-4">
                {{-- Karty typów rozgrywek: wystawieni (klik w kartę wybiera typ, klik w zawodnika zdejmuje), po rozliczeniu protokoły meczów --}}
                <div class="{{ $cols }} grid items-start gap-3">
                    @foreach ($sets as $setValue => $set)
                        @php
                            $mine = $squad->whereIn('id', $picks[$setValue] ?? [])->sortBy(fn ($p) => array_search($p->position, \App\Enums\PlayerPosition::cases(), true));
                            $isActive = $open && $setValue === $this->active;
                            $setSheets = $sheets[$setValue] ?? [];
                        @endphp
                        <section wire:key="set-{{ $setValue }}" style="{{ \App\Support\CompetitionColors::style($colors[$setValue] ?? 'league_1') }}"
                            @if ($open) wire:click="selectSet('{{ $setValue }}')" @endif
                            class="comp-banner {{ $isActive ? 'ring-2 ring-lech-600 ring-offset-2 ring-offset-white dark:ring-offset-zinc-900' : '' }} {{ $open ? 'cursor-pointer' : '' }} min-w-0 rounded-2xl p-2.5">
                            <header class="relative z-10 flex items-center justify-between gap-2 px-0.5">
                                <h2 class="min-w-0 text-xs font-black uppercase leading-tight tracking-[0.12em]">{{ $set->scoreSetLabel() }}</h2>
                                <span class="comp-badge shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-bold tabular-nums">{{ $mine->count() }}/{{ $max }}</span>
                            </header>

                            @if ($setSheets === [])
                                <div class="relative z-10 mt-2 flex min-h-8 flex-col gap-1">
                                    @forelse ($mine as $player)
                                        @php
                                            $forfeit = isset($locks[$player->id]);
                                        @endphp
                                        <button type="button" wire:click.stop="toggle({{ $player->id }}, '{{ $setValue }}')" @disabled(!$open)
                                            class="{{ $forfeit ? 'bg-red-600 text-white' : 'bg-white/90 text-zinc-900' }} flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1 text-left text-xs font-semibold shadow-sm"
                                            title="{{ $forfeit ? __('This player rests now: the pick does not count.') : ($open ? __('Remove') : '') }}">
                                            <span class="w-4 shrink-0 text-[10px] opacity-60">{{ $player->position->short() }}</span>
                                            <span class="min-w-0 flex-1 truncate">{{ $player->name }}</span>
                                            <span class="shrink-0 text-[10px] tabular-nums opacity-60" title="{{ __('Experience in your club') }}">{{ $fmt($player->club_experience) }}</span>
                                            @if ($forfeit)
                                                <flux:icon.no-symbol variant="micro" />
                                            @elseif ($open)
                                                <flux:icon.x-mark variant="micro" class="opacity-50" />
                                            @endif
                                        </button>
                                    @empty
                                        <span class="text-xs opacity-80">{{ $open ? __('Nobody fielded yet. Choose this type and click players above.') : __('Nobody was fielded.') }}</span>
                                    @endforelse
                                </div>
                            @else
                                <div class="relative z-10 mt-2 space-y-2">
                                    @foreach ($setSheets as $sheet)
                                        @include('partials.match-sheet', ['sheet' => $sheet, 'showCompetition' => count($setSheets) > 1 || $setValue !== \App\Enums\CompetitionType::Legends->value])
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                {{-- Legenda protokołów --}}
                @if ($sheets !== [])
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                        <span class="flex items-center gap-1.5"><x-ball /> {{ __('Attack point (goal, assist)') }}</span>
                        <span class="flex items-center gap-1.5"><x-ball own /> {{ __('Own goal: a point for the rival') }}</span>
                        <span class="flex items-center gap-1.5"><x-fist /> {{ __('Clean sheet: takes a point from the rival') }}</span>
                        <span class="flex items-center gap-1.5"><span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-yellow-400"></span><span class="inline-block h-3.5 w-2.5 rounded-[2px] bg-red-500"></span> {{ __('Cards') }}</span>
                        <span class="flex items-center gap-1.5"><span class="font-bold">{{ __('Exp.') }}</span> {{ __('experience in the club after the match') }}</span>
                        <span class="flex items-center gap-1.5 text-zinc-400">{{ __('Grey name: did not play in the Lech match (fielding still gives 0.5 experience)') }}</span>
                        <span class="flex items-center gap-1.5">{{ __('Liga Legend: each duel that takes place counts as one appearance') }}</span>
                    </div>
                @endif

                {{-- Odpoczywający: kolor według godzin do końca odpoczynku --}}
                @if ($resting->isNotEmpty())
                    <section class="space-y-2 rounded-2xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-white/5">
                        <flux:heading>{{ __('Resting players') }} <span class="font-normal text-zinc-500">({{ $resting->count() }})</span></flux:heading>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($resting as $player)
                                @php
                                    $freeAt = $locks[$player->id];
                                    $in = $pickedIn[$player->id] ?? null;
                                @endphp
                                <div class="flex max-w-full items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900" wire:key="rest-{{ $player->id }}">
                                    <span class="w-4 shrink-0 text-[10px] font-semibold uppercase">{{ $player->position->short() }}</span>
                                    <span class="min-w-0 truncate">{{ $player->name }}</span>
                                    @if ($in && $open)
                                        {{-- Wystawiony, a potem odpoczywający (raport wpisany później): wybór przepada, można zdjąć. --}}
                                        <button type="button" wire:click="toggle({{ $player->id }}, '{{ $in }}')" class="shrink-0 rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white"
                                            title="{{ __('This player rests now: the pick does not count.') }}">{{ __('Remove') }}</button>
                                    @endif
                                    <span class="{{ \App\Support\Squad::lockColor(\App\Support\Squad::hoursLeft($freeAt)) }} shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                                        title="{{ __(':count h left', ['count' => \App\Support\Squad::hoursLeft($freeAt)]) }}">
                                        {{ __('free from :date', ['date' => \Illuminate\Support\Carbon::createFromTimestamp($freeAt, config('app.timezone'))->format('d.m, H:i')]) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
                            @foreach (\App\Support\Squad::REST_LEGEND as $upTo => $sample)
                                <span class="flex items-center gap-1.5">
                                    <span class="{{ \App\Support\Squad::lockColor($sample) }} inline-block h-3 w-5 rounded"></span>
                                    {{ __('up to :count h', ['count' => $upTo]) }}
                                </span>
                            @endforeach
                        </div>
                    </section>
                @endif

                <flux:text class="text-xs">{{ __('A fielded player who played rests in your club for some hours from the kickoff (from 40 h for part of the first half to 250 h for the whole match with extra time).') }}</flux:text>
            </div>
        @endif
    @endif
</div>
