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

    // Workflow V2 — type de sous-action (cf. docs/WORKFLOW-SUIVI-V2.md).
    public const TYPE_QUANTITATIVE = 'quantitative';
    public const TYPE_NON_QUANTITATIVE = 'non_quantitative';

    // Statuts de validation (parallèle au statut de suivi).
    public const VALIDATION_NON_SOUMISE = 'non_soumise';
    public const VALIDATION_SOUMISE = 'soumise';
    public const VALIDATION_VALIDEE = 'validee';
    public const VALIDATION_REJETEE = 'rejetee';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'agent_id',
        'libelle',
        // Workflow V2
        'sub_action_type',
        'weight',
        'requires_proof',
        'requires_comment',
        'allows_difficulty',
        'official_progress_percent',
        'validation_status',
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
            // Workflow V2
            'sub_action_type' => 'string',
            'weight' => 'decimal:2',
            'requires_proof' => 'boolean',
            'requires_comment' => 'boolean',
            'allows_difficulty' => 'boolean',
            'official_progress_percent' => 'decimal:2',
            'validation_status' => 'string',
            'deleted_at' => 'datetime',
        ];
    }

    public function resolvedType(): string
    {
        $type = trim((string) ($this->sub_action_type ?? ''));
        if (in_array($type, [self::TYPE_QUANTITATIVE, self::TYPE_NON_QUANTITATIVE], true)) {
            return $type;
        }

        return (filled($this->cible_prevue) && (float) $this->cible_prevue > 0)
            ? self::TYPE_QUANTITATIVE
            : self::TYPE_NON_QUANTITATIVE;
    }

    public function isQuantitative(): bool
    {
        return $this->resolvedType() === self::TYPE_QUANTITATIVE;
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

    public function deadlineExtensionRequests(): HasMany
    {
        return $this->hasMany(DeadlineExtensionRequest::class, 'sous_action_id');
    }
}
