<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Support\Audit;
use App\Support\Premium;
use Illuminate\Support\Facades\DB;

/**
 * Zaksięgowanie wpłaty (po potwierdzeniu z Przelewy24 albo wpisaniu przez admina): status „opłacona”,
 * data wpłaty i przedłużenie premium o dni z wpłaty. Drugie wywołanie dla tej samej wpłaty nic nie robi.
 */
class CompletePayment
{
    public function handle(Payment $payment, ?int $orderId = null): Payment
    {
        return DB::transaction(function () use ($payment, $orderId): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::PAID) {
                return $payment;
            }

            $payment->update([
                'status' => Payment::PAID,
                'paid_at' => $payment->paid_at ?? now(),
                'p24_order_id' => $orderId ?? $payment->p24_order_id,
            ]);

            $until = null;
            if ($payment->user && $payment->premium_days > 0) {
                $until = Premium::extend($payment->user, $payment->premium_days);
            }

            Audit::log('payment.paid', $payment->user, [], [
                'amount' => $payment->amountLabel(),
                'kind' => $payment->kind,
                'source' => $payment->source,
                'premium_until' => $until?->toDateTimeString(),
            ]);

            return $payment;
        });
    }
}
