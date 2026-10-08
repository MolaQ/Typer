<?php

use App\Models\HallOfFameAward;
use App\Models\User;
use App\Support\HallOfFame;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hall of Fame (etap 14): ranking wszech czasów graczy według punktów za sukcesy w zakończonych sezonach,
 * z trofeami. Kliknięcie zespołu prowadzi do jego strony z gablotą i historią sezonów.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    public const PER_PAGE = 50;

    public function render(): View
    {
        return $this->view()->title(__('Hall of Fame'));
    }

    #[Computed]
    public function ranking()
    {
        return HallOfFameAward::query()
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(points) as total, count(trophy) as trophies, count(distinct season_id) as seasons')
            ->orderByDesc('total')
            ->orderByDesc('trophies')
            ->orderBy('user_id')
            ->paginate(self::PER_PAGE);
    }

    /** Gracze z bieżącej strony rankingu. */
    #[Computed]
    public function users()
    {
        return User::whereIn('id', $this->ranking->getCollection()->pluck('user_id'))->get(['id', 'name', 'team_name'])->keyBy('id');
    }

    /** Trofea graczy z bieżącej strony: user_id => [klucz trofeum => liczba]. */
    #[Computed]
    public function trophies(): array
    {
        return HallOfFameAward::whereIn('user_id', $this->ranking->getCollection()->pluck('user_id'))
            ->whereNotNull('trophy')
            ->get(['user_id', 'trophy'])
            ->groupBy('user_id')
            ->map(fn($rows) => $rows->countBy('trophy')->all())
            ->all();
    }

    #[Computed]
    public function icons(): array
    {
        return HallOfFame::iconUrls();
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Hall of Fame') }}</flux:heading>
        <flux:text>{{ __('All-time ranking: points for titles, promotions, cup rounds and won matches in finished seasons.') }}</flux:text>
    </div>

    @if ($this->ranking->isEmpty())
        <flux:card>
            <flux:text>{{ __('The Hall of Fame fills up after the first finished season.') }}</flux:text>
        </flux:card>
    @else
        @php
            $trophyNames = \App\Support\HallOfFame::trophies();
            $offset = ($this->ranking->currentPage() - 1) * $this->ranking->perPage();
        @endphp

        <flux:table :paginate="$this->ranking">
            <flux:table.columns>
                <flux:table.column class="w-12">#</flux:table.column>
                <flux:table.column>{{ __('Team') }}</flux:table.column>
                <flux:table.column>{{ __('Trophies') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Seasons') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Points') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->ranking as $row)
                    @php
                        $user = $this->users[$row->user_id] ?? null;
                    @endphp
                    <flux:table.row :key="$row->user_id">
                        <flux:table.cell class="tabular-nums">{{ $offset + $loop->iteration }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user)
                                <flux:link :href="route('team.show', $user)" wire:navigate>{{ $user->team_name ?: $user->name }}</flux:link>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap items-center gap-1">
                                @foreach ($this->trophies[$row->user_id] ?? [] as $key => $count)
                                    <span class="inline-flex items-center gap-0.5" title="{{ $trophyNames[$key] ?? $key }}">
                                        @if (isset($this->icons[$key]))
                                            <img src="{{ $this->icons[$key] }}" alt="{{ $trophyNames[$key] ?? $key }}" class="size-6 object-contain">
                                        @else
                                            <flux:icon.trophy variant="micro" class="text-amber-500" />
                                        @endif
                                        @if ($count > 1)
                                            <span class="text-xs text-zinc-500">×{{ $count }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row->seasons }}</flux:table.cell>
                        <flux:table.cell align="end" class="font-semibold tabular-nums">
                            {{ \App\Support\HallOfFame::format((float) $row->total) }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
