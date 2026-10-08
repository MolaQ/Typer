<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CompletePayment;
use App\Models\Payment;
use App\Support\Przelewy24;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Powiadomienie od Przelewy24 o wpłacie (urlStatus). Sprawdzamy podpis, kwotę i walutę, potwierdzamy
 * transakcję w P24 (verify) i dopiero wtedy księgujemy wpłatę. Trasa jest wyłączona z ochrony CSRF.
 */
class Przelewy24Controller extends Controller
{
    public function status(Request $request, Przelewy24 $p24, CompletePayment $complete): Response
    {
        $data = $request->all();

        if (!Przelewy24::configured() || !$p24->validNotification($data)) {
            Log::warning('Przelewy24 notification rejected', ['session' => $data['sessionId'] ?? null]);

            return response('invalid', 400);
        }

        $payment = Payment::where('session_id', (string) $data['sessionId'])->first();

        if (!$payment || (int) $data['amount'] !== $payment->amount || ($data['currency'] ?? '') !== $payment->currency) {
            Log::warning('Przelewy24 notification does not match a payment', ['session' => $data['sessionId']]);

            return response('unknown', 400);
        }

        if ($payment->status !== Payment::PAID) {
            if (!$p24->verify($payment, (int) $data['orderId'])) {
                $payment->update(['status' => Payment::FAILED, 'p24_order_id' => (int) $data['orderId']]);

                return response('not verified', 400);
            }

            $complete->handle($payment, (int) $data['orderId']);
        }

        return response('OK');
    }
}
