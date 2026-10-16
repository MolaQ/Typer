{{--
    Wspólne okno potwierdzenia (16.10.2026, zamiast wire:confirm), w layoutach panelu i publicznym.
    Użycie na przycisku: x-on:click="$dispatch('confirm-action', { message: @js(__('...')), run: () => $wire.akcja(1) })",
    opcjonalnie confirm (napis przycisku) i danger (czerwony przycisk).
--}}
<div x-data="{ message: '', confirmLabel: '', danger: false, run: null }"
    x-on:confirm-action.window="message = $event.detail.message; confirmLabel = $event.detail.confirm ?? @js(__('Confirm')); danger = $event.detail.danger ?? false; run = $event.detail.run; $flux.modal('confirm-action').show()">
    <flux:modal name="confirm-action" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Are you sure?') }}</flux:heading>
            <flux:text x-text="message"></flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button x-show="! danger" variant="primary" x-on:click="run && run(); $flux.modal('confirm-action').close()"><span x-text="confirmLabel"></span></flux:button>
                <flux:button x-show="danger" x-cloak variant="danger" x-on:click="run && run(); $flux.modal('confirm-action').close()"><span x-text="confirmLabel"></span></flux:button>
            </div>
        </div>
    </flux:modal>
</div>
