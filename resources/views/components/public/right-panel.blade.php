<div class="space-y-4">
    {{-- Rywale gracza w kolejce: nad informacją o premium (tylko zalogowani gracze z zespołem). --}}
    @auth
        <livewire:pages::home.rivals-panel />
    @endauth
    <flux:heading>{{ __('News') }}</flux:heading>
    <flux:text>{{ __('Latest announcements will appear here.') }}</flux:text>
    <flux:separator />
    <x-public.quote />
    <flux:separator />
    <x-public.premium-card />
</div>
