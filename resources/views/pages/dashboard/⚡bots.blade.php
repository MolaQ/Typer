<?php

use App\Enums\Permission;
use App\Models\Bot;
use App\Models\User;
use App\Support\Audit;
use App\Support\BotNames;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Boty: pula 512 zespołów bez właścicieli. Admin zmienia nazwy wyświetlane w tabelach.
 * Domyślne nazwy pochodzą z App\Support\BotNames (BotsSeeder).
 * Uprawnienia: podgląd season-list, zmiana nazwy season-edit.
 */
new class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    // --- formularz zmiany nazwy ---
    public ?int $botId = null;
    public int $botSlot = 0;
    public string $name = '';

    public function render(): View
    {
        return $this->view()->title(__('Bots'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage('botsPage');
    }

    /* ==================================================================
     | DANE DO WIDOKU
     * ================================================================*/

    /** Liczba botów w bazie i ile ma zmienioną nazwę. */
    #[Computed]
    public function stats(): array
    {
        $bots = Bot::get(['sort_order', 'name']);

        return [
            'total' => $bots->count(),
            'renamed' => $bots->filter(fn(Bot $bot) => $bot->isRenamed())->count(),
        ];
    }

    #[Computed]
    public function bots()
    {
        return Bot::query()
            ->when($this->search !== '', fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('sort_order')
            ->paginate(25, pageName: 'botsPage');
    }

    /* ==================================================================
     | ZMIANA NAZWY
     * ================================================================*/

    public function edit(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $bot = Bot::findOrFail($id);

        $this->botId = $bot->id;
        $this->botSlot = $bot->sort_order;
        $this->name = $bot->name;
        $this->resetValidation();

        Flux::modal('bot-form')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:40',
                // Nazwa bota musi być unikalna wśród botów (wielkość liter nie ma znaczenia).
                Rule::unique('bots', 'name')->ignore($this->botId),
                // ...i nie może pokrywać się z nazwą zespołu żadnego gracza.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::whereRaw('LOWER(team_name) = ?', [mb_strtolower((string) $value)])->exists()) {
                        $fail(__('This name is already used by a player.'));
                    }
                },
            ],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['name' => __('Bot name')];
    }

    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $bot = Bot::findOrFail($this->botId);

        $this->name = Str::squish($this->name);
        $this->validate();

        $this->rename($bot, $this->name);

        Flux::modal('bot-form')->close();
        $this->resetForm();

        Flux::toast(text: __('Bot name saved.'), variant: 'success');
    }

    /** Przywraca domyślną nazwę z BotNames, o ile nie zajął jej inny bot. */
    public function resetName(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $bot = Bot::findOrFail($id);
        $default = $bot->defaultName();

        if ($default === null || $default === $bot->name) {
            return;
        }

        if (Bot::where('name', $default)->where('id', '!=', $bot->id)->exists()) {
            Flux::toast(text: __('The default name is used by another bot.'), variant: 'warning');

            return;
        }

        $this->rename($bot, $default);

        Flux::toast(text: __('Default name restored.'), variant: 'success');
    }

    /** Wywoływana także przez @close modala, więc jest publiczna. */
    public function resetForm(): void
    {
        $this->reset('botId', 'botSlot', 'name');
        $this->resetValidation();
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function rename(Bot $bot, string $newName): void
    {
        $old = $bot->name;

        if ($old === $newName) {
            return;
        }

        $bot->update(['name' => $newName]);

        Audit::log('bot.renamed', null, ['name' => $old], ['name' => $newName], __('Bot') . ' #' . $bot->sort_order);

        unset($this->bots, $this->stats);
    }

    /** Akcje Livewire to osobne żądania, więc uprawnienie sprawdzamy w każdej z nich. */
    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    {{-- Nagłówek --}}
    <div>
        <flux:heading size="xl" level="1">{{ __('Bots') }}</flux:heading>
        <flux:subheading>{{ __('Bots fill the empty places on the team list. Here you can change their names.') }}
        </flux:subheading>
    </div>

    {{-- Statystyki --}}
    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="flex items-center gap-4">
            <flux:icon.cpu-chip class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('Bots in the database') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['total'] }} / {{ BotNames::COUNT }}</flux:heading>
            </div>
        </flux:card>
        <flux:card class="flex items-center gap-4">
            <flux:icon.pencil-square class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('Renamed bots') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['renamed'] }}</flux:heading>
            </div>
        </flux:card>
    </div>

    @if ($this->stats['total'] < BotNames::COUNT)
        <flux:text class="text-amber-600 dark:text-amber-400">
            {{ __('Some bots are missing. Run: php artisan db:seed --class=BotsSeeder') }}
        </flux:text>
    @endif

    {{-- Wyszukiwarka --}}
    <div class="w-full sm:w-72">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
            :placeholder="__('Search by name...')" clearable />
    </div>

    {{-- Tabela --}}
    <flux:table :paginate="$this->bots">
        <flux:table.columns>
            <flux:table.column>#</flux:table.column>
            <flux:table.column>{{ __('Bot name') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->bots as $bot)
                <flux:table.row :key="'bot-'.$bot->id">
                    <flux:table.cell variant="strong">{{ $bot->sort_order }}</flux:table.cell>
                    <flux:table.cell>{{ $bot->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($bot->isRenamed())
                            <flux:badge size="sm" color="amber">{{ __('Renamed') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Default') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <div class="flex items-center justify-end gap-1">
                                @if ($bot->isRenamed())
                                    <flux:button variant="ghost" size="sm" icon="arrow-uturn-left" inset="top bottom"
                                        wire:click="resetName({{ $bot->id }})" :aria-label="__('Restore default name')" />
                                @endif
                                <flux:button variant="ghost" size="sm" icon="pencil-square" inset="top bottom"
                                    wire:click="edit({{ $bot->id }})" :aria-label="__('Edit')" />
                            </div>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-10 text-center text-zinc-500">
                        {{ __('No bots match your search.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal: zmiana nazwy bota --}}
    <flux:modal name="bot-form" class="w-full md:w-[28rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit bot name') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Bot') }} #{{ $botSlot }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Bot name')" maxlength="40" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>