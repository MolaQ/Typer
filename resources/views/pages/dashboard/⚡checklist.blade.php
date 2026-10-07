<?php

use App\Models\Season;
use App\Support\SeasonChecklist;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Konieczne do zrobienia": wszystkie ustawienia potrzebne, żeby sezon działał poprawnie.
 * Tylko podgląd (uprawnienie season-list), każdy punkt ma przycisk do właściwej strony.
 */
new class extends Component {
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('To do'));
    }

    #[Computed]
    public function seasons()
    {
        return Season::orderByDesc('number')->get();
    }

    #[Computed]
    public function season(): ?Season
    {
        return Season::find($this->seasonId);
    }

    /** Punkty listy pogrupowane (Ogólne, Sezon, Lista zespołów, Uruchomienie). */
    #[Computed]
    public function groups(): array
    {
        return collect(SeasonChecklist::for($this->season))
            ->groupBy('group')
            ->all();
    }

    /** @return array{done: int, total: int} */
    #[Computed]
    public function progress(): array
    {
        $items = collect(SeasonChecklist::for($this->season));

        return ['done' => $items->where('ok', true)->count(), 'total' => $items->count()];
    }

    /** Odświeża listę po powrocie z innej strony (przycisk "Sprawdź ponownie"). */
    public function recheck(): void
    {
        unset($this->groups, $this->progress, $this->season);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('To do') }}</flux:heading>
            <flux:subheading>{{ __('Everything that must be set up for the season to work correctly.') }}
            </flux:subheading>
        </div>

        <div class="flex items-end gap-2">
            @if ($this->seasons->isNotEmpty())
                <div class="w-full sm:w-56">
                    <flux:select wire:model.live="seasonId" :label="__('Season')">
                        @foreach ($this->seasons as $option)
                            <flux:select.option :value="$option->id">
                                {{ $option->title }} ({{ $option->status->label() }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            <flux:button icon="arrow-path" wire:click="recheck">{{ __('Check again') }}</flux:button>
        </div>
    </div>

    @php
        $progress = $this->progress;
        $percent = $progress['total'] > 0 ? round(($progress['done'] / $progress['total']) * 100) : 0;
    @endphp

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading>{{ __(':done of :total done', ['done' => $progress['done'], 'total' => $progress['total']]) }}
            </flux:heading>
            @if ($progress['done'] === $progress['total'])
                <flux:badge color="green">{{ __('Ready') }}</flux:badge>
            @endif
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
            <div class="h-full rounded-full bg-green-500" style="width: {{ $percent }}%"></div>
        </div>
    </flux:card>

    @foreach ($this->groups as $group => $items)
        <flux:card class="space-y-1">
            <flux:heading class="mb-2">{{ $group }}</flux:heading>

            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($items as $item)
                    <li class="flex items-center gap-3 py-3"
                        wire:key="check-{{ $loop->parent->index }}-{{ $loop->index }}">
                        @if ($item['ok'])
                            <flux:icon.check-circle class="size-6 shrink-0 text-green-500" />
                        @else
                            <flux:icon.exclamation-circle class="size-6 shrink-0 text-amber-500" />
                        @endif

                        <div class="min-w-0 flex-1">
                            <div>{{ $item['title'] }}</div>
                            @if (!$item['ok'] && $item['hint'])
                                <code class="text-xs text-zinc-500">{{ $item['hint'] }}</code>
                            @endif
                        </div>

                        @if ($item['detail'] !== '')
                            <span class="shrink-0 text-sm tabular-nums text-zinc-500">{{ $item['detail'] }}</span>
                        @endif

                        @if (!$item['ok'] && $item['route'] && \Illuminate\Support\Facades\Route::has($item['route']))
                            <flux:button size="sm"
                                :href="route($item['route'], $this->seasonId ? ['season' => $this->seasonId] : [])"
                                wire:navigate>
                                {{ __('Fix') }}
                            </flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endforeach
</div>
