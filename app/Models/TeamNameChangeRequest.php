<?php

namespace App\Models;

use App\Enums\TeamNameRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamNameChangeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'current_team_name',
        'current_team_short_name',
        'current_team_abbr',
        'requested_team_name',
        'requested_team_short_name',
        'requested_team_abbr',
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
