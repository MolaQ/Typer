<?php

namespace App\Models;

use App\Enums\MatchWeight;
use App\Enums\QuestionData;
use App\Enums\QuestionMatchType;
use App\Enums\QuestionSide;
use App\Enums\QuestionSubject;
use App\Support\QuestionClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pytanie z banku (odpowiedź tak/nie). Bank pytań 2.0: kategoria (w zestawie najwyżej jedno pytanie z kategorii),
 * czego dotyczy, rodzaje meczów Lecha i wagi meczów, do których pasuje, oraz wymagane dane (podstawowe albo statystyki).
 * Losowanie bierze tylko aktywne pytania.
 *
 * @property int $id
 * @property string $text
 * @property QuestionSide $side
 * @property int|null $category_id
 * @property QuestionSubject $subject
 * @property array<int, string>|null $match_types wartości QuestionMatchType
 * @property array<int, string>|null $weights wartości MatchWeight
 * @property QuestionData $data_level
 * @property bool $is_active
 */
class Question extends Model
{
    protected $fillable = ['text', 'side', 'category_id', 'subject', 'match_types', 'weights', 'data_level', 'is_active'];

    protected $attributes = ['is_active' => true, 'subject' => 'lech', 'data_level' => 'basic'];

    protected function casts(): array
    {
        return [
            'side' => QuestionSide::class,
            'subject' => QuestionSubject::class,
            'data_level' => QuestionData::class,
            'match_types' => 'array',
            'weights' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** Nowe pytanie bez wyboru (np. z propozycji gracza) pasuje do wszystkich wag i do lig, PP i Europy (bez sparingu). */
    protected static function booted(): void
    {
        static::creating(function (Question $question): void {
            $question->match_types ??= array_values(array_diff(QuestionMatchType::values(), [QuestionMatchType::Friendly->value]));
            $question->weights ??= MatchWeight::values();
        });
    }

    /**
     * Wstępne pola banku 2.0 dla treści pytania (kategoria, dotyczy, dane, rodzaje meczów, wagi), np. dla propozycji gracza i seedera.
     *
     * @return array<string, mixed>
     */
    public static function guessAttributes(string $text): array
    {
        $data = QuestionClassifier::data($text);

        return [
            'category_id' => QuestionCategory::where('key', QuestionClassifier::category($text))->value('id'),
            'subject' => QuestionClassifier::subject($text)->value,
            'data_level' => $data->value,
            'match_types' => QuestionClassifier::matchTypes($data),
            'weights' => MatchWeight::values(),
        ];
    }

    /** Użycia w zestawach kolejek. */
    public function assignments(): HasMany
    {
        return $this->hasMany(MatchdayQuestion::class);
    }

    /** @return BelongsTo<QuestionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class, 'category_id');
    }

    /**
     * Pytania pasujące do rodzaju meczu (np. tylko sparingowe).
     *
     * @param  Builder<Question>  $query
     */
    public function scopeForMatchType(Builder $query, QuestionMatchType $type): void
    {
        $query->whereJsonContains('match_types', $type->value);
    }

    /**
     * Pytania pasujące do wagi meczu.
     *
     * @param  Builder<Question>  $query
     */
    public function scopeForWeight(Builder $query, MatchWeight $weight): void
    {
        $query->whereJsonContains('weights', $weight->value);
    }

    /** @return array<int, QuestionMatchType> */
    public function matchTypeCases(): array
    {
        return array_values(array_filter(array_map(fn ($v) => QuestionMatchType::tryFrom((string) $v), $this->match_types ?? [])));
    }

    /** @return array<int, MatchWeight> */
    public function weightCases(): array
    {
        return array_values(array_filter(array_map(fn ($v) => MatchWeight::tryFrom((string) $v), $this->weights ?? [])));
    }
}
