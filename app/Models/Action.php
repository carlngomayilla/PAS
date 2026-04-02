<?php

namespace App\Models;

use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Action extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $appends = [
        'status_label',
        'validation_status_label',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pta_id',
        'libelle',
        'description',
        'type_cible',
        'unite_cible',
        'quantite_cible',
        'resultat_attendu',
        'criteres_validation',
        'livrable_attendu',
        'date_debut',
        'date_fin',
        'frequence_execution',
        'date_fin_reelle',
        'date_echeance',
        'responsable_id',
        'statut',
        'statut_dynamique',
        'progression_reelle',
        'progression_theorique',
        'seuil_alerte_progression',
        'risques',
        'mesures_preventives',
        'financement_requis',
        'ressource_main_oeuvre',
        'ressource_equipement',
        'ressource_partenariat',
        'ressource_autres',
        'ressource_autres_details',
        'description_financement',
        'source_financement',
        'montant_estime',
        'rapport_final',
        'validation_hierarchique',
        'validation_sans_correction',
        'statut_validation',
        'soumise_par',
        'soumise_le',
        'evalue_par',
        'evalue_le',
        'evaluation_note',
        'evaluation_commentaire',
        'direction_valide_par',
        'direction_valide_le',
        'direction_evaluation_note',
        'direction_evaluation_commentaire',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'date_fin_reelle' => 'date',
            'date_echeance' => 'date',
            'quantite_cible' => 'decimal:4',
            'progression_reelle' => 'decimal:2',
            'progression_theorique' => 'decimal:2',
            'seuil_alerte_progression' => 'decimal:2',
            'financement_requis' => 'boolean',
            'ressource_main_oeuvre' => 'boolean',
            'ressource_equipement' => 'boolean',
            'ressource_partenariat' => 'boolean',
            'ressource_autres' => 'boolean',
            'montant_estime' => 'decimal:2',
            'validation_hierarchique' => 'boolean',
            'validation_sans_correction' => 'boolean',
            'soumise_le' => 'datetime',
            'evalue_le' => 'datetime',
            'evaluation_note' => 'decimal:2',
            'direction_valide_le' => 'datetime',
            'direction_evaluation_note' => 'decimal:2',
        ];
    }

    public function pta(): BelongsTo
    {
        return $this->belongsTo(Pta::class, 'pta_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(Kpi::class, 'action_id');
    }

    public function primaryKpi(): HasOne
    {
        return $this->hasOne(Kpi::class, 'action_id')->orderBy('id');
    }

    public function justificatifs(): MorphMany
    {
        return $this->morphMany(Justificatif::class, 'justifiable');
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ActionWeek::class, 'action_id');
    }

    public function actionKpi(): HasOne
    {
        return $this->hasOne(ActionKpi::class, 'action_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class, 'action_id');
    }

    public function discussionEntries(): HasMany
    {
        return $this->hasMany(ActionLog::class, 'action_id')
            ->whereIn('type_evenement', [
                'commentaire',
                'action_soumise_validation',
                'action_validee_chef',
                'action_rejetee_chef',
                'action_validee_direction',
                'action_rejetee_direction',
            ]);
    }

    public function soumisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumise_par');
    }

    public function evaluePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evalue_par');
    }

    public function directionValidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direction_valide_par');
    }

    public function getStatusLabelAttribute(): string
    {
        return UiLabel::actionStatus($this->statut_dynamique ?: $this->statut);
    }

    public function getValidationStatusLabelAttribute(): string
    {
        return UiLabel::validationStatus($this->statut_validation);
    }
}
