<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Enums\Permission;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class
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
