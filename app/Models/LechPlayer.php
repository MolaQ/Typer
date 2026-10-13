<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Zawodnik z kadry Lecha, do desygnowania przez graczy. Panel `dashboard/lech-squad`, start z `LechSquadSeeder`.
 *
 * @property int $id
 * @property string $name
 * @property int|null $number
 * @property PlayerPosition $position
 * @property bool $is_academy
 * @property string|null $nationality
 * @property PlayerStatus $status
 */
class LechPlayer extends Model
{
    protected $fillable = ['name', 'number', 'position', 'is_academy', 'nationality', 'status'];

    protected $attributes = ['status' => 'regular', 'is_academy' => false];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'position' => PlayerPosition::class,
            'is_academy' => 'boolean',
            'status' => PlayerStatus::class,
        ];
    }
}
