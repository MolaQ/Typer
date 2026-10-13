<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Enums\QuestionData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Szablon pytania o zawodnika Lecha, np. „Czy :zawodnik dostanie żółtą kartkę?” (bank pytań 2.0, etap 2).
 * Pytania z szablonu tworzy i aktualizuje App\Support\QuestionTemplates. Panel `dashboard/question-templates`.
 *
 * @property int $id
 * @property string $text
 * @property int|null $category_id
 * @property array<int, string>|null $positions wartości PlayerPosition, pusta = wszystkie
 * @property array<int, string>|null $match_types
 * @property array<int, string>|null $weights
 * @property QuestionData $data_level
 * @property bool $is_active
 */
class QuestionTemplate extends Model
{
    /** Znacznik w treści zastępowany imieniem i nazwiskiem zawodnika. */
    public const PLACEHOLDER = ':zawodnik';

    protected $fillable = ['text', 'category_id', 'positions', 'match_types', 'weights', 'data_level', 'is_active'];

    protected $attributes = ['is_active' => true, 'data_level' => 'basic'];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'match_types' => 'array',
            'weights' => 'array',
            'data_level' => QuestionData::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<QuestionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class, 'category_id');
    }

    /** @return HasMany<Question, $this> pytania utworzone z szablonu */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /** Treść pytania dla zawodnika. */
    public function textFor(LechPlayer $player): string
    {
        return str_replace(self::PLACEHOLDER, $player->name, $this->text);
    }

    /** Czy szablon dotyczy pozycji zawodnika (pusta lista pozycji = wszystkie). */
    public function fits(LechPlayer $player): bool
    {
        return empty($this->positions) || in_array($player->position->value, $this->positions, true);
    }

    /** @return array<int, PlayerPosition> */
    public function positionCases(): array
    {
        return array_values(array_filter(array_map(fn ($v) => PlayerPosition::tryFrom((string) $v), $this->positions ?? [])));
    }
}
