<?php

use App\Enums\QuestionSide;
use App\Models\QuestionProposal;
use App\Support\BotNames;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Propozycja gracza: pytanie bonusowe albo nazwa bota (stopka strony i strona główna). Trafia do panelu admina
 * (dashboard/question-proposals): pytanie po akceptacji dochodzi do banku, nazwę admin nadaje wybranemu botowi.
 * Tylko zalogowani, najwyżej kilka propozycji w kolejce naraz.
 */
new class extends Component {
    public string $kind = QuestionProposal::QUESTION;
    public string $text = '';
    public string $side = 'offensive';

    /** Wariant wyglądu: 'footer' (tekst + przycisk) albo 'card' (karta na stronie głównej). */
    public string $variant = 'footer';

    /** Każda kopia komponentu (stopka, strona główna) ma własne okno. */
    public function modalName(): string
    {
        return 'proposal-' . $this->getId();
    }

    public function open(string $kind = QuestionProposal::QUESTION): void
    {
        $this->reset('text', 'side');
        $this->kind = in_array($kind, [QuestionProposal::QUESTION, QuestionProposal::BOT_NAME], true) ? $kind : QuestionProposal::QUESTION;
        $this->resetValidation();
        Flux::modal($this->modalName())->show();
    }

    public function updatedKind(): void
    {
        $this->resetValidation();
    }

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $isBot = $this->kind === QuestionProposal::BOT_NAME;
        $this->text = $isBot ? Str::squish($this->text) : trim($this->text);

        $this->validate($isBot
            ? [
                'kind' => ['required', Rule::in([QuestionProposal::QUESTION, QuestionProposal::BOT_NAME])],
                'text' => [...BotNames::rules(), Rule::unique('question_proposals', 'text')->where('kind', QuestionProposal::BOT_NAME)],
            ]
            : [
                'kind' => ['required', Rule::in([QuestionProposal::QUESTION, QuestionProposal::BOT_NAME])],
                'text' => ['required', 'string', 'min:10', 'max:255', Rule::unique('questions', 'text'), Rule::unique('question_proposals', 'text')->where('kind', QuestionProposal::QUESTION)],
                'side' => ['required', Rule::in(array_column(QuestionSide::cases(), 'value'))],
            ], [], ['text' => $isBot ? __('Bot name') : __('Question'), 'side' => __('Type')]);

        if (QuestionProposal::pending()->where('user_id', $user->id)->count() >= QuestionProposal::MAX_PENDING) {
            $this->addError('text', __('You already have :count proposals waiting for the administrator.', ['count' => QuestionProposal::MAX_PENDING]));

            return;
        }

        QuestionProposal::create([
            'user_id' => $user->id,
            'kind' => $this->kind,
            'text' => $this->text,
            'side' => $isBot ? null : $this->side,
        ]);

        $this->reset('text', 'side');
        Flux::modal($this->modalName())->close();
        Flux::toast(variant: 'success', text: $isBot
            ? __('Thank you! The administrator will review your bot name.')
            : __('Thank you! The administrator will review your question.'));
    }
}; ?>

<div>
    @if ($variant === 'card')
        {{-- Strona główna: karta z dwoma przyciskami --}}
        <flux:card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Propose something') }}</flux:heading>
                <flux:text>{{ __('Suggest a bonus question for the Lech match or a name for one of the bots.') }}</flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                @auth
                    <flux:button size="sm" icon="light-bulb" wire:click="open('{{ \App\Models\QuestionProposal::QUESTION }}')">{{ __('Propose a question') }}</flux:button>
                    <flux:button size="sm" icon="cpu-chip" wire:click="open('{{ \App\Models\QuestionProposal::BOT_NAME }}')">{{ __('Propose a bot name') }}</flux:button>
                @else
                    <flux:link :href="route('login')">{{ __('Log in to send a proposal') }}</flux:link>
                @endauth
            </div>
        </flux:card>
    @else
        <div class="space-y-2">
            <flux:text>{{ __('Have an idea for a bonus question or a bot name? Send it to us.') }}</flux:text>

            @auth
                <flux:button size="sm" icon="light-bulb" wire:click="open">{{ __('Send a proposal') }}</flux:button>
            @else
                <flux:link :href="route('login')">{{ __('Log in to send a proposal') }}</flux:link>
            @endauth
        </div>
    @endif

    @auth
        <flux:modal :name="$this->modalName()" class="w-full max-w-lg">
            <form wire:submit="send" class="space-y-5">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Send a proposal') }}</flux:heading>
                    <flux:text>{{ __('The administrator reviews every proposal. An accepted question joins the question bank, an accepted name goes to one of the bots.') }}</flux:text>
                </div>

                <flux:radio.group wire:model.live="kind" :label="__('What do you propose?')" variant="segmented">
                    <flux:radio :value="\App\Models\QuestionProposal::QUESTION" :label="__('Bonus question')" />
                    <flux:radio :value="\App\Models\QuestionProposal::BOT_NAME" :label="__('Bot name')" />
                </flux:radio.group>

                @if ($kind === \App\Models\QuestionProposal::BOT_NAME)
                    <flux:input wire:model="text" :label="__('Bot name')" maxlength="40" :placeholder="__('e.g. Kolejorz z Wildy')" />
                @else
                    <flux:textarea wire:model="text" :label="__('Question')" rows="3" :placeholder="__('e.g. Will Lech score in the first 15 minutes?')" />

                    <flux:radio.group wire:model="side" :label="__('Type')" variant="segmented">
                        @foreach (\App\Enums\QuestionSide::cases() as $option)
                            <flux:radio :value="$option->value" :label="$option->label()" />
                        @endforeach
                    </flux:radio.group>
                @endif

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
