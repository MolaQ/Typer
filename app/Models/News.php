<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * News na stronie głównej, pisany w panelu (uprawnienie news-create). Bez daty publikacji to szkic.
 *
 * @property string $title
 * @property string $body
 * @property \Carbon\CarbonInterface|null $published_at
 */
class News extends Model
{
    protected $table = 'news';

    protected $fillable = ['user_id', 'title', 'body', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(NewsVote::class);
    }

    /** Liczniki ocen: up_count i down_count. */
    public function scopeWithVoteCounts(Builder $query): Builder
    {
        return $query->withCount([
            'votes as up_count' => fn ($q) => $q->where('value', 1),
            'votes as down_count' => fn ($q) => $q->where('value', -1),
        ]);
    }

    /**
     * Ocena zalogowanego: ten sam kciuk drugi raz cofa ocenę, drugi kciuk ją zmienia.
     */
    public function toggleVote(int $userId, int $value): void
    {
        $existing = $this->votes()->where('user_id', $userId)->first();

        if ($existing && (int) $existing->value === $value) {
            $existing->delete();

            return;
        }

        NewsVote::updateOrCreate(['news_id' => $this->id, 'user_id' => $userId], ['value' => $value]);
    }

    /** Opublikowane (data publikacji minęła). */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
