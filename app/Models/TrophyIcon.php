<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ikona trofeum do gabloty (plik na dysku public, katalog trophies).
 *
 * @property string $key
 * @property string $path
 */
class TrophyIcon extends Model
{
    protected $fillable = ['key', 'path'];
}
