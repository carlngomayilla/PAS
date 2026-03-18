<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionWeek extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'numero_semaine',
        'date_debut',
        'date_fin',
        'est_renseignee',
        'quantite_realisee',
        'quantite_cumulee',
        'taches_realisees',
        'avancement_estime',
        'commentaire',
        'difficultes',
        'mesures_correctives',
        'progression_reelle',
        'progression_theorique',
        'ecart_progression',
        'saisi_par',
        'saisi_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'est_renseignee' => 'boolean',
            'quantite_realisee' => 'decimal:4',
            'quantite_cumulee' => 'decimal:4',
            'avancement_estime' => 'decimal:2',
            'progression_reelle' => 'decimal:2',
            'progression_theorique' => 'decimal:2',
            'ecart_progression' => 'decimal:2',
            'saisi_le' => 'datetime',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function justificatifs(): HasMany
    {
        return $this->hasMany(Justificatif::class, 'action_week_id')
            ->where('justifiable_type', Action::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ActionLog::class, 'action_week_id');
    }
}
