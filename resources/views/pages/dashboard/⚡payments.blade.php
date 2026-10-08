<?php

use App\Actions\Payments\CompletePayment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\GoldenLeague;
use App\Support\Premium;
use App\Support\Przelewy24;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Wpłaty i premium (etap 15): lista wpłat (Przelewy24 i ręczne), dopisanie wpłaty (np. przelew na konto),
 * zaksięgowanie wiszącej wpłaty, ręczne nadanie albo odebranie premium oraz podgląd rankingu Złotej Ligi
 * (suma wpłat z 12 miesięcy). Tylko Admin.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // Wybór gracza w oknach (wpłata, premium).
    public string $userSearch = '';
    public ?int $userId = null;

    // Nowa wpłata.
    public string $kind = Payment::KIND_SUPPORT;
    public string $plan = 'month';
    public string $amount = '';
    public string $paidAt = '';
    public string $note = '';

    // Premium ręcznie.
    public string $premiumUntil = '';

    // Księgowanie wiszącej wpłaty.
    public ?int $completeId = null;

    public function render(): View
    {
        return $this->view()->title(__('Payments'));
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function payments()
    {
        $term = trim($this->search);

        return Payment::with('user:id,name,email,team_name', 'creator:id,name')
            ->when($this->status !== '', fn($q) => $q->where('status', $this->status))
            ->when($term !== '', fn($q) => $q->whereHas('user', fn($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('team_name', 'like', "%{$term}%")))
            ->latest('id')
            ->paginate(25);
    }

    #[Computed]
    public function premiumUsers()
    {
        return User::where('premium_until', '>', now())->orderBy('premium_until')->get(['id', 'name', 'email', 'team_name', 'premium_until']);
    }

    /** Ranking Złotej Ligi na dziś: 15 pierwszych (do składu wchodzi 10 z listy sezonu). */
    #[Computed]
    public function golden()
    {
        $ranking = GoldenLeague::ranking()->take(15);
        $users = User::whereIn('id', $ranking->pluck('user_id'))->get(['id', 'name', 'team_name'])->keyBy('id');

        return $ranking->map(fn($row) => $row + ['user' => $users->get($row['user_id'])]);
    }

    #[Computed]
    public function userResults()
    {
        $term = trim($this->userSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return User::where(fn($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('team_name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email', 'team_name', 'premium_until']);
    }

    #[Computed]
    public function selectedUser(): ?User
    {
        return $this->userId ? User::find($this->userId) : null;
    }

    public function pickUser(int $id): void
    {
        $this->userId = $id;
        $this->userSearch = '';
        unset($this->selectedUser, $this->userResults);

        $this->premiumUntil = Premium::isActive($this->selectedUser) ? $this->selectedUser->premium_until->format('Y-m-d') : now()->addDays(30)->format('Y-m-d');
    }

    /* ==================================================================
     | WPŁATY
     * ================================================================*/

    public function openPayment(): void
    {
        $this->reset('userSearch', 'userId', 'amount', 'note');
        $this->kind = Payment::KIND_SUPPORT;
        $this->plan = 'month';
        $this->paidAt = now()->format('Y-m-d');
        $this->resetValidation();

        Flux::modal('payment-form')->show();
    }

    public function updatedPlan(): void
    {
        $this->amount = isset(Premium::PLANS[$this->plan]) ? (string) (Premium::PLANS[$this->plan][0] / 100) : $this->amount;
    }

    public function updatedKind(): void
    {
        if ($this->kind === Payment::KIND_PREMIUM) {
            $this->updatedPlan();
        }
    }

    /** Wpłata spoza Przelewy24 (np. przelew na konto): od razu opłacona, przedłuża premium jak wpłata online. */
    public function savePayment(CompletePayment $complete): void
    {
        $this->validate([
            'userId' => ['required', 'exists:users,id'],
            'kind' => ['required', 'in:' . Payment::KIND_PREMIUM . ',' . Payment::KIND_SUPPORT],
            'plan' => ['required_if:kind,' . Payment::KIND_PREMIUM, 'nullable', 'in:' . implode(',', array_keys(Premium::PLANS))],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'paidAt' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'userId' => __('Player'),
            'amount' => __('Amount'),
            'paidAt' => __('Payment date'),
        ]);

        $amount = (int) round((float) str_replace(',', '.', $this->amount) * 100);
        $isPremium = $this->kind === Payment::KIND_PREMIUM;

        $payment = Payment::create([
            'user_id' => $this->userId,
            'kind' => $this->kind,
            'plan' => $isPremium ? $this->plan : null,
            'amount' => $amount,
            'status' => Payment::PENDING,
            'source' => 'manual',
            'premium_days' => $isPremium ? Premium::PLANS[$this->plan][1] : Premium::daysFor($amount),
            // Dzisiejsza data z bieżącą godziną, wcześniejsza na koniec dnia (kolejność w rankingu Złotej Ligi).
            'paid_at' => Carbon::parse($this->paidAt)->isToday() ? now() : Carbon::parse($this->paidAt)->endOfDay(),
            'note' => $this->note ?: null,
            'created_by' => auth()->id(),
        ]);

        $complete->handle($payment);

        Flux::modal('payment-form')->close();
        $this->clearCaches();
        Flux::toast(variant: 'success', text: __('Payment saved.'));
    }

    /** Wisząca wpłata online (np. powiadomienie z P24 nie dotarło, a pieniądze są na koncie). */
    public function confirmComplete(int $id): void
    {
        $this->completeId = Payment::where('status', '!=', Payment::PAID)->findOrFail($id)->id;

        Flux::modal('confirm-complete')->show();
    }

    public function complete(CompletePayment $complete): void
    {
        $payment = Payment::where('status', '!=', Payment::PAID)->findOrFail((int) $this->completeId);
        $payment->update(['note' => trim(($payment->note ? $payment->note . ' ' : '') . __('Confirmed by :name', ['name' => auth()->user()->name]))]);

        $complete->handle($payment);

        Flux::modal('confirm-complete')->close();
        $this->reset('completeId');
        $this->clearCaches();
        Flux::toast(variant: 'success', text: __('Payment confirmed.'));
    }

    /* ==================================================================
     | PREMIUM RĘCZNIE
     * ================================================================*/

    public function openPremium(?int $userId = null): void
    {
        $this->reset('userSearch', 'userId', 'premiumUntil');
        $this->resetValidation();

        if ($userId) {
            $this->pickUser($userId);
        }

        Flux::modal('premium-form')->show();
    }

    /** Ustawia datę końca premium (dzień włącznie). Data w przeszłości odbiera premium. */
    public function savePremium(): void
    {
        $this->validate(
            ['userId' => ['required', 'exists:users,id'], 'premiumUntil' => ['required', 'date']],
            [],
            ['userId' => __('Player'), 'premiumUntil' => __('Premium until')],
        );

        $user = User::findOrFail($this->userId);
        $old = $user->premium_until?->toDateTimeString();
        $until = Carbon::parse($this->premiumUntil)->endOfDay();

        Premium::setUntil($user, $until->isFuture() ? $until : now());

        Audit::log('premium.manual', $user, ['premium_until' => $old], ['premium_until' => $user->premium_until?->toDateTimeString()]);

        Flux::modal('premium-form')->close();
        $this->clearCaches();
        Flux::toast(variant: 'success', text: __('Premium saved.'));
    }

    public ?int $revokeId = null;

    public function confirmRevoke(int $userId): void
    {
        $this->revokeId = User::findOrFail($userId)->id;

        Flux::modal('confirm-revoke')->show();
    }

    public function revokePremium(): void
    {
        $user = User::findOrFail((int) $this->revokeId);
        $old = $user->premium_until?->toDateTimeString();

        Premium::setUntil($user, now());
        Audit::log('premium.manual', $user, ['premium_until' => $old], ['premium_until' => null]);

        Flux::modal('confirm-revoke')->close();
        $this->reset('revokeId');
        $this->clearCaches();
        Flux::toast(text: __('Premium revoked.'));
    }

    private function clearCaches(): void
    {
        unset($this->payments, $this->premiumUsers, $this->golden, $this->selectedUser, $this->userResults);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
            <flux:subheading>
                {{ __('Premium and support payments. Online payments come from Przelewy24, others you add by hand.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="sparkles" wire:click="openPremium">{{ __('Grant premium') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openPayment">{{ __('Add payment') }}</flux:button>
        </div>
    </div>

    @if (!\App\Support\Przelewy24::configured())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>
                {{ __('Przelewy24 is not configured: set P24_MERCHANT_ID, P24_POS_ID, P24_CRC and P24_API_KEY in .env.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
        {{-- Lista wpłat --}}
        <div class="space-y-3">
            <div class="flex flex-wrap gap-2">
                <div class="w-full sm:w-64">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable
                        :placeholder="__('Search by player...')" />
                </div>
                <div class="w-full sm:w-44">
                    <flux:select wire:model.live="status">
                        <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                        <flux:select.option value="paid">{{ __('Paid') }}</flux:select.option>
                        <flux:select.option value="pending">{{ __('Pending') }}</flux:select.option>
                        <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
                    </flux:select>
                </div>
            </div>

            <flux:table :paginate="$this->payments">
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Player') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->payments as $payment)
                        <flux:table.row :key="$payment->id">
                            <flux:table.cell class="whitespace-nowrap text-sm">
                                {{ ($payment->paid_at ?? $payment->created_at)->format('d.m.Y H:i') }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="text-sm font-medium">{{ $payment->user?->name ?? '—' }}</div>
                                <div class="text-xs text-zinc-500">{{ $payment->user?->email }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="text-sm">
                                {{ $payment->kindLabel() }}
                                @if ($payment->plan)
                                    ({{ \App\Support\Premium::labels()[$payment->plan] ?? $payment->plan }})
                                @endif
                                <div class="text-xs text-zinc-500">
                                    {{ $payment->source === 'manual' ? __('By hand') . ($payment->creator ? ': ' . $payment->creator->name : '') : 'Przelewy24' }}
                                    @if ($payment->premium_days > 0)
                                        &middot; {{ trans_choice(':count day|:count days', $payment->premium_days, ['count' => $payment->premium_days]) }}
                                    @endif
                                </div>
                                @if ($payment->note)
                                    <div class="text-xs text-zinc-400">{{ $payment->note }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $payment->amountLabel() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$payment->statusColor()">{{ $payment->statusLabel() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($payment->status !== 'paid')
                                    <flux:button size="xs" icon="check" wire:click="confirmComplete({{ $payment->id }})">
                                        {{ __('Confirm') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">{{ __('No payments yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="space-y-6">
            {{-- Premium --}}
            <flux:card class="space-y-3">
                <flux:heading>{{ __('Active premium') }} ({{ $this->premiumUsers->count() }})</flux:heading>
                @forelse ($this->premiumUsers as $user)
                    <div class="flex items-center gap-2 text-sm" wire:key="premium-{{ $user->id }}">
                        <span class="min-w-0 flex-1 truncate">{{ $user->team_name ?: $user->name }}</span>
                        <span class="text-xs text-zinc-500">{{ $user->premium_until->format('d.m.Y') }}</span>
                        <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openPremium({{ $user->id }})"
                            :aria-label="__('Edit')" />
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="confirmRevoke({{ $user->id }})"
                            :aria-label="__('Revoke premium')" />
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('Nobody has premium now.') }}</flux:text>
                @endforelse
            </flux:card>

            {{-- Złota Liga --}}
            <flux:card class="space-y-3">
                <div>
                    <flux:heading>{{ __('Złota Liga ranking') }}</flux:heading>
                    <flux:text class="text-xs">
                        {{ __('Payments in the last 12 months. The top 10 teams from the season list play when the season is approved.') }}
                    </flux:text>
                </div>
                @forelse ($this->golden as $row)
                    <div class="flex items-center gap-2 text-sm" wire:key="golden-{{ $row['user_id'] }}">
                        <span class="w-6 text-right tabular-nums text-zinc-500">{{ $loop->iteration }}.</span>
                        <span class="min-w-0 flex-1 truncate">{{ $row['user']?->team_name ?: $row['user']?->name }}</span>
                        <span class="tabular-nums">{{ \App\Models\Payment::money($row['total']) }}</span>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('No payments in the last 12 months.') }}</flux:text>
                @endforelse
            </flux:card>
        </div>
    </div>

    <flux:modal name="payment-form" class="w-full md:w-[30rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Add payment') }}</flux:heading>
            <flux:text class="text-sm">{{ __('For example a bank transfer. The payment is saved as paid and extends premium.') }}</flux:text>

            @include('partials.payments-user-picker')

            <flux:radio.group wire:model.live="kind" :label="__('Type')" variant="segmented">
                <flux:radio value="support" :label="__('Support')" />
                <flux:radio value="premium" :label="__('Premium')" />
            </flux:radio.group>

            @if ($kind === 'premium')
                <flux:select wire:model.live="plan" :label="__('Plan')">
                    @foreach (\App\Support\Premium::PLANS as $key => [$price, $days])
                        <flux:select.option :value="$key">
                            {{ \App\Support\Premium::labels()[$key] }} ({{ \App\Models\Payment::money($price) }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <flux:input type="number" step="0.01" min="1" wire:model="amount" :label="__('Amount')" suffix="zł" />
                <flux:input type="date" wire:model="paidAt" :label="__('Payment date')" />
            </div>
            <flux:input wire:model="note" :label="__('Note')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="savePayment">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="premium-form" class="w-full md:w-[30rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Grant premium') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Premium lasts until the end of the chosen day. A past date revokes it.') }}</flux:text>

            @include('partials.payments-user-picker')

            <flux:input type="date" wire:model="premiumUntil" :label="__('Premium until')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="savePremium">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-revoke" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Revoke premium') }}</flux:heading>
            <flux:text>{{ __('Revoke premium?') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="revokePremium">{{ __('Revoke premium') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-complete" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Confirm payment') }}</flux:heading>
            <flux:text>{{ __('Mark this payment as paid? Check first that the money is in the account. Premium will be extended.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="complete">{{ __('Confirm') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
