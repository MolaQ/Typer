<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Klient REST API Przelewy24 (v1): rejestracja transakcji, sprawdzenie powiadomienia i weryfikacja.
 * Przebieg: register() zwraca adres płatności, P24 po wpłacie wysyła powiadomienie na urlStatus
 * (Przelewy24Controller@status), a my sprawdzamy podpis i potwierdzamy transakcję przez verify().
 * Dopiero po udanej weryfikacji wpłata jest opłacona (CompletePayment).
 */
final class Przelewy24
{
    public static function configured(): bool
    {
        $config = config('services.przelewy24');

        return $config['merchant_id'] > 0 && filled($config['crc']) && filled($config['api_key']);
    }

    /** Adres płatności dla nowej transakcji. */
    public function register(Payment $payment, User $user, string $description): string
    {
        $config = config('services.przelewy24');

        $body = [
            'merchantId' => $config['merchant_id'],
            'posId' => $config['pos_id'],
            'sessionId' => $payment->session_id,
            'amount' => $payment->amount,
            'currency' => 'PLN',
            'description' => $description,
            'email' => $user->email,
            'client' => $user->name,
            'country' => 'PL',
            'language' => 'pl',
            'urlReturn' => route('support', ['payment' => $payment->session_id]),
            'urlStatus' => route('przelewy24.status'),
            'sign' => $this->sign([
                'sessionId' => $payment->session_id,
                'merchantId' => $config['merchant_id'],
                'amount' => $payment->amount,
                'currency' => 'PLN',
                'crc' => $config['crc'],
            ]),
        ];

        $response = $this->http()->post($this->api('transaction/register'), $body);
        $token = $response->json('data.token');

        if (! $response->successful() || ! $token) {
            Log::warning('Przelewy24 register failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new RuntimeException(__('The payment could not be started. Try again later.'));
        }

        return $this->host().'/trnRequest/'.$token;
    }

    /** Czy powiadomienie od P24 ma poprawny podpis i pasuje do naszych danych. */
    public function validNotification(array $data): bool
    {
        $config = config('services.przelewy24');

        $expected = $this->sign([
            'merchantId' => (int) ($data['merchantId'] ?? 0),
            'posId' => (int) ($data['posId'] ?? 0),
            'sessionId' => (string) ($data['sessionId'] ?? ''),
            'amount' => (int) ($data['amount'] ?? 0),
            'originAmount' => (int) ($data['originAmount'] ?? 0),
            'currency' => (string) ($data['currency'] ?? ''),
            'orderId' => (int) ($data['orderId'] ?? 0),
            'methodId' => (int) ($data['methodId'] ?? 0),
            'statement' => (string) ($data['statement'] ?? ''),
            'crc' => $config['crc'],
        ]);

        return hash_equals($expected, (string) ($data['sign'] ?? ''))
            && (int) ($data['merchantId'] ?? 0) === $config['merchant_id'];
    }

    /** Potwierdzenie transakcji w P24 (bez tego pieniądze nie trafiają do sprzedawcy). */
    public function verify(Payment $payment, int $orderId): bool
    {
        $config = config('services.przelewy24');

        $response = $this->http()->put($this->api('transaction/verify'), [
            'merchantId' => $config['merchant_id'],
            'posId' => $config['pos_id'],
            'sessionId' => $payment->session_id,
            'amount' => $payment->amount,
            'currency' => 'PLN',
            'orderId' => $orderId,
            'sign' => $this->sign([
                'sessionId' => $payment->session_id,
                'orderId' => $orderId,
                'amount' => $payment->amount,
                'currency' => 'PLN',
                'crc' => $config['crc'],
            ]),
        ]);

        if (! $response->successful() || $response->json('data.status') !== 'success') {
            Log::warning('Przelewy24 verify failed', ['session' => $payment->session_id, 'status' => $response->status(), 'body' => $response->json()]);

            return false;
        }

        return true;
    }

    /** Podpis P24: SHA-384 z JSON-a pól w ustalonej kolejności. */
    private function sign(array $fields): string
    {
        return hash('sha384', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function http()
    {
        $config = config('services.przelewy24');

        return Http::withBasicAuth((string) $config['pos_id'], (string) $config['api_key'])->acceptJson()->asJson()->timeout(20);
    }

    private function host(): string
    {
        return config('services.przelewy24.sandbox') ? 'https://sandbox.przelewy24.pl' : 'https://secure.przelewy24.pl';
    }

    private function api(string $path): string
    {
        return $this->host().'/api/v1/'.$path;
    }
}
