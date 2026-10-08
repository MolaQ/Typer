<?php

namespace App\Models;

use App\Enums\QuestionSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pytanie z banku (odpowiedź tak/nie).
 *
 * @property string $text
 * @property QuestionSide $side
 * @property bool $is_active
 */
class Question extends Model
{
    protected $fillable = ['text', 'side', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'side' => QuestionSide::class,
            'is_active' => 'boolean',
        ];
    }

    /** Użycia w zestawach kolejek. */
    public function assignments(): HasMany
    {
        return $this->hasMany(MatchdayQuestion::class);
    }
}