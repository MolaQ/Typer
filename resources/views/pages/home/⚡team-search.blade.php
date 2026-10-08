<?php

use App\Models\User;
use App\Support\Players;
use App\Support\Premium;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Wyszukiwarka zespołów na stronie głównej (funkcja premium): po nazwie zespołu, skrócie albo nazwie gracza,
 * prowadzi do strony zespołu. Bez premium widać zablokowane pole z zachętą.
 */
new class extends Component {
    public string $q = '';

    #[Computed]
    public function premium(): bool
    {
        return Premium::isActive(auth()->user());
    }

    #[Computed]
    public function found()
    {
        $q = trim($this->q);

        if (!$this->premium || mb_strlen($q) < 2) {
            return collect();
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        return User::query()
            ->whereHas('roles')
            ->whereDoesntHave('roles', fn ($r) => $r->where('name', Players::BANNED_ROLE))
            ->where(fn ($w) => $w->where('team_name', 'like', $like)
                ->orWhere('team_short_name', 'like', $like)
                ->orWhere('team_abbr', 'like', $like)
                ->orWhere('name', 'like', $like))
            ->orderByRaw('team_name is null')
            ->orderBy('team_name')
            ->limit(10)
            ->get(['id', 'name', 'team_name', 'team_abbr']);
    }

    public function render(): View
    {
        return $this->view();
    }
}; ?>

<section class="space-y-3 rounded-2xl border border-lech-100 bg-white p-5 shadow-sm dark:border-lech-900 dark:bg-zinc-900">
    <div class="flex items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Find a team') }}</flux:heading>
        <x-premium-badge />
    </div>

    @if ($this->premium)
        <flux:input wire:model.live.debounce.300ms="q" icon="magnifying-glass" clearable
            :placeholder="__('Team name, abbreviation or player')" />

        @if (mb_strlen(trim($q)) >= 2)
            @if ($this->found->isEmpty())
                <flux:text size="sm">{{ __('No teams found.') }}</flux:text>
            @else
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->found as $team)
                        <a href="{{ route('team.show', $team) }}" wire:navigate wire:key="found-{{ $team->id }}"
                            class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-lech-50 dark:hover:bg-lech-950">
                            <span class="font-medium">{{ $team->team_name ?: $team->name }}</span>
                            @if ($team->team_abbr)
                                <span class="text-xs font-semibold text-zinc-500">{{ $team->team_abbr }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    @else
        <flux:input icon="magnifying-glass" disabled :placeholder="__('Team name, abbreviation or player')" />
        <flux:text size="sm">
            {{ __('Searching for teams is a premium feature.') }}
            <flux:link :href="route('support')" wire:navigate>{{ __('See premium') }}</flux:link>
        </flux:text>
    @endif
</section>
