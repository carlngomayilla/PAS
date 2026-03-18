<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PasObjectif extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'pas_objectifs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pas_axe_id',
        'code',
        'libelle',
        'description',
        'ordre',
        'indicateur_global',
        'valeur_cible',
        'valeurs_cible',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'valeurs_cible' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function pasAxe(): BelongsTo
    {
        return $this->belongsTo(PasAxe::class, 'pas_axe_id');
    }

    public function paos(): HasMany
    {
        return $this->hasMany(Pao::class, 'pas_objectif_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
