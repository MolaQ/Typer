<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategoria pytań bonusowych (gole, kartki, posiadanie…). W zestawie pytań kolejki może być
 * najwyżej jedno pytanie z danej kategorii (bank pytań 2.0, ustalenie P1). Lista do edycji w „Banku pytań”.
 *
 * @property int $id
 * @property string|null $key klucz kategorii domyślnej (do automatycznego przypisania), null = dodana w panelu
 * @property string $name
 * @property int $sort_order
 */
class QuestionCategory extends Model
{
    protected $fillable = ['key', 'name', 'sort_order'];

    /**
     * Kategorie domyślne (klucz => nazwa), tworzone w migracji.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'goals' => 'Gole',
        'match_flow' => 'Przebieg meczu',
        'cards' => 'Kartki',
        'possession' => 'Posiadanie',
        'shots' => 'Strzały',
        'corners' => 'Rzuty rożne',
        'fouls' => 'Faule i spalone',
        'penalties' => 'Rzuty karne i VAR',
        'substitutions' => 'Zmiany i rezerwowi',
        'players' => 'Zawodnicy',
        'goalkeeper' => 'Bramkarz i czyste konto',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'category_id');
    }
}
