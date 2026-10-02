<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new class extends Component {
    use WithPagination;

    public const ADMIN_ROLE = 'Admin';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $roleFilter = '';

    #[Url(except: 'name')]
    public string $sortBy = 'name';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    // Edytowany użytkownik
    public ?int $userId = null;
    public string $userName = '';
    /** @var array<int, string> nazwy zaznaczonych ról */
    public array $selected = [];

    public function render(): View
    {
        return $this->view()->title(__('Users'));
    }

    /* ------------------------------------------------------------------
     | Dane
     * ----------------------------------------------------------------*/

    #[Computed]
    public function stats(): array
    {
        return [
            'users' => User::count(),
            'withoutRole' => User::doesntHave('roles')->count(),
            'admins' => User::whereHas('roles', fn ($q) => $q->where('name', self::ADMIN_ROLE))->count(),
        ];
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with('roles:id,name')
            ->withCount('roles')
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($this->roleFilter === '__none', fn ($q) => $q->doesntHave('roles'))
            ->when(
                $this->roleFilter !== '' && $this->roleFilter !== '__none',
                fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $this->roleFilter))
            )
            ->orderBy($this->safeSortBy(), $this->safeDirection())
            ->paginate(10, pageName: 'usersPage');
    }

    /* ------------------------------------------------------------------
     | Sortowanie i filtry
     * ----------------------------------------------------------------*/

    private function sortableColumns(): array
    {
        return ['name', 'email', 'roles_count', 'created_at'];
    }

    private function safeSortBy(): string
    {
        return in_array($this->sortBy, $this->sortableColumns(), true) ? $this->sortBy : 'name';
    }

    private function safeDirection(): string
    {
        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }

    public function sort(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage('usersPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('usersPage');
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage('usersPage');
    }

    /* ------------------------------------------------------------------
     | Przypisywanie ról
     * ----------------------------------------------------------------*/

    public function edit(int $id): void
    {
        $this->authorizeAdmin();

        $user = User::with('roles:id,name')->findOrFail($id);

        $this->userId = $user->id;
        $this->userName = $user->name;
        $this->selected = $user->roles->pluck('name')->all();
        $this->resetValidation();

        Flux::modal('user-roles')->show();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'selected' => ['array'],
            'selected.*' => ['string', 'exists:roles,name'],
        ]);

        $user = User::findOrFail($this->userId);

        $hadAdmin = $user->hasRole(self::ADMIN_ROLE);
        $keepsAdmin = in_array(self::ADMIN_ROLE, $this->selected, true);

        if ($hadAdmin && ! $keepsAdmin) {
            if ($user->is(auth()->user())) {
                $this->addError('selected', __('You cannot remove your own Admin role.'));

                return;
            }

            if (User::role(self::ADMIN_ROLE)->count() <= 1) {
                $this->addError('selected', __('At least one Admin must remain.'));

                return;
            }
        }

        $user->syncRoles($this->selected);

        Flux::modal('user-roles')->close();
        Flux::toast(text: __('Roles updated.'), variant: 'success');
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset('userId', 'userName', 'selected');
        $this->resetValidation();
    }

    /* ------------------------------------------------------------------
     | Akcje Livewire to osobne żądania, więc sprawdzamy uprawnienia w każdej.
     * ----------------------------------------------------------------*/

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(self::ADMIN_ROLE), 403);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    {{-- Nagłówek --}}
    <div>
        <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
        <flux:subheading>{{ __('Assign roles to the people who use this application.') }}</flux:subheading>
    </div>

    {{-- Statystyki --}}
    <div class="grid gap-4 md:grid-cols-3">
        <flux:card class="flex items-center gap-4">
            <flux:icon.users class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('All users') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['users'] }}</flux:heading>
            </div>
        </flux:card>
        <flux:card class="flex items-center gap-4">
            <flux:icon.shield-check class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('Admins') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['admins'] }}</flux:heading>
            </div>
        </flux:card>
        <flux:card class="flex items-center gap-4">
            <flux:icon.user-minus class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('Without a role') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['withoutRole'] }}</flux:heading>
            </div>
        </flux:card>
    </div>

    {{-- Wyszukiwarka i filtr --}}
    <div class="flex flex-wrap items-center gap-4">
        <div class="w-full sm:w-72">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search by name or email...')"
                clearable
            />
        </div>

        <div class="w-full sm:w-56">
            <flux:select wire:model.live="roleFilter">
                <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
                <flux:select.option value="__none">{{ __('Without a role') }}</flux:select.option>
                @foreach ($this->roles as $role)
                    <flux:select.option :value="$role->name">{{ $role->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Tabela użytkowników --}}
    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">
                {{ __('User') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')">
                {{ __('Email') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'roles_count'" :direction="$sortDirection" wire:click="sort('roles_count')">
                {{ __('Roles') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">
                {{ __('Joined') }}
            </flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row :key="'user-'.$user->id">
                    <flux:table.cell variant="strong">
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$user->name" />
                            <span>{{ $user->name }}</span>
                            @if ($user->is(auth()->user()))
                                <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($user->roles as $role)
                                <flux:badge size="sm" :color="$role->name === 'Admin' ? 'amber' : 'blue'">{{ $role->name }}</flux:badge>
                            @empty
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $user->created_at?->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="pencil-square"
                            inset="top bottom"
                            wire:click="edit({{ $user->id }})"
                            :aria-label="__('Edit roles')"
                        />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-10 text-center text-zinc-500">
                        {{ __('No users match your filters.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal: role użytkownika --}}
    <flux:modal name="user-roles" class="w-full md:w-[30rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit roles') }}</flux:heading>
                <flux:text class="mt-1">{{ $userName }}</flux:text>
            </div>

            <div class="space-y-1">
                @if ($this->roles->isEmpty())
                    <flux:text>{{ __('No roles yet. Create them in Roles and permissions first.') }}</flux:text>
                @else
                    <flux:checkbox.group wire:model="selected" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        @foreach ($this->roles as $role)
                            <flux:checkbox
                                wire:key="role-{{ $role->id }}"
                                :value="$role->name"
                                :label="$role->name"
                            />
                        @endforeach
                    </flux:checkbox.group>
                @endif

                <flux:error name="selected" />
            </div>

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