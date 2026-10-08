<?php

use App\Enums\RoleName;
use App\Models\HallOfFameAward;
use App\Models\User;
use App\Support\HallOfFame;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ranking Hall of Fame (etap 14) do osadzenia: na stronie głównej (skrócony) i na stronie /hall-of-fame.
 * Domyślnie otwiera się strona z zalogowanym graczem (wiersz jest wyróżniony). Gość, użytkownik bez roli
 * i zbanowany widzą pierwszą stronę.
 */
new class extends Component {
    use WithPagination;

    /** Parametr strony w adresie (?hof=3), żeby nie mieszał się z innymi listami. */
    private const PAGE = 'hof';

    public int $perPage = 50;

    /** Wersja skrócona na stronę główną: mniej kolumn i link do pełnego rankingu. */
    public bool $compact = false;

    public function mount(): void
    {
        // Strona z graczem tylko wtedy, gdy nie wybrano jej w adresie.
        if (request()->query(self::PAGE) === null && ($rank = $this->myRank())) {
            $this->setPage((int) ceil($rank / $this->perPage), self::PAGE);
        }
    }

    /** Ranking: suma punktów, przy remisie więcej trofeów, potem kolejność kont. */
    private function query()
    {
        return HallOfFameAward::query()
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(points) as total, count(trophy) as trophies, count(distinct season_id) as seasons')
            ->orderByDesc('total')
            ->orderByDesc('trophies')
            ->orderBy('user_id');
    }

    #[Computed]
    public function ranking()
    {
        return $this->query()->paginate($this->perPage, pageName: self::PAGE);
    }

    /** Miejsce zalogowanego gracza (null: gość, bez roli, zbanowany albo bez punktów). */
    #[Computed]
    public function myRank(): ?int
    {
        $user = auth()->user();

        if (!$user || $user->roles()->doesntExist() || $user->hasRole(RoleName::Banned->value)) {
            return null;
        }

        $index = $this->query()->pluck('user_id')->search($user->id);

        return $index === false ? null : $index + 1;
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

<div>
    @if ($this->ranking->isEmpty())
        <flux:card>
            <flux:text>{{ __('The Hall of Fame fills up after the first finished season.') }}</flux:text>
        </flux:card>
    @else
        @php
            $trophyNames = \App\Support\HallOfFame::trophies();
            $offset = ($this->ranking->currentPage() - 1) * $this->ranking->perPage();
            $me = auth()->id();
        @endphp

        <flux:table :paginate="$this->ranking">
            <flux:table.columns>
                <flux:table.column class="w-12">#</flux:table.column>
                <flux:table.column>{{ __('Team') }}</flux:table.column>
                <flux:table.column>{{ __('Trophies') }}</flux:table.column>
                @if (!$compact)
                    <flux:table.column align="end">{{ __('Seasons') }}</flux:table.column>
                @endif
                <flux:table.column align="end">{{ __('Points') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->ranking as $row)
                    @php
                        $user = $this->users[$row->user_id] ?? null;
                        $rowClass = $me && (int) $row->user_id === (int) $me ? 'bg-amber-50 dark:bg-amber-500/10' : '';
                    @endphp
                    <flux:table.row :key="$row->user_id" :class="$rowClass">
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
                        @if (!$compact)
                            <flux:table.cell align="end" class="tabular-nums">{{ $row->seasons }}</flux:table.cell>
                        @endif
                        <flux:table.cell align="end" class="font-semibold tabular-nums">
                            {{ \App\Support\HallOfFame::format((float) $row->total) }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
