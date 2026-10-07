<?php

namespace App\Models;

use App\Support\BotNames;
use Illuminate\Database\Eloquent\Model;

/**
 * Bot: zespół bez właściciela z nazwą edytowalną w panelu admina.
 * Pula 512 botów jest tworzona przez BotsSeeder.
 *
 * @property int $sort_order
 * @property string $name
 */
class Bot extends Model
{
    protected $fillable = ['sort_order', 'name'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** Domyślna nazwa tego slotu z App\Support\BotNames (do przywracania). */
    public function defaultName(): ?string
    {
        return BotNames::forSlot($this->sort_order);
    }

    /** Czy admin zmienił nazwę względem domyślnej? */
    public function isRenamed(): bool
    {
        return $this->name !== $this->defaultName();
    }
}