<?php

namespace App\Models;

use App\Enums\TypeIndicateur;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    public const TYPE_MIXTE = 'mixte';

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
        'type_indicateur',
        'weight',
        'requires_proof',
        'requires_comment',
        'allows_difficulty',
        'official_progress_percent',
        'validation_status',
        'description',
        'resultat_attendu',
        'cible',
        'quantite_a_realiser',
        'seuil_minimum',
        'livrable_attendu',
        'cible_prevue',
        'quantite_realisee',
        'unite',
        'resultat_obtenu',
        'taux_realisation',
        'commentaire',
        'date_debut',
        'date_fin',
        'statut_echeance',
        'statut_retard',
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
            'statut_echeance' => 'string',
            'statut_retard' => 'string',
            'date_realisation' => 'datetime',
            'completed_at' => 'datetime',
            'est_effectuee' => 'boolean',
            'taux_execution' => 'decimal:2',
            'cible_prevue' => 'decimal:4',
            'quantite_realisee' => 'decimal:4',
            'taux_realisation' => 'decimal:2',
            // Workflow V2
            'sub_action_type' => 'string',
            'type_indicateur' => 'string',
            'weight' => 'decimal:2',
            'quantite_a_realiser' => 'decimal:4',
            'seuil_minimum' => 'decimal:2',
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
        if (in_array($type, [self::TYPE_QUANTITATIVE, self::TYPE_NON_QUANTITATIVE, self::TYPE_MIXTE], true)) {
            return $type;
        }

        $typeIndicateur = trim((string) ($this->attributes['type_indicateur'] ?? ''));
        if ($typeIndicateur !== '') {
            return match (TypeIndicateur::fromLegacy($typeIndicateur)) {
                TypeIndicateur::Quantitatif => self::TYPE_QUANTITATIVE,
                TypeIndicateur::Mixte => self::TYPE_MIXTE,
                TypeIndicateur::NonQuantitatif => self::TYPE_NON_QUANTITATIVE,
            };
        }

        $hasQuantity = filled($this->cible_prevue) && (float) $this->cible_prevue > 0;
        $hasDeliverable = trim((string) ($this->livrable_attendu ?? $this->resultat_attendu ?? $this->description ?? '')) !== '';

        if ($hasQuantity && $hasDeliverable) {
            return self::TYPE_MIXTE;
        }

        return $hasQuantity ? self::TYPE_QUANTITATIVE : self::TYPE_NON_QUANTITATIVE;
    }

    public function resolvedTypeIndicateur(): TypeIndicateur
    {
        $typeIndicateur = trim((string) ($this->attributes['type_indicateur'] ?? ''));
        if ($typeIndicateur !== '') {
            return TypeIndicateur::fromLegacy($typeIndicateur);
        }

        return TypeIndicateur::fromLegacy($this->resolvedType());
    }

    public function isQuantitative(): bool
    {
        return $this->resolvedTypeIndicateur() === TypeIndicateur::Quantitatif;
    }

    public function isMixedTarget(): bool
    {
        return $this->resolvedTypeIndicateur() === TypeIndicateur::Mixte;
    }

    public function tracksQuantitativeTarget(): bool
    {
        return $this->resolvedTypeIndicateur()->tracksQuantity()
            || (float) ($this->cible_prevue ?? 0) > 0.0;
    }

    public function tracksDeliverableTarget(): bool
    {
        return $this->resolvedTypeIndicateur()->tracksDeliverable()
            || trim((string) ($this->livrable_attendu ?? $this->resultat_attendu ?? $this->description ?? '')) !== '';
    }

    /**
     * @return Attribute<string, string>
     */
    protected function typeIndicateur(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => $value !== null && $value !== ''
                ? TypeIndicateur::fromLegacy($value)->value
                : $this->resolvedTypeIndicateur()->value,
            set: fn (?string $value): array => [
                'type_indicateur' => TypeIndicateur::fromLegacy($value)->value,
            ],
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function cible(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value ?: $this->firstFilledText([
                $this->resultat_attendu ?? null,
                $this->livrable_attendu ?? null,
                $this->description ?? null,
            ]),
            set: fn (?string $value): array => ['cible' => $value],
        );
    }

    /**
     * @return Attribute<?float, ?float>
     */
    protected function quantiteARealiser(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?float => $value !== null
                ? (float) $value
                : ($this->cible_prevue !== null ? (float) $this->cible_prevue : null),
            set: fn (mixed $value): array => ['quantite_a_realiser' => $value],
        );
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstFilledText(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
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
