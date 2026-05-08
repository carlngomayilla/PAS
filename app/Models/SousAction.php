<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SousAction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'agent_id',
        'libelle',
        'description',
        'resultat_attendu',
        'cible_prevue',
        'quantite_realisee',
        'unite',
        'resultat_obtenu',
        'taux_realisation',
        'commentaire',
        'date_debut',
        'date_fin',
        'date_realisation',
        'completed_at',
        'statut',
        'est_effectuee',
        'taux_execution',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'date_realisation' => 'datetime',
            'completed_at' => 'datetime',
            'est_effectuee' => 'boolean',
            'taux_execution' => 'decimal:2',
            'cible_prevue' => 'decimal:4',
            'quantite_realisee' => 'decimal:4',
            'taux_realisation' => 'decimal:2',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (SousAction $sousAction): void {
            $sousAction->action?->recalculateRealization();
        });

        static::deleted(function (SousAction $sousAction): void {
            $sousAction->action?->recalculateRealization();
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function justificatifs(): HasMany
    {
        return $this->hasMany(Justificatif::class, 'sous_action_id');
    }
}
