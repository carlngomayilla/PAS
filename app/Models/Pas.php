<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pas extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'pas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'titre',
        'periode_debut',
        'periode_fin',
        'statut',
        'created_by',
        'valide_le',
        'valide_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periode_debut' => 'integer',
            'periode_fin' => 'integer',
            'valide_le' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function axes(): HasMany
    {
        return $this->hasMany(PasAxe::class, 'pas_id');
    }

    public function paos(): HasMany
    {
        return $this->hasMany(Pao::class, 'pas_id');
    }

    public function directions(): BelongsToMany
    {
        return $this->belongsToMany(
            Direction::class,
            'pas_directions',
            'pas_id',
            'direction_id'
        )->withTimestamps();
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
