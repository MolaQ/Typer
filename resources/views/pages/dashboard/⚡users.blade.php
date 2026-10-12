<?php

use App\Enums\RoleName;
use App\Models\User;
use App\Support\Roster;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new class extends Component {
    use WithPagination;

    public const ADMIN_ROLE = RoleName::Admin->value;

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

    /** @var array<int, string> id zaznaczonych kont do akcji zbiorczych (zdjęcie bana, zdjęcie Inactive) */
    public array $checked = [];

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
            'unverified' => User::whereNull('email_verified_at')->count(),
            'admins' => User::whereHas('roles', fn($q) => $q->where('name', self::ADMIN_ROLE))->count(),
        ];
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('name')->get(['id', 'name']);
    }

    /** Konta pasujące do wyszukiwarki (imię, e-mail, nazwa drużyny) i filtra ról. */
    private function filtered()
    {
        return User::query()
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(fn($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('team_name', 'like', $term));
            })
            ->when($this->roleFilter === '__none', fn($q) => $q->doesntHave('roles'))
            ->when($this->roleFilter === '__unverified', fn($q) => $q->whereNull('email_verified_at'))
            ->when($this->roleFilter !== '' && !str_starts_with($this->roleFilter, '__'), fn($q) => $q->whereHas('roles', fn($r) => $r->where('name', $this->roleFilter)));
    }

    #[Computed]
    public function users()
    {
        return $this->filtered()
            ->with('roles:id,name')
            ->withCount('roles')
            ->orderBy($this->safeSortBy(), $this->safeDirection())
            ->paginate(10, pageName: 'usersPage');
    }

    /** Liczba kont pasujących do filtra (przycisk „zaznacz wszystkie z filtra”). */
    #[Computed]
    public function filteredCount(): int
    {
        return $this->filtered()->count();
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
        if (!in_array($column, $this->sortableColumns(), true)) {
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
        $this->checked = [];
    }

    /* ------------------------------------------------------------------
     | Akcje zbiorcze: zdjęcie bana albo statusu Inactive z zaznaczonych kont
     * ----------------------------------------------------------------*/

    /** Zaznacza wszystkie konta pasujące do filtra (ze wszystkich stron listy). */
    public function checkAllFiltered(): void
    {
        $this->checked = $this->filtered()->pluck('id')->map(fn($id) => (string) $id)->all();
    }

    public function clearChecked(): void
    {
        $this->checked = [];
    }

    /**
     * Zdejmuje rolę Banned albo Inactive z zaznaczonych kont. Konto bez innej roli dostaje User,
     * więc wraca do gry (nowy gracz trafia do Ligi podwórkowej, Roster::syncUser).
     */
    public function releaseRole(string $role): void
    {
        $this->authorizeAdmin();

        if (!in_array($role, \App\Support\Players::BLOCKING_ROLES, true)) {
            return;
        }

        $ids = array_map('intval', $this->checked);
        $users = User::whereIn('id', $ids)->whereHas('roles', fn($q) => $q->where('name', $role))->get();
        $added = 0;

        foreach ($users as $user) {
            $wasPlayer = \App\Support\Players::canPlay($user);

            $user->removeRole($role);
            $user->unsetRelation('roles');
            if (!$user->roles()->exists()) {
                $user->assignRole(RoleName::User->value);
                $user->unsetRelation('roles');
            }

            if (!$wasPlayer && \App\Support\Players::canPlay($user)) {
                \App\Support\SystemFeed::record('players', ':name joined the game', ['name' => $user->team_name ?: $user->name], 'team.show', ['user' => $user->id], $user->id);
            }

            \App\Support\Audit::log('user.role_removed', $user, ['roles' => [$role]], ['roles' => $user->roles->pluck('name')->all()], __('Bulk action'));

            $added += Roster::syncUser($user)['added'];
        }

        $this->checked = [];
        unset($this->users, $this->stats, $this->filteredCount);

        Flux::toast(text: __(':role removed from :count accounts.', ['role' => $role, 'count' => $users->count()]), variant: 'success');
        if ($added > 0) {
            Flux::toast(text: __('Added to the season list: :count.', ['count' => $added]), variant: 'success');
        }
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

        if ($hadAdmin && !$keepsAdmin) {
            if ($user->is(auth()->user())) {
                $this->addError('selected', __('You cannot remove your own Admin role.'));

                return;
            }

            if (User::role(self::ADMIN_ROLE)->count() <= 1) {
                $this->addError('selected', __('At least one Admin must remain.'));

                return;
            }
        }

        // Przywrócenie nieaktywnego gracza: nadanie mu innej roli zdejmuje rolę Inactive,
        // inaczej dalej by blokowała grę (Players::BLOCKING_ROLES) i nie trafiłby na listę sezonu.
        $inactive = RoleName::Inactive->value;
        if ($user->hasRole($inactive) && in_array($inactive, $this->selected, true) && count($this->selected) > 1) {
            $this->selected = array_values(array_diff($this->selected, [$inactive]));
        }

        $wasPlayer = \App\Support\Players::canPlay($user);
        $wasBanned = $user->hasRole(RoleName::Banned->value);

        $user->syncRoles($this->selected);
        $user->unsetRelation('roles');

        // Informacje systemowe: nowy gracz albo ban.
        if (!$wasPlayer && \App\Support\Players::canPlay($user)) {
            \App\Support\SystemFeed::record('players', ':name joined the game', ['name' => $user->team_name ?: $user->name], 'team.show', ['user' => $user->id], $user->id);
        }
        if (!$wasBanned && $user->hasRole(RoleName::Banned->value)) {
            \App\Support\SystemFeed::record('moderation', ':name was banned', ['name' => $user->name], null, [], $user->id);
        }

        // Rola decyduje o udziale w zabawie: gracz (bez bana) trafia na listy sezonów,
        // a zbanowany albo bez roli traci miejsce na rzecz bota (patrz App\Support\Roster).
        $roster = Roster::syncUser($user);

        Flux::modal('user-roles')->close();
        Flux::toast(text: __('Roles updated.'), variant: 'success');

        if ($roster['added'] > 0) {
            Flux::toast(text: __('The player was added to the season list.'), variant: 'success');
        }

        if ($roster['released'] > 0) {
            Flux::toast(text: __('The player was removed from the season list.'), variant: 'warning');
        }
        $this->resetForm();
    }

    /* ------------------------------------------------------------------
     | Potwierdzenie e-maila kodem: admin widzi kod, może wygenerować nowy albo potwierdzić ręcznie.
     * ----------------------------------------------------------------*/

    /** Nowy kod (bez wysyłki maila), np. gdy gracz dzwoni, że mail nie dotarł. */
    public function newCode(int $id): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($id);

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $code = $user->newVerificationCode();
        Flux::toast(text: __('New code for :name: :code', ['name' => $user->name, 'code' => $code]), variant: 'success');
    }

    /** Potwierdza adres bez kodu. */
    public function confirmEmail(int $id): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($id);

        if (!$user->hasVerifiedEmail()) {
            $user->confirmEmail();
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        unset($this->stats);
        Flux::toast(text: __('Email of :name confirmed.', ['name' => $user->name]), variant: 'success');
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
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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
        <flux:card class="flex items-center gap-4">
            <flux:icon.envelope class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('Email not confirmed') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['unverified'] }}</flux:heading>
            </div>
        </flux:card>
    </div>

    {{-- Wyszukiwarka i filtr --}}
    <div class="flex flex-wrap items-center gap-4">
        <div class="w-full sm:w-72">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                :placeholder="__('Search by name, email or team...')" clearable />
        </div>

        <div class="w-full sm:w-56">
            <flux:select wire:model.live="roleFilter">
                <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
                <flux:select.option value="__none">{{ __('Without a role') }}</flux:select.option>
                <flux:select.option value="__unverified">{{ __('Email not confirmed') }}</flux:select.option>
                @foreach ($this->roles as $role)
                    <flux:select.option :value="$role->name">{{ $role->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Akcje zbiorcze: zdjęcie bana i statusu Inactive z zaznaczonych kont --}}
    @php
        $checkedCount = count($checked);
    @endphp
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
        <flux:text class="me-2">{{ __('Selected: :count', ['count' => $checkedCount]) }}</flux:text>
        <flux:button size="sm" variant="ghost" icon="check" wire:click="checkAllFiltered">
            {{ __('Select all matching (:count)', ['count' => $this->filteredCount]) }}
        </flux:button>
        @if ($checkedCount > 0)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearChecked">{{ __('Clear selection') }}</flux:button>
            <flux:button size="sm" icon="lock-open" wire:click="releaseRole('{{ \App\Enums\RoleName::Banned->value }}')"
                wire:confirm="{{ __('Remove the ban from the selected accounts?') }}">{{ __('Remove ban') }}</flux:button>
            <flux:button size="sm" icon="arrow-uturn-left" wire:click="releaseRole('{{ \App\Enums\RoleName::Inactive->value }}')"
                wire:confirm="{{ __('Remove Inactive from the selected accounts?') }}">{{ __('Remove Inactive') }}</flux:button>
        @endif
    </div>

    {{-- Tabela użytkowników --}}
    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column class="w-8" />
            <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection"
                wire:click="sort('name')">
                {{ __('User') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection"
                wire:click="sort('email')">
                {{ __('Email') }}
            </flux:table.column>
            <flux:table.column>{{ __('Email confirmation') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'roles_count'" :direction="$sortDirection"
                wire:click="sort('roles_count')">
                {{ __('Roles') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection"
                wire:click="sort('created_at')">
                {{ __('Joined') }}
            </flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row :key="'user-'.$user->id">
                    <flux:table.cell>
                        <input type="checkbox" wire:model.live="checked" value="{{ $user->id }}" aria-label="{{ __('Select') }}"
                            class="size-4 rounded border-zinc-300 text-lech-700 dark:border-zinc-600">
                    </flux:table.cell>
                    <flux:table.cell variant="strong">
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$user->name" />
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span>{{ $user->name }}</span>
                                    @if ($user->is(auth()->user()))
                                        <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                                    @endif
                                </div>
                                {{-- Nazwa drużyny z linkiem do profilu --}}
                                @if ($user->team_name)
                                    <a href="{{ route('team.show', $user) }}" target="_blank" class="block truncate text-xs font-normal text-zinc-500 hover:text-lech-700 hover:underline">{{ $user->team_name }}</a>
                                @else
                                    <span class="block text-xs font-normal text-zinc-400">{{ __('No team name') }}</span>
                                @endif
                            </div>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($user->email_verified_at)
                            <flux:badge size="sm" color="green" icon="check">{{ __('Confirmed') }}</flux:badge>
                        @else
                            @php
                                $codeValid = $user->verification_code && $user->verification_code_expires_at?->isFuture();
                            @endphp
                            <div class="flex items-center gap-1">
                                @if ($codeValid)
                                    <span class="rounded bg-amber-100 px-2 py-0.5 font-mono text-sm font-semibold tracking-widest text-amber-800 dark:bg-amber-500/20 dark:text-amber-200"
                                        title="{{ __('Valid until :time', ['time' => $user->verification_code_expires_at->format('d.m H:i')]) }}">{{ $user->verification_code }}</span>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ $user->verification_code ? __('Code expired') : __('No code') }}</flux:badge>
                                @endif
                                <flux:button variant="ghost" size="xs" icon="arrow-path" wire:click="newCode({{ $user->id }})"
                                    :aria-label="__('New code')" :tooltip="__('New code')" />
                                <flux:button variant="ghost" size="xs" icon="check-badge" wire:click="confirmEmail({{ $user->id }})"
                                    wire:confirm="{{ __('Confirm this email without a code?') }}" :aria-label="__('Confirm without a code')"
                                    :tooltip="__('Confirm without a code')" />
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($user->roles as $role)
                                <flux:badge size="sm"
                                    :color="\App\Enums\RoleName::tryFrom($role->name)?->color() ?? 'zinc'">
                                    {{ $role->name }}</flux:badge>
                            @empty
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $user->created_at?->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button variant="ghost" size="sm" icon="pencil-square" inset="top bottom"
                            wire:click="edit({{ $user->id }})" :aria-label="__('Edit roles')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="py-10 text-center text-zinc-500">
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
                    <flux:checkbox.group wire:model="selected"
                        class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        @foreach ($this->roles as $role)
                            <flux:checkbox wire:key="role-{{ $role->id }}" :value="$role->name"
                                :label="$role->name" />
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
