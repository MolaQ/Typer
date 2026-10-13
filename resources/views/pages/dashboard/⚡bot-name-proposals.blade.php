<?php

use App\Enums\Permission;
use App\Models\Bot;
use App\Models\BotNameProposal;
use App\Support\Audit;
use App\Support\BotNames;
use Illuminate\Support\Str;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Propozycje nazw botów od graczy (formularz w stopce i na stronie głównej). Akceptacja nadaje nazwę
 * wybranemu botowi (można ją poprawić), odrzucenie zapisuje powód.
 */
new class extends Component {
    use WithPagination;

    #[Url(except: 'pending')]
    public string $status = 'pending';

    // Akceptacja nazwy bota: admin wybiera bota, który ją dostaje
    public ?int $nameId = null;
    public string $nameText = '';
    public string $botSearch = '';
    public ?int $nameBotId = null;

    // Odrzucenie
    public ?int $rejectId = null;
    public string $rejectReason = '';

    public function render(): View
    {
        return $this->view()->title(__('Bot name proposals'));
    }

    #[Computed]
    public function stats(): array
    {
        $counts = BotNameProposal::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            BotNameProposal::PENDING => (int) ($counts[BotNameProposal::PENDING] ?? 0),
            BotNameProposal::ACCEPTED => (int) ($counts[BotNameProposal::ACCEPTED] ?? 0),
            BotNameProposal::REJECTED => (int) ($counts[BotNameProposal::REJECTED] ?? 0),
        ];
    }

    #[Computed]
    public function proposals()
    {
        return BotNameProposal::with(['user:id,name,email', 'reviewer:id,name', 'bot:id,name'])
            ->when(array_key_exists($this->status, $this->stats), fn($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(15, pageName: 'proposalsPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('proposalsPage');
    }

    /** Boty do wyboru przy nadawaniu nazwy: najpierw z domyślną nazwą (jeszcze niezmienione), z wyszukiwaniem. */
    #[Computed]
    public function botOptions()
    {
        return Bot::query()
            ->when(trim($this->botSearch) !== '', fn ($q) => $q->where('name', 'like', '%' . trim($this->botSearch) . '%'))
            ->orderBy('sort_order')
            ->limit(50)
            ->get(['id', 'sort_order', 'name']);
    }

    public function openName(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $proposal = BotNameProposal::pending()->findOrFail($id);
        $this->nameId = $proposal->id;
        $this->nameText = $proposal->text;
        $this->botSearch = '';
        $this->nameBotId = null;
        $this->resetValidation();
        unset($this->botOptions);

        Flux::modal('accept-bot-name')->show();
    }

    public function acceptName(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->nameText = Str::squish($this->nameText);
        $this->validate([
            'nameBotId' => ['required', 'integer', 'exists:bots,id'],
            'nameText' => BotNames::rules($this->nameBotId),
        ], [], ['nameBotId' => __('Bot'), 'nameText' => __('Bot name')]);

        $done = DB::transaction(function (): bool {
            $proposal = BotNameProposal::query()->lockForUpdate()->find($this->nameId);
            $bot = Bot::find($this->nameBotId);

            if (!$proposal || !$bot || $proposal->status !== BotNameProposal::PENDING) {
                return false;
            }

            $old = $bot->name;
            $bot->update(['name' => $this->nameText]);

            $proposal->update([
                'status' => BotNameProposal::ACCEPTED,
                'bot_id' => $bot->id,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            Audit::log('bot.renamed', $proposal->user, ['name' => $old], ['name' => $bot->name], __('Bot') . ' #' . $bot->sort_order);

            return true;
        });

        Flux::modal('accept-bot-name')->close();
        $this->reset('nameId', 'nameText', 'botSearch', 'nameBotId');
        unset($this->stats, $this->proposals);

        $done
            ? Flux::toast(variant: 'success', text: __('Bot renamed.'))
            : Flux::toast(variant: 'warning', text: __('This proposal has already been handled.'));
    }

    public function openReject(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->rejectId = BotNameProposal::pending()->findOrFail($id)->id;
        $this->rejectReason = '';
        Flux::modal('reject-proposal')->show();
    }

    public function reject(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        $this->validate(['rejectReason' => ['nullable', 'string', 'max:255']]);

        $proposal = BotNameProposal::pending()->find($this->rejectId);

        if ($proposal) {
            $proposal->update([
                'status' => BotNameProposal::REJECTED,
                'reject_reason' => trim($this->rejectReason) ?: null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
            Audit::log('bot.proposal_rejected', $proposal->user, [], ['text' => $proposal->text, 'reason' => $proposal->reject_reason], $proposal->text);
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
        <flux:heading size="xl" level="1">{{ __('Bot name proposals') }}</flux:heading>
        <flux:text>{{ __('Bot names proposed by players. An accepted name goes to the bot you choose.') }}</flux:text>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach ([\App\Models\BotNameProposal::PENDING => __('Pending'), \App\Models\BotNameProposal::ACCEPTED => __('Accepted'), \App\Models\BotNameProposal::REJECTED => __('Rejected'), 'all' => __('All')] as $key => $label)
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
                    <th class="px-3 py-2 text-left">{{ __('Proposal') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Bot') }}</th>
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
                        <td class="px-3 py-2">
                            @if ($proposal->bot)
                                <span class="text-xs text-zinc-500">#{{ $proposal->bot_id }}</span>
                            @else
                                <span class="text-xs text-zinc-400">–</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ $proposal->user?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $proposal->user?->email }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs text-zinc-500">{{ $proposal->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($proposal->status === \App\Models\BotNameProposal::PENDING)
                                <div class="flex justify-end gap-1">
                                    <flux:button size="xs" variant="primary" icon="check" wire:click="openName({{ $proposal->id }})">{{ __('Accept') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="openReject({{ $proposal->id }})">{{ __('Reject') }}</flux:button>
                                </div>
                            @else
                                <flux:badge size="sm" :color="$proposal->status === \App\Models\BotNameProposal::ACCEPTED ? 'green' : 'red'">
                                    {{ $proposal->status === \App\Models\BotNameProposal::ACCEPTED ? __('Accepted') : __('Rejected') }}
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

    <flux:modal name="accept-bot-name" class="w-full max-w-lg">
        <form wire:submit="acceptName" class="space-y-5">
            <flux:heading size="lg">{{ __('Give the name to a bot') }}</flux:heading>
            <flux:text>{{ __('Choose the bot that gets this name. Its history, trophies and statistics stay with it.') }}</flux:text>
            <flux:input wire:model="nameText" :label="__('Bot name')" maxlength="40" />
            <flux:input wire:model.live.debounce.300ms="botSearch" icon="magnifying-glass" :label="__('Find a bot')" :placeholder="__('Search by name...')" />
            <flux:select wire:model="nameBotId" :label="__('Bot')" :placeholder="__('Choose a bot')">
                @foreach ($this->botOptions as $option)
                    <flux:select.option :value="$option->id" wire:key="bo-{{ $option->id }}">#{{ $option->sort_order }} {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Rename the bot') }}</flux:button>
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
