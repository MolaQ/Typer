<?php

use App\Enums\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Permission as PermissionModel;

// Jeśli nie masz jeszcze layoutu publicznego, usuń atrybut #[Layout]
// (komponent użyje wtedy domyślnego layoutu aplikacji).
new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(config('app.name'));
    }

    /**
     * Raport porównujący enum Permission z tym, co użytkownik faktycznie posiada.
     * Zwraca null dla gościa.
     */
    #[Computed]
    public function report(): ?array
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $user->loadMissing('roles.permissions', 'permissions');

        $inDatabase = PermissionModel::pluck('name')->all();
        $direct = $user->permissions->pluck('name')->all();

        // nazwa uprawnienia => lista ról, które je dają
        $viaRoles = [];
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $viaRoles[$permission->name][] = $role->name;
            }
        }

        $rows = collect(Permission::cases())->map(function (Permission $case) use ($inDatabase, $direct, $viaRoles) {
            $name = $case->value;

            return [
                'case' => $case->name,
                'value' => $name,
                'group' => Str::headline(Str::before($name, '-')),
                'inDatabase' => in_array($name, $inDatabase, true),
                'granted' => isset($viaRoles[$name]) || in_array($name, $direct, true),
                'viaRoles' => $viaRoles[$name] ?? [],
                'direct' => in_array($name, $direct, true),
            ];
        });

        $allGranted = array_unique([...array_keys($viaRoles), ...$direct]);

        return [
            'user' => $user,
            'roles' => $user->roles->pluck('name')->all(),
            'rows' => $rows,
            'granted' => $rows->where('granted', true)->count(),
            'missing' => $rows->where('granted', false),
            'notInDatabase' => $rows->where('inDatabase', false),
            // uprawnienia użytkownika, których nie ma w enumie (rozjazd kod/baza)
            'outsideEnum' => array_values(array_diff($allGranted, Permission::values())),
        ];
    }
};
?>

<div class="mx-auto w-full max-w-4xl space-y-6 py-10">

    <div>
        <flux:heading size="xl" level="1">{{ __('Access check') }}</flux:heading>
        <flux:subheading>{{ __('A quick view of what the signed-in user can do.') }}</flux:subheading>
    </div>

    @if (! $this->report)
        <flux:card class="space-y-4">
            <flux:heading>{{ __('You are not signed in') }}</flux:heading>
            <flux:text>{{ __('Sign in to see your roles and permissions.') }}</flux:text>
            <div>
                <flux:button variant="primary" :href="route('login')">{{ __('Log in') }}</flux:button>
            </div>
        </flux:card>
    @else
        @php($report = $this->report)

        {{-- Użytkownik i role --}}
        <flux:card class="space-y-4">
            <div class="flex items-center gap-4">
                <flux:avatar :name="$report['user']->name" />
                <div>
                    <flux:heading>{{ $report['user']->name }}</flux:heading>
                    <flux:text>{{ $report['user']->email }}</flux:text>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:text>{{ __('Roles') }}:</flux:text>
                @forelse ($report['roles'] as $role)
                    <flux:badge color="blue">{{ $role }}</flux:badge>
                @empty
                    <flux:badge color="zinc">{{ __('None') }}</flux:badge>
                @endforelse
            </div>
        </flux:card>

        {{-- Podsumowanie --}}
        <div class="grid gap-4 md:grid-cols-3">
            <flux:card class="flex items-center gap-4">
                <flux:icon.check-circle class="size-8 text-green-500" />
                <div>
                    <flux:text>{{ __('Granted') }}</flux:text>
                    <flux:heading size="xl">{{ $report['granted'] }} / {{ $report['rows']->count() }}</flux:heading>
                </div>
            </flux:card>
            <flux:card class="flex items-center gap-4">
                <flux:icon.x-circle class="size-8 text-zinc-400" />
                <div>
                    <flux:text>{{ __('Missing') }}</flux:text>
                    <flux:heading size="xl">{{ $report['missing']->count() }}</flux:heading>
                </div>
            </flux:card>
            <flux:card class="flex items-center gap-4">
                <flux:icon.exclamation-triangle class="size-8 text-amber-500" />
                <div>
                    <flux:text>{{ __('Not in database') }}</flux:text>
                    <flux:heading size="xl">{{ $report['notInDatabase']->count() }}</flux:heading>
                </div>
            </flux:card>
        </div>

        @if ($report['notInDatabase']->isNotEmpty())
            <flux:card class="space-y-2 border-amber-300 dark:border-amber-500/50">
                <flux:heading>{{ __('Some permissions from the code are missing in the database') }}</flux:heading>
                <flux:text>{{ __('Run the seeder to create them:') }} <code>php artisan db:seed --class=RolesAndPermissionsSeeder</code></flux:text>
            </flux:card>
        @endif

        {{-- Tabela wszystkich uprawnień z enuma --}}
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Group') }}</flux:table.column>
                <flux:table.column>{{ __('Permission') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($report['rows'] as $row)
                    <flux:table.row :key="$row['value']">
                        <flux:table.cell class="text-zinc-500">{{ $row['group'] }}</flux:table.cell>
                        <flux:table.cell>
                            <code class="text-sm">{{ $row['value'] }}</code>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($row['granted'])
                                <flux:badge size="sm" color="green" icon="check">{{ __('Granted') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" icon="x-mark">{{ __('Missing') }}</flux:badge>
                            @endif

                            @unless ($row['inDatabase'])
                                <flux:badge size="sm" color="amber">{{ __('Not in database') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            @if ($row['viaRoles'])
                                {{ __('Role') }}: {{ implode(', ', $row['viaRoles']) }}
                            @endif
                            @if ($row['direct'])
                                {{ __('Direct') }}
                            @endif
                            @if (! $row['granted'])
                                &ndash;
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($report['outsideEnum'])
            <flux:card class="space-y-2">
                <flux:heading>{{ __('Permissions outside the Permission enum') }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($report['outsideEnum'] as $name)
                        <flux:badge size="sm" color="amber">{{ $name }}</flux:badge>
                    @endforeach
                </div>
            </flux:card>
        @endif
    @endif
</div>
