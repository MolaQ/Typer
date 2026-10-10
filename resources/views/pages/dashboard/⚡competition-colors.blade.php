<?php

use App\Enums\Permission;
use App\Support\Audit;
use App\Support\CompetitionColors;
use App\Support\HallOfFame;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Barwy rozgrywek (App\Support\CompetitionColors): tło (gradient z dwóch kolorów), akcent i tekst dla każdych
 * rozgrywek, z podglądem banera, odznaki i plakietki. Barwy służą banerom, odznakom i oznaczeniom zwycięzców.
 * Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    /** klucz => [bg1, bg2, accent, ink] */
    public array $colors = [];

    public function mount(): void
    {
        $this->colors = CompetitionColors::all();
    }

    public function render(): View
    {
        return $this->view()->title(__('Competition colors'));
    }

    #[Computed]
    public function icons(): array
    {
        return HallOfFame::iconUrls();
    }

    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $rules = [];
        foreach (CompetitionColors::order() as $key) {
            foreach (['bg1', 'bg2', 'accent', 'ink'] as $field) {
                $rules["colors.$key.$field"] = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
            }
        }
        $this->validate($rules);

        $now = now();
        DB::table('competition_colors')->upsert(
            collect(CompetitionColors::order())->map(fn($key) => [
                'key' => $key,
                'bg1' => strtolower($this->colors[$key]['bg1']),
                'bg2' => strtolower($this->colors[$key]['bg2']),
                'accent' => strtolower($this->colors[$key]['accent']),
                'ink' => strtolower($this->colors[$key]['ink']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['bg1', 'bg2', 'accent', 'ink', 'updated_at'],
        );
        CompetitionColors::flush();

        Audit::log('competition_colors.saved', null, [], [], __('Competition colors'));
        Flux::toast(variant: 'success', text: __('Colors saved.'));
    }

    /** Przywraca barwy domyślne jednych rozgrywek (zapis dopiero po „Zapisz”). */
    public function restore(string $key): void
    {
        abort_unless(isset(CompetitionColors::DEFAULTS[$key]), 422);
        $this->colors[$key] = CompetitionColors::DEFAULTS[$key];
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Competition colors') }}</flux:heading>
            <flux:subheading>{{ __('Background gradient, accent and text color of each competition, used in banners, badges and winner marks. Backgrounds without yellow, orange or red; gold only as an accent.') }}</flux:subheading>
        </div>
        @can(\App\Enums\Permission::SeasonEdit->value)
            <flux:button variant="primary" icon="check" wire:click="save">{{ __('Save') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (\App\Support\CompetitionColors::labels() as $key => $label)
            @php
                $c = $colors[$key] ?? \App\Support\CompetitionColors::DEFAULTS[$key];
                $style = "--c-bg1: {$c['bg1']}; --c-bg2: {$c['bg2']}; --c-accent: {$c['accent']}; --c-ink: {$c['ink']};";
                $dashed = \App\Support\CompetitionColors::dashed($key) ? 'comp-dashed' : '';
            @endphp
            <flux:card class="space-y-3" wire:key="cc-{{ $key }}">
                {{-- Podgląd na żywo: baner z trofeum, odznaka i plakietka zwycięzcy --}}
                <div class="comp-banner {{ $dashed }} flex items-center gap-3 rounded-xl px-4 py-3" style="{{ $style }}">
                    <div class="comp-badge {{ $dashed }} relative z-10 flex size-12 shrink-0 items-center justify-center rounded-full">
                        @if (isset($this->icons[$key]))
                            <img src="{{ $this->icons[$key] }}" alt="" class="size-8 object-contain">
                        @else
                            <flux:icon.trophy class="size-6" />
                        @endif
                    </div>
                    <div class="relative z-10 min-w-0 flex-1">
                        <div class="comp-accent text-[10px] font-bold uppercase tracking-[0.2em]">{{ $label }} – {{ __('Season') }}</div>
                        <div class="truncate text-xl font-black">{{ $label }}</div>
                    </div>
                    <span class="comp-chip {{ $dashed }} relative z-10 rounded-full px-2 py-0.5 text-xs font-bold">{{ __('Winner') }}</span>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach (['bg1' => __('Background 1'), 'bg2' => __('Background 2'), 'accent' => __('Accent'), 'ink' => __('Text')] as $field => $fieldLabel)
                        <label class="flex flex-col gap-1 text-xs text-zinc-500">
                            {{ $fieldLabel }}
                            <span class="flex items-center gap-1.5">
                                <input type="color" wire:model.live="colors.{{ $key }}.{{ $field }}" id="cc-{{ $key }}-{{ $field }}"
                                    class="h-8 w-10 cursor-pointer rounded border border-zinc-300 bg-transparent p-0.5 dark:border-zinc-600">
                                <code class="text-[11px] text-zinc-700 dark:text-zinc-300">{{ $c[$field] }}</code>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="restore('{{ $key }}')">{{ __('Restore default') }}</flux:button>
                </div>
            </flux:card>
        @endforeach
    </div>
</div>
