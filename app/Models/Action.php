<?php

namespace App\Models;

use App\Services\ActionPerformanceService;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class Action extends Model
{
    use HasFactory, SoftDeletes;

    public const CONTEXT_PILOTAGE = 'pilotage';
    public const CONTEXT_OPERATIONNEL = 'operationnel';

    public const MODE_SOUS_ACTIONS = 'sous_actions';
    public const MODE_QUANTITATIF = 'quantitatif';
    public const MODE_MIXTE = 'mixte';

    public const ORIGIN_PAS = 'PAS';
    public const ORIGIN_PAO = 'PAO';
    public const ORIGIN_PTA = 'PTA';
    public const ORIGIN_INTERNE = 'INTERNE';

    public const FINANCEMENT_NON_REQUIS = 'non_requis';
    public const FINANCEMENT_EN_ATTENTE_DAF = 'en_attente_daf';
    public const FINANCEMENT_EN_COURS_ANALYSE = 'en_cours_analyse';
    public const FINANCEMENT_APPROUVE = 'approuve';
    public const FINANCEMENT_REJETE = 'rejete';
    public const FINANCEMENT_FINANCE = 'finance';
    public const FINANCEMENT_NON_FINANCE = 'non_finance';

    public const FINANCEMENT_A_TRAITER_DAF = self::FINANCEMENT_EN_ATTENTE_DAF;
    public const FINANCEMENT_VALIDE_DAF = self::FINANCEMENT_APPROUVE;
    public const FINANCEMENT_REJETE_DAF = self::FINANCEMENT_REJETE;
    public const FINANCEMENT_ACCORDE_DG = self::FINANCEMENT_FINANCE;
    public const FINANCEMENT_REFUSE_DG = self::FINANCEMENT_NON_FINANCE;

    /**
     * @var list<string>
     */
    protected $appends = [
        'status_label',
        'validation_status_label',
        'financement_status_label',
    ];

    /**
     * @var list<string>
     */
    /**
     * Champs mass-assignables : UNIQUEMENT les champs saisis par l utilisateur
     * dans un formulaire (definition metier d une action).
     *
     * Sont volontairement EXCLUS (cf. A02 mass-assignment) : les statuts, le workflow
     * de validation, les traces utilisateurs (valide_par, cloture_par, etc.),
     * les calculs automatiques (taux_*, progression_*) et le workflow financement
     * DAF/DG. Ces champs doivent etre poses uniquement via les services / endpoints
     * dedies (ActionTrackingService, *_workflow controllers) et utilisent forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [
        'exercice_id',
        'pta_id',
        'pao_id',
        'objectif_operationnel_id',
        'unite_dg_id',
        'mode_evaluation',
        'libelle',
        'description',
        'type_cible',
        'intitule_cible',
        'priorite',
        'unite_cible',
        'quantite_cible',
        'seuil_minimum',
        'seuil_mode',
        'seuil_t1',
        'seuil_t2',
        'seuil_t3',
        'seuil_t4',
        'methode_calcul',
        'justificatif_obligatoire',
        'echeance_cible',
        'resultat_attendu',
        'indicateurs_attendus',
        'observations',
        'criteres_validation',
        'livrable_attendu',
        'date_debut',
        'date_fin',
        'frequence_execution',
        'date_echeance',
        'responsable_id',
        'contexte_action',
        'origine_action',
        'seuil_alerte_progression',
        'risque_lie',
        'risques',
        'risque_potentiel',
        'niveau_risque',
        'impact_estime',
        'probabilite',
        'mesures_preventives',
        'financement_requis',
        'ressource_main_oeuvre',
        'ressources_humaines',
        'ressource_equipement',
        'ressources_materielles',
        'ressource_partenariat',
        'ressources_techniques',
        'ressource_autres',
        'ressource_autres_details',
        'description_financement',
        'ressources_financieres',
        'ressources_necessaires',
        'ressources_details',
        'source_financement',
        'observation_financement',
        'montant_estime',
        'nature_financement',
        'commentaire_financement',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'mode_evaluation' => 'string',
            'exercice_id' => 'integer',
            'date_fin' => 'date',
            'date_fin_reelle' => 'date',
            'date_echeance' => 'date',
            'echeance_cible' => 'date',
            'quantite_cible' => 'decimal:4',
            'quantite_realisee' => 'decimal:4',
            'seuil_minimum' => 'decimal:2',
            'seuil_t1' => 'decimal:2',
            'seuil_t2' => 'decimal:2',
            'seuil_t3' => 'decimal:2',
            'seuil_t4' => 'decimal:2',
            'justificatif_obligatoire' => 'boolean',
            'reste_a_realiser' => 'decimal:4',
            'taux_depassement' => 'decimal:2',
            'progression_reelle' => 'decimal:2',
            'progression_theorique' => 'decimal:2',
            'seuil_alerte_progression' => 'decimal:2',
            'financement_requis' => 'boolean',
            'ressource_main_oeuvre' => 'boolean',
            'ressource_equipement' => 'boolean',
            'ressource_partenariat' => 'boolean',
            'ressource_autres' => 'boolean',
            'ressources_necessaires' => 'array',
            'montant_estime' => 'decimal:2',
            'financement_soumis_le' => 'datetime',
            'financement_notifie_le' => 'datetime',
            'financement_daf_le' => 'datetime',
            'financement_montant_valide' => 'decimal:2',
            'financement_dg_le' => 'datetime',
            'validation_hierarchique' => 'boolean',
            'validation_sans_correction' => 'boolean',
            'soumise_le' => 'datetime',
            'evalue_le' => 'datetime',
            'evaluation_note' => 'decimal:2',
            'taux_valide_chef' => 'decimal:2',
            // Casts direction_* retires : colonnes supprimees par la migration
            // 2026_05_22_100000_drop_direction_validation_columns.
            'cloture_le' => 'datetime',
            'taux_performance' => 'decimal:2',
            'taux_conformite' => 'decimal:2',
            'taux_delai' => 'decimal:2',
            'taux_realisation_global' => 'decimal:2',
            'avancement_operationnel' => 'decimal:2',
            'taux_atteinte_cible' => 'decimal:2',
            'taux_global' => 'decimal:2',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function pta(): BelongsTo
    {
        return $this->belongsTo(Pta::class, 'pta_id');
    }

    public function pao(): BelongsTo
    {
        return $this->belongsTo(Pao::class, 'pao_id');
    }

    public function objectifOperationnel(): BelongsTo
    {
        return $this->belongsTo(ObjectifOperationnel::class, 'objectif_operationnel_id');
    }

    public function uniteDg(): BelongsTo
    {
        return $this->belongsTo(UniteDg::class, 'unite_dg_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function responsables(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'action_responsables', 'action_id', 'user_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function rmos(): BelongsToMany
    {
        return $this->responsables();
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

    public function sousActions(): HasMany
    {
        return $this->hasMany(SousAction::class, 'action_id');
    }

    public function subTasks(): HasMany
    {
        return $this->sousActions();
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

    public function recalculateRealization(): void
    {
        $this->loadMissing('sousActions');

        $performanceService = app(ActionPerformanceService::class);
        $target = max(0.0, (float) ($this->quantite_cible ?? 0));
        $subActions = $this->sousActions;
        $realized = $performanceService->realizedQuantity($this);

        $rawRealizationRate = $target > 0.0 ? ($realized / $target) * 100 : 0.0;
        $realizationRate = round(min(100.0, max(0.0, $rawRealizationRate)), 2);
        $overachievementRate = $target > 0.0 && $realized > $target
            ? round((($realized - $target) / $target) * 100, 2)
            : 0.0;
        $remainingValue = round(max($target - $realized, 0.0), 4);

        $totalSubActions = $subActions->count();
        $completedSubActions = $subActions
            ->filter(fn (SousAction $subAction): bool => $performanceService->isCompletedSubAction($subAction))
            ->count();
        $technicalProgressRate = $totalSubActions > 0
            ? round(($completedSubActions / $totalSubActions) * 100, 2)
            : 0.0;

        $progression = $performanceService->calculateRealProgress($this);

        $updates = [
            'quantite_realisee' => round($realized, 4),
            'taux_atteinte_cible' => $realizationRate,
            'avancement_operationnel' => $technicalProgressRate,
            'taux_global' => $progression,
            'progression_reelle' => $progression,
            'taux_realisation_global' => $progression,
        ];

        $optionalColumns = [
            'reste_a_realiser' => $remainingValue,
            'taux_depassement' => $overachievementRate,
            'statut_performance' => $this->resolvePerformanceStatus(
                $target > 0.0 ? $realizationRate : $technicalProgressRate,
                $overachievementRate,
                $target > 0.0 || $totalSubActions > 0
            ),
            'statut_execution_quantitative' => $this->resolveQuantitativeExecutionStatus($rawRealizationRate),
        ];

        foreach ($optionalColumns as $column => $value) {
            if (Schema::hasColumn($this->getTable(), $column)) {
                $updates[$column] = $value;
            }
        }

        $this->forceFill($updates)->saveQuietly();
    }

    public function resolvePerformanceStatus(float $realizationRate, float $overachievementRate = 0.0, bool $hasPerformanceBasis = true): string
    {
        if (! $hasPerformanceBasis) {
            return 'non_evaluee';
        }

        if ($overachievementRate > 0.0) {
            return 'cible_depassee';
        }

        if ($realizationRate < 50.0) {
            return 'critique';
        }

        $threshold = $this->performanceThreshold();
        if ($realizationRate < $threshold) {
            return 'sous_seuil';
        }

        if ($realizationRate < 100.0) {
            return 'acceptable';
        }

        return $this->isCompletedAfterDeadline() ? 'satisfaisante' : 'excellente';
    }

    public function performanceThreshold(): float
    {
        $fallback = max(0.0, (float) ($this->seuil_minimum ?? 80));

        if (($this->seuil_mode ?? 'unique') !== 'trimestriel') {
            return $fallback;
        }

        $referenceDate = $this->date_fin_reelle ?? $this->cloture_le ?? now();
        $quarter = max(1, min(4, (int) $referenceDate->quarter));
        $column = 'seuil_t'.$quarter;
        $value = $this->{$column} ?? null;

        return $value !== null ? max(0.0, (float) $value) : $fallback;
    }

    public function resolveQuantitativeExecutionStatus(float $rawRealizationRate): string
    {
        if ($rawRealizationRate <= 0.0) {
            return 'non_demarre';
        }

        if ($rawRealizationRate < 50.0) {
            return 'faible_avancement';
        }

        if ($rawRealizationRate < 80.0) {
            return 'en_progression';
        }

        if ($rawRealizationRate < 100.0) {
            return 'presque_atteinte';
        }

        if ($rawRealizationRate > 100.0) {
            return 'cible_depassee';
        }

        return 'cible_atteinte';
    }

    private function isCompletedAfterDeadline(): bool
    {
        $deadline = $this->echeance_cible ?? $this->date_echeance ?? $this->date_fin;
        if ($deadline === null) {
            return false;
        }

        $completedAt = $this->date_fin_reelle ?? $this->cloture_le ?? null;
        if ($completedAt === null) {
            return false;
        }

        return $completedAt->gt($deadline);
    }

    public function soumisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumise_par');
    }

    public function evaluePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evalue_par');
    }

    // Relation directionValidePar retiree : colonne direction_valide_par
    // supprimee par la migration de purge de la validation direction.

    public function financementDafPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financement_daf_par');
    }

    public function financementDgPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financement_dg_par');
    }

    public function clotureePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par');
    }

    public function computeTauxRealisation(): float
    {
        return app(ActionPerformanceService::class)->calculateExecutionPerformance($this);
    }

    /**
     * A17 — Source UNIQUE de verite pour la progression d une action.
     *
     * Conventions des colonnes stockees (synchronisees par recalculateRealization
     * et par ActionTrackingService::refreshActionMetrics) :
     *   - progression_reelle         : valeur autoritaire (taux global %)
     *   - progression_theorique      : taux attendu a la date courante
     *   - taux_realisation_global    : alias historique = progression_reelle
     *   - taux_global                : alias historique = progression_reelle
     *   - taux_atteinte_cible        : taux quantitatif quantite_realisee/cible
     *   - avancement_operationnel    : taux sous-actions completees / total
     *   - taux_performance           : KPI execution (different : pondere delai/conformite)
     *   - taux_delai                 : KPI delai pur
     *   - taux_conformite            : KPI conformite
     *
     * **Le code metier doit consommer authoritativeProgress() ci-dessous** :
     * en cas de drift entre les colonnes (race condition, recalcul partiel), on
     * privilegie progression_reelle calcule en live par ActionProgressService.
     */
    public function authoritativeProgress(): float
    {
        $live = app(ActionPerformanceService::class)
            ->calculateRealProgress($this);

        return round(max(0.0, min(100.0, $live)), 2);
    }

    public function isCloturee(): bool
    {
        return $this->statut_dynamique === 'cloturee';
    }

    public function scopeForPilotage(Builder $query): void
    {
        $query->where('contexte_action', self::CONTEXT_PILOTAGE);
    }

    public function scopeForOperationnel(Builder $query): void
    {
        $query->where('contexte_action', self::CONTEXT_OPERATIONNEL);
    }

    public function scopeForContext(Builder $query, string $context): void
    {
        $query->where('contexte_action', $context);
    }

    public function scopeWithFinancingRequired(Builder $query): void
    {
        $query->where('financement_requis', true);
    }

    public function scopeForResponsable(Builder $query, int $userId): void
    {
        $query->where(function (Builder $scopedQuery) use ($userId): void {
            $scopedQuery->where('responsable_id', $userId);

            if (\App\Support\SchemaIntrospectionCache::hasTable('action_responsables')) {
                $scopedQuery->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey($userId));
            }
        });
    }

    public function scopeWithDynamicStatus(Builder $query, string $status): void
    {
        $query->where('statut_dynamique', $status);
    }

    /**
     * @return array<string, string>
     */
    public static function contextOptions(): array
    {
        return [
            self::CONTEXT_PILOTAGE => 'Pilotage',
            self::CONTEXT_OPERATIONNEL => 'Opérationnel',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function originOptions(): array
    {
        return [
            self::ORIGIN_PAS => 'PAS',
            self::ORIGIN_PAO => 'PAO',
            self::ORIGIN_PTA => 'PTA',
            self::ORIGIN_INTERNE => 'Interne',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function evaluationModeOptions(): array
    {
        return [
            self::MODE_QUANTITATIF => 'Cible quantitative',
            self::MODE_SOUS_ACTIONS => 'Cible par sous-action',
            self::MODE_MIXTE => 'Cible mixte',
        ];
    }

    public function resolvedEvaluationMode(): string
    {
        $mode = trim((string) ($this->mode_evaluation ?? ''));
        $legacyType = trim((string) ($this->type_cible ?? ''));

        if (array_key_exists($mode, self::evaluationModeOptions())) {
            return $mode;
        }

        if (in_array($legacyType, ['quantitative', 'quantitatif'], true)) {
            return self::MODE_QUANTITATIF;
        }

        if (in_array($legacyType, ['qualitative', 'qualitatif'], true)) {
            return self::MODE_SOUS_ACTIONS;
        }

        return (filled($this->quantite_cible) || filled($this->unite_cible))
            ? self::MODE_QUANTITATIF
            : self::MODE_SOUS_ACTIONS;
    }

    public function isQuantitativeOnlyTracking(): bool
    {
        return $this->resolvedEvaluationMode() === self::MODE_QUANTITATIF;
    }

    public function isQualitativeOnlyTracking(): bool
    {
        return $this->resolvedEvaluationMode() === self::MODE_SOUS_ACTIONS;
    }

    public function isMixedTracking(): bool
    {
        return $this->resolvedEvaluationMode() === self::MODE_MIXTE;
    }

    public function usesStructuredProgressTracking(): bool
    {
        if ($this->mode_evaluation !== null && $this->mode_evaluation !== '') {
            return true;
        }

        if ($this->relationLoaded('sousActions')) {
            return $this->sousActions->isNotEmpty();
        }

        return $this->sousActions()->exists();
    }

    public function usesSubTasksProgress(): bool
    {
        return in_array($this->resolvedEvaluationMode(), [self::MODE_SOUS_ACTIONS, self::MODE_MIXTE], true);
    }

    public function usesQuantitativeProgress(): bool
    {
        return in_array($this->resolvedEvaluationMode(), [self::MODE_QUANTITATIF, self::MODE_MIXTE], true);
    }

    public function getModeEvaluationLabelAttribute(): string
    {
        return self::evaluationModeOptions()[$this->resolvedEvaluationMode()] ?? 'Par sous-actions';
    }

    /**
     * @return array<string, string>
     */
    public static function financingStatusOptions(): array
    {
        return [
            self::FINANCEMENT_NON_REQUIS => 'Non requis',
            self::FINANCEMENT_EN_ATTENTE_DAF => 'En attente DAF',
            self::FINANCEMENT_EN_COURS_ANALYSE => 'En cours d analyse',
            self::FINANCEMENT_APPROUVE => 'Approuve',
            self::FINANCEMENT_REJETE => 'Rejete',
            self::FINANCEMENT_FINANCE => 'Finance',
            self::FINANCEMENT_NON_FINANCE => 'Non finance',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function legacyFinancingStatusMap(): array
    {
        return [
            'a_traiter_daf' => self::FINANCEMENT_EN_ATTENTE_DAF,
            'valide_daf' => self::FINANCEMENT_APPROUVE,
            'rejete_daf' => self::FINANCEMENT_REJETE,
            'accorde_dg' => self::FINANCEMENT_FINANCE,
            'refuse_dg' => self::FINANCEMENT_NON_FINANCE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function resourceOptions(): array
    {
        return [
            'main_oeuvre' => 'Main-d oeuvre',
            'ressources_humaines' => 'Ressources humaines',
            'ressources_informatiques' => 'Ressources informatiques',
            'ressources_materielles' => 'Ressources materielles',
            'ressources_documentaires' => 'Ressources documentaires',
            'partenariat' => 'Partenariat',
            'autres_ressources' => 'Autres ressources',
        ];
    }

    /**
     * @return list<string>
     */
    public function resourceLabels(): array
    {
        $options = self::resourceOptions();
        $selected = collect($this->ressources_necessaires ?? [])
            ->filter(fn ($value): bool => is_string($value) && array_key_exists($value, $options))
            ->map(fn (string $value): string => $options[$value])
            ->values()
            ->all();

        if ($selected !== []) {
            return $selected;
        }

        return $this->legacyResourceLabels($options);
    }

    /**
     * A23 — Lecture des resources via les anciennes colonnes booleennes.
     *
     * Source de verite CANONIQUE = `ressources_necessaires` (array JSON).
     * Les colonnes booleennes `ressource_main_oeuvre`, `ressource_equipement`,
     * `ressource_partenariat`, `ressource_autres` sont DEPRECATED et restent
     * UNIQUEMENT pour la retrocompatibilite des fixtures historiques.
     *
     * Toute nouvelle ecriture DOIT passer par `ressources_necessaires`.
     * Une migration future pourra supprimer les booleens une fois que
     * `ressources_necessaires` est rempli pour 100 % des lignes (cf. plan
     * Phase 3 du rapport d audit).
     *
     * @param array<string, string> $options
     * @return list<string>
     */
    public function legacyResourceLabels(array $options): array
    {
        $legacy = [];
        if ((bool) $this->ressource_main_oeuvre) {
            $legacy[] = $options['main_oeuvre'];
        }
        if ((bool) $this->ressource_equipement) {
            $legacy[] = $options['ressources_materielles'];
        }
        if ((bool) $this->ressource_partenariat) {
            $legacy[] = 'Partenariat';
        }
        if ((bool) $this->ressource_autres) {
            $legacy[] = $options['autres_ressources'];
        }

        return $legacy;
    }

    /**
     * A23 — Aligne `ressources_necessaires` (canonique) avec les booleens
     * legacy si ils sont les seuls remplis. A appeler avant un export ou un
     * agregat pour garantir la coherence.
     *
     * Ne sauvegarde pas en BD : retourne la liste canonique attendue.
     *
     * @return list<string>
     */
    public function canonicalResources(): array
    {
        $selected = collect($this->ressources_necessaires ?? [])
            ->filter(fn ($value): bool => is_string($value) && array_key_exists($value, self::resourceOptions()))
            ->map(fn (string $value): string => $value)
            ->values()
            ->all();

        if ($selected !== []) {
            return $selected;
        }

        // Fallback : on derive depuis les booleens legacy.
        $derived = [];
        if ((bool) $this->ressource_main_oeuvre) {
            $derived[] = 'main_oeuvre';
        }
        if ((bool) $this->ressource_equipement) {
            $derived[] = 'ressources_materielles';
        }
        if ((bool) $this->ressource_partenariat) {
            $derived[] = 'partenariat';
        }
        if ((bool) $this->ressource_autres) {
            $derived[] = 'autres_ressources';
        }

        return $derived;
    }

    public function financementStatus(): string
    {
        if (! (bool) $this->financement_requis) {
            return self::FINANCEMENT_NON_REQUIS;
        }

        $status = trim((string) ($this->financement_statut ?? ''));
        $status = self::legacyFinancingStatusMap()[$status] ?? $status;

        return array_key_exists($status, self::financingStatusOptions())
            ? $status
            : self::FINANCEMENT_A_TRAITER_DAF;
    }

    public function getFinancementStatusLabelAttribute(): string
    {
        return self::financingStatusOptions()[$this->financementStatus()] ?? 'A traiter DAF';
    }

    public function isFundingRequested(): bool
    {
        return (bool) $this->financement_requis
            && $this->financementStatus() !== self::FINANCEMENT_NON_REQUIS;
    }

    public function isFundingApprovedByDg(): bool
    {
        return $this->financementStatus() === self::FINANCEMENT_ACCORDE_DG;
    }

    public function isOperationalContext(): bool
    {
        return (string) ($this->contexte_action ?? self::CONTEXT_PILOTAGE) === self::CONTEXT_OPERATIONNEL;
    }

    public function isResponsible(User $user): bool
    {
        if ((int) ($this->responsable_id ?? 0) === (int) $user->id) {
            return true;
        }

        if (! \App\Support\SchemaIntrospectionCache::hasTable('action_responsables')) {
            return false;
        }

        if ($this->relationLoaded('responsables')) {
            return $this->responsables->contains('id', (int) $user->id);
        }

        return $this->responsables()->whereKey((int) $user->id)->exists();
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
