<?php

use App\Actions\Competitions\DrawSwissRound;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\Audit;
use App\Support\Standings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Strona główna panelu: powiadomienia (czego brakuje, żeby rozgrywki działały, App\Support\AdminAlerts)
 * z szybkimi akcjami oraz skrót stanu sezonu.
 */
new class extends Component {
    public function render(): View
    {
        return $this->view()->title(__('Dashboard'));
    }

    #[Computed]
    public function alerts(): array
    {
        return AdminAlerts::all();
    }

    #[Computed]
    public function season(): ?Season
    {
        return Season::current();
    }

    /** Następny krok: pierwsze pilne powiadomienie albo ostrzeżenie (lista jest posortowana od najpilniejszych). */
    #[Computed]
    public function nextStep(): ?array
    {
        return collect($this->alerts)->first(fn($a) => $a['level'] !== AdminAlerts::INFO);
    }

    /**
     * Pasek postępu sezonu (trwającego albo najbliższego, a gdy ich nie ma, ostatniego):
     * utworzenie, rozgrywki, mecze kolejek, start, rozegrane kolejki, koniec.
     *
     * @return array{season: ?Season, steps: array<int, array{label: string, done: bool, detail: ?string}>}
     */
    #[Computed]
    public function seasonProgress(): array
    {
        $season = $this->season
            ?? Season::whereIn('status', [SeasonStatus::Draft->value, SeasonStatus::Approved->value])->orderBy('number')->first()
            ?? Season::orderByDesc('number')->first();

        if (!$season) {
            return ['season' => null, 'steps' => []];
        }

        $matchdays = Matchday::where('season_id', $season->id)->get();
        $filled = $matchdays->filter(fn(Matchday $m) => $m->isFilled())->count();
        $played = $matchdays->where('status', MatchdayStatus::Played)->count();
        $status = $season->status;
        $after = fn(SeasonStatus ...$statuses) => in_array($status, $statuses, true);

        return [
            'season' => $season,
            'steps' => [
                ['label' => __('Season created'), 'done' => true, 'detail' => null],
                ['label' => __('Competitions'), 'done' => $after(SeasonStatus::Approved, SeasonStatus::Active, SeasonStatus::Finished), 'detail' => null],
                ['label' => __('Lech matches'), 'done' => $filled >= Matchday::PER_SEASON || $after(SeasonStatus::Finished), 'detail' => $filled . ' / ' . Matchday::PER_SEASON],
                ['label' => __('Season started'), 'done' => $after(SeasonStatus::Active, SeasonStatus::Finished), 'detail' => null],
                ['label' => __('Matchdays played'), 'done' => $played >= Matchday::PER_SEASON || $after(SeasonStatus::Finished), 'detail' => $played . ' / ' . Matchday::PER_SEASON],
                ['label' => __('Season finished'), 'done' => $after(SeasonStatus::Finished), 'detail' => null],
            ],
        ];
    }

    /** Najbliższa kolejka bez wyniku. */
    #[Computed]
    public function nextMatchday(): ?Matchday
    {
        return $this->season
            ? Matchday::where('season_id', $this->season->id)->where('status', '!=', MatchdayStatus::Played->value)->orderBy('number')->first()
            : null;
    }

    #[Computed]
    public function numbers(): array
    {
        $players = $this->season ? SeasonTeam::where('season_id', $this->season->id)->whereNotNull('user_id')->count() : 0;

        return [
            'players' => $players,
            'tips' => $this->nextMatchday ? Tip::where('matchday_id', $this->nextMatchday->id)->count() : 0,
            'premium' => User::where('premium_until', '>', now())->count(),
            'users' => User::count(),
        ];
    }

    /** Gracze z rolą bez miejsca na liście sezonu trafiają do Ligi podwórkowej (jak przy zmianie roli, Roster::place). */
    public function placeUnlisted(int $seasonId): void
    {
        abort_unless(auth()->user()->can(Permission::SeasonEdit->value), 403);

        $season = Season::findOrFail($seasonId);
        $placed = 0;

        foreach (\App\Support\Players::unlisted($season->id)->orderBy('created_at')->orderBy('id')->get() as $user) {
            if (DB::transaction(fn() => \App\Support\Roster::place($season->id, $user->id, \App\Enums\League::Podworkowa), \App\Support\Roster::ATTEMPTS)) {
                $placed++;
                Audit::log('season_list.player_assigned', $user, [], ['league' => \App\Enums\League::Podworkowa->label()], ($user->team_name ?: $user->name) . ' (' . $season->title . ')');
            }
        }

        unset($this->alerts, $this->nextStep, $this->numbers);
        Flux::toast(text: trans_choice(':count player added to Liga podwórkowa.|:count players added to Liga podwórkowa.', $placed, ['count' => $placed]), variant: 'success');
    }

    /** Losuje kolejną rundę Ligi podwórkowej (runda 1 z listy, dalsze z klasyfikacji). */
    public function drawSwiss(int $competitionId): void
    {
        abort_unless(auth()->user()->can(Permission::SeasonEdit->value), 403);

        $competition = Competition::findOrFail($competitionId);
        $round = (int) Fixture::where('competition_id', $competition->id)->max('round') + 1;

        try {
            $matches = app(DrawSwissRound::class)->handle($competition, $round, $round > 1 ? Standings::rankedEntryIds($competition) : null);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->alerts, $this->nextStep, $this->seasonProgress);
        Flux::toast(text: __('Round :round drawn: :matches matches.', ['round' => $round, 'matches' => $matches]), variant: 'success');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>
                {{ $this->season ? $this->season->title : __('No active season.') }}
                @if ($this->nextMatchday)
                    &middot; {{ __('Next: :matchday', ['matchday' => __('Matchday :number', ['number' => $this->nextMatchday->number])]) }}
                    @if ($this->nextMatchday->isFilled())
                        ({{ $this->nextMatchday->fixture }}, {{ $this->nextMatchday->kickoff_at->translatedFormat('j F, H:i') }})
                    @endif
                @endif
            </flux:subheading>
        </div>
        <flux:button icon="globe-alt" :href="route('home')">{{ __('Public site') }}</flux:button>
    </div>

    {{-- Następny krok: najpilniejsza sprawa z powiadomień jako duża karta z jednym przyciskiem --}}
    @if ($step = $this->nextStep)
        <div class="lech-banner flex flex-wrap items-center gap-4 rounded-2xl p-5 text-white" wire:key="next-step">
            <flux:icon :name="$step['icon']" class="size-10 shrink-0 text-white/80" />
            <div class="min-w-0 flex-1">
                <div class="text-xs font-semibold uppercase tracking-wider text-white/70">{{ __('Next step') }}</div>
                <div class="text-lg font-semibold">{{ $step['title'] }}</div>
                <div class="text-sm text-white/80">{{ $step['detail'] }}</div>
            </div>
            <div class="flex gap-2">
                @if ($step['action'])
                    <flux:button variant="primary" wire:click="{{ $step['action']['method'] }}({{ $step['action']['arg'] }})">
                        {{ $step['action']['label'] }}
                    </flux:button>
                @endif
                @if ($step['route'] && \Illuminate\Support\Facades\Route::has($step['route']))
                    <flux:button :variant="$step['action'] ? 'filled' : 'primary'" icon-trailing="arrow-right" :href="route($step['route'], $step['params'])" wire:navigate>
                        {{ __('Go') }}
                    </flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- Postęp sezonu: kroki od utworzenia do zakończenia, pełna lista kontrolna pod linkiem --}}
    @php
        $progress = $this->seasonProgress;
    @endphp
    @if ($progress['season'])
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">{{ $progress['season']->title }}</flux:heading>
                    <flux:badge size="sm" :color="$progress['season']->status->color()">{{ $progress['season']->status->label() }}</flux:badge>
                </div>
                <flux:button size="sm" variant="ghost" icon="clipboard-document-check" :href="route('dashboard.checklist', ['season' => $progress['season']->id])" wire:navigate>
                    {{ __('Full checklist') }}
                </flux:button>
            </div>
            <ol class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($progress['steps'] as $item)
                    <li class="flex items-center gap-2 rounded-xl border p-3 {{ $item['done'] ? 'border-green-200 bg-green-50 dark:border-green-500/30 dark:bg-green-500/10' : 'border-zinc-200 dark:border-zinc-700' }}">
                        <flux:icon :name="$item['done'] ? 'check-circle' : 'minus-circle'" class="{{ $item['done'] ? 'text-green-600' : 'text-zinc-400' }} size-5 shrink-0" />
                        <div class="min-w-0">
                            <div class="text-sm font-medium">{{ $item['label'] }}</div>
                            @if ($item['detail'])
                                <div class="text-xs tabular-nums text-zinc-500">{{ $item['detail'] }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </flux:card>
    @endif

    {{-- Skrót liczb --}}
    @php
        $numbers = $this->numbers;
        $tiles = [
            ['users', __('Players in the season'), $numbers['players']],
            ['pencil-square', __('Tips for the next matchday'), $numbers['tips'] . ($numbers['players'] ? ' / ' . $numbers['players'] : '')],
            ['sparkles', __('Active premium'), $numbers['premium']],
            ['user-group', __('All users'), $numbers['users']],
        ];
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tiles as [$icon, $label, $value])
            <flux:card class="flex items-center gap-4">
                <flux:icon :name="$icon" class="size-8 text-lech-500" />
                <div>
                    <flux:text>{{ $label }}</flux:text>
                    <flux:heading size="xl" class="tabular-nums">{{ $value }}</flux:heading>
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Powiadomienia --}}
    <flux:card class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Notifications') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="$refresh">{{ __('Check again') }}</flux:button>
        </div>

        @forelse ($this->alerts as $alert)
            @php
                $styles = [
                    'danger' => 'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10',
                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10',
                    'info' => 'border-lech-100 bg-lech-50 dark:border-lech-800 dark:bg-lech-950/40',
                ];
                $iconColors = ['danger' => 'text-red-600', 'warning' => 'text-amber-600', 'info' => 'text-lech-600'];
            @endphp
            <div class="{{ $styles[$alert['level']] }} flex flex-wrap items-center gap-3 rounded-xl border p-3" wire:key="alert-{{ $loop->index }}">
                <flux:icon :name="$alert['icon']" class="{{ $iconColors[$alert['level']] }} size-6 shrink-0" />
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $alert['title'] }}</div>
                    <flux:text size="sm">{{ $alert['detail'] }}</flux:text>
                </div>
                <div class="flex gap-2">
                    @if ($alert['action'])
                        <flux:button size="sm" variant="primary" wire:click="{{ $alert['action']['method'] }}({{ $alert['action']['arg'] }})">
                            {{ $alert['action']['label'] }}
                        </flux:button>
                    @endif
                    @if ($alert['route'] && \Illuminate\Support\Facades\Route::has($alert['route']))
                        <flux:button size="sm" :href="route($alert['route'], $alert['params'])" wire:navigate>{{ __('Open') }}</flux:button>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 p-3 dark:border-green-500/30 dark:bg-green-500/10">
                <flux:icon.check-circle class="size-6 text-green-600" />
                <flux:text>{{ __('Everything is in place. Nothing needs your attention.') }}</flux:text>
            </div>
        @endforelse
    </flux:card>
</div>
