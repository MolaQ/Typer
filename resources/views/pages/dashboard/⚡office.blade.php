<?php

use App\Models\OfficeRequest;
use App\Support\Office;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Biuro skarg i wniosków w panelu (App\Support\Office): sprawy czekające na decyzję (odwołania, inne sprawy,
 * wnioski o nazwy botów po udanym głosowaniu), trwające głosowania i archiwum. Akceptacja odwołania zdejmuje rolę
 * Banned/Inactive, akceptacja wniosku nadaje botowi nazwę (można ją poprawić). Odpowiedź widzi autor.
 */
new class extends Component {
    use WithPagination;

    #[Url(except: 'pending')]
    public string $status = 'pending';

    #[Url(except: '')]
    public string $type = '';

    // Decyzja w oknie
    public ?int $decideId = null;
    public bool $accept = true;
    public string $reply = '';
    public string $botName = '';

    public function render(): View
    {
        return $this->view()->title(__('Complaints and requests office'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stats(): array
    {
        $counts = OfficeRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[OfficeRequest::PENDING] ?? 0),
            'voting' => (int) ($counts[OfficeRequest::VOTING] ?? 0),
            'done' => (int) (($counts[OfficeRequest::ACCEPTED] ?? 0) + ($counts[OfficeRequest::REJECTED] ?? 0) + ($counts[OfficeRequest::VOTE_FAILED] ?? 0)),
        ];
    }

    #[Computed]
    public function requests()
    {
        return OfficeRequest::with(['user:id,name,email,team_name', 'user.roles:id,name', 'bot:id,name,sort_order', 'reviewer:id,name'])
            ->when($this->status === 'pending', fn ($q) => $q->where('status', OfficeRequest::PENDING)->oldest('updated_at'))
            ->when($this->status === 'voting', fn ($q) => $q->where('status', OfficeRequest::VOTING)->latest('id'))
            ->when($this->status === 'done', fn ($q) => $q->whereNotIn('status', OfficeRequest::OPEN)->latest('updated_at'))
            ->when(in_array($this->type, OfficeRequest::TYPES, true), fn ($q) => $q->where('type', $this->type))
            ->paginate(15);
    }

    public function open(int $id, bool $accept): void
    {
        $this->authorizeAdmin();

        $request = OfficeRequest::open()->findOrFail($id);
        $this->decideId = $request->id;
        $this->accept = $accept;
        $this->reply = '';
        $this->botName = (string) $request->bot_name;
        $this->resetValidation();

        Flux::modal('office-decide')->show();
    }

    public function decide(): void
    {
        $this->authorizeAdmin();
        $this->validate(['reply' => ['nullable', 'string', 'max:500'], 'botName' => ['nullable', 'string', 'max:40']]);

        $request = OfficeRequest::find($this->decideId);
        if (! $request) {
            return;
        }

        try {
            Office::decide($request, $this->accept, $this->reply, auth()->user(), $this->botName);
        } catch (DomainException $e) {
            $this->addError('botName', $e->getMessage());

            return;
        }

        Flux::modal('office-decide')->close();
        $this->reset('decideId', 'reply', 'botName');
        unset($this->stats, $this->requests);
        Flux::toast(variant: 'success', text: $this->accept ? __('Request accepted.') : __('Request rejected.'));
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(\App\Enums\RoleName::Admin->value), 403);
    }
}; ?>

