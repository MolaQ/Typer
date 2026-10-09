<?php

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\User;
use App\Support\HallOfFame;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Strona zespołu (etap 14): punkty Hall of Fame i miejsce w rankingu wszech czasów, gablota z trofeami
 * oraz historia zakończonych sezonów (miejsca w rozgrywkach i zdobyte punkty).
 */
new #[Layout('layouts::public')] class extends Component {
    public User $user;

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    public function render(): View
    {
        return $this->view()->title($this->user->team_name ?: $this->user->name);
    }

    /** Sezon do sekcji „Rozgrywki w sezonie”: aktywny, a bez niego ostatnio zakończony. */
    #[Computed]
    public function currentSeason(): ?Season
    {
        return Season::current() ?? Season::where('status', \App\Enums\SeasonStatus::Finished->value)->orderByDesc('number')->first();
    }

    /**
     * Rozgrywki zespołu w sezonie: miejsce w tabeli albo etap (puchar, Liga Legend) i link do wyników
     * z wyróżnionym zespołem (?team=), na kolejce jego ostatniego meczu.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function seasonCompetitions(): array
    {
        $season = $this->currentSeason;
        if (!$season) {
            return [];
        }

        return array_map(function (array $row) use ($season) {
            $lastMatch = end($row['matches']);
            $lastLegends = isset($row['legends']) ? end($row['legends']) : false;
            $round = $row['eliminated_round'] ?? ($lastLegends ? $lastLegends['round'] : ($lastMatch ? $lastMatch['round'] : null));
            $params = ['season_slug' => 'sezon-' . $season->number, 'competition_slug' => $row['slug']];
            if ($round) {
                $params['round_slug'] = 'kolejka-' . $round;
            }
            $row['url'] = route('results', $params) . '?team=' . $row['team_id'];
            $row['out'] = (bool) $row['eliminated_round'];

            return $row;
        }, \App\Support\PlayerStats::competitions($this->user, $season));
    }

    #[Computed]
    public function awards()
    {
        return HallOfFameAward::with('season:id,number')->where('user_id', $this->user->id)->get();
    }

    #[Computed]
    public function total(): float
    {
        return round((float) $this->awards->sum('points'), 1);
    }

    /** Miejsce w rankingu wszech czasów (null, gdy zespół nie ma jeszcze punktów). */
    #[Computed]
    public function rank(): ?int
    {
        if ($this->awards->isEmpty()) {
            return null;
        }

        $better = HallOfFameAward::query()
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('sum(points) > ?', [$this->total])
            ->select('user_id')
            ->get()
            ->count();

        return $better + 1;
    }

    /**
     * Gablota: trofea pogrupowane według rodzaju, z sezonami zdobycia.
     *
     * @return array<int, array{key: string, label: string, count: int, seasons: array<int, string>}>
     */
    #[Computed]
    public function cabinet(): array
    {
        $names = HallOfFame::trophies();

        return $this->awards->whereNotNull('trophy')
            ->groupBy('trophy')
            ->map(fn($rows, $key) => [
                'key' => $key,
                'label' => $names[$key] ?? $key,
                'count' => $rows->count(),
                'seasons' => $rows->map(fn($a) => $a->season?->roman_number)->filter()->sort()->values()->all(),
            ])
            // Kolejność według ważności trofeów (HallOfFame::trophies(): od Ligi Legend w dół).
            ->sortBy(fn($item) => array_search($item['key'], array_keys($names), true))
            ->values()
            ->all();
    }

    /**
     * Historia sezonów: sezon => wiersze [rozgrywki, wynik, punkty].
     *
     * @return array<int, array{season: Season, points: float, rows: array<int, array{name: string, result: string, points: float}>}>
     */
    #[Computed]
    public function history(): array
    {
        $standings = FinalStanding::with('competition')->where('user_id', $this->user->id)->get();
        $seasonIds = $standings->pluck('season_id')->merge($this->awards->pluck('season_id'))->unique();
        $order = array_map(fn($t) => $t->value, CompetitionType::cases());

        $out = [];

        foreach (Season::whereIn('id', $seasonIds)->orderByDesc('number')->get() as $season) {
            $awards = $this->awards->where('season_id', $season->id);
            $own = $standings->where('season_id', $season->id)->keyBy('competition_id');
            $competitionIds = $own->keys()->merge($awards->pluck('competition_id')->filter())->unique();
            $competitions = Competition::whereIn('id', $competitionIds)->get()
                ->sortBy(fn($c) => [array_search($c->type->value, $order, true), $c->tier ?? 99]);

            $rows = [];
            foreach ($competitions as $competition) {
                $points = (float) $awards->where('competition_id', $competition->id)->sum('points');
                $standing = $own->get($competition->id);

                $rows[] = [
                    'name' => $competition->name ?: $competition->type->label(),
                    'result' => $this->result($competition, $standing?->place, $awards->where('competition_id', $competition->id)),
                    'points' => round($points, 1),
                ];
            }

            $out[] = ['season' => $season, 'points' => round((float) $awards->sum('points'), 1), 'rows' => $rows];
        }

        return $out;
    }

    #[Computed]
    public function icons(): array
    {
        return HallOfFame::iconUrls();
    }

    /** Opis wyniku w rozgrywkach: miejsce, a w pucharze etap. */
    private function result(Competition $competition, ?int $place, $awards): string
    {
        if ($competition->type === CompetitionType::Cup) {
            if ($place === 1) {
                return __('Winner');
            }
            if ($place === 2) {
                return __('Final');
            }

            $rounds = $awards->firstWhere('kind', 'cup_rounds')?->meta['rounds'] ?? [];

            return $rounds === [] ? '—' : __('Rounds won: :count', ['count' => count($rounds)]);
        }

        return $place ? __(':place. place', ['place' => $place]) : '—';
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('Hall of Fame')" :title="$user->team_name ?: $user->name">
        <a href="{{ route('hall-of-fame') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-lech-100 hover:text-white hover:underline">
            <flux:icon.arrow-left variant="micro" /> {{ __('Full ranking') }}
        </a>

        <x-slot:aside>
            <div class="flex gap-6 rounded-xl bg-white/10 px-5 py-3">
                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-lech-200">{{ __('Hall of Fame points') }}</p>
                    <p class="text-2xl font-bold tabular-nums">{{ \App\Support\HallOfFame::format($this->total) }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-lech-200">{{ __('All-time rank') }}</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $this->rank ? $this->rank . '.' : '—' }}</p>
                </div>
            </div>
        </x-slot:aside>
    </x-page-banner>

    {{-- Rozgrywki w bieżącym sezonie: miejsce albo odpadnięcie, kliknięcie otwiera wyniki z wyróżnionym zespołem --}}
    @if (count($this->seasonCompetitions) > 0)
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Competitions this season') }}</flux:heading>
                <flux:badge color="zinc">{{ $this->currentSeason->title }}</flux:badge>
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->seasonCompetitions as $row)
                    <a href="{{ $row['url'] }}" wire:navigate wire:key="sc-{{ $loop->index }}"
                        class="{{ $row['out'] ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800' : 'border-lech-200 bg-lech-50/60 hover:border-lech-400 dark:border-lech-800 dark:bg-lech-950/40' }} flex items-center gap-3 rounded-xl border p-3 text-sm transition hover:shadow-md">
                        @if (isset($this->icons[$row['trophy']]))
                            <img src="{{ $this->icons[$row['trophy']] }}" alt="" class="{{ $row['out'] ? 'opacity-40 grayscale' : '' }} size-10 shrink-0 object-contain">
                        @else
                            <flux:icon.trophy class="size-10 shrink-0 text-amber-500" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold">{{ $row['name'] }}</div>
                            <div class="{{ $row['out'] ? 'text-zinc-600 dark:text-zinc-300' : 'text-lech-700 dark:text-lech-300' }} flex items-center gap-1 text-xs font-medium">
                                @if ($row['out'])
                                    <flux:icon.x-circle variant="micro" />
                                @endif
                                <span class="truncate">{{ $row['status'] }}</span>
                            </div>
                        </div>
                        @if ($row['points'] !== null)
                            <span class="shrink-0 font-bold tabular-nums">{{ __(':points pts', ['points' => $row['points']]) }}</span>
                        @endif
                        <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400" />
                    </a>
                @endforeach
            </div>
        </flux:card>
    @endif

    {{-- Gablota --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Trophy cabinet') }}</flux:heading>

        @if (count($this->cabinet) === 0)
            <flux:text>{{ __('No trophies yet.') }}</flux:text>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($this->cabinet as $item)
                    <div class="flex flex-col items-center gap-2 rounded-xl border border-zinc-200 p-4 text-center dark:border-zinc-700"
                        wire:key="cab-{{ $item['key'] }}">
                        <div class="relative">
                            @if (isset($this->icons[$item['key']]))
                                <img src="{{ $this->icons[$item['key']] }}" alt="" class="size-16 object-contain">
                            @else
                                <flux:icon.trophy class="size-16 text-amber-500" />
                            @endif
                            @if ($item['count'] > 1)
                                <span class="absolute -right-2 -top-1 rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">×{{ $item['count'] }}</span>
                            @endif
                        </div>
                        <span class="text-sm font-medium">{{ $item['label'] }}</span>
                        <span class="text-xs text-zinc-500">{{ implode(', ', $item['seasons']) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Historia sezonów --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Season history') }}</flux:heading>

        @forelse ($this->history as $item)
            <div class="space-y-2" wire:key="season-{{ $item['season']->id }}">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading>{{ $item['season']->title }}</flux:heading>
                    <flux:badge color="amber">{{ __(':points pts', ['points' => \App\Support\HallOfFame::format($item['points'])]) }}</flux:badge>
                </div>
                <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($item['rows'] as $row)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">{{ $row['name'] }}</span>
                            <span class="w-32 text-right">{{ $row['result'] }}</span>
                            <span class="w-16 text-right tabular-nums text-zinc-500">{{ \App\Support\HallOfFame::format($row['points']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:text>{{ __('No finished seasons yet.') }}</flux:text>
        @endforelse
    </flux:card>
</div>
