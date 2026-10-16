{{-- Potwierdzenie adresu e-mail kodem (komponent pages::auth.verify-code). --}}
<x-layouts::auth :title="__('Email verification')">
    <div class="mt-4 flex flex-col gap-6">
        <livewire:pages::auth.verify-code />

        <form method="POST" action="{{ route('logout') }}" class="flex justify-center">
            @csrf
            <flux:button variant="ghost" type="submit" class="cursor-pointer text-sm" data-test="logout-button">
                {{ __('Log out') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