@php
    $decided = $decideId ? \App\Models\OfficeRequest::with('user', 'bot')->find($decideId) : null;
    $typeLabels = [
        '' => __('All kinds'),
        \App\Models\OfficeRequest::BAN_APPEAL => __('Ban appeal'),
        \App\Models\OfficeRequest::INACTIVE_APPEAL => __('Inactive status appeal'),
        \App\Models\OfficeRequest::BOT_RENAME => __('Bot rename request'),
        \App\Models\OfficeRequest::OTHER => __('Other complaint or request'),
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Complaints and requests office') }}</flux:heading>
            <flux:text>{{ __('Appeals go straight to you. Bot names come here after the players\' vote (:votes votes, at least :approval% for).', ['votes' => \App\Support\Office::VOTES_NEEDED, 'approval' => \App\Support\Office::APPROVAL]) }}</flux:text>
        </div>
        <div class="w-full sm:w-64">
        <flux:select wire:model.live="type">
            @foreach ($typeLabels as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (['pending' => __('Waiting for decision'), 'voting' => __('Players are voting'), 'done' => __('Decided')] as $value => $label)
            <flux:button size="sm" wire:click="$set('status', '{{ $value }}')" :variant="$status === $value ? 'primary' : 'outline'">
                {{ $label }} ({{ $this->stats[$value] }})
            </flux:button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($this->requests as $request)
            <flux:card wire:key="office-admin-{{ $request->id }}" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm">{{ $request->typeLabel() }}</flux:badge>
                        <flux:badge size="sm" :color="$request->statusColor()">{{ $request->statusLabel() }}</flux:badge>
                        <span class="text-xs text-zinc-500">{{ $request->created_at?->format('d.m.Y H:i') }}</span>
                    </div>
                    @if ($request->user)
                        <span class="text-sm">
                            <flux:link :href="route('dashboard.users', ['search' => $request->user->email])" wire:navigate>{{ $request->user->name }}</flux:link>
                            <span class="text-zinc-500">· {{ $request->user->team_name }} · {{ $request->user->roles->pluck('name')->join(', ') ?: __('no role') }}</span>
                        </span>
                    @endif
                </div>

                @if ($request->type === \App\Models\OfficeRequest::BOT_RENAME)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="italic text-zinc-500">#{{ $request->bot?->sort_order }} {{ $request->old_bot_name ?? $request->bot?->name }}</span>
                        <flux:icon.arrow-long-right class="size-4 text-zinc-400" />
                        <span class="font-semibold italic">{{ $request->bot_name }}</span>
                        <span class="text-xs text-zinc-500">· 👍 {{ $request->votes_up }} · 👎 {{ $request->votes_down }} ({{ $request->approval() }}%)</span>
                    </div>
                @endif

                <p class="whitespace-pre-line text-sm">{{ $request->body }}</p>

                @if ($request->admin_reply || $request->reviewer)
                    <p class="text-xs text-zinc-500">
                        {{ __('Decided by :name on :date', ['name' => $request->reviewer?->name ?? '—', 'date' => $request->reviewed_at?->format('d.m.Y H:i') ?? '—']) }}
                        @if ($request->admin_reply)· „{{ $request->admin_reply }}”@endif
                    </p>
                @endif

                @if (in_array($request->status, \App\Models\OfficeRequest::OPEN, true))
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="primary" icon="check" wire:click="open({{ $request->id }}, true)">{{ __('Accept') }}</flux:button>
                        <flux:button size="sm" variant="danger" icon="x-mark" wire:click="open({{ $request->id }}, false)">{{ __('Reject') }}</flux:button>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:card><flux:text>{{ __('Nothing here.') }}</flux:text></flux:card>
        @endforelse
    </div>

    <flux:pagination :paginator="$this->requests" />

    <flux:modal name="office-decide" class="md:w-[32rem]">
        @if ($decided)
            <form wire:submit="decide" class="space-y-4">
                <flux:heading size="lg">{{ $accept ? __('Accept request') : __('Reject request') }}</flux:heading>
                <flux:text>{{ $decided->typeLabel() }} · {{ $decided->user?->name }}</flux:text>
                @if ($accept)
                    <flux:text class="text-sm">
                        {{ match ($decided->type) {
                            \App\Models\OfficeRequest::BAN_APPEAL => __('The ban will be lifted and the player returns to the game.'),
                            \App\Models\OfficeRequest::INACTIVE_APPEAL => __('The Inactive status will be lifted and the player returns to Liga podwórkowa.'),
                            \App\Models\OfficeRequest::BOT_RENAME => __('The bot gets the new name.'),
                            default => __('The request is marked as accepted.'),
                        } }}
                    </flux:text>
                    @if ($decided->type === \App\Models\OfficeRequest::BOT_RENAME)
                        <flux:input wire:model="botName" :label="__('New bot name')" maxlength="40" />
                    @endif
                @endif
                <flux:error name="botName" />
                <flux:textarea wire:model="reply" :label="__('Reply to the player (optional)')" rows="3" maxlength="500" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" :variant="$accept ? 'primary' : 'danger'">{{ $accept ? __('Accept') : __('Reject') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
