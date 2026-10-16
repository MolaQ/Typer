<?php

namespace App\Http\Middleware;

use App\Support\Maintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tryb konserwacji z panelu (App\Support\Maintenance): wszyscy poza adminami dostają stronę „Przerwa techniczna”
 * (503 z Retry-After), z wyjątkiem logowania i powiadomień płatności. Zapytania Livewire dostają samo 503.
 */
class SiteMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Maintenance::isOn() || Maintenance::canBypass($request->user()) || $request->routeIs(...Maintenance::ALLOWED_ROUTES)) {
            return $next($request);
        }

        $until = Maintenance::until();
        $headers = $until && $until->isFuture() ? ['Retry-After' => (string) max(60, (int) now()->diffInSeconds($until))] : [];

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => __('The site is under maintenance.')], 503, $headers);
        }

        return response()->view('maintenance', ['state' => Maintenance::state(), 'until' => $until], 503, $headers);
    }
}
