<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Wpis w „Informacjach systemowych” (App\Support\SystemFeed): kategoria, treść (klucz tłumaczenia z parametrami)
 * i opcjonalny link (nazwa trasy z parametrami).
 *
 * @property string $category
 * @property string $message
 * @property array|null $params
 * @property string|null $route
 * @property array|null $route_params
 */
class SystemEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['category', 'message', 'params', 'route', 'route_params', 'user_id'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'route_params' => 'array',
        ];
    }

    /** Przetłumaczona treść. */
    public function text(): string
    {
        return __($this->message, $this->params ?? []);
    }

    /** Adres linku albo null (np. gdy trasa zniknęła). */
    public function url(): ?string
    {
        if (!$this->route || !Route::has($this->route)) {
            return null;
        }

        try {
            return route($this->route, $this->route_params ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
}
