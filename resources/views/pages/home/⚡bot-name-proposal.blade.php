<?php

use App\Models\BotNameProposal;
use App\Support\BotNames;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Propozycja nazwy bota (stopka strony i strona główna). Trafia do panelu admina (dashboard/bot-name-proposals),
 * który nadaje nazwę wybranemu botowi. Tylko zalogowani, najwyżej kilka propozycji w kolejce naraz.
 */
new class extends Component {
    public string $text = '';

    /** Wariant wyglądu: 'footer' (tekst + przycisk) albo 'card' (karta na stronie głównej). */
    public string $variant = 'footer';

    /** Każda kopia komponentu (stopka, strona główna) ma własne okno. */
    public function modalName(): string
    {
        return 'proposal-' . $this->getId();
    }

    public function open(): void
    {
        $this->reset('text');
        $this->resetValidation();
        Flux::modal($this->modalName())->show();
    }

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $this->text = Str::squish($this->text);

        $this->validate([
            'text' => [...BotNames::rules(), Rule::unique('bot_name_proposals', 'text')],
        ], [], ['text' => __('Bot name')]);

        if (BotNameProposal::pending()->where('user_id', $user->id)->count() >= BotNameProposal::MAX_PENDING) {
            $this->addError('text', __('You already have :count proposals waiting for the administrator.', ['count' => BotNameProposal::MAX_PENDING]));

            return;
        }

        BotNameProposal::create([
            'user_id' => $user->id,
            'text' => $this->text,
        ]);

        $this->reset('text');
        Flux::modal($this->modalName())->close();
        Flux::toast(variant: 'success', text: __('Thank you! The administrator will review your bot name.'));
    }
}; ?>

<div>
    @if ($variant === 'card')
        {{-- Strona główna: karta z przyciskiem --}}
        <flux:card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Name a bot') }}</flux:heading>
                <flux:text>{{ __('Suggest a name for one of the bots.') }}</flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                @auth
                    <flux:button size="sm" icon="cpu-chip" wire:click="open">{{ __('Propose a bot name') }}</flux:button>
                @else
                    <flux:link :href="route('login')">{{ __('Log in to send a proposal') }}</flux:link>
                @endauth
            </div>
        </flux:card>
    @else
        <div class="space-y-2">
            <flux:text>{{ __('Have an idea for a bot name? Send it to us.') }}</flux:text>

            @auth
                <flux:button size="sm" icon="cpu-chip" wire:click="open">{{ __('Propose a bot name') }}</flux:button>
            @else
                <flux:link :href="route('login')">{{ __('Log in to send a proposal') }}</flux:link>
            @endauth
        </div>
    @endif

    @auth
        <flux:modal :name="$this->modalName()" class="w-full max-w-lg">
            <form wire:submit="send" class="space-y-5">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Propose a bot name') }}</flux:heading>
                    <flux:text>{{ __('The administrator reviews every proposal and gives an accepted name to one of the bots.') }}</flux:text>
                </div>

                <flux:input wire:model="text" :label="__('Bot name')" maxlength="40" :placeholder="__('e.g. Kolejorz z Wildy')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endauth
</div>
