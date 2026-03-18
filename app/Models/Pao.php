<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pao extends Model
{
    use HasFactory;

    protected $table = 'paos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pas_id',
        'pas_objectif_id',
        'direction_id',
        'service_id',
        'annee',
        'titre',
        'echeance',
        'objectif_operationnel',
        'resultats_attendus',
        'indicateurs_associes',
        'statut',
        'valide_le',
        'valide_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'echeance' => 'date',
            'valide_le' => 'datetime',
        ];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function pas(): BelongsTo
    {
        return $this->belongsTo(Pas::class, 'pas_id');
    }

    public function pasObjectif(): BelongsTo
    {
        return $this->belongsTo(PasObjectif::class, 'pas_objectif_id');
    }

    public function axes(): HasMany
    {
        return $this->hasMany(PaoAxe::class, 'pao_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'pao_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
