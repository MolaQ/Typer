<?php

use App\Actions\Competitions\DrawSwissRound;
use App\Actions\Questions\DrawQuestions;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\Standings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
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

    /** Losuje brakujące pytania kolejki (wszystkie zestawy; Liga Legend najpierw, z najtrudniejszych). */
    public function drawQuestions(int $matchdayId): void
    {
        abort_unless(auth()->user()->can(Permission::SeasonEdit->value), 403);

        try {
            $filled = app(DrawQuestions::class)->handle(Matchday::findOrFail($matchdayId));
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->alerts);
        Flux::toast(text: __(':count questions drawn.', ['count' => $filled]), variant: 'success');
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

        unset($this->alerts);
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
