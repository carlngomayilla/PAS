<?php

namespace App\Services;

use App\Enums\StatutRetard;
use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\Pta;
use App\Models\SousAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PtaHierarchyService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $actionResultCache = [];

    public function __construct(
        private readonly PtaOfficialCalculationService $officialCalculation
    ) {}

    /**
     * @return array{
     *     hierarchy:array<string, array<string, int|string|null>>,
     *     actions:list<array<string, mixed>>,
     *     summary:array<string, int|float>,
     *     anomalies:list<array{label:string,count:int}>
     * }
     */
    public function build(Pta $pta): array
    {
        $this->actionResultCache = [];

        $pta->load([
            'pao:id,code,pas_id,pas_objectif_id,direction_id,service_id,annee,titre,echeance,statut',
            'pao.pas:id,titre,periode_debut,periode_fin,statut',
            'pao.pasObjectif:id,pas_axe_id,code,libelle,date_echeance,indicateur_global,valeur_cible',
            'pao.pasObjectif.pasAxe:id,pas_id,code,libelle',
            'objectifOperationnel:id,code,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,libelle,description,echeance,indicateurs,statut',
            'objectifOperationnel.pasAxe:id,pas_id,code,libelle',
            'objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle,date_echeance,indicateur_global,valeur_cible',
            'direction:id,code,libelle',
            'service:id,direction_id,code,libelle',
            'validateur:id,name,email',
            'actions' => fn (Builder|Relation $query) => $query
                ->with([
                    'responsable:id,name,email',
                    'responsables:id,name,email',
                    'sousActions' => fn (Builder|Relation $subActionQuery) => $subActionQuery
                        ->with([
                            'agent:id,name,email',
                            'justificatifs:id,sous_action_id',
                        ])
                        ->orderBy('date_debut')
                        ->orderBy('date_fin')
                        ->orderBy('id'),
                    'justificatifs:id,justifiable_type,justifiable_id,categorie',
                    'deadlineExtensionRequests' => fn (Builder|Relation $requestQuery) => $requestQuery
                        ->orderByDesc('created_at')
                        ->orderByDesc('id'),
                ])
                ->orderByRaw('CASE WHEN ordre_import IS NULL THEN 1 ELSE 0 END')
                ->orderBy('ordre_import')
                ->orderByRaw('COALESCE(echeance_cible, date_echeance, date_fin) ASC')
                ->orderBy('id'),
        ]);

        $actions = $pta->actions;
        $officialRollup = $this->officialCalculation->targetWeighted(
            $actions->map(fn (Action $action): array => $this->actionResult($action)),
            'pta'
        );
        $actionNodes = $actions
            ->map(fn (Action $action): array => $this->actionNode($action))
            ->values();
        $subActions = $actions->flatMap->sousActions;
        $lateActions = $actions->filter(fn (Action $action): bool => $this->isLate($action))->count();
        $activeReports = $actions
            ->filter(fn (Action $action): bool => $this->activeDeadlineRequest($action) instanceof DeadlineExtensionRequest)
            ->count();
        $unconfiguredActions = $actions
            ->filter(fn (Action $action): bool => (string) $action->statut_parametrage === 'a_parametrer'
                || ! (bool) ($this->actionResult($action)['is_configured'] ?? false))
            ->count();
        $withoutResponsible = $actions->filter(fn (Action $action): bool => $this->responsibleNames($action)->isEmpty())->count();
        $withoutDeadline = $actions->filter(fn (Action $action): bool => $this->actionDeadline($action) === null)->count();
        $withoutTarget = $actions->filter(fn (Action $action): bool => $this->targetLabel($action) === 'À renseigner')->count();
        $pendingValidations = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                'soumise',
                'soumise_chef',
                'validee_chef',
            ], true))
            ->count();
        $progress = round((float) ($officialRollup['display_rate'] ?? 0), 1);

        return [
            'hierarchy' => $this->hierarchy($pta),
            'actions' => $actionNodes->all(),
            'summary' => [
                'actions' => $actions->count(),
                'sub_actions' => $subActions->count(),
                'progress' => $progress,
                'late_actions' => $lateActions,
                'active_reports' => $activeReports,
                'unconfigured_actions' => $unconfiguredActions,
                'pending_validations' => $pendingValidations,
                'proofs' => $actionNodes->sum('proofs_count'),
            ],
            'anomalies' => collect([
                ['label' => 'Actions a parametrer', 'count' => $unconfiguredActions],
                ['label' => 'Actions sans responsable', 'count' => $withoutResponsible],
                ['label' => 'Actions sans echeance', 'count' => $withoutDeadline],
                ['label' => 'Actions sans cible exploitable', 'count' => $withoutTarget],
                ['label' => 'Actions en retard', 'count' => $lateActions],
                ['label' => 'Reports d echeance actifs', 'count' => $activeReports],
            ])->filter(fn (array $anomaly): bool => $anomaly['count'] > 0)->values()->all(),
        ];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private function hierarchy(Pta $pta): array
    {
        $pao = $pta->pao;
        $operationalObjective = $pta->objectifOperationnel;
        $strategicObjective = $operationalObjective?->pasObjectif ?: $pao?->pasObjectif;
        $axis = $operationalObjective?->pasAxe ?: $strategicObjective?->pasAxe;
        $pas = $pao?->pas;

        return [
            'pas' => [
                'id' => $pas?->id,
                'code' => $pas?->id !== null ? 'PAS-'.$pas->periode_debut : '-',
                'label' => (string) ($pas?->titre ?: 'PAS non renseigne'),
            ],
            'axis' => [
                'id' => $axis?->id,
                'code' => (string) ($axis?->code ?: '-'),
                'label' => (string) ($axis?->libelle ?: 'Axe strategique non renseigne'),
            ],
            'strategic_objective' => [
                'id' => $strategicObjective?->id,
                'code' => (string) ($strategicObjective?->code ?: '-'),
                'label' => (string) ($strategicObjective?->libelle ?: 'Objectif strategique non renseigne'),
            ],
            'pao' => [
                'id' => $pao?->id,
                'code' => (string) ($pao?->code ?: ($pao?->id !== null ? 'PAO-'.$pao->id : '-')),
                'label' => (string) ($pao?->titre ?: 'PAO non renseigne'),
            ],
            'operational_objective' => [
                'id' => $operationalObjective?->id,
                'code' => (string) ($operationalObjective?->code ?: ($operationalObjective?->id !== null ? 'OO-'.$operationalObjective->id : '-')),
                'label' => (string) ($operationalObjective?->libelle ?: 'Objectif operationnel non renseigne'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionNode(Action $action): array
    {
        $activeReport = $this->activeDeadlineRequest($action);
        $responsibleNames = $this->responsibleNames($action);
        $subActions = $action->sousActions;
        $officialResult = $this->actionResult($action);
        $isLate = $this->isLate($action);
        $isConfigured = (string) $action->statut_parametrage !== 'a_parametrer'
            && (bool) ($officialResult['is_configured'] ?? false);
        $status = $this->officialCalculation->statusForRate(
            $officialResult['rate'] ?? null,
            $isLate,
            (float) ($action->seuil_minimum ?? 80)
        );

        return [
            'id' => (int) $action->id,
            'code' => (string) ($action->code ?: 'ACT-'.$action->id),
            'label' => (string) $action->libelle,
            'description' => $action->description,
            'type' => $action->typeActionLabel(),
            'indicator_type' => $action->type_indicateur_label,
            'indicator' => $this->firstFilledText([
                $action->indicateurs_attendus,
                $action->intitule_cible,
                $action->cible,
            ]) ?? 'À renseigner',
            'target' => $this->targetLabel($action),
            'expected_result' => $this->firstFilledText([
                $action->resultat_attendu,
                $action->livrable_attendu,
            ]) ?? 'À renseigner',
            'responsible' => $responsibleNames->isEmpty() ? 'Non affecte' : $responsibleNames->implode(', '),
            'start_date' => $action->date_debut?->format('d/m/Y'),
            'deadline' => $this->actionDeadline($action)?->format('d/m/Y'),
            'progress' => round((float) ($officialResult['display_rate'] ?? 0), 1),
            'status' => $status,
            'status_label' => $this->officialCalculation->statusLabel($status),
            'validation_status' => (string) ($action->statut_validation ?: 'non_soumise'),
            'configuration_status' => $isConfigured ? 'parametre' : 'a_parametrer',
            'is_late' => $isLate,
            'sub_actions_count' => $subActions->count(),
            'planned_sub_actions_count' => (int) ($action->nombre_sous_actions_prevu ?? 0),
            'completed_sub_actions_count' => $subActions->filter(fn (SousAction $subAction): bool => $this->isCompletedSubAction($subAction))->count(),
            'proofs_count' => $action->justificatifs->count() + $subActions->sum(fn (SousAction $subAction): int => $subAction->justificatifs->count()),
            'requires_proof' => (bool) $action->justificatif_obligatoire,
            'financing' => $action->financement_status_label,
            'report' => $activeReport instanceof DeadlineExtensionRequest
                ? [
                    'id' => (int) $activeReport->id,
                    'status' => (string) $activeReport->status,
                    'requested_deadline' => $activeReport->requested_deadline?->format('d/m/Y'),
                    'approved_deadline' => $activeReport->approved_deadline?->format('d/m/Y'),
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionResult(Action $action): array
    {
        return $this->actionResultCache[(int) $action->id]
            ??= $this->officialCalculation->actionResult($action);
    }

    private function isLate(Action $action): bool
    {
        return $this->officialCalculation->delayStatus(
            $action,
            $this->actionResult($action)['rate'] ?? null
        ) === StatutRetard::EnRetard;
    }

    private function actionDeadline(Action $action): ?Carbon
    {
        $deadline = $action->echeance_cible ?? $action->date_echeance ?? $action->date_fin;

        return $deadline === null ? null : Carbon::parse($deadline);
    }

    private function activeDeadlineRequest(Action $action): ?DeadlineExtensionRequest
    {
        return $action->deadlineExtensionRequests
            ->first(fn (DeadlineExtensionRequest $request): bool => ! in_array((string) $request->status, [
                DeadlineExtensionRequest::STATUS_REJETEE,
                DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE,
            ], true));
    }

    /**
     * @return Collection<int, string>
     */
    private function responsibleNames(Action $action): Collection
    {
        return $action->responsables
            ->pluck('name')
            ->push($action->responsable?->name)
            ->filter()
            ->unique()
            ->values();
    }

    private function targetLabel(Action $action): string
    {
        $parts = [];
        $quantity = $action->quantite_a_realiser ?? $action->quantite_cible;

        if ($action->tracksQuantitativeTarget() && $quantity !== null) {
            $parts[] = $this->numberLabel((float) $quantity).' '.trim((string) $action->unite_cible);
        }

        if ($action->tracksDeliverableTarget()) {
            $deliverable = $this->firstFilledText([
                $action->livrable_attendu,
                $action->resultat_attendu,
                $action->intitule_cible,
                $action->cible,
            ]);
            if ($deliverable !== null) {
                $parts[] = $deliverable;
            }
        }

        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->unique()
            ->implode(' / ') ?: 'À renseigner';
    }

    private function isCompletedSubAction(SousAction $subAction): bool
    {
        return (bool) $subAction->est_effectuee
            || in_array((string) $subAction->statut, ['effectuee', 'terminee', 'termine', 'validee', 'cloturee'], true);
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

    private function numberLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ' '), '0'), ',');
    }
}
