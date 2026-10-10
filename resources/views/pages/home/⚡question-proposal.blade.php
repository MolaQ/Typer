<?php

use App\Enums\QuestionSide;
use App\Models\QuestionProposal;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Propozycja pytania bonusowego (kontakt w stopce strony). Trafia do panelu admina (dashboard/question-proposals);
 * po akceptacji pytanie dochodzi do banku pytań. Tylko zalogowani, najwyżej kilka propozycji w kolejce naraz.
 */
new class extends Component {
    public string $text = '';
    public string $side = 'offensive';

    public function open(): void
    {
        $this->reset('text', 'side');
        $this->resetValidation();
        Flux::modal('question-proposal')->show();
    }

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $this->text = trim($this->text);
        $this->validate([
            'text' => ['required', 'string', 'min:10', 'max:255', Rule::unique('questions', 'text'), Rule::unique('question_proposals', 'text')],
            'side' => ['required', Rule::in(array_column(QuestionSide::cases(), 'value'))],
        ], [], ['text' => __('Question'), 'side' => __('Type')]);

        if (QuestionProposal::pending()->where('user_id', $user->id)->count() >= QuestionProposal::MAX_PENDING) {
            $this->addError('text', __('You already have :count proposals waiting for the administrator.', ['count' => QuestionProposal::MAX_PENDING]));

            return;
        }

        QuestionProposal::create(['user_id' => $user->id, 'text' => $this->text, 'side' => $this->side]);

        $this->reset('text', 'side');
        Flux::modal('question-proposal')->close();
        Flux::toast(variant: 'success', text: __('Thank you! The administrator will review your question.'));
    }
}; ?>

<div class="space-y-2">
    <flux:text>{{ __('Have an idea for a bonus question? Send it to us.') }}</flux:text>

    @auth
        <flux:button size="sm" icon="light-bulb" wire:click="open">{{ __('Propose a question') }}</flux:button>
    @else
        <flux:link :href="route('login')">{{ __('Log in to propose a question') }}</flux:link>
    @endauth

    @auth
        <flux:modal name="question-proposal" class="w-full max-w-lg">
            <form wire:submit="send" class="space-y-5">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Propose a bonus question') }}</flux:heading>
                    <flux:text>{{ __('A yes/no question about the Lech match. If the administrator accepts it, it joins the question bank.') }}</flux:text>
                </div>

                <flux:textarea wire:model="text" :label="__('Question')" rows="3" :placeholder="__('e.g. Will Lech score in the first 15 minutes?')" />

                <flux:radio.group wire:model="side" :label="__('Type')" variant="segmented">
                    @foreach (\App\Enums\QuestionSide::cases() as $option)
                        <flux:radio :value="$option->value" :label="$option->label()" />
                    @endforeach
                </flux:radio.group>

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
