<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaoObjectifStrategique extends Model
{
    use HasFactory;

    protected $table = 'pao_objectifs_strategiques';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pao_axe_id',
        'code',
        'libelle',
        'description',
        'echeance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'echeance' => 'date',
        ];
    }

    public function paoAxe(): BelongsTo
    {
        return $this->belongsTo(PaoAxe::class, 'pao_axe_id');
    }

    public function objectifsOperationnels(): HasMany
    {
        return $this->hasMany(
            PaoObjectifOperationnel::class,
            'pao_objectif_strategique_id'
        );
    }
}
