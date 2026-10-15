<?php

namespace App\Models;

use App\Support\BotNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bot: zespół bez właściciela z nazwą edytowalną w panelu admina.
 * Pula 512 botów jest tworzona przez BotsSeeder.
 *
 * @property int $sort_order
 * @property string $name
 * @property bool $is_active
 */
class Bot extends Model
{
    protected $fillable = ['sort_order', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Aktywne są tylko boty z listy sezonu (pozostałe pauzują w puli, np. spoza 512 miejsc po awansach i spadkach).
     */
    public static function syncActive(int $seasonId): void
    {
        $listed = SeasonTeam::where('season_id', $seasonId)->whereNotNull('bot_id')->select('bot_id');

        self::query()->whereIn('id', $listed)->update(['is_active' => true]);
        self::query()->whereNotIn('id', $listed)->update(['is_active' => false]);
    }

    /**
     * Kolejność z puli przy włączaniu botów (dopełnienie listy do 512, miejsce zbanowanego): najpierw najlepsze,
     * czyli z największą sumą punktów Hall of Fame, potem według sort_order. Najsłabsze zostają w pauzie.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBestFirst($query): void
    {
        $query->orderByDesc(
            HallOfFameAward::query()->selectRaw('coalesce(sum(points), 0)')->whereColumn('hall_of_fame_awards.bot_id', 'bots.id')
        )->orderBy('sort_order');
    }

    /** Adres profilu /bots/{id}-{nazwa}, jak przy zespołach graczy. */
    public function getRouteKey(): string
    {
        return $this->getKey().'-'.Str::slug($this->name);
    }

    /** Z adresu liczy się tylko id na początku, więc linki działają też po zmianie nazwy. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->newQuery()->whereKey((int) $value)->first();
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
