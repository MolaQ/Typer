<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Sponsor rozgrywek (przypisywany do rozgrywek w danym sezonie w panelu „Sponsorzy”).
 *
 * @property string $name
 * @property string|null $url
 * @property string|null $logo_path
 */
class Sponsor extends Model
{
    protected $fillable = ['name', 'url', 'logo_path'];

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
