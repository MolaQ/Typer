<?php

use App\Actions\Seasons\AwardHallOfFame;
use App\Enums\Permission;
use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Models\TrophyIcon;
use App\Support\Audit;
use App\Support\HallOfFame;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ustawienia Hall of Fame (etap 14): wartości punktacji, ikony trofeów do gabloty i przeliczenie
 * zakończonych sezonów (np. po zmianie wartości). Podgląd z season-list, zmiany z season-edit.
 */
new class extends Component {
    use WithFileUploads;

    /** klucz => wartość (tekst z formularza) */
    public array $values = [];

    /** Trofeum, którego ikonę wgrywamy w oknie. */
    public string $iconKey = '';

    public $icon = null;

    public function mount(): void
    {
        $this->values = array_map(fn($v) => (string) $v, HallOfFame::values());
    }

    public function render(): View
    {
        return $this->view()->title(__('Hall of Fame'));
    }

    #[Computed]
    public function icons(): array
    {
        return HallOfFame::iconUrls();
    }

    #[Computed]
    public function finishedSeasons()
    {
        // Także sezon w trakcie: Hall of Fame liczy punkty za mecze na bieżąco.
        return Season::whereIn('status', [SeasonStatus::Active->value, SeasonStatus::Finished->value])->orderBy('number')->get();
    }

    public function saveValues(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $rules = [];
        foreach (array_keys(HallOfFame::DEFAULTS) as $key) {
            $rules['values.' . $key] = ['required', 'numeric', 'min:0', 'max:100000'];
        }
        $this->validate($rules, [], collect(HallOfFame::groups())->collapse()->mapWithKeys(fn($label, $key) => ['values.' . $key => $label])->all());

        $before = HallOfFame::values();
        $now = now();

        DB::table('hall_of_fame_settings')->upsert(
            collect(HallOfFame::DEFAULTS)->keys()->map(fn($key) => [
                'key' => $key,
                'value' => round((float) $this->values[$key], 2),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['value', 'updated_at'],
        );

        HallOfFame::forget();
        $after = HallOfFame::values();

        $changed = array_keys(array_filter($after, fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        Audit::log('hall_of_fame.settings', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), __('Hall of Fame'));

        Flux::toast(variant: 'success', text: __('Point values saved. Recalculate the seasons to apply them.'));
    }

    public function resetValues(): void
    {
        $this->values = array_map(fn($v) => (string) $v, HallOfFame::DEFAULTS);
        Flux::toast(text: __('Default values restored in the form. Save to keep them.'));
    }

    public function recalculate(AwardHallOfFame $action): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $count = 0;
        foreach ($this->finishedSeasons as $season) {
            $count += $action->handle($season);
        }

        Audit::log('hall_of_fame.recalculated', null, [], ['seasons' => $this->finishedSeasons->count(), 'awards' => $count], __('Hall of Fame'));

        Flux::toast(variant: 'success', text: __('Hall of Fame recalculated: :seasons seasons.', ['seasons' => $this->finishedSeasons->count()]));
    }

    public function openIcon(string $key): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        abort_unless(array_key_exists($key, HallOfFame::trophies()), 422);

        $this->iconKey = $key;
        $this->reset('icon');
        $this->resetValidation();

        Flux::modal('trophy-icon')->show();
    }

    public function saveIcon(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        abort_unless(array_key_exists($this->iconKey, HallOfFame::trophies()), 422);

        $this->validate($this->iconRules(), [], ['icon' => __('Icon')]);

        $path = $this->icon->store('trophies', 'public');
        $old = TrophyIcon::where('key', $this->iconKey)->value('path');

        TrophyIcon::updateOrCreate(['key' => $this->iconKey], ['path' => $path]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        Audit::log('hall_of_fame.icon', null, [], ['trophy' => HallOfFame::trophies()[$this->iconKey]], __('Hall of Fame'));

        Flux::modal('trophy-icon')->close();
        $this->resetIcon();
        unset($this->icons);
        Flux::toast(variant: 'success', text: __('Icon saved.'));
    }

    /** Sprawdzenie od razu po wybraniu pliku (podgląd pokazujemy tylko dla poprawnego obrazka). */
    public function updatedIcon(): void
    {
        $this->validate($this->iconRules(), [], ['icon' => __('Icon')]);
    }

    /** Jak logo sponsora: PNG, JPG albo WebP do 2 MB, kwadrat (SVG może zawierać skrypty). */
    private function iconRules(): array
    {
        return ['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:ratio=1/1,min_width=64,min_height=64,max_width=2000,max_height=2000']];
    }

    public function removeIcon(string $key): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $icon = TrophyIcon::where('key', $key)->first();

        if ($icon) {
            Storage::disk('public')->delete($icon->path);
            $icon->delete();
        }

        unset($this->icons);
    }

    public function resetIcon(): void
    {
        $this->reset('iconKey', 'icon');
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Hall of Fame') }}</flux:heading>
            <flux:subheading>
                {{ __('Points for successes in finished seasons and the trophy icons shown in team cabinets.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="eye" :href="route('hall-of-fame')" target="_blank">{{ __('Show ranking') }}</flux:button>

            @can(\App\Enums\Permission::SeasonEdit->value)
                <flux:modal.trigger name="confirm-recalculate">
                    <flux:button icon="arrow-path">
                        {{ __('Recalculate seasons') }} ({{ $this->finishedSeasons->count() }})
                    </flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    {{-- Punktacja --}}
    <flux:card class="space-y-6">
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Point values') }}</flux:heading>
            <flux:text>{{ __('League points are multiplied by the league multiplier. Złota Liga gives only a trophy, no points.') }}</flux:text>
        </div>

        @foreach (\App\Support\HallOfFame::groups() as $group => $fields)
            <div class="space-y-3" wire:key="group-{{ $loop->index }}">
                <flux:heading>{{ $group }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($fields as $key => $label)
                        <flux:input type="number" step="0.1" min="0" wire:model="values.{{ $key }}" :label="$label"
                            wire:key="value-{{ $key }}" />
                    @endforeach
                </div>
            </div>
        @endforeach

        @can(\App\Enums\Permission::SeasonEdit->value)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="check" wire:click="saveValues">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="resetValues">{{ __('Restore defaults') }}</flux:button>
            </div>
        @endcan
    </flux:card>

    {{-- Ikony trofeów --}}
    <flux:card class="space-y-4">
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Trophy icons') }}</flux:heading>
            <flux:text>{{ __('Square PNG, JPG or WebP up to 2 MB. Without an icon the cabinet shows a default trophy.') }}</flux:text>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach (\App\Support\HallOfFame::trophies() as $key => $label)
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                    wire:key="trophy-{{ $key }}">
                    @if (isset($this->icons[$key]))
                        <img src="{{ $this->icons[$key] }}" alt="" class="size-10 shrink-0 rounded object-contain">
                    @else
                        <div class="flex size-10 shrink-0 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-700">
                            <flux:icon.trophy class="size-5 text-amber-500" />
                        </div>
                    @endif

                    <span class="min-w-0 flex-1 text-sm">{{ $label }}</span>

                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <flux:button size="xs" variant="ghost" icon="arrow-up-tray" wire:click="openIcon('{{ $key }}')"
                            :aria-label="__('Upload icon')" />
                        @if (isset($this->icons[$key]))
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeIcon('{{ $key }}')"
                                :aria-label="__('Remove')" />
                        @endif
                    @endcan
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:modal name="confirm-recalculate" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Recalculate seasons') }}</flux:heading>
            <flux:text>{{ __('Recalculate Hall of Fame for all finished seasons and the current one?') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="recalculate" x-on:click="$flux.modal('confirm-recalculate').close()">
                    {{ __('Confirm') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="trophy-icon" class="w-full md:w-[28rem]" @close="resetIcon">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Upload icon') }}</flux:heading>
            <flux:text>{{ \App\Support\HallOfFame::trophies()[$iconKey] ?? '' }}</flux:text>

            <input type="file" wire:model="icon" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
            <flux:error name="icon" />

            @if ($icon && !$errors->has('icon'))
                <img src="{{ $icon->temporaryUrl() }}" alt="" class="size-24 rounded object-contain">
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveIcon" wire:loading.attr="disabled">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
