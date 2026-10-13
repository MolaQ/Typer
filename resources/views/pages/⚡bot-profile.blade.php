<?php

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\HallOfFame;
use App\Support\PlayerStats;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Profil bota (/bots/{id}-{nazwa}): boty grają zawsze, więc mają własną historię, gablotę i statystyki.
 * Wszystko liczy się temu, kto naprawdę grał (Ownership): gracz, który wskoczył za bota, nie zabiera mu
 * dotychczasowych statystyk ani nagród za mecze, a bot nie dostaje niczego za kolejki gracza.
 */
new #[Layout('layouts::public')] class extends Component {
    public Bot $bot;

    public function mount(Bot $bot): void
    {
        $this->bot = $bot;
    }

    public function render(): View
    {
        return $this->view()->title($this->bot->name);
    }

    /** Sezon aktywny albo zatwierdzony, a bez nich ostatnio zakończony (jak na profilu gracza). */
    #[Computed]
    public function currentSeason(): ?Season
    {
        return Season::whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Approved->value])->orderByDesc('number')->first()
            ?? Season::where('status', SeasonStatus::Finished->value)->orderByDesc('number')->first();
    }

    /** Miejsce bota na liście sezonu (null: bot w tym sezonie nie gra). */
    #[Computed]
    public function team(): ?SeasonTeam
    {
        return $this->currentSeason ? SeasonTeam::where('season_id', $this->currentSeason->id)->where('bot_id', $this->bot->id)->first() : null;
    }

    /** @return array{label: string, url: ?string}|null */
    #[Computed]
    public function currentLeague(): ?array
    {
        if (!$this->team) {
            return null;
        }

        $league = League::forPosition((int) $this->team->position);
        $competition = Competition::where('season_id', $this->currentSeason->id)
            ->whereHas('entries', fn ($q) => $q->where('season_team_id', $this->team->id))
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->first();

        return [
            'label' => $competition ? ($competition->name ?: $league->label()) : $league->label(),
            'url' => $competition
                ? route('results', ['season_slug' => 'sezon-' . $this->currentSeason->number, 'competition_slug' => $competition->slug()]) . '?team=' . $this->team->id
                : null,
        ];
    }

    #[Computed]
    public function stats(): ?array
    {
        return $this->currentSeason ? PlayerStats::botSeason($this->bot, $this->currentSeason) : null;
    }

    /** Zawodnicy klubu bota z dorobkiem (Support\ClubStats). */
    #[Computed]
    public function clubPlayers(): array
    {
        return \App\Support\ClubStats::players(null, $this->bot->id, $this->currentSeason?->id);
    }

    #[Computed]
    public function awards()
    {
        return HallOfFameAward::with('season:id,number')->where('bot_id', $this->bot->id)->get();
    }

    #[Computed]
    public function total(): float
    {
        return round((float) $this->awards->sum('points'), 1);
    }

    /** Miejsce wśród botów w rankingu wszech czasów (null bez punktów). */
    #[Computed]
    public function rank(): ?int
    {
        if ($this->awards->isEmpty()) {
            return null;
        }

        return HallOfFameAward::query()
            ->whereNotNull('bot_id')
            ->groupBy('bot_id')
            ->havingRaw('sum(points) > ?', [$this->total])
            ->select('bot_id')
            ->get()
            ->count() + 1;
    }

    /** @return array<int, array{key: string, label: string, count: int, seasons: array<int, string>}> */
    #[Computed]
    public function cabinet(): array
    {
        $names = HallOfFame::trophies();

        return $this->awards->whereNotNull('trophy')
            ->groupBy('trophy')
            ->map(fn ($rows, $key) => [
                'key' => $key,
                'label' => $names[$key] ?? $key,
                'count' => $rows->count(),
                'seasons' => $rows->map(fn ($a) => $a->season?->roman_number)->filter()->sort()->values()->all(),
            ])
            ->sortBy(fn ($item) => array_search($item['key'], array_keys($names), true))
            ->values()
            ->all();
    }

    /**
     * Historia sezonów z tabel końcowych i punktów Hall of Fame bota.
     *
     * @return array<int, array{season: Season, points: float, rows: array<int, array{name: string, result: string, points: float}>}>
     */
    #[Computed]
    public function history(): array
    {
        $standings = FinalStanding::with('competition')->where('bot_id', $this->bot->id)->get();
        $seasonIds = $standings->pluck('season_id')->merge($this->awards->pluck('season_id'))->unique();
        $order = array_map(fn ($t) => $t->value, CompetitionType::cases());
        $out = [];

        foreach (Season::whereIn('id', $seasonIds)->orderByDesc('number')->get() as $season) {
            $awards = $this->awards->where('season_id', $season->id);
            $own = $standings->where('season_id', $season->id)->keyBy('competition_id');
            $competitions = Competition::whereIn('id', $own->keys()->merge($awards->pluck('competition_id')->filter())->unique())->get()
                ->sortBy(fn ($c) => [array_search($c->type->value, $order, true), $c->tier ?? 99]);

            $rows = [];
            foreach ($competitions as $competition) {
                $place = $own->get($competition->id)?->place;
                $rows[] = [
                    'name' => $competition->name ?: $competition->type->label(),
                    'result' => match (true) {
                        $place === 1 && ($competition->type === CompetitionType::Cup || $competition->type->isEuropean()) => __('Winner'),
                        $place === 2 && ($competition->type === CompetitionType::Cup || $competition->type->isEuropean()) => __('Final'),
                        $place !== null => __(':place. place', ['place' => $place]),
                        default => '—',
                    },
                    'points' => round((float) $awards->where('competition_id', $competition->id)->sum('points'), 1),
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
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('Bot')" :title="$bot->name" :subtitle="__('Bots always play: they tip a random score.')">
        <a href="{{ route('hall-of-fame') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-lech-100 hover:text-white hover:underline">
            <flux:icon.arrow-left variant="micro" /> {{ __('Full ranking') }}
        </a>

        <x-slot:aside>
            <div class="flex flex-wrap gap-6 rounded-xl bg-white/10 px-5 py-3">
                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-lech-200">{{ $this->currentSeason ? __('League') . ' · ' . $this->currentSeason->title : __('League') }}</p>
                    @if ($this->currentLeague)
                        @if ($this->currentLeague['url'])
                            <a href="{{ $this->currentLeague['url'] }}" wire:navigate class="text-2xl font-bold hover:underline">{{ $this->currentLeague['label'] }}</a>
                        @else
                            <p class="text-2xl font-bold">{{ $this->currentLeague['label'] }}</p>
                        @endif
                    @else
                        <p class="text-base font-semibold text-lech-100">{{ __('Not on the season list') }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-lech-200">{{ __('Hall of Fame points') }}</p>
                    <p class="text-2xl font-bold tabular-nums">{{ HallOfFame::format($this->total) }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-lech-200">{{ __('Rank among bots') }}</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $this->rank ? $this->rank . '.' : '—' }}</p>
                </div>
            </div>
        </x-slot:aside>
    </x-page-banner>

    {{-- Statystyki sezonu: tylko kolejki rozegrane przez bota --}}
    @if ($this->stats)
        @php
            $s = $this->stats;
            $tiles = [
                [__('Matches'), $s['played'] > 0 ? $s['won'] . '–' . $s['drawn'] . '–' . $s['lost'] : '—', __('Won, drawn, lost')],
                [__('Goals'), $s['played'] > 0 ? $s['for'] . ':' . $s['against'] : '—', __('Scored and conceded')],
                [__('Tip points'), $s['tip_points'], __('Average :avg per matchday', ['avg' => $s['avg_tip'] ?? '—'])],
                [__('Exact tips'), $s['exact'], __('Correct differences: :count', ['count' => $s['diff']])],
                [__('Correct outcomes'), $s['accuracy'] !== null ? $s['accuracy'] . '%' : '—', __(':count of :total', ['count' => $s['outcome'], 'total' => $s['tips']])],
                [__('Best outcome streak'), $s['outcome_streak_best'], __('Current: :count', ['count' => $s['outcome_streak']])],
            ];
        @endphp
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Statistics') }}</flux:heading>
                <flux:badge color="zinc">{{ $this->currentSeason->title }}</flux:badge>
            </div>
            @if ($s['scored'] === 0)
                <flux:text>{{ __('No settled matchdays played by this bot yet.') }}</flux:text>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($tiles as [$label, $value, $hint])
                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" wire:key="bs-{{ $loop->index }}">
                            <p class="text-xs uppercase tracking-wide text-zinc-500">{{ $label }}</p>
                            <p class="text-2xl font-bold tabular-nums">{{ $value }}</p>
                            <p class="text-xs text-zinc-500">{{ $hint }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($s['form'] !== [])
                    <div class="flex items-center gap-1 text-xs">
                        <span class="mr-1 text-zinc-500">{{ __('Form') }}</span>
                        @foreach ($s['form'] as $result)
                            @php
                                $formClass = match ($result) { 'W' => 'bg-green-500', 'D' => 'bg-amber-400', default => 'bg-red-500' };
                            @endphp
                            <span class="{{ $formClass }} flex size-6 items-center justify-center rounded font-bold text-white" wire:key="bf-{{ $loop->index }}">{{ ['W' => __('W'), 'D' => __('D'), 'L' => __('L')][$result] }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
        </flux:card>
    @endif

    {{-- Zawodnicy Lecha w klubie bota i ich dorobek (desygnowanie, Support\ClubStats) --}}
    <flux:card>
        @include('partials.club-players', ['clubPlayers' => $this->clubPlayers, 'clubTitle' => __('Bot players')])
    </flux:card>

    {{-- Gablota --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Trophy cabinet') }}</flux:heading>

        @if (count($this->cabinet) === 0)
            <flux:text>{{ __('No trophies yet.') }}</flux:text>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($this->cabinet as $item)
                    <div class="flex flex-col items-center gap-2 rounded-xl border border-zinc-200 p-4 text-center dark:border-zinc-700" wire:key="cab-{{ $item['key'] }}">
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
                    <flux:badge color="amber">{{ __(':points pts', ['points' => HallOfFame::format($item['points'])]) }}</flux:badge>
                </div>
                <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($item['rows'] as $row)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">{{ $row['name'] }}</span>
                            <span class="w-32 text-right">{{ $row['result'] }}</span>
                            <span class="w-16 text-right tabular-nums text-zinc-500">{{ HallOfFame::format($row['points']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:text>{{ __('No finished seasons yet.') }}</flux:text>
        @endforelse
    </flux:card>
</div>
