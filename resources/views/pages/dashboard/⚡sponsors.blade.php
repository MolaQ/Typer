<?php

use App\Enums\CompetitionType;
use App\Enums\Permission;
use App\Models\Competition;
use App\Models\Season;
use App\Models\Sponsor;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sponsorzy rozgrywek: lista sponsorów (nazwa, adres, logo) i przypisanie sponsora do każdych rozgrywek
 * wybranego sezonu. Sponsor pokazuje się obok nazwy rozgrywek na stronie wyników. Podgląd: season-list,
 * zmiany: season-edit.
 */
new class extends Component {
    use WithFileUploads;

    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    // Formularz sponsora.
    public ?int $editId = null;
    public string $name = '';
    public string $url = '';
    public $logo = null;

    public ?int $deleteId = null;

    /** competition_id => sponsor_id ('' = brak) */
    public array $assigned = [];

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }

        $this->loadAssigned();
    }

    public function render(): View
    {
        return $this->view()->title(__('Sponsors'));
    }

    public function updatedSeasonId(): void
    {
        unset($this->competitions);
        $this->loadAssigned();
    }

    private function loadAssigned(): void
    {
        $this->assigned = $this->competitions->mapWithKeys(fn($c) => [$c->id => (string) ($c->sponsor_id ?? '')])->all();
    }

    #[Computed]
    public function seasons()
    {
        return Season::orderByDesc('number')->get();
    }

    #[Computed]
    public function sponsors()
    {
        return Sponsor::withCount('competitions')->orderBy('name')->get();
    }

    /** Rozgrywki sezonu w kolejności typów (ligi od Ekstraklasy). */
    #[Computed]
    public function competitions()
    {
        $order = array_map(fn($t) => $t->value, CompetitionType::cases());

        return Competition::where('season_id', $this->seasonId)->get()
            ->sortBy(fn($c) => [array_search($c->type->value, $order, true), $c->tier ?? 0])
            ->values();
    }

    /* ==================================================================
     | SPONSORZY
     * ================================================================*/

    public function openForm(?int $id = null): void
    {
        $this->authorizeEdit();
        $this->reset('editId', 'name', 'url', 'logo');
        $this->resetValidation();

        if ($id) {
            $sponsor = Sponsor::findOrFail($id);
            $this->editId = $sponsor->id;
            $this->name = $sponsor->name;
            $this->url = (string) $sponsor->url;
        }

        Flux::modal('sponsor-form')->show();
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [], ['name' => __('Name'), 'url' => __('Website'), 'logo' => __('Logo')]);

        $sponsor = $this->editId ? Sponsor::findOrFail($this->editId) : new Sponsor();
        $old = $sponsor->only(['name', 'url']);
        $sponsor->fill(['name' => trim($this->name), 'url' => $this->url ?: null]);

        if ($this->logo) {
            if ($sponsor->logo_path) {
                Storage::disk('public')->delete($sponsor->logo_path);
            }
            $sponsor->logo_path = $this->logo->store('sponsors', 'public');
        }

        $sponsor->save();
        Audit::log($this->editId ? 'sponsor.updated' : 'sponsor.created', null, $old, $sponsor->only(['name', 'url']), $sponsor->name);

        Flux::modal('sponsor-form')->close();
        $this->reset('editId', 'name', 'url', 'logo');
        unset($this->sponsors);
        Flux::toast(variant: 'success', text: __('Sponsor saved.'));
    }

    public function confirmDelete(int $id): void
    {
        $this->authorizeEdit();
        $this->deleteId = Sponsor::findOrFail($id)->id;

        Flux::modal('delete-sponsor')->show();
    }

    public function delete(): void
    {
        $this->authorizeEdit();
        $sponsor = Sponsor::findOrFail((int) $this->deleteId);

        if ($sponsor->logo_path) {
            Storage::disk('public')->delete($sponsor->logo_path);
        }
        $sponsor->delete(); // rozgrywki tracą sponsora (nullOnDelete)

        Audit::log('sponsor.deleted', null, ['name' => $sponsor->name], [], $sponsor->name);

        Flux::modal('delete-sponsor')->close();
        $this->reset('deleteId');
        unset($this->sponsors, $this->competitions);
        $this->loadAssigned();
    }

    /* ==================================================================
     | PRZYPISANIE DO ROZGRYWEK
     * ================================================================*/

    /** Wybór z listy zapisuje się od razu. */
    public function updatedAssigned(mixed $value, string $key): void
    {
        $this->authorizeEdit();

        $competition = Competition::where('season_id', $this->seasonId)->findOrFail((int) $key);
        $sponsorId = $value === '' || $value === null ? null : Sponsor::findOrFail((int) $value)->id;

        $old = $competition->sponsor?->name;
        $competition->update(['sponsor_id' => $sponsorId]);

        Audit::log('competition.sponsor', null, ['sponsor' => $old], ['sponsor' => $competition->fresh()->sponsor?->name], $competition->name);

        unset($this->sponsors);
        Flux::toast(variant: 'success', text: __('Sponsor assigned.'));
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sponsors') }}</flux:heading>
            <flux:subheading>{{ __('Sponsors of the competitions. Each competition of a season can have its own sponsor.') }}</flux:subheading>
        </div>

        @can(\App\Enums\Permission::SeasonEdit->value)
            <flux:button variant="primary" icon="plus" wire:click="openForm">{{ __('New sponsor') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-6 xl:grid-cols-[22rem_1fr]">
        {{-- Sponsorzy --}}
        <flux:card class="space-y-3 self-start">
            <flux:heading>{{ __('Sponsors') }} ({{ $this->sponsors->count() }})</flux:heading>
            @forelse ($this->sponsors as $sponsor)
                <div class="flex items-center gap-3" wire:key="sponsor-{{ $sponsor->id }}">
                    @if ($sponsor->logoUrl())
                        <img src="{{ $sponsor->logoUrl() }}" alt="" class="h-10 w-16 shrink-0 object-contain">
                    @else
                        <div class="flex h-10 w-16 shrink-0 items-center justify-center rounded bg-zinc-100 text-xs text-zinc-400 dark:bg-zinc-800">{{ __('Logo') }}</div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $sponsor->name }}</div>
                        <div class="text-xs text-zinc-500">{{ trans_choice(':count competition|:count competitions', $sponsor->competitions_count, ['count' => $sponsor->competitions_count]) }}</div>
                    </div>
                    @can(\App\Enums\Permission::SeasonEdit->value)
                        <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openForm({{ $sponsor->id }})" :aria-label="__('Edit')" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $sponsor->id }})" :aria-label="__('Delete')" />
                    @endcan
                </div>
            @empty
                <flux:text class="text-sm">{{ __('No sponsors yet.') }}</flux:text>
            @endforelse
        </flux:card>

        {{-- Przypisanie --}}
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:heading>{{ __('Competitions of the season') }}</flux:heading>
                <div class="w-full sm:w-56">
                    <flux:select wire:model.live="seasonId" :label="__('Season')">
                        @foreach ($this->seasons as $option)
                            <flux:select.option :value="$option->id">{{ $option->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            @forelse ($this->competitions as $competition)
                <div class="flex flex-wrap items-center gap-3" wire:key="assign-{{ $competition->id }}">
                    <span class="min-w-0 flex-1 text-sm font-medium">{{ $competition->name ?: $competition->type->label() }}</span>
                    <div class="w-full sm:w-64">
                        <flux:select wire:model.live="assigned.{{ $competition->id }}" size="sm"
                            :disabled="! auth()->user()->can(\App\Enums\Permission::SeasonEdit->value)">
                            <flux:select.option value="">{{ __('No sponsor') }}</flux:select.option>
                            @foreach ($this->sponsors as $sponsor)
                                <flux:select.option :value="(string) $sponsor->id">{{ $sponsor->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            @empty
                <flux:text class="text-sm">{{ __('This season has no competitions yet.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>

    <flux:modal name="sponsor-form" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editId ? __('Edit sponsor') : __('New sponsor') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" />
            <flux:input wire:model="url" type="url" :label="__('Website')" placeholder="https://" />
            <div class="space-y-2">
                <flux:label>{{ __('Logo') }}</flux:label>
                <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                <flux:error name="logo" />
                @if ($logo && !$errors->has('logo'))
                    <img src="{{ $logo->temporaryUrl() }}" alt="" class="h-16 object-contain">
                @endif
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-sponsor" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete sponsor') }}</flux:heading>
            <flux:text>{{ __('Delete this sponsor? The competitions lose their sponsor.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
