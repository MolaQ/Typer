<?php

use App\Support\Maintenance;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Tryb konserwacji (App\Support\Maintenance): włączenie zamyka stronę dla wszystkich poza adminami
 * (przerwa techniczna z komunikatem i planowanym końcem), admini sprawdzają zmiany i otwierają stronę ponownie.
 */
new class extends Component {
    public string $message = '';

    public string $until = '';

    public function mount(): void
    {
        $state = Maintenance::state();
        $this->message = $state['message'];
        $this->until = $state['until'] ? substr(str_replace(' ', 'T', $state['until']), 0, 16) : '';
    }

    public function render(): View
    {
        return $this->view()->title(__('Maintenance mode'));
    }

    public function enable(): void
    {
        $this->authorizeAdmin();
        $this->validateForm();

        Maintenance::enable($this->message, $this->untilValue(), auth()->user());
        Flux::toast(variant: 'warning', text: __('Maintenance mode is on. Only admins can see the site.'));
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validateForm();

        Maintenance::update($this->message, $this->untilValue());
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function disable(): void
    {
        $this->authorizeAdmin();

        Maintenance::disable();
        Flux::toast(variant: 'success', text: __('The site is open again.'));
    }

    private function validateForm(): void
    {
        $this->validate([
            'message' => ['nullable', 'string', 'max:500'],
            'until' => ['nullable', 'date'],
        ]);
    }

    private function untilValue(): ?string
    {
        return $this->until !== '' ? \Illuminate\Support\Carbon::parse($this->until)->toDateTimeString() : null;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Maintenance::canBypass(auth()->user()), 403);
    }
}; ?>

@php
    $state = \App\Support\Maintenance::state();
    $until = \App\Support\Maintenance::until();
@endphp

<div class="max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Maintenance mode') }}</flux:heading>
        <flux:text>{{ __('Close the site for players while you update it or test new features. Admins still see the whole site and the panel.') }}</flux:text>
    </div>

    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="{{ $state['enabled'] ? 'bg-amber-500' : 'bg-emerald-500' }} inline-block size-3 rounded-full"></span>
                <div>
                    <div class="font-semibold">{{ $state['enabled'] ? __('The site is closed (technical break)') : __('The site is open') }}</div>
                    @if ($state['enabled'] && $state['since'])
                        <div class="text-xs text-zinc-500">{{ __('Since :date, by :name', ['date' => \Illuminate\Support\Carbon::parse($state['since'])->format('d.m.Y H:i'), 'name' => $state['by'] ?? '—']) }}</div>
                    @endif
                </div>
            </div>
            @if ($state['enabled'])
                <flux:button variant="primary" icon="lock-open" wire:click="disable">{{ __('Open the site') }}</flux:button>
            @else
                <flux:button variant="danger" icon="wrench-screwdriver" wire:click="enable">{{ __('Close the site') }}</flux:button>
            @endif
        </div>

        <flux:textarea wire:model="message" :label="__('Message for players (optional)')" rows="3" maxlength="500"
            :placeholder="__('e.g. We are adding player designation. Back after the update.')" />
        <flux:input type="datetime-local" wire:model="until" :label="__('Planned end (optional)')" />
        @if ($state['enabled'])
            <div class="flex justify-end">
                <flux:button size="sm" wire:click="save">{{ __('Save message') }}</flux:button>
            </div>
        @endif
    </flux:card>

    <flux:card class="space-y-2">
        <flux:heading>{{ __('How to update the site safely') }}</flux:heading>
        <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-300">
            <li>{{ __('Close the site here, so nobody saves tips while the update runs.') }}</li>
            <li>{{ __('Upload the code and run the migrations (php artisan migrate --force), then php artisan optimize.') }}</li>
            <li>{{ __('Check the site as admin, then open it again.') }}</li>
        </ol>
        <flux:text class="text-xs">{{ __('If the update can break the panel itself, use php artisan down --secret=word instead: the site is closed for everybody and you enter it with the address /word.') }}</flux:text>
    </flux:card>
</div>
