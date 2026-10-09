<?php

use App\Enums\Permission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Powiadomienia od Przelewy24 przychodzą z ich serwera, bez tokenu CSRF (podpis sprawdza kontroler).
        $middleware->validateCsrfTokens(except: ['payments/przelewy24/status']);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            // Zapytania Livewire i JSON zostawiamy w spokoju (zwykły 403).
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            // Konto z dostępem do panelu wraca do panelu, reszta na stronę główną.
            $target = $request->user()?->can(Permission::DashboardAccess->value)
                ? route('dashboard')
                : route('home');

            return redirect($target)->with('error', __('You do not have access to this page.'));
        });
    })->create();
