<?php

namespace App\Models;

use App\Enums\TeamNameRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wniosek gracza o zmianę nazwy zespołu, zatwierdzany przez admina w panelu (dashboard/team-requests).
 */
class TeamNameChangeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'current_team_name',
        'requested_team_name',
        'status',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TeamNameRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TeamNameRequestStatus::Pending->value);
    }
}
