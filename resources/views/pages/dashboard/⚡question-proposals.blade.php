<?php

use App\Enums\Permission;
use App\Enums\QuestionSide;
use App\Models\Question;
use App\Models\QuestionProposal;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Propozycje pytań bonusowych od graczy (formularz w stopce strony publicznej). Akceptacja dodaje pytanie
 * do banku (z możliwością poprawienia treści i typu), odrzucenie zapisuje powód.
 */
new class extends Component {
    use WithPagination;

    #[Url(except: 'pending')]
    public string $status = 'pending';

    // Akceptacja z poprawkami
    public ?int $acceptId = null;
    public string $acceptText = '';
    public string $acceptSide = 'offensive';

    // Odrzucenie
    public ?int $rejectId = null;
    public string $rejectReason = '';

    public function render(): View
    {
        return $this->view()->title(__('Question proposals'));
    }

    #[Computed]
    public function stats(): array
    {
        $counts = QuestionProposal::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            QuestionProposal::PENDING => (int) ($counts[QuestionProposal::PENDING] ?? 0),
            QuestionProposal::ACCEPTED => (int) ($counts[QuestionProposal::ACCEPTED] ?? 0),
            QuestionProposal::REJECTED => (int) ($counts[QuestionProposal::REJECTED] ?? 0),
        ];
    }

    #[Computed]
    public function proposals()
    {
        return QuestionProposal::with(['user:id,name,email', 'reviewer:id,name'])
            ->when(array_key_exists($this->status, $this->stats), fn($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(15, pageName: 'proposalsPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('proposalsPage');
    }

    public function openAccept(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $proposal = QuestionProposal::pending()->findOrFail($id);
        $this->acceptId = $proposal->id;
        $this->acceptText = $proposal->text;
        $this->acceptSide = $proposal->side->value;
        $this->resetValidation();

        Flux::modal('accept-proposal')->show();
    }

    public function accept(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->acceptText = trim($this->acceptText);
        $this->validate([
            'acceptText' => ['required', 'string', 'min:5', 'max:255', 'unique:questions,text'],
            'acceptSide' => ['required', 'in:' . implode(',', array_column(QuestionSide::cases(), 'value'))],
        ], [], ['acceptText' => __('Question'), 'acceptSide' => __('Type')]);

        $done = DB::transaction(function (): bool {
            $proposal = QuestionProposal::query()->lockForUpdate()->find($this->acceptId);

            if (!$proposal || $proposal->status !== QuestionProposal::PENDING) {
                return false;
            }

            $question = Question::create(['text' => $this->acceptText, 'side' => $this->acceptSide, 'is_active' => true]);

            $proposal->update([
                'status' => QuestionProposal::ACCEPTED,
                'question_id' => $question->id,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            Audit::log('question.proposal_accepted', $proposal->user, ['text' => $proposal->text], ['text' => $question->text, 'side' => $question->side->label()], $question->text);

            return true;
        });

        Flux::modal('accept-proposal')->close();
        $this->reset('acceptId', 'acceptText', 'acceptSide');
        unset($this->stats, $this->proposals);

        $done
            ? Flux::toast(variant: 'success', text: __('Question added to the bank.'))
            : Flux::toast(variant: 'warning', text: __('This proposal has already been handled.'));
    }

    public function openReject(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->rejectId = QuestionProposal::pending()->findOrFail($id)->id;
        $this->rejectReason = '';
        Flux::modal('reject-proposal')->show();
    }

    public function reject(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        $this->validate(['rejectReason' => ['nullable', 'string', 'max:255']]);

        $proposal = QuestionProposal::pending()->find($this->rejectId);

        if ($proposal) {
            $proposal->update([
                'status' => QuestionProposal::REJECTED,
                'reject_reason' => trim($this->rejectReason) ?: null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
            Audit::log('question.proposal_rejected', $proposal->user, [], ['text' => $proposal->text, 'reason' => $proposal->reject_reason], $proposal->text);
        }

        Flux::modal('reject-proposal')->close();
        $this->reset('rejectId', 'rejectReason');
        unset($this->stats, $this->proposals);

        Flux::toast(text: __('Proposal rejected.'));
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Question proposals') }}</flux:heading>
        <flux:text>{{ __('Bonus questions proposed by players. An accepted question joins the question bank.') }}</flux:text>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach ([\App\Models\QuestionProposal::PENDING => __('Pending'), \App\Models\QuestionProposal::ACCEPTED => __('Accepted'), \App\Models\QuestionProposal::REJECTED => __('Rejected'), 'all' => __('All')] as $key => $label)
            <flux:button size="sm" :variant="$status === $key ? 'primary' : 'outline'" wire:click="$set('status', '{{ $key }}')">
                {{ $label }}
                @if (isset($this->stats[$key]))
                    ({{ $this->stats[$key] }})
                @endif
            </flux:button>
        @endforeach
    </div>

    <flux:card class="overflow-x-auto p-0">
        <table class="w-full text-sm">
            <thead class="text-xs text-zinc-500">
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 text-left">{{ __('Question') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Player') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Sent') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->proposals as $proposal)
                    <tr wire:key="qp-{{ $proposal->id }}" class="border-b border-zinc-100 last:border-0 dark:border-zinc-700/50">
                        <td class="max-w-md px-3 py-2">
                            <div class="font-medium">{{ $proposal->text }}</div>
                            @if ($proposal->reject_reason)
                                <div class="text-xs text-red-600">{{ $proposal->reject_reason }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="$proposal->side->color()">{{ $proposal->side->label() }}</flux:badge></td>
                        <td class="px-3 py-2">
                            <div>{{ $proposal->user?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $proposal->user?->email }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs text-zinc-500">{{ $proposal->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($proposal->status === \App\Models\QuestionProposal::PENDING)
                                <div class="flex justify-end gap-1">
                                    <flux:button size="xs" variant="primary" icon="check" wire:click="openAccept({{ $proposal->id }})">{{ __('Accept') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="openReject({{ $proposal->id }})">{{ __('Reject') }}</flux:button>
                                </div>
                            @else
                                <flux:badge size="sm" :color="$proposal->status === \App\Models\QuestionProposal::ACCEPTED ? 'green' : 'red'">
                                    {{ $proposal->status === \App\Models\QuestionProposal::ACCEPTED ? __('Accepted') : __('Rejected') }}
                                </flux:badge>
                                <div class="text-xs text-zinc-500">{{ $proposal->reviewer?->name }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-center text-zinc-500">{{ __('No proposals.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    {{ $this->proposals->links() }}

    <flux:modal name="accept-proposal" class="w-full max-w-lg">
        <form wire:submit="accept" class="space-y-5">
            <flux:heading size="lg">{{ __('Accept the question') }}</flux:heading>
            <flux:text>{{ __('You can correct the wording and type before adding it to the bank.') }}</flux:text>
            <flux:textarea wire:model="acceptText" :label="__('Question')" rows="3" />
            <flux:radio.group wire:model="acceptSide" :label="__('Type')" variant="segmented">
                @foreach (\App\Enums\QuestionSide::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" />
                @endforeach
            </flux:radio.group>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Add to the bank') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reject-proposal" class="w-full max-w-md">
        <form wire:submit="reject" class="space-y-5">
            <flux:heading size="lg">{{ __('Reject the proposal') }}</flux:heading>
            <flux:input wire:model="rejectReason" :label="__('Reason (optional)')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
