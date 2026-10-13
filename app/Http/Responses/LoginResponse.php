<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Odpowiedź po zalogowaniu: zawsze strona główna, bez powrotu do adresu sprzed logowania.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        // Ignorujemy adres, który użytkownik próbował otworzyć przed logowaniem.
        $request->session()->forget('url.intended');

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->route('home');
    }
}
