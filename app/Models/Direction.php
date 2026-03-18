<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Direction extends Model
{
    use HasFactory;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'libelle',
        'actif',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'direction_id');
    }

    public function paos(): HasMany
    {
        return $this->hasMany(Pao::class, 'direction_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'direction_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'direction_id');
    }

    public function pasConcernes(): BelongsToMany
    {
        return $this->belongsToMany(
            Pas::class,
            'pas_directions',
            'direction_id',
            'pas_id'
        )->withTimestamps();
    }
}
