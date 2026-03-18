<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaoObjectifOperationnel extends Model
{
    use HasFactory;

    protected $table = 'pao_objectifs_operationnels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pao_objectif_strategique_id',
        'code',
        'libelle',
        'description_action_detaillee',
        'responsable_id',
        'cible_pourcentage',
        'date_debut',
        'date_fin',
        'statut_realisation',
        'ressources_requises',
        'indicateur_performance',
        'risques_potentiels',
        'echeance',
        'priorite',
        'progression_pourcentage',
        'date_realisation',
        'livrable_attendu',
        'contraintes',
        'dependances',
        'observations',
        'ordre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cible_pourcentage' => 'decimal:2',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'echeance' => 'date',
            'date_realisation' => 'date',
            'progression_pourcentage' => 'integer',
            'ordre' => 'integer',
        ];
    }

    public function objectifStrategique(): BelongsTo
    {
        return $this->belongsTo(
            PaoObjectifStrategique::class,
            'pao_objectif_strategique_id'
        );
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}

