@props(['title' => null])

@if (auth()->user()?->hasRole('Admin') || auth()->user()?->can(\App\Enums\Permission::DashboardAccess->value))
    <x-layouts::admin :title="$title">
        {{ $slot }}
    </x-layouts::admin>
@else
    <x-layouts::public :title="$title">
        {{ $slot }}
    </x-layouts::public>
@endif