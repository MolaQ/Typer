<?php

use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Support\PlayerCompetitions;
use App\Support\Squad;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Desygnowanie zawodników na /tips (zakładka Typowanie, pod tablicą wyniku): karta w barwach rozgrywek dla każdego
 * typu rozgrywek, w którym gracz gra, zakładki pozycji B / O / P / N i klik = wystaw albo zdejmij (zapis od razu).
 * Zablokowani zawodnicy mają kolor według liczby kolejek blokady (legenda pod kartami). Zasady: Support\Squad.
 */
new class extends Component {
    #[Locked]
    public int $matchdayId = 0;

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

    #[Computed]
    public function squad()
    {
        return Squad::available();
    }

    /** @return array<string, array<int, int>> typ rozgrywek => id zawodników */
    #[Computed]
    public function picks(): array
    {
        return $this->matchday ? Squad::picks(auth()->id(), null, $this->matchday) : [];
    }

    /** @return array<int, int> id zawodnika => kolejki blokady */
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

    public function toggle(string $set, int $playerId): void
    {
        $type = CompetitionType::tryFrom($set);
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
    }
}; ?>

<div>
    @if ($this->matchday && count($this->sets) > 0)
        @php
            $max = \App\Support\Designation::value('max_players');
            $picks = $this->picks;
            $locks = $this->locks;
            $squad = $this->squad;
            // Gdzie wystawiony jest zawodnik: id => typ rozgrywek (wartość).
            $pickedIn = [];
            foreach ($picks as $setValue => $ids) {
                foreach ($ids as $id) {
                    $pickedIn[$id] = $setValue;
                }
            }
            $positions = \App\Enums\PlayerPosition::cases();
            $open = $this->isOpen;
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

            {{--
                Karty typów rozgrywek: najwyżej cztery, po dwie w wierszu (na telefonie jedna pod drugą). Karta w barwach rozgrywek,
                na górze wystawieni (klik zdejmuje), niżej zakładki pozycji z kadrą.
            --}}
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($this->sets as $set)
                    @php
                        $setKey = $this->setColors[$set->value] ?? 'league_1';
                        $mine = $picks[$set->value] ?? [];
                        $minePlayers = $squad->whereIn('id', $mine);
                    @endphp
                    <section class="overflow-hidden rounded-2xl p-1 shadow-md" wire:key="set-{{ $set->value }}" x-data="{ pos: 'goalkeeper' }"
                        style="{{ \App\Support\CompetitionColors::style($setKey) }} background-image: linear-gradient(140deg, var(--c-bg1), var(--c-bg2)); color: var(--c-ink); border: 2px solid var(--c-accent);">
                        <header class="flex flex-wrap items-center justify-between gap-2 px-3 pb-2 pt-2">
                            <h2 class="text-sm font-black uppercase tracking-[0.15em]">{{ $set->scoreSetLabel() }}</h2>
                            <span class="comp-badge rounded-md px-2 py-0.5 text-xs font-bold tabular-nums">{{ count($mine) }}/{{ $max }}</span>
                        </header>

                        {{-- Wystawieni: klik zdejmuje --}}
                        <div class="flex min-h-9 flex-wrap gap-1.5 px-3 pb-2">
                            @forelse ($minePlayers as $player)
                                <button type="button" wire:click="toggle('{{ $set->value }}', {{ $player->id }})" @disabled(! $open)
                                    class="comp-chip flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold shadow-sm" title="{{ $open ? __('Remove') : '' }}">
                                    <span class="opacity-70">{{ $player->position->short() }}</span> {{ $player->name }}
                                    @if ($open)
                                        <flux:icon.x-mark variant="micro" />
                                    @endif
                                </button>
                            @empty
                                <span class="text-xs opacity-80">{{ $open ? __('Nobody fielded yet. Pick players below.') : __('Nobody was fielded.') }}</span>
                            @endforelse
                        </div>

                        {{-- Zakładki pozycji z liczbą wystawionych --}}
                        <div class="flex gap-1 px-2 pb-2">
                            @foreach ($positions as $position)
                                @php
                                    $onPos = $minePlayers->where('position', $position)->count();
                                @endphp
                                <button type="button" x-on:click="pos = '{{ $position->value }}'"
                                    x-bind:class="pos === '{{ $position->value }}' ? 'comp-badge shadow' : 'bg-black/20 hover:bg-black/30'"
                                    class="flex-1 rounded-full px-2 py-1 text-xs font-bold uppercase tracking-wide transition" title="{{ $position->label() }}">
                                    {{ $position->short() }} @if ($onPos)<span class="tabular-nums">· {{ $onPos }}</span>@endif
                                </button>
                            @endforeach
                        </div>

                        @foreach ($positions as $position)
                            <ul x-show="pos === '{{ $position->value }}'" @if ($position->value !== 'goalkeeper') x-cloak @endif
                                class="divide-y divide-zinc-100 rounded-xl bg-white text-zinc-800 dark:divide-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                                @foreach ($squad->where('position', $position) as $player)
                                    @php
                                        $here = in_array($player->id, $mine, true);
                                        $elsewhere = isset($pickedIn[$player->id]) && !$here ? \App\Enums\CompetitionType::from($pickedIn[$player->id]) : null;
                                        $left = $locks[$player->id] ?? 0;
                                        $disabled = !$open || (!$here && ($left > 0 || $elsewhere || count($mine) >= $max));
                                    @endphp
                                    <li wire:key="p-{{ $set->value }}-{{ $player->id }}">
                                        <button type="button" wire:click="toggle('{{ $set->value }}', {{ $player->id }})" @disabled($disabled)
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left transition {{ $here ? 'bg-lech-50 dark:bg-lech-500/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5' }} disabled:cursor-default {{ $disabled && !$here ? 'opacity-60' : '' }}">
                                            <span class="w-6 shrink-0 text-right text-xs tabular-nums text-zinc-400">{{ $player->number }}</span>
                                            <span class="min-w-0 flex-1 truncate text-sm {{ $here ? 'font-bold' : '' }}">
                                                {{ $player->name }}
                                                @if ($player->is_academy)
                                                    <flux:icon.academic-cap variant="micro" class="inline text-lech-600" />
                                                @endif
                                            </span>
                                            @if ($left > 0)
                                                <span class="{{ \App\Support\Squad::lockColor($left) }} shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold"
                                                    title="{{ __('Rests until matchday :number', ['number' => $this->matchday->number + $left - 1]) }}">
                                                    {{ __('free from :number', ['number' => $this->matchday->number + $left]) }}
                                                </span>
                                            @elseif ($elsewhere)
                                                <span class="shrink-0 text-[10px] text-zinc-500">{{ $elsewhere->scoreSetLabel() }}</span>
                                            @endif
                                            <span class="{{ $here ? 'bg-lech-700 text-white' : 'border border-zinc-300 text-zinc-400 dark:border-zinc-600' }} flex size-6 shrink-0 items-center justify-center rounded-full">
                                                @if ($here)
                                                    <flux:icon.check variant="micro" />
                                                @else
                                                    <flux:icon.plus variant="micro" />
                                                @endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endforeach
                    </section>
                @endforeach
            </div>

            {{-- Legenda blokad pod kartami --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="font-semibold">{{ __('Resting players') }}:</span>
                @foreach ([4, 3, 2, 1] as $legend)
                    <span class="flex items-center gap-1.5">
                        <span class="{{ \App\Support\Squad::lockColor($legend) }} inline-block h-3 w-5 rounded"></span>
                        {{ trans_choice(':count matchday|:count matchdays', $legend, ['count' => $legend]) }}
                    </span>
                @endforeach
                <span class="basis-full text-zinc-500">{{ __('A fielded player who played a whole half rests one matchday per half in your club. A player can be in only one competition type per matchday.') }}</span>
            </div>
        </div>
    @endif
</div>
