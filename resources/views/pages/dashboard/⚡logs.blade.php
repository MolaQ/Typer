<?php

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public function render(): View
    {
        return $this->view()->title(__('Change log'));
    }

    #[Computed]
    public function logs()
    {
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        return AuditLog::query()
            ->with(['actor:id,name', 'target:id,name'])
            ->when(array_key_exists($this->event, Audit::events()), fn($q) => $q->where('event', $this->event))
            ->when($this->isDate($this->dateFrom), fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->isDate($this->dateTo), fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';

                $q->where(function ($q) use ($term) {
                    $q->where('description', 'like', $term)
                        ->orWhere('actor_name', 'like', $term)
                        ->orWhere('target_name', 'like', $term)
                        ->orWhere('properties', 'like', $term);
                });
            })
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(15, pageName: 'logsPage');
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    public function sort(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage('logsPage');
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'event', 'dateFrom', 'dateTo');
        $this->resetPage('logsPage');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'event', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage('logsPage');
        }
    }

    /** Lista zmienionych pól: [etykieta, było, jest]. */
    public function changes(AuditLog $log): array
    {
        $old = $log->properties['old'] ?? [];
        $new = $log->properties['new'] ?? [];
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = $this->format($old[$key] ?? null);
            $after = $this->format($new[$key] ?? null);

            if ($before !== $after) {
                $rows[] = [$this->fieldLabel($key), $before, $after];
            }
        }

        return $rows;
    }

    private function fieldLabel(string $key): string
    {
        return match ($key) {
            'team_name' => __('Team name'),
            'team_short_name' => __('Team short name'),
            'team_abbr' => __('Team abbreviation'),
            'roles' => __('Roles'),
            default => $key,
        };
    }

    private function format(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '—' : implode(', ', $value);
        }

        return ($value === null || $value === '') ? '—' : (string) $value;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div>
        <flux:heading size="xl" level="1">{{ __('Change log') }}</flux:heading>
        <flux:subheading>{{ __('Who changed what, and when.') }}</flux:subheading>
    </div>

    {{-- Filtry --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                :placeholder="__('Search by user, team or description...')" clearable />
        </div>

        <flux:select wire:model.live="event">
            <flux:select.option value="">{{ __('All events') }}</flux:select.option>
            @foreach (\App\Support\Audit::events() as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input type="date" wire:model.live="dateFrom" :aria-label="__('From')" />
        <flux:input type="date" wire:model.live="dateTo" :aria-label="__('To')" />
    </div>

    @if ($search !== '' || $event !== '' || $dateFrom !== '' || $dateTo !== '')
        <div>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="resetFilters">
                {{ __('Clear filters') }}
            </flux:button>
        </div>
    @endif

    {{-- Tabela --}}
    <flux:table :paginate="$this->logs">
        <flux:table.columns>
            <flux:table.column sortable sorted direction="{{ $sortDirection }}" wire:click="sort">
                {{ __('When') }}
            </flux:table.column>
            <flux:table.column>{{ __('Event') }}</flux:table.column>
            <flux:table.column>{{ __('Done by') }}</flux:table.column>
            <flux:table.column>{{ __('Concerns') }}</flux:table.column>
            <flux:table.column>{{ __('Details') }}</flux:table.column>
            <flux:table.column class="hidden xl:table-cell">{{ __('IP address') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->logs as $log)
                <flux:table.row :key="'log-'.$log->id">
                    <flux:table.cell class="whitespace-nowrap text-zinc-500">
                        {{ $log->created_at?->format('Y-m-d H:i:s') }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge size="sm" :color="\App\Support\Audit::color($log->event)">
                            {{ \App\Support\Audit::label($log->event) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell>{{ $log->actor?->name ?? $log->actor_name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $log->target?->name ?? $log->target_name ?? '—' }}</flux:table.cell>

                    <flux:table.cell>
                        <div class="space-y-1 text-sm">
                            @if ($log->description)
                                <div>{{ $log->description }}</div>
                            @endif

                            @foreach ($this->changes($log) as [$label, $before, $after])
                                <div class="flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <span class="text-zinc-500">{{ $label }}:</span>
                                    <span class="text-zinc-400 line-through">{{ $before }}</span>
                                    <span>&rarr;</span>
                                    <span class="font-medium">{{ $after }}</span>
                                </div>
                            @endforeach
                        </div>
                    </flux:table.cell>

                    <flux:table.cell class="hidden text-zinc-500 xl:table-cell">{{ $log->ip_address }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-10 text-center text-zinc-500">
                        {{ __('No log entries match your filters.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>