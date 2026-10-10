<?php

use App\Models\Payment;
use App\Support\Audit;
use App\Support\GoldenLeague;
use App\Support\Premium;
use App\Support\Przelewy24;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Premium i wsparcie (etap 15, regulamin, punkty 10 i 11): cennik premium, cegiełka dowolną kwotą, płatność
 * przez Przelewy24, stan premium gracza, jego domyślny typ i historia wpłat. Typowanie jest zawsze darmowe.
 * Po powrocie z P24 adres ma ?payment=… i pokazujemy stan tej wpłaty (potwierdzenie przychodzi osobno).
 */
new #[Layout('layouts::public')] class extends Component {
    /** Wpłata, z której gracz wrócił z Przelewy24. */
    #[Url(as: 'payment', except: '')]
    public string $returned = '';

    /** Cegiełka w złotych. */
    public string $supportAmount = '20';

    public string $defaultLech = '';
    public string $defaultOpponent = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user) {
            [$lech, $opponent] = Premium::defaultTip($user);
            $this->defaultLech = (string) $lech;
            $this->defaultOpponent = (string) $opponent;
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Premium and support'));
    }

    #[Computed]
    public function configured(): bool
    {
        return Przelewy24::configured();
    }

    #[Computed]
    public function isPremium(): bool
    {
        return Premium::isActive(auth()->user());
    }

    #[Computed]
    public function returnedPayment(): ?Payment
    {
        return $this->returned === '' || !auth()->check()
            ? null
            : Payment::where('session_id', $this->returned)->where('user_id', auth()->id())->first();
    }

    #[Computed]
    public function payments()
    {
        return auth()->check() ? Payment::where('user_id', auth()->id())->latest()->limit(10)->get() : collect();
    }

    /** Suma wpłat gracza z 12 miesięcy i miejsce w rankingu Złotej Ligi. @return array{total: int, rank: ?int} */
    #[Computed]
    public function golden(): array
    {
        $ranking = GoldenLeague::ranking()->values();
        $index = $ranking->search(fn($row) => $row['user_id'] === auth()->id());

        return [
            'total' => $index === false ? 0 : $ranking[$index]['total'],
            'rank' => $index === false ? null : $index + 1,
        ];
    }

    /** Premium według cennika. */
    public function buy(string $plan): void
    {
        abort_unless(isset(Premium::PLANS[$plan]), 422);
        [$amount, $days] = Premium::PLANS[$plan];

        $this->startPayment(Payment::KIND_PREMIUM, $plan, $amount, $days, __('Premium: :plan', ['plan' => Premium::labels()[$plan]]));
    }

    /** Cegiełka: premium według najwyższej opcji cennika, na którą wystarcza kwoty. */
    public function support(): void
    {
        $this->validate(
            ['supportAmount' => ['required', 'numeric', 'min:' . Premium::MIN_SUPPORT / 100, 'max:' . Premium::MAX_SUPPORT / 100]],
            [],
            ['supportAmount' => __('Amount')],
        );

        $amount = (int) round((float) str_replace(',', '.', $this->supportAmount) * 100);

        $this->startPayment(Payment::KIND_SUPPORT, null, $amount, Premium::daysFor($amount), __('Support for LechTyper'));
    }

    public function saveDefaultTip(): void
    {
        abort_unless($this->isPremium, 403);

        $this->validate(
            ['defaultLech' => ['required', 'integer', 'min:0', 'max:20'], 'defaultOpponent' => ['required', 'integer', 'min:0', 'max:20']],
            [],
            ['defaultLech' => 'Lech', 'defaultOpponent' => __('Opponent')],
        );

        $user = auth()->user();
        $old = Premium::defaultTip($user);
        $user->forceFill(['default_tip_lech' => (int) $this->defaultLech, 'default_tip_opponent' => (int) $this->defaultOpponent])->save();

        Audit::log('premium.default_tip', $user, ['tip' => implode(':', $old)], ['tip' => $this->defaultLech . ':' . $this->defaultOpponent]);

        Flux::toast(variant: 'success', text: __('Default tip saved.'));
    }

    private function startPayment(string $kind, ?string $plan, int $amount, int $days, string $description): void
    {
        $user = auth()->user();

        if (!$user) {
            $this->redirectRoute('login');

            return;
        }

        if (!$this->configured) {
            Flux::toast(variant: 'warning', text: __('Online payments are not available yet.'));

            return;
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'plan' => $plan,
            'amount' => $amount,
            'status' => Payment::PENDING,
            'source' => 'p24',
            'session_id' => 'LT-' . Str::uuid(),
            'premium_days' => $days,
        ]);

        try {
            $url = app(Przelewy24::class)->register($payment, $user, $description);
        } catch (\Throwable $e) {
            $payment->update(['status' => Payment::FAILED, 'note' => Str::limit($e->getMessage(), 250)]);
            report($e);
            Flux::toast(variant: 'danger', text: __('The payment could not be started. Try again later.'));

            return;
        }

        $this->redirect($url);
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :title="__('Premium and support')"
        :subtitle="__('Tipping is always free. Premium gives extras, and every payment counts towards a place in Złota Liga.')" />

    {{-- Powrót z Przelewy24 --}}
    @if ($this->returnedPayment)
        @php
            $returnedPayment = $this->returnedPayment;
        @endphp
        {{-- Potwierdzenie z P24 przychodzi osobno, więc dopóki wpłata czeka, odświeżamy stan. --}}
        <div @if ($returnedPayment->status === 'pending') wire:poll.5s @endif>
            <flux:callout :variant="$returnedPayment->status === 'paid' ? 'success' : ($returnedPayment->status === 'failed' ? 'danger' : 'warning')"
                :icon="$returnedPayment->status === 'paid' ? 'check-circle' : 'clock'">
                <flux:callout.heading>
                    {{ $returnedPayment->status === 'paid' ? __('Thank you! The payment is confirmed.') : ($returnedPayment->status === 'failed' ? __('The payment failed.') : __('Waiting for the payment confirmation...')) }}
                </flux:callout.heading>
                <flux:callout.text>{{ $returnedPayment->kindLabel() }}: {{ $returnedPayment->amountLabel() }}</flux:callout.text>
            </flux:callout>
        </div>
    @endif

    @if (!$this->configured)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ __('Online payments are not available yet.') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Stan premium gracza --}}
    @auth
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Your premium') }}</flux:heading>
                @if ($this->isPremium)
                    <flux:badge color="purple" icon="sparkles">
                        {{ auth()->user()->premium_until ? __('Active until :date', ['date' => auth()->user()->premium_until->format('d.m.Y H:i')]) : __('Active without an end date') }}
                    </flux:badge>
                @else
                    <flux:badge color="zinc">{{ __('Inactive') }}</flux:badge>
                @endif
            </div>

            @if ($this->isPremium)
                <div class="space-y-2">
                    <flux:text>
                        {{ __('Default tip: used when you do not tip before the kick-off. Until you set it, it is 0:0.') }}
                    </flux:text>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-20">
                            <flux:input type="number" min="0" max="20" wire:model="defaultLech" label="Lech" />
                        </div>
                        <span class="pb-2 text-lg font-bold">:</span>
                        <div class="w-20">
                            <flux:input type="number" min="0" max="20" wire:model="defaultOpponent" :label="__('Opponent')" />
                        </div>
                        <flux:button variant="primary" wire:click="saveDefaultTip">{{ __('Save') }}</flux:button>
                    </div>
                    <flux:error name="defaultLech" />
                    <flux:error name="defaultOpponent" />
                </div>
            @endif

            <flux:text class="text-sm">
                {{ __('Your payments in the last 12 months: :amount.', ['amount' => \App\Models\Payment::money($this->golden['total'])]) }}
                @if ($this->golden['rank'])
                    {{ __('Złota Liga ranking: :rank. place (the top 10 play in the next season).', ['rank' => $this->golden['rank']]) }}
                @endif
            </flux:text>
        </flux:card>
    @endauth

    {{-- Cennik premium --}}
    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Premium') }}</flux:heading>
        <flux:text class="text-sm">
            {{ __('Premium: a default tip when you forget to tip, a team name change without approval and a badge. A new payment extends it.') }}
        </flux:text>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (\App\Support\Premium::PLANS as $plan => [$price, $days])
                <flux:card class="flex flex-col items-center gap-2 text-center" wire:key="plan-{{ $plan }}">
                    <flux:heading>{{ \App\Support\Premium::labels()[$plan] }}</flux:heading>
                    <p class="text-3xl font-black tabular-nums">{{ \App\Models\Payment::money($price) }}</p>
                    <flux:text class="text-sm">{{ trans_choice(':count day|:count days', $days, ['count' => $days]) }}</flux:text>
                    @auth
                        <flux:button variant="primary" class="w-full" wire:click="buy('{{ $plan }}')" :disabled="!$this->configured">
                            {{ __('Buy') }}
                        </flux:button>
                    @else
                        <flux:button class="w-full" :href="route('login')">{{ __('Log in') }}</flux:button>
                    @endauth
                </flux:card>
            @endforeach
        </div>
    </div>

    {{-- Cegiełka --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg">{{ __('Support') }}</flux:heading>
        <flux:text class="text-sm">
            {{ __('Any amount from 5 zł. You get premium for the highest price list option the amount covers, and the whole amount counts towards Złota Liga.') }}
        </flux:text>

        @auth
            <div class="flex flex-wrap items-end gap-2">
                <div class="w-40">
                    <flux:input type="number" min="5" step="1" wire:model="supportAmount" :label="__('Amount')" suffix="zł" />
                </div>
                <flux:button variant="primary" icon="heart" wire:click="support" :disabled="!$this->configured">
                    {{ __('Support') }}
                </flux:button>
            </div>
            <flux:error name="supportAmount" />
        @else
            <div>
                <flux:button :href="route('login')">{{ __('Log in') }}</flux:button>
            </div>
        @endauth
    </flux:card>

    {{-- Historia wpłat --}}
    @if ($this->payments->isNotEmpty())
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Your payments') }}</flux:heading>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach ($this->payments as $payment)
                    <div class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="pay-{{ $payment->id }}">
                        <span class="w-32 text-zinc-500">{{ ($payment->paid_at ?? $payment->created_at)->format('d.m.Y H:i') }}</span>
                        <span class="min-w-0 flex-1">{{ $payment->kindLabel() }}</span>
                        <span class="tabular-nums">{{ $payment->amountLabel() }}</span>
                        <flux:badge size="sm" :color="$payment->statusColor()">{{ $payment->statusLabel() }}</flux:badge>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
