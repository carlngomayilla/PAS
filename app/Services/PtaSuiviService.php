<?php

namespace App\Services;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionStatusService;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PtaSuiviService
{
    use AuthorizesPlanningScope;

    public const PERMISSION = 'pta.control';

    public function __construct(
        private readonly ExerciceContext $exerciceContext,
        private readonly ActionStatusService $actionStatusService,
        private readonly PtaOfficialCalculationService $officialCalculation
    ) {}

    public function canAccess(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION)
            || $user->isPlanningControlChief()
            || $user->hasRole(
                User::ROLE_SUPER_ADMIN,
                User::ROLE_ADMIN,
                User::ROLE_ADMIN_FONCTIONNEL,
                User::ROLE_PLANIFICATION,
                User::ROLE_SCIQ,
                User::ROLE_SCIQ_SUIVI_GLOBAL
            );
    }

    public function denyUnlessAuthorized(User $user): void
    {
        if ($this->canAccess($user)) {
            return;
        }

        abort(403, 'Acces reserve au controle du PTA.');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPagePayload(Request $request, User $user): array
    {
        $filters = $this->filtersFromRequest($request, $user);
        $actions = $this->filteredActions($filters, $user)->get();
        $rows = $this->applyRowStatusFilters(
            $actions->map(fn (Action $action): array => $this->actionRow($action))->values(),
            $filters
        );
        $groups = $this->groupRows($rows);

        return [
            'generatedAt' => now(),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($filters, $user),
            'summary' => $this->summary($rows),
            'groups' => $groups,
            'rows' => $rows,
            'title' => 'SUIVI PTA '.$this->titleScopeLabel($filters),
            'scopeLabel' => $this->scopeLabel($filters),
            'legends' => $this->legends(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildActionDetails(Action $action, User $user): array
    {
        $this->denyUnlessActionVisible($action, $user);

        $action->loadMissing([
            'pta:id,code,pao_id,objectif_operationnel_id,titre,direction_id,service_id',
            'pta.direction:id,code,libelle',
            'pta.service:id,code,libelle',
            'pta.objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,code,libelle,echeance,indicateurs',
            'pta.objectifOperationnel.pasAxe:id,code,libelle,ordre',
            'pta.objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle,indicateur_global,valeur_cible',
            'pta.pao:id,code,pas_id,pas_objectif_id,direction_id,service_id,annee,titre,objectif_operationnel,echeance',
            'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
            'pta.pao.pasObjectif:id,pas_axe_id,code,libelle,indicateur_global,valeur_cible',
            'pta.pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
            'objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,code,libelle,echeance,indicateurs',
            'objectifOperationnel.pasAxe:id,code,libelle',
            'objectifOperationnel.pasObjectif:id,code,libelle,indicateur_global,valeur_cible',
            'responsable:id,name,email',
            'responsables:id,name,email',
            'kpis:id,action_id,libelle,unite,cible,seuil_alerte,periodicite',
            'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,progression_reelle,progression_theorique',
            'justificatifs:id,justifiable_type,justifiable_id,categorie,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'justificatifs.ajoutePar:id,name',
            'sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible_prevue,quantite_realisee,unite,resultat_obtenu,taux_realisation,taux_execution,est_effectuee,statut,date_debut,date_fin,completed_at,date_realisation,validation_status,commentaire,sub_action_type,weight',
            'sousActions.agent:id,name',
            'sousActions.justificatifs:id,sous_action_id,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'sousActions.justificatifs.ajoutePar:id,name',
            'actionLogs:id,action_id,niveau,type_evenement,message,details,cible_role,created_at,utilisateur_id',
            'actionLogs.utilisateur:id,name',
            'soumisPar:id,name',
            'evaluePar:id,name',
            'clotureePar:id,name',
        ]);

        $row = $this->actionRow($action);
        $details = [
            'Code action' => $this->dash($action->code ?? null),
            'Libelle complet' => $this->dash($action->libelle),
            'PAS rattache' => $row['pas_label'],
            'Axe strategique' => $row['axe_label'],
            'Objectif strategique' => $row['objectif_strategique_label'],
            'Objectif operationnel' => $row['objectif_operationnel_label'],
            'Direction' => $row['direction_label'],
            'Service' => $row['service_label'],
            'Responsable' => $row['responsable'],
            'Indicateur' => $row['indicateur'],
            'Cible' => $row['cible'],
            'Realise' => $row['realise'],
            'Ratio' => $row['ratio'],
            'Taux de realisation' => $row['taux_realisation_label'],
            'Performance' => $row['performance_label'],
            'Ecart' => $row['ecart_label'],
            'Echeance' => $row['echeance_label'],
            'Retard' => $row['retard_label'],
            'Statut de suivi' => $row['statut_suivi_label'],
            'Statut delai' => $row['statut_delai_label'],
            'Alerte echeance' => $row['alerte_echeance_label'],
            'Observation' => $row['observations'],
            'Niveau de risque' => $this->dash($action->niveau_risque ?? null),
            'Potentiel' => $this->dash($action->risque_potentiel ?? null),
            'Mesures preventives' => $this->dash($action->mesures_preventives ?? null),
        ];

        return [
            'action' => $action,
            'row' => $row,
            'details' => $details,
            'history' => $this->historyRows($action),
            'validations' => $this->validationRows($action),
            'attachments' => $this->attachmentRows($action),
        ];
    }

    public function denyUnlessActionVisible(Action $action, User $user): void
    {
        $query = Action::query()
            ->whereKey((int) $action->id)
            ->whereNotNull('pta_id');
        $this->scopePlanningActions($query, $user);

        if ($query->exists()) {
            return;
        }

        abort(403, 'Action hors perimetre.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Action>
     */
    public function filteredActions(array $filters, User $user): Builder
    {
        $query = Action::query()
            ->with($this->actionRelations())
            ->whereNotNull('pta_id')
            ->orderBy('id');

        $this->scopePlanningActions($query, $user);
        if ($filters['annee'] !== null) {
            $this->exerciceContext->applyToAction($query, $filters['annee']);
        }

        if (($directionId = $filters['direction_id']) !== null) {
            if (! $this->canReadDirection($user, $directionId)) {
                abort(403, 'Direction hors perimetre.');
            }

            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }

        if (($serviceId = $filters['service_id']) !== null) {
            $service = Service::query()->find($serviceId);
            if (! $service instanceof Service || ! $this->canReadService($user, (int) $service->direction_id, (int) $service->id)) {
                abort(403, 'Service hors perimetre.');
            }

            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }

        if (($range = $this->periodRange($filters['annee'], (string) ($filters['periode'] ?? 'all'))) !== null) {
            $query->where(function (Builder $periodQuery) use ($range): void {
                $periodQuery->whereBetween('date_echeance', $range)
                    ->orWhereBetween('date_fin', $range)
                    ->orWhereBetween('date_debut', $range)
                    ->orWhereBetween('created_at', $range);
            });
        }

        return $query->getQuery()->orders === null
            ? $query->orderBy('id')
            : $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request, User $user): array
    {
        $yearValue = trim((string) ($request->query('annee', $request->query('exercice', ''))));
        $year = $yearValue === 'all'
            ? null
            : (preg_match('/^\d{4}$/', $yearValue) === 1 ? (int) $yearValue : $this->exerciceContext->selectedYear());

        $period = $this->normalizePeriod($request->query('periode', $request->query('trimestre', '')));
        $quarter = $this->periodQuarter($period);

        $directionId = $this->integerFilter($request->query('direction_id'));
        $serviceId = $this->integerFilter($request->query('service_id'));
        if (! $user->hasGlobalReadAccess()) {
            $directionId ??= $user->direction_id !== null ? (int) $user->direction_id : null;
            $serviceId ??= $user->service_id !== null ? (int) $user->service_id : null;
        }

        $legacyStatus = $this->optionFilter($request->query('statut', ''), array_keys($this->workflowStatusOptions()));
        $statutSuivi = $this->optionFilter($request->query('statut_suivi', $legacyStatus ?? ''), array_keys($this->workflowStatusOptions()));
        $statutDelai = $this->optionFilter($request->query('statut_delai', ''), ['dans_les_delais', 'hors_delai']);
        $alerteEcheance = $this->optionFilter($request->query('alerte_echeance', ''), array_keys($this->alertStatusOptions()));

        return [
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'annee' => $year,
            'periode' => $period,
            'periode_label' => $this->periodLabel($period),
            'trimestre' => $quarter,
            'statut_suivi' => $statutSuivi,
            'statut_delai' => $statutDelai,
            'alerte_echeance' => $alerteEcheance,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function actionRelations(): array
    {
        return [
            'pta:id,code,pao_id,objectif_operationnel_id,titre,direction_id,service_id',
            'pta.direction:id,code,libelle',
            'pta.service:id,code,libelle',
            'pta.objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,code,libelle,echeance,indicateurs',
            'pta.objectifOperationnel.pasAxe:id,code,libelle,ordre',
            'pta.objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle,indicateur_global,valeur_cible',
            'pta.pao:id,code,pas_id,pas_objectif_id,direction_id,service_id,annee,titre,objectif_operationnel,echeance',
            'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
            'pta.pao.pasObjectif:id,pas_axe_id,code,libelle,indicateur_global,valeur_cible',
            'pta.pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
            'objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,code,libelle,echeance,indicateurs,import_ordre',
            'objectifOperationnel.pasAxe:id,code,libelle,ordre',
            'objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle,indicateur_global,valeur_cible',
            'responsable:id,name,email',
            'responsables:id,name,email',
            'kpis:id,action_id,libelle,unite,cible,seuil_alerte,periodicite',
            'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,progression_reelle,progression_theorique',
            'justificatifs:id,justifiable_type,justifiable_id,categorie,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'justificatifs.ajoutePar:id,name',
            'sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible_prevue,quantite_realisee,unite,resultat_obtenu,taux_realisation,taux_execution,est_effectuee,statut,date_debut,date_fin,completed_at,date_realisation,validation_status,commentaire,sub_action_type,weight',
            'sousActions.agent:id,name',
            'sousActions.justificatifs:id,sous_action_id,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'sousActions.justificatifs.ajoutePar:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionRow(Action $action): array
    {
        $pta = $action->pta;
        $pao = $pta?->pao;
        $pas = $pao?->pas;
        $objective = $action->objectifOperationnel ?: $pta?->objectifOperationnel;
        $strategicObjective = $objective?->pasObjectif ?: $pao?->pasObjectif;
        $axis = $objective?->pasAxe ?: $strategicObjective?->pasAxe;
        $kpi = $action->kpis->first();
        $official = $this->officialCalculation->actionResult($action);
        $target = (float) $official['target'];
        $realized = (float) $official['realized'];
        $progress = $official['rate'] !== null ? (float) $official['rate'] : 0.0;
        $displayProgress = (float) $official['display_rate'];
        $performance = $progress;
        $ecart = round(max(0.0, 100.0 - $displayProgress), 2);
        $deadline = $this->deadline($action);
        $workflowStatus = $this->workflowStatus($action, $official);
        $delayStatus = $this->delayStatus($action, $official['rate']);
        $alertStatus = $this->alertStatus($action, $official['rate']);
        $simpleStatus = $this->actionDisplayStatus($official, $delayStatus, $workflowStatus);
        $proofStatus = $this->proofStatus($action, $delayStatus);
        $delayDays = $this->delayDays($action);
        $proofCount = $this->proofCount($action);
        $responsable = $action->responsable?->name
            ?: $action->responsables->pluck('name')->filter()->implode(', ');
        $responsable = $responsable !== '' ? $responsable : '-';
        $unit = (string) ($action->unite_cible ?? $kpi?->unite ?? '');
        $indicator = $this->dash($kpi?->libelle ?? $action->indicateurs_attendus ?? $objective?->indicateurs ?? $strategicObjective?->indicateur_global ?? null);

        return [
            'id' => (int) $action->id,
            'action_id' => (int) $action->id,
            'action_url' => route('workspace.actions.suivi', $action),
            'details_url' => route('pta.suivi.details', $action),
            'libelle' => (string) ($action->libelle ?: '-'),
            'pas_key' => (string) ($pas?->id ?? 'pas-none'),
            'pas_code' => $this->pasCode($pas, $pao),
            'pas_label' => $this->pasLabel($pas, $pao),
            'axe_key' => (string) ($axis?->id ?? 'axe-none'),
            'axe_label' => $this->entityLabel($axis?->code ?? null, $axis?->libelle ?? null, 'Axe strategique non renseigne'),
            'objectif_strategique_key' => (string) ($strategicObjective?->id ?? 'os-none'),
            'objectif_strategique_label' => $this->entityLabel($strategicObjective?->code ?? null, $strategicObjective?->libelle ?? null, 'Objectif strategique non renseigne'),
            'objectif_operationnel_key' => (string) ($objective?->id ?? $pta?->id ?? 'oo-none'),
            'objectif_operationnel_label' => $this->entityLabel($objective?->code ?? null, $objective?->libelle ?? $pao?->objectif_operationnel ?? $pta?->titre ?? null, 'Objectif operationnel non renseigne'),
            'direction_label' => $this->entityLabel($pta?->direction?->code ?? null, $pta?->direction?->libelle ?? null, 'Direction non renseignee'),
            'service_label' => $this->entityLabel($pta?->service?->code ?? null, $pta?->service?->libelle ?? null, 'Service non renseigne'),
            'responsable' => $responsable,
            'indicateur' => $indicator,
            'ratio' => $target > 0 ? $this->numberLabel($realized).' / '.$this->numberLabel($target) : $this->ratioFromSubActions($action),
            'taux_realisation' => $progress,
            'taux_realisation_display' => $displayProgress,
            'taux_realisation_label' => $this->percentLabel($progress),
            'cible' => $target > 0 ? trim($this->numberLabel($target).' '.$unit) : 'A parametrer',
            'realise' => $target > 0 ? trim($this->numberLabel($realized).' '.$unit) : $this->dash($action->intitule_cible ?? $strategicObjective?->valeur_cible ?? null),
            'performance' => $performance,
            'performance_label' => $this->percentLabel($performance),
            'ecart' => $ecart,
            'ecart_label' => $this->percentLabel($ecart),
            'echeance' => $deadline?->toDateString(),
            'echeance_label' => $deadline?->format('Y-m-d') ?? '-',
            'retard_jours' => $delayDays,
            'retard_label' => $this->delayLabel($delayDays),
            'statut_suivi' => $workflowStatus,
            'statut_suivi_label' => $this->workflowStatusLabel($workflowStatus),
            'statut_action' => $simpleStatus,
            'statut_action_label' => $this->officialCalculation->statusLabel($simpleStatus),
            'statut_delai' => $delayStatus,
            'statut_delai_label' => $this->delayStatusLabel($delayStatus),
            'alerte_echeance' => $alertStatus,
            'alerte_echeance_label' => $this->alertStatusLabel($alertStatus),
            'preuve_statut' => $proofStatus,
            'preuve_statut_label' => $this->proofStatusLabel($proofStatus),
            'preuve_count' => $proofCount,
            'has_preuve' => $proofCount > 0,
            'observations' => $this->observations($action),
            'calcul_cible' => $target,
            'calcul_realise' => $realized,
            'calcul_configured' => (bool) $official['is_configured'],
            'calcul_status' => (string) $official['status'],
            'calcul_status_label' => (string) $official['status_label'],
            'sous_actions' => $this->subActionRows($action, $responsable, $indicator, $unit),
            'ordre' => (int) ($action->ordre_import ?? $action->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subActionRows(Action $action, string $fallbackResponsable, string $fallbackIndicator, string $fallbackUnit): array
    {
        if (! $action->relationLoaded('sousActions')) {
            return [];
        }

        return $action->sousActions
            ->sortBy(fn (SousAction $sousAction): int => (int) $sousAction->id)
            ->values()
            ->map(function (SousAction $sousAction, int $index) use ($fallbackResponsable, $fallbackIndicator, $fallbackUnit): array {
                $official = $this->officialCalculation->subActionResult($sousAction);
                $target = (float) $official['target'];
                $realized = (float) $official['realized'];
                $rate = $official['rate'] !== null ? (float) $official['rate'] : null;
                $displayRate = (float) $official['display_rate'];
                $deadline = $this->subActionDeadline($sousAction);
                $completedAt = $this->subActionCompletedAt($sousAction);
                $delayStatus = $this->delayStatusForDates($deadline, $completedAt, $rate);
                $delayDays = $this->delayDaysForDates($deadline, $completedAt);
                $ecart = $rate !== null ? round(max(0.0, 100.0 - $displayRate), 2) : null;
                $unit = trim((string) ($sousAction->unite ?? '')) ?: $fallbackUnit;
                $proofCount = $this->subActionProofCount($sousAction);
                $status = $this->subActionDisplayStatus($official, $delayStatus);
                $workflowStatus = $this->subActionWorkflowStatus($sousAction, $official);

                return [
                    'id' => (int) $sousAction->id,
                    'numero' => $index + 1,
                    'libelle' => (string) ($sousAction->libelle ?: '-'),
                    'indicateur' => $this->dash($sousAction->resultat_attendu ?? $sousAction->description ?? $fallbackIndicator),
                    'responsable' => (string) ($sousAction->agent?->name ?? $fallbackResponsable),
                    'ratio' => $target > 0 ? $this->numberLabel($realized).' / '.$this->numberLabel($target) : 'A parametrer',
                    'taux_realisation' => $rate ?? 0.0,
                    'taux_realisation_display' => $displayRate,
                    'taux_realisation_label' => $this->percentLabel($rate),
                    'cible' => $target > 0 ? trim($this->numberLabel($target).' '.$unit) : 'A parametrer',
                    'realise' => $target > 0 ? trim($this->numberLabel($realized).' '.$unit) : $this->subActionRealizationLabel($sousAction),
                    'performance' => $rate ?? 0.0,
                    'performance_label' => $this->percentLabel($rate),
                    'ecart' => $ecart,
                    'ecart_label' => $this->percentLabel($ecart),
                    'echeance' => $deadline?->toDateString(),
                    'echeance_label' => $deadline?->format('Y-m-d') ?? '-',
                    'retard_jours' => $delayDays,
                    'retard_label' => $this->delayLabel($delayDays),
                    'statut_action' => $status,
                    'statut_action_label' => $this->officialCalculation->statusLabel($status),
                    'statut_suivi' => $workflowStatus,
                    'statut_suivi_label' => $this->workflowStatusLabel($workflowStatus),
                    'statut_delai' => $delayStatus,
                    'statut_delai_label' => $this->delayStatusLabel($delayStatus),
                    'preuve_count' => $proofCount,
                    'has_preuve' => $proofCount > 0,
                    'observations' => $this->subActionObservations($sousAction),
                    'calcul_cible' => $target,
                    'calcul_realise' => $realized,
                    'calcul_configured' => (bool) $official['is_configured'],
                    'calcul_status' => (string) $official['status'],
                    'calcul_status_label' => (string) $official['status_label'],
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function groupRows(Collection $rows): Collection
    {
        return $rows
            ->groupBy('pas_key')
            ->map(function (Collection $pasRows): array {
                $axes = $pasRows
                    ->groupBy('axe_key')
                    ->map(function (Collection $axisRows): array {
                        $strategicObjectives = $axisRows
                            ->groupBy('objectif_strategique_key')
                            ->map(function (Collection $objectiveRows): array {
                                $operationalObjectives = $objectiveRows
                                    ->groupBy('objectif_operationnel_key')
                                    ->map(function (Collection $operationalRows): array {
                                        $result = $this->officialCalculation->targetWeightedRows($operationalRows, 'objectif_operationnel');

                                        return [
                                            'key' => (string) $operationalRows->first()['objectif_operationnel_key'],
                                            'label' => (string) $operationalRows->first()['objectif_operationnel_label'],
                                            'performance' => (float) ($result['rate'] ?? 0.0),
                                            'performance_display' => (float) $result['display_rate'],
                                            'performance_label' => $this->percentLabel($result['rate']),
                                            'cible_cumulee' => (float) $result['target'],
                                            'realisation_cumulee' => (float) $result['realized'],
                                            'calcul_configured' => (bool) $result['is_configured'],
                                            'calcul_status' => (string) $result['status'],
                                            'calcul_status_label' => (string) $result['status_label'],
                                            'actions' => $operationalRows
                                                ->sortBy('ordre')
                                                ->values(),
                                        ];
                                    })
                                    ->values();
                                $result = $this->officialCalculation->targetWeightedRows($operationalObjectives, 'objectif_strategique');

                                return [
                                    'key' => (string) $objectiveRows->first()['objectif_strategique_key'],
                                    'label' => (string) $objectiveRows->first()['objectif_strategique_label'],
                                    'performance' => (float) ($result['rate'] ?? 0.0),
                                    'performance_display' => (float) $result['display_rate'],
                                    'performance_label' => $this->percentLabel($result['rate']),
                                    'cible_cumulee' => (float) $result['target'],
                                    'realisation_cumulee' => (float) $result['realized'],
                                    'calcul_configured' => (bool) $result['is_configured'],
                                    'calcul_status' => (string) $result['status'],
                                    'calcul_status_label' => (string) $result['status_label'],
                                    'objectifs_operationnels' => $operationalObjectives,
                                ];
                            })
                            ->values();
                        $result = $this->officialCalculation->targetWeightedRows($strategicObjectives, 'axe_strategique');

                        return [
                            'key' => (string) $axisRows->first()['axe_key'],
                            'label' => (string) $axisRows->first()['axe_label'],
                            'performance' => (float) ($result['rate'] ?? 0.0),
                            'performance_display' => (float) $result['display_rate'],
                            'performance_label' => $this->percentLabel($result['rate']),
                            'cible_cumulee' => (float) $result['target'],
                            'realisation_cumulee' => (float) $result['realized'],
                            'calcul_configured' => (bool) $result['is_configured'],
                            'calcul_status' => (string) $result['status'],
                            'calcul_status_label' => (string) $result['status_label'],
                            'objectifs' => $strategicObjectives,
                        ];
                    })
                    ->values();
                $result = $this->officialCalculation->targetWeightedRows($axes, 'pas_global');

                return [
                    'key' => (string) $pasRows->first()['pas_key'],
                    'code' => (string) $pasRows->first()['pas_code'],
                    'label' => (string) $pasRows->first()['pas_label'],
                    'performance' => (float) ($result['rate'] ?? 0.0),
                    'performance_display' => (float) $result['display_rate'],
                    'performance_label' => $this->percentLabel($result['rate']),
                    'cible_cumulee' => (float) $result['target'],
                    'realisation_cumulee' => (float) $result['realized'],
                    'calcul_configured' => (bool) $result['is_configured'],
                    'calcul_status' => (string) $result['status'],
                    'calcul_status_label' => (string) $result['status_label'],
                    'axes' => $axes,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyRowStatusFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['statut_suivi'] !== null, fn (Collection $items): Collection => $items->where('statut_suivi', $filters['statut_suivi'])->values())
            ->when($filters['statut_delai'] !== null, fn (Collection $items): Collection => $items->where('statut_delai', $filters['statut_delai'])->values())
            ->when($filters['alerte_echeance'] !== null, fn (Collection $items): Collection => $items->where('alerte_echeance', $filters['alerte_echeance'])->values())
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(array $filters, User $user): array
    {
        $directionQuery = Direction::query()->where('actif', true)->orderBy('code')->orderBy('libelle');
        if (! $this->canReadAllPlanning($user)) {
            $directionQuery->whereHas('services', function (Builder $serviceQuery) use ($user): void {
                $this->scopeByUserDirection($serviceQuery, $user, 'direction_id', 'id');
            });
        }
        $directions = $directionQuery->get(['id', 'code', 'libelle']);

        $serviceQuery = Service::query()->where('actif', true)->orderBy('code')->orderBy('libelle');
        if ($filters['direction_id'] !== null) {
            $serviceQuery->where('direction_id', (int) $filters['direction_id']);
        }
        $this->scopeByUserDirection($serviceQuery, $user, 'direction_id', 'id');
        $services = $serviceQuery->get(['id', 'direction_id', 'code', 'libelle']);

        return [
            'directions' => $directions->map(fn (Direction $direction): array => [
                'id' => (int) $direction->id,
                'label' => $this->entityLabel($direction->code, $direction->libelle, 'Direction'),
            ])->values()->all(),
            'services' => $services->map(fn (Service $service): array => [
                'id' => (int) $service->id,
                'direction_id' => (int) $service->direction_id,
                'label' => $this->entityLabel($service->code, $service->libelle, 'Service'),
            ])->values()->all(),
            'exercices' => $this->exerciceContext->options(),
            'periodes' => $this->periodOptions(),
            'trimestres' => $this->periodOptions(),
            'statut_suivi' => $this->workflowStatusOptions(),
            'statut_delai' => [
                'dans_les_delais' => 'Dans les delais',
                'hors_delai' => 'Hors delai',
            ],
            'alerte_echeance' => $this->alertStatusOptions(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $result = $this->officialCalculation->targetWeightedRows($rows, 'summary');

        return [
            'actions' => $rows->count(),
            'performance' => (float) ($result['rate'] ?? 0.0),
            'performance_display' => (float) $result['display_rate'],
            'cible_cumulee' => (float) $result['target'],
            'realisation_cumulee' => (float) $result['realized'],
            'a_parametrer' => $rows->where('calcul_configured', false)->count(),
            'en_retard' => $rows->where('alerte_echeance', 'en_retard')->count(),
            'critiques' => $rows->where('alerte_echeance', 'critique')->count(),
            'cloturees' => $rows->where('statut_suivi', 'cloture')->count(),
        ];
    }

    /**
     * @return array<string, array<int, array{label: string, color: string, text?: string}>>
     */
    private function legends(): array
    {
        return [
            'Couleurs hierarchiques' => [
                ['label' => 'Axe strategique', 'color' => '#0f2f57'],
                ['label' => 'Objectif strategique', 'color' => '#1e5fa8'],
                ['label' => 'Objectif operationnel', 'color' => '#d8ecff', 'text' => '#0f2f57'],
                ['label' => 'Action', 'color' => '#f8fafc', 'text' => '#111827'],
                ['label' => 'Sous-action', 'color' => '#f1f5f9', 'text' => '#334155'],
            ],
            'Statut action' => [
                ['label' => 'A parametrer', 'color' => '#6b7280'],
                ['label' => 'En attente', 'color' => '#e5e7eb', 'text' => '#111827'],
                ['label' => 'En cours', 'color' => '#3996d3'],
                ['label' => 'Realise', 'color' => '#00b050'],
                ['label' => 'En retard', 'color' => '#ff0000'],
            ],
            'Statut delai' => [
                ['label' => 'Dans les delais', 'color' => '#00b050'],
                ['label' => 'Hors delai', 'color' => '#f97316'],
            ],
            'Statut de suivi' => [
                ['label' => 'A parametrer', 'color' => '#6b7280'],
                ['label' => 'Non demarre', 'color' => '#cbd5e1', 'text' => '#111827'],
                ['label' => 'En cours', 'color' => '#3996d3'],
                ['label' => 'En validation chef', 'color' => '#9333ea'],
                ['label' => 'En validation controleur', 'color' => '#4f46e5'],
                ['label' => 'Cloture', 'color' => '#00b050'],
            ],
            'Alerte echeance' => [
                ['label' => 'Aucune alerte', 'color' => '#d9ead3', 'text' => '#14532d'],
                ['label' => 'Echeance proche', 'color' => '#fff200', 'text' => '#111827'],
                ['label' => 'Critique', 'color' => '#f9b13c', 'text' => '#111827'],
                ['label' => 'En retard', 'color' => '#ff0000'],
                ['label' => 'Cloturee', 'color' => '#00b050'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $officialResult
     */
    private function workflowStatus(Action $action, ?array $officialResult = null): string
    {
        if ($officialResult !== null && ! (bool) ($officialResult['is_configured'] ?? false)) {
            return 'a_parametrer';
        }

        if ($this->actionStatusService->isPendingSetup($action)) {
            return 'a_parametrer';
        }

        $dynamic = strtolower(trim((string) ($action->statut_dynamique ?? $action->statut ?? '')));
        $validation = strtolower(trim((string) ($action->statut_validation ?? '')));

        if ($dynamic === 'cloturee' || $action->cloture_le !== null) {
            return 'cloture';
        }

        if (in_array($validation, ['validee_chef', 'validee_direction'], true)) {
            return 'validation_controleur';
        }

        if (in_array($validation, ['soumise_chef', 'en_validation_chef'], true)) {
            return 'validation_chef';
        }

        if ($this->actionStatusService->isNotStarted($action)) {
            return 'non_demarre';
        }

        return 'en_cours';
    }

    private function delayStatus(Action $action, ?float $rate = null): string
    {
        return $this->delayStatusForDates($this->deadline($action), $this->completedAt($action), $rate);
    }

    private function delayStatusForDates(?Carbon $deadline, ?Carbon $completedAt = null, ?float $rate = null): string
    {
        if ($deadline === null) {
            return 'dans_les_delais';
        }

        if ($completedAt !== null) {
            return $completedAt->gt($deadline->copy()->endOfDay()) ? 'hors_delai' : 'dans_les_delais';
        }

        if ($rate !== null && $rate >= 100.0) {
            return 'dans_les_delais';
        }

        return now()->startOfDay()->gt($deadline->copy()->endOfDay()) ? 'hors_delai' : 'dans_les_delais';
    }

    private function alertStatus(Action $action, ?float $rate = null): string
    {
        if ($this->completedAt($action) !== null || $this->workflowStatus($action) === 'cloture' || ($rate !== null && $rate >= 100.0)) {
            return 'cloturee';
        }

        $deadline = $this->deadline($action);
        if ($deadline === null) {
            return 'a_parametrer';
        }

        $today = now()->startOfDay();
        if ($today->gt($deadline->copy()->endOfDay())) {
            return 'en_retard';
        }

        $days = $today->diffInDays($deadline->copy()->startOfDay(), false);
        if ($days <= 3) {
            return 'critique';
        }

        if ($days <= 7) {
            return 'echeance_proche';
        }

        return 'aucune_alerte';
    }

    private function proofStatus(Action $action, string $delayStatus): string
    {
        $hasProof = $this->proofCount($action) > 0;

        if (! $hasProof) {
            return $delayStatus === 'hors_delai' ? 'preuves_non_livrees' : 'en_attente';
        }

        return $delayStatus === 'hors_delai'
            ? 'preuves_hors_delai'
            : 'preuves_dans_delais';
    }

    /**
     * @param  array<string, mixed>  $officialResult
     */
    private function actionDisplayStatus(array $officialResult, string $delayStatus, string $workflowStatus): string
    {
        if ($workflowStatus === 'a_parametrer' || ! (bool) ($officialResult['is_configured'] ?? false)) {
            return PtaOfficialCalculationService::STATUS_TO_CONFIGURE;
        }

        $rate = $officialResult['rate'] !== null ? (float) $officialResult['rate'] : null;

        return $this->officialCalculation->statusForRate($rate, $delayStatus === 'hors_delai');
    }

    /**
     * @param  array<string, mixed>  $officialResult
     */
    private function subActionDisplayStatus(array $officialResult, string $delayStatus): string
    {
        if (! (bool) ($officialResult['is_configured'] ?? false)) {
            return PtaOfficialCalculationService::STATUS_TO_CONFIGURE;
        }

        $rate = $officialResult['rate'] !== null ? (float) $officialResult['rate'] : null;

        return $this->officialCalculation->statusForRate($rate, $delayStatus === 'hors_delai');
    }

    /**
     * @param  array<string, mixed>  $officialResult
     */
    private function subActionWorkflowStatus(SousAction $sousAction, array $officialResult): string
    {
        if (! (bool) ($officialResult['is_configured'] ?? false)) {
            return 'a_parametrer';
        }

        $validationStatus = strtolower(trim((string) ($sousAction->validation_status ?? '')));
        if ($validationStatus === SousAction::VALIDATION_VALIDEE) {
            return 'cloture';
        }

        if ($validationStatus === SousAction::VALIDATION_SOUMISE) {
            return 'validation_chef';
        }

        if ((bool) ($sousAction->est_effectuee ?? false) || $this->subActionCompletedAt($sousAction) !== null) {
            return 'validation_controleur';
        }

        $rate = $officialResult['rate'] !== null ? (float) $officialResult['rate'] : null;
        if ($rate === null || $rate <= 0.0) {
            return 'non_demarre';
        }

        return 'en_cours';
    }

    private function subActionProofCount(SousAction $sousAction): int
    {
        if ($sousAction->relationLoaded('justificatifs')) {
            return $sousAction->justificatifs->pluck('id')->filter()->unique()->count();
        }

        return $sousAction->exists ? $sousAction->justificatifs()->count() : 0;
    }

    private function proofCount(Action $action): int
    {
        $proofIds = collect();

        if ($action->relationLoaded('justificatifs')) {
            $proofIds = $proofIds->concat($action->justificatifs->pluck('id'));
        } elseif ($action->exists) {
            $proofIds = $proofIds->concat($action->justificatifs()->pluck('id'));
        }

        if ($action->relationLoaded('sousActions')) {
            $action->sousActions->each(function ($subAction) use (&$proofIds): void {
                if ($subAction->relationLoaded('justificatifs')) {
                    $proofIds = $proofIds->concat($subAction->justificatifs->pluck('id'));

                    return;
                }

                if ($subAction->exists) {
                    $proofIds = $proofIds->concat($subAction->justificatifs()->pluck('id'));
                }
            });
        }

        return $proofIds->filter()->unique()->count();
    }

    private function workflowStatusLabel(string $status): string
    {
        return $this->workflowStatusOptions()[$status] ?? Str::headline($status);
    }

    /**
     * @return array<string, string>
     */
    private function workflowStatusOptions(): array
    {
        return [
            'a_parametrer' => 'A parametrer',
            'non_demarre' => 'Non demarre',
            'en_cours' => 'En cours',
            'validation_chef' => 'En validation chef',
            'validation_controleur' => 'En validation controleur',
            'cloture' => 'Cloture',
        ];
    }

    private function delayStatusLabel(string $status): string
    {
        return match ($status) {
            'hors_delai' => 'Hors delai',
            default => 'Dans les delais',
        };
    }

    private function alertStatusLabel(string $status): string
    {
        return $this->alertStatusOptions()[$status] ?? Str::headline($status);
    }

    /**
     * @return array<string, string>
     */
    private function alertStatusOptions(): array
    {
        return [
            'aucune_alerte' => 'Aucune alerte',
            'echeance_proche' => 'Echeance proche',
            'critique' => 'Critique',
            'en_retard' => 'En retard',
            'cloturee' => 'Cloturee',
            'a_parametrer' => 'A parametrer',
        ];
    }

    private function proofStatusLabel(string $status): string
    {
        return match ($status) {
            'preuves_dans_delais' => 'Preuves transmises dans les delais definis',
            'preuves_hors_delai' => 'Preuves transmises hors delai',
            'preuves_non_livrees' => 'Preuves non livrees',
            default => 'En attente',
        };
    }

    private function deadline(Action $action): ?Carbon
    {
        $value = $action->date_echeance ?? $action->echeance_cible ?? $action->date_fin ?? null;

        return $value instanceof Carbon ? $value->copy() : ($value !== null ? Carbon::parse($value) : null);
    }

    private function completedAt(Action $action): ?Carbon
    {
        $value = $action->date_fin_reelle ?? $action->cloture_le ?? null;

        return $value instanceof Carbon ? $value->copy() : ($value !== null ? Carbon::parse($value) : null);
    }

    private function subActionDeadline(SousAction $sousAction): ?Carbon
    {
        $value = $sousAction->date_fin ?? null;

        return $value instanceof Carbon ? $value->copy() : ($value !== null ? Carbon::parse($value) : null);
    }

    private function subActionCompletedAt(SousAction $sousAction): ?Carbon
    {
        $value = $sousAction->completed_at ?? $sousAction->date_realisation ?? null;

        return $value instanceof Carbon ? $value->copy() : ($value !== null ? Carbon::parse($value) : null);
    }

    private function delayDays(Action $action): int
    {
        return $this->delayDaysForDates($this->deadline($action), $this->completedAt($action));
    }

    private function delayDaysForDates(?Carbon $deadline, ?Carbon $completedAt = null): int
    {
        if ($deadline === null) {
            return 0;
        }

        $reference = $completedAt ?? now();
        $days = $deadline->copy()->endOfDay()->diffInDays($reference, false);

        return max(0, (int) $days);
    }

    private function delayLabel(int $days): string
    {
        return $days <= 1 ? $days.' j' : $days.' j';
    }

    private function ratioFromSubActions(Action $action): string
    {
        if (! $action->relationLoaded('sousActions')) {
            return '-';
        }

        $total = $action->sousActions->count();
        if ($total === 0) {
            return '-';
        }

        $done = $action->sousActions->filter(fn ($subAction): bool => (bool) ($subAction->est_effectuee ?? false))->count();

        return $done.'/'.$total;
    }

    private function observations(Action $action): string
    {
        $parts = [];
        foreach ([
            'Observation' => $action->observations ?? null,
            'Risque' => $action->risques ?? $action->risque_lie ?? null,
            'Potentiel' => $action->risque_potentiel ?? null,
            'Mesures preventives' => $action->mesures_preventives ?? null,
            'Difficultes' => $action->difficultes_rencontrees ?? null,
            'Mesures correctives' => $action->mesures_correctives ?? null,
        ] as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $label.' : '.$value;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    private function subActionRealizationLabel(SousAction $sousAction): string
    {
        $result = $this->dash($sousAction->resultat_obtenu ?? null);
        if ($result !== '-') {
            return $result;
        }

        return (bool) ($sousAction->est_effectuee ?? false) ? 'Realise' : '-';
    }

    private function subActionObservations(SousAction $sousAction): string
    {
        $parts = [];
        foreach ([
            'Resultat attendu' => $sousAction->resultat_attendu ?? null,
            'Resultat obtenu' => $sousAction->resultat_obtenu ?? null,
            'Commentaire' => $sousAction->commentaire ?? null,
            'Description' => $sousAction->description ?? null,
        ] as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $label.' : '.$value;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function historyRows(Action $action): array
    {
        $rows = $action->actionLogs
            ->sortBy('created_at')
            ->map(fn ($log): array => [
                'date' => $log->created_at?->format('Y-m-d H:i') ?? '-',
                'etape' => Str::headline((string) ($log->type_evenement ?? 'evenement')),
                'utilisateur' => (string) ($log->utilisateur?->name ?? '-'),
                'action' => (string) ($log->message ?? '-'),
                'commentaire' => $this->logDetailsLabel((array) ($log->details ?? [])),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        return [[
            'date' => $action->created_at?->format('Y-m-d H:i') ?? '-',
            'etape' => 'Creation',
            'utilisateur' => '-',
            'action' => 'Action creee',
            'commentaire' => '-',
        ]];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function validationRows(Action $action): array
    {
        return [
            [
                'niveau' => 'Chef',
                'statut' => UiLabel::validationStatus((string) ($action->statut_validation ?? 'non_soumise')),
                'validateur' => (string) ($action->evaluePar?->name ?? '-'),
                'date' => $action->evalue_le?->format('Y-m-d H:i') ?? $action->soumise_le?->format('Y-m-d H:i') ?? '-',
                'commentaire' => $this->dash($action->motif_validation_chef ?? null),
            ],
            [
                'niveau' => 'Controleur',
                'statut' => $this->workflowStatus($action) === 'cloture' ? 'Cloture' : ($this->workflowStatus($action) === 'validation_controleur' ? 'En attente controle' : 'Non transmis'),
                'validateur' => (string) ($action->clotureePar?->name ?? '-'),
                'date' => $action->cloture_le?->format('Y-m-d H:i') ?? '-',
                'commentaire' => $this->dash($action->justification_cloture ?? $action->rapport_final ?? null),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachmentRows(Action $action): array
    {
        $actionAttachments = $action->justificatifs
            ->map(fn (Justificatif $justificatif): array => $this->attachmentRow($action, $justificatif, 'Action'));

        $subActionAttachments = $action->sousActions
            ->flatMap(function (SousAction $sousAction) use ($action): Collection {
                return $sousAction->justificatifs
                    ->map(fn (Justificatif $justificatif): array => $this->attachmentRow(
                        $action,
                        $justificatif,
                        'Sous-action : '.($sousAction->libelle ?: ('#'.$sousAction->id))
                    ));
            });

        return $actionAttachments
            ->concat($subActionAttachments)
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentRow(Action $action, Justificatif $justificatif, string $source): array
    {
        $mime = strtolower((string) ($justificatif->mime_type ?? ''));
        $isImage = str_starts_with($mime, 'image/');
        $isPdf = $mime === 'application/pdf';

        return [
            'id' => (int) $justificatif->id,
            'nom' => (string) ($justificatif->nom_original ?? 'Piece jointe'),
            'source' => $source,
            'type' => $mime !== '' ? $mime : '-',
            'ajoute_par' => (string) ($justificatif->ajoutePar?->name ?? '-'),
            'date' => $justificatif->created_at?->format('Y-m-d H:i') ?? '-',
            'is_previewable' => $isImage || $isPdf,
            'is_image' => $isImage,
            'is_pdf' => $isPdf,
            'preview_url' => route('workspace.actions.justificatifs.preview', [$action, $justificatif]),
            'download_url' => route('workspace.actions.justificatifs.download', [$action, $justificatif]),
        ];
    }

    private function logDetailsLabel(array $details): string
    {
        if ($details === []) {
            return '-';
        }

        return collect($details)
            ->reject(fn ($value): bool => is_array($value) || is_object($value))
            ->map(fn ($value, $key): string => Str::headline((string) $key).' : '.(string) $value)
            ->implode(' | ') ?: '-';
    }

    private function titleScopeLabel(array $filters): string
    {
        if (($filters['service_id'] ?? null) !== null) {
            $service = Service::query()->find((int) $filters['service_id']);

            return $service instanceof Service ? (string) ($service->code ?: $service->libelle) : 'SERVICE';
        }

        if (($filters['direction_id'] ?? null) !== null) {
            $direction = Direction::query()->find((int) $filters['direction_id']);

            return $direction instanceof Direction ? (string) ($direction->code ?: $direction->libelle) : 'DIRECTION';
        }

        return 'GLOBAL';
    }

    private function scopeLabel(array $filters): string
    {
        $service = ($filters['service_id'] ?? null) !== null ? Service::query()->with('direction:id,code,libelle')->find((int) $filters['service_id']) : null;
        $direction = ($filters['direction_id'] ?? null) !== null ? Direction::query()->find((int) $filters['direction_id']) : null;

        return implode(' | ', array_filter([
            $service instanceof Service ? 'Service : '.$this->entityLabel($service->code, $service->libelle, 'Service') : null,
            $direction instanceof Direction ? 'Direction : '.$this->entityLabel($direction->code, $direction->libelle, 'Direction') : null,
            ($filters['annee'] ?? null) !== null ? 'Annee : '.$filters['annee'] : 'Annee : Tous exercices',
            'Periode : '.(string) ($filters['periode_label'] ?? 'Annuelle'),
        ]));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function periodOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Annuelle'],
            ['value' => 'q1', 'label' => 'T1'],
            ['value' => 'q2', 'label' => 'T2'],
            ['value' => 'q3', 'label' => 'T3'],
            ['value' => 'q4', 'label' => 'T4'],
            ['value' => 's1', 'label' => 'S1'],
            ['value' => 's2', 'label' => 'S2'],
            ['value' => 'm1', 'label' => 'Janvier'],
            ['value' => 'm2', 'label' => 'Fevrier'],
            ['value' => 'm3', 'label' => 'Mars'],
            ['value' => 'm4', 'label' => 'Avril'],
            ['value' => 'm5', 'label' => 'Mai'],
            ['value' => 'm6', 'label' => 'Juin'],
            ['value' => 'm7', 'label' => 'Juillet'],
            ['value' => 'm8', 'label' => 'Aout'],
            ['value' => 'm9', 'label' => 'Septembre'],
            ['value' => 'm10', 'label' => 'Octobre'],
            ['value' => 'm11', 'label' => 'Novembre'],
            ['value' => 'm12', 'label' => 'Decembre'],
        ];
    }

    public function normalizePeriod(mixed $value): string
    {
        $period = Str::lower(trim((string) $value));
        $period = str_replace([' ', '_'], '', $period);

        if ($period === '' || in_array($period, ['all', 'annual', 'annuel', 'annuelle', 'annee'], true)) {
            return 'all';
        }

        if (preg_match('/^[1-4]$/', $period) === 1) {
            return 'q'.$period;
        }

        if (preg_match('/^(?:q|t)([1-4])$/', $period, $matches) === 1) {
            return 'q'.$matches[1];
        }

        if (preg_match('/^(?:s|semestre)([1-2])$/', $period, $matches) === 1) {
            return 's'.$matches[1];
        }

        if (preg_match('/^(?:m|mois)(0?[1-9]|1[0-2])$/', $period, $matches) === 1) {
            return 'm'.(int) $matches[1];
        }

        return 'all';
    }

    private function periodQuarter(string $period): ?int
    {
        return preg_match('/^q([1-4])$/', $period, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    public function periodLabel(string $period): string
    {
        $option = collect($this->periodOptions())->firstWhere('value', $period);

        return is_array($option) ? (string) ($option['label'] ?? 'Annuelle') : 'Annuelle';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function periodRange(?int $year, string $period): ?array
    {
        if ($year === null || $period === 'all') {
            return null;
        }

        if (($quarter = $this->periodQuarter($period)) !== null) {
            return $this->exerciceContext->quarterRange($year, $quarter);
        }

        if (preg_match('/^s([1-2])$/', $period, $matches) === 1) {
            $startMonth = (int) $matches[1] === 1 ? 1 : 7;
            $start = Carbon::create($year, $startMonth, 1)->startOfDay();

            return [$start, $start->copy()->addMonthsNoOverflow(5)->endOfMonth()->endOfDay()];
        }

        if (preg_match('/^m(0?[1-9]|1[0-2])$/', $period, $matches) === 1) {
            $start = Carbon::create($year, (int) $matches[1], 1)->startOfDay();

            return [$start, $start->copy()->endOfMonth()->endOfDay()];
        }

        return null;
    }

    private function pasCode(mixed $pas, mixed $pao): string
    {
        $year = (int) ($pao?->annee ?? now()->year);
        $code = trim((string) ($pas?->code ?? ''));

        return $code !== '' ? $code : 'PAS-'.$year;
    }

    private function pasLabel(mixed $pas, mixed $pao): string
    {
        $title = trim((string) ($pas?->titre ?? ''));

        return $title !== '' ? $title : $this->pasCode($pas, $pao);
    }

    private function entityLabel(mixed $code, mixed $label, string $fallback): string
    {
        $code = trim((string) $code);
        $label = trim((string) $label);
        if ($code !== '' && $label !== '') {
            return $code.' - '.$label;
        }

        return $label !== '' ? $label : ($code !== '' ? $code : $fallback);
    }

    private function dash(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    private function numberLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ' '), '0'), '.');
    }

    private function percentLabel(?float $value): string
    {
        if ($value === null) {
            return 'A parametrer';
        }

        return number_format($value, 2, '.', ' ').'%';
    }

    private function integerFilter(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'all' || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function optionFilter(mixed $value, array $allowed): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== 'all' && in_array($value, $allowed, true)
            ? $value
            : null;
    }
}
