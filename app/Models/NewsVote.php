<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ocena newsa przez zalogowanego gracza: 1 (kciuk w górę) albo -1 (kciuk w dół). */
class NewsVote extends Model
{
    protected $fillable = ['news_id', 'user_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
