<?php

namespace App\Services;

use App\Enums\TypeIndicateur;
use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\ObjectifOperationnel;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
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
        return (bool) $user->is_active;
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
            $actions->map(fn (Action $action): array => $this->actionRow($action, $user))->values(),
            $filters
        );
        $groups = $this->mergeHierarchyGroups(
            $this->pasHierarchyGroups($filters, $user),
            $this->groupRows($rows)
        );

        return [
            'generatedAt' => now(),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($filters, $user),
            'summary' => $this->summary($rows),
            'groups' => $groups,
            'rows' => $rows,
            'rmoOptions' => $this->rmoOptions($user),
            'title' => 'SUIVI PTA '.$this->titleScopeLabel($filters),
            'scopeLabel' => $this->scopeLabel($filters),
            'legends' => $this->legends(),
        ];
    }

    /**
     * Rapport d'evolution du PTA : les actions sont regroupees par direction
     * puis par service, chaque bloc etant precede du responsable concerne
     * (directeur ou chef de service). A l'interieur, l'arborescence du modele
     * institutionnel est conservee : axe, objectif strategique, objectif
     * operationnel, puis le tableau des actions detaillees.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function buildEvolutionReportGroups(Collection $rows): array
    {
        $responsables = $this->organizationResponsables($rows);

        return $rows
            ->groupBy(fn (array $row): string => (string) ($row['direction_id'] ?? 'sans-direction'))
            ->map(function (Collection $directionRows, string $directionKey) use ($responsables): array {
                $services = $directionRows
                    ->groupBy(fn (array $row): string => (string) ($row['service_id'] ?? 'sans-service'))
                    ->map(fn (Collection $serviceRows, string $serviceKey): array => [
                        'service' => (string) ($serviceRows->first()['service_label'] ?? 'Service non renseigné'),
                        'chef' => $responsables['services'][$serviceKey] ?? 'Non renseigné',
                        'actions_total' => $serviceRows->count(),
                        'blocks' => $this->evolutionBlocks($serviceRows),
                    ])
                    ->sortBy('service')
                    ->values()
                    ->all();

                return [
                    'direction' => (string) ($directionRows->first()['direction_label'] ?? 'Direction non renseignée'),
                    'directeur' => $responsables['directions'][$directionKey] ?? 'Non renseigné',
                    'actions_total' => $directionRows->count(),
                    'services' => $services,
                ];
            })
            ->sortBy('direction')
            ->values()
            ->all();
    }

    /**
     * Blocs axe / objectif strategique / objectif operationnel d'un service.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function evolutionBlocks(Collection $rows): array
    {
        $blocks = [];
        $numero = 0;

        foreach ($rows->groupBy('axe_key') as $axisRows) {
            foreach ($axisRows->groupBy('objectif_strategique_key') as $strategicRows) {
                foreach ($strategicRows->groupBy('objectif_operationnel_key') as $operationalRows) {
                    $first = $operationalRows->first();
                    $numero++;

                    $blocks[] = [
                        'numero' => $numero,
                        'axe' => (string) ($first['axe_label'] ?? '-'),
                        'objectif_strategique' => (string) ($first['objectif_strategique_label'] ?? '-'),
                        'objectif_operationnel' => (string) ($first['objectif_operationnel_label'] ?? '-'),
                        'actions' => $operationalRows->sortBy('ordre')->values()->all(),
                    ];
                }
            }
        }

        return $blocks;
    }

    /**
     * Noms des directeurs et des chefs de service concernes par le rapport.
     *
     * Le chef du service Planification porte le role `chef_planification` et non
     * le role `service` : les deux sont donc acceptes.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{directions: array<string, string>, services: array<string, string>}
     */
    private function organizationResponsables(Collection $rows): array
    {
        $directionIds = $rows->pluck('direction_id')->filter()->unique()->values();
        $serviceIds = $rows->pluck('service_id')->filter()->unique()->values();

        $directions = $directionIds->isEmpty()
            ? collect()
            : User::query()
                ->where('role', User::ROLE_DIRECTION)
                ->whereIn('direction_id', $directionIds)
                ->orderBy('id')
                ->get(['id', 'name', 'direction_id'])
                ->groupBy(fn (User $user): string => (string) $user->direction_id)
                ->map(fn (Collection $users): string => (string) $users->first()->name);

        $services = $serviceIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('role', [User::ROLE_SERVICE, User::ROLE_CHEF_PLANIFICATION])
                ->whereIn('service_id', $serviceIds)
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [User::ROLE_SERVICE])
                ->orderBy('id')
                ->get(['id', 'name', 'service_id', 'role'])
                ->groupBy(fn (User $user): string => (string) $user->service_id)
                ->map(fn (Collection $users): string => (string) $users->first()->name);

        return [
            'directions' => $directions->all(),
            'services' => $services->all(),
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
            'sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible,type_indicateur,quantite_a_realiser,seuil_minimum,livrable_attendu,cible_prevue,quantite_realisee,unite,resultat_obtenu,taux_realisation,taux_execution,est_effectuee,statut,date_debut,date_fin,completed_at,date_realisation,validation_status,commentaire,sub_action_type,weight',
            'sousActions.agent:id,name',
            'sousActions.justificatifs:id,sous_action_id,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'sousActions.justificatifs.ajoutePar:id,name',
            'actionLogs:id,action_id,niveau,type_evenement,message,details,cible_role,created_at,utilisateur_id',
            'actionLogs.utilisateur:id,name',
            'soumisPar:id,name',
            'evaluePar:id,name',
            'controleReviewedBy:id,name',
            'clotureePar:id,name',
        ]);

        $row = $this->actionRow($action, $user);
        $details = [
            'Code action' => $this->dash($action->code ?? null),
            'Libelle complet' => $this->dash($action->libelle),
            'PAS rattache' => $row['pas_label'],
            'Axe strategique' => $row['axe_label'],
            'Objectif strategique' => $row['objectif_strategique_label'],
            'Objectif operationnel' => $row['objectif_operationnel_label'],
            'Direction' => $row['direction_label'],
            'Service' => $row['service_label'],
            'RMO' => $row['responsable'],
            'Indicateur' => $row['indicateur'],
            'Seuil' => $row['seuil_label'] ?? '-',
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
            'trackingUrl' => route('workspace.actions.suivi', $action),
        ];
    }

    public function denyUnlessActionVisible(Action $action, User $user): void
    {
        $query = Action::query()
            ->whereKey((int) $action->id)
            ->whereNotNull('pta_id');
        $this->scopeVisibleActions($query, $user);

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

        $this->scopeVisibleActions($query, $user);
        if ($filters['annee'] !== null) {
            $this->exerciceContext->applyToAction($query, $filters['annee']);
        }

        if (($directionId = $filters['direction_id']) !== null) {
            if (! $this->canReadPtaSuiviDirection($user, $directionId)) {
                abort(403, 'Direction hors perimetre.');
            }

            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }

        if (($serviceId = $filters['service_id']) !== null) {
            $service = Service::query()->find($serviceId);
            if (! $service instanceof Service || ! $this->canReadPtaSuiviService($user, (int) $service->direction_id, (int) $service->id)) {
                abort(403, 'Service hors perimetre.');
            }

            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }

        if (($objectiveId = $filters['objectif_operationnel_id']) !== null) {
            $objective = ObjectifOperationnel::query()->find($objectiveId);
            if (! $objective instanceof ObjectifOperationnel || ! $this->canReadPtaSuiviService($user, (int) $objective->direction_id, (int) $objective->service_id)) {
                abort(403, 'Objectif operationnel hors perimetre.');
            }

            $query->where(function (Builder $objectiveQuery) use ($objectiveId): void {
                $objectiveQuery
                    ->where('objectif_operationnel_id', $objectiveId)
                    ->orWhereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('objectif_operationnel_id', $objectiveId));
            });
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
        $objectiveId = $this->integerFilter($request->query('objectif_operationnel_id'));
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
            'objectif_operationnel_id' => $objectiveId,
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
            'sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible,type_indicateur,quantite_a_realiser,seuil_minimum,livrable_attendu,cible_prevue,quantite_realisee,unite,resultat_obtenu,taux_realisation,taux_execution,est_effectuee,statut,date_debut,date_fin,completed_at,date_realisation,validation_status,commentaire,sub_action_type,weight',
            'sousActions.agent:id,name',
            'sousActions.justificatifs:id,sous_action_id,nom_original,description,mime_type,taille_octets,created_at,ajoute_par',
            'sousActions.justificatifs.ajoutePar:id,name',
            'actionLogs:id,action_id,type_evenement',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionRow(Action $action, ?User $user = null): array
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
        $typeIndicateur = TypeIndicateur::fromLegacy($action->type_indicateur ?? $action->type_action ?? $action->mode_evaluation ?? null);
        $completionThreshold = $this->completionThreshold($action->seuil_minimum ?? null);
        $progress = $official['rate'] !== null ? (float) $official['rate'] : 0.0;
        $displayProgress = (float) $official['display_rate'];
        $performance = $progress;
        $ecart = round(max(0.0, 100.0 - $displayProgress), 2);
        $deadline = $this->deadline($action);
        $workflowStatus = $this->workflowStatus($action, $official);
        $delayStatus = $this->delayStatus($action, $official['rate'], $completionThreshold);
        $alertStatus = $this->alertStatus($action, $official['rate'], $completionThreshold, $workflowStatus);
        $simpleStatus = $this->actionDisplayStatus($official, $delayStatus, $workflowStatus, $completionThreshold);
        $proofStatus = $this->proofStatus($action, $delayStatus);
        $delayDays = $this->delayDays($action);
        $proofCount = $this->proofCount($action);
        $responsable = $action->responsable?->name
            ?: $action->responsables->pluck('name')->filter()->implode(', ');
        $responsable = $responsable !== '' ? $responsable : '-';
        $unit = (string) ($action->unite_cible ?? $kpi?->unite ?? '');
        $indicatorText = $this->firstFilledText([
            $kpi?->libelle ?? null,
            $action->indicateurs_attendus ?? null,
            $objective?->indicateurs ?? null,
            $strategicObjective?->indicateur_global ?? null,
        ]);
        $indicator = $indicatorText ?? 'À renseigner';

        $detailsUrl = route('pta.suivi.details', $action);
        $proofPreview = $this->proofPreviewData($action);
        $inlineEditable = $user instanceof User && $this->canInlineEditAction($action, $user);
        $canRequestDeadlineExtension = $user instanceof User && $user->can('requestDeadlineExtension', $action);
        $thresholdLabel = $this->thresholdLabel($completionThreshold);
        $deliverable = (string) ($this->firstFilledText([
            $action->livrable_attendu ?? null,
            $action->cible ?? null,
            $action->resultat_attendu ?? null,
            $action->intitule_cible ?? null,
        ]) ?? '');
        $quantityToRealize = $this->rawDecimal($action->quantite_a_realiser ?? $action->quantite_cible ?? null);

        return [
            'id' => (int) $action->id,
            'action_id' => (int) $action->id,
            'action_url' => route('workspace.actions.suivi', $action),
            'report_url' => route('workspace.actions.suivi', $action).'#action-echeances',
            'can_request_report' => $canRequestDeadlineExtension,
            'details_url' => $detailsUrl,
            'preview_url' => $detailsUrl,
            'parameter_url' => $this->actionParameterUrl($action),
            'inline_editable' => $inlineEditable,
            'inline_update_url' => route('pta.suivi.actions.update', $action),
            'inline_delete_url' => route('pta.suivi.actions.destroy', $action),
            'indicator_type_options' => $this->indicatorTypeOptions(),
            'inline_values' => [
                'libelle' => (string) ($action->libelle ?? ''),
                'type_indicateur' => $typeIndicateur->value,
                'indicateur' => (string) ($indicatorText ?? ''),
                'livrable_attendu' => $deliverable,
                'quantite_a_realiser' => $quantityToRealize,
                'seuil_minimum' => $this->rawDecimal($completionThreshold),
                'unite' => (string) ($action->unite_cible ?? $kpi?->unite ?? ''),
                'rmo_id' => $this->rawInteger($action->responsable_id ?? $action->responsables->first()?->id ?? null),
                'date_debut' => $this->rawDate($action->date_debut ?? null),
                'date_fin' => $this->rawDate($this->deadline($action)),
                'observations' => (string) ($action->observations ?? ''),
            ],
            'proof_preview_url' => $proofPreview['preview_url'],
            'proof_download_url' => $proofPreview['download_url'],
            'proof_title' => $proofPreview['title'],
            'proof_subtitle' => $proofPreview['subtitle'],
            'proof_mime' => $proofPreview['mime'],
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
            'direction_id' => $pta?->direction?->id !== null ? (int) $pta->direction->id : null,
            'service_id' => $pta?->service?->id !== null ? (int) $pta->service->id : null,
            'direction_label' => $this->entityLabel($pta?->direction?->code ?? null, $pta?->direction?->libelle ?? null, 'Direction non renseignée'),
            'service_label' => $this->entityLabel($pta?->service?->code ?? null, $pta?->service?->libelle ?? null, 'Service non renseigné'),
            // Livrable attendu affiche dans la colonne « Indicateurs de performance »
            // du rapport d'evolution : « 100 dossiers », « Rapport signe »...
            'livrable_attendu_label' => $this->expectedDeliverableLabel($quantityToRealize, $unit, $deliverable, $indicatorText),
            'responsable' => $responsable,
            'type_indicateur' => $typeIndicateur->value,
            'type_indicateur_label' => $typeIndicateur->label(),
            'indicateur' => $indicator,
            'indicateur_affichage' => $this->indicatorDisplayLabel($typeIndicateur, $indicatorText, $quantityToRealize, $unit, $deliverable),
            'ratio' => $target > 0 ? $this->numberLabel($realized).' / '.$this->numberLabel($target) : $this->ratioFromSubActions($action),
            'taux_realisation' => $progress,
            'taux_realisation_display' => $displayProgress,
            'taux_realisation_label' => $this->percentLabel($progress),
            'cible' => $thresholdLabel,
            'seuil' => $thresholdLabel,
            'seuil_label' => $thresholdLabel,
            'realise' => $target > 0 ? trim($this->numberLabel($realized).' '.$unit) : $this->dash($action->intitule_cible ?? $strategicObjective?->valeur_cible ?? null),
            'performance' => $performance,
            'performance_label' => $this->percentLabel($performance),
            'ecart' => $ecart,
            'ecart_label' => $this->percentLabel($ecart),
            'echeance' => $deadline?->toDateString(),
            'echeance_label' => $deadline?->format('Y-m-d') ?? '-',
            // Comptages du PAS : une action est « realisee » lorsqu'elle est
            // entierement executee (100 %), « echue » lorsque son echeance est
            // passee. Une action a 80 % n'est pas comptee comme terminee.
            'est_realisee' => $displayProgress >= 100.0,
            'est_echue' => $deadline instanceof Carbon && $deadline->startOfDay()->lte(Carbon::today()),
            // Colonnes du rapport d'evolution du PTA (modele institutionnel).
            'debut_label' => $action->date_debut !== null ? Carbon::parse($action->date_debut)->format('d/m/Y') : '-',
            'fin_label' => $deadline?->format('d/m/Y') ?? '-',
            'ressources_requises' => $this->resourcesLabel($action),
            'risques_potentiels' => $this->risksLabel($action),
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
            'sous_actions' => $this->subActionRows($action, $responsable, $indicator, $unit, $inlineEditable, $canRequestDeadlineExtension),
            'ordre' => (int) ($action->ordre_import ?? $action->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subActionRows(
        Action $action,
        string $fallbackResponsable,
        string $fallbackIndicator,
        string $fallbackUnit,
        bool $inlineEditable,
        bool $canRequestDeadlineExtension
    ): array {
        if (! $action->relationLoaded('sousActions')) {
            return [];
        }

        return $action->sousActions
            ->sortBy(fn (SousAction $sousAction): int => (int) $sousAction->id)
            ->values()
            ->map(function (SousAction $sousAction, int $index) use ($action, $fallbackResponsable, $fallbackIndicator, $fallbackUnit, $inlineEditable, $canRequestDeadlineExtension): array {
                $official = $this->officialCalculation->subActionResult($sousAction);
                $target = (float) $official['target'];
                $realized = (float) $official['realized'];
                $rate = $official['rate'] !== null ? (float) $official['rate'] : null;
                $displayRate = (float) $official['display_rate'];
                $deadline = $this->subActionDeadline($sousAction);
                $completedAt = $this->subActionCompletedAt($sousAction);
                $delayDays = $this->delayDaysForDates($deadline, $completedAt);
                $ecart = $rate !== null ? round(max(0.0, 100.0 - $displayRate), 2) : null;
                $unit = trim((string) ($sousAction->unite ?? '')) ?: $fallbackUnit;
                $proofCount = $this->subActionProofCount($sousAction);
                $workflowStatus = $this->subActionWorkflowStatus($sousAction, $official);
                $typeIndicateur = TypeIndicateur::fromLegacy($sousAction->type_indicateur ?? $sousAction->sub_action_type ?? null);
                $completionThreshold = $this->completionThreshold($sousAction->seuil_minimum ?? $action->seuil_minimum ?? null);
                $delayStatus = $this->delayStatusForDates($deadline, $completedAt, $rate, $completionThreshold);
                $status = $this->subActionDisplayStatus($official, $delayStatus, $completionThreshold);
                $thresholdLabel = $this->thresholdLabel($completionThreshold);
                $indicatorText = $this->firstFilledText([
                    $sousAction->resultat_attendu ?? null,
                    $sousAction->description ?? null,
                    $fallbackIndicator,
                ]);
                $deliverable = (string) ($this->firstFilledText([
                    $sousAction->livrable_attendu ?? null,
                    $sousAction->cible ?? null,
                    $sousAction->resultat_attendu ?? null,
                ]) ?? '');
                $quantityToRealize = $this->rawDecimal($sousAction->quantite_a_realiser ?? $sousAction->cible_prevue ?? null);

                $detailsUrl = route('pta.suivi.details', $action);
                $proofPreview = $this->proofPreviewData($action, $sousAction);

                return [
                    'id' => (int) $sousAction->id,
                    'numero' => $index + 1,
                    'details_url' => $detailsUrl,
                    'preview_url' => $detailsUrl,
                    'action_url' => route('workspace.actions.suivi', $action),
                    'report_url' => route('workspace.actions.suivi', [
                        'action' => $action,
                        'report_sous_action_id' => $sousAction->id,
                    ]).'#action-echeances',
                    'can_request_report' => $canRequestDeadlineExtension,
                    'parameter_url' => $this->actionParameterUrl($action, $sousAction),
                    'inline_editable' => $inlineEditable,
                    'inline_update_url' => route('pta.suivi.actions.update', $action),
                    'inline_delete_url' => route('pta.suivi.actions.destroy', $action),
                    'indicator_type_options' => $this->indicatorTypeOptions(),
                    'inline_values' => [
                        'libelle' => (string) ($sousAction->libelle ?? ''),
                        'type_indicateur' => $typeIndicateur->value,
                        'indicateur' => (string) ($indicatorText ?? ''),
                        'livrable_attendu' => $deliverable,
                        'quantite_a_realiser' => $quantityToRealize,
                        'seuil_minimum' => $this->rawDecimal($completionThreshold),
                        'unite' => (string) ($sousAction->unite ?? $fallbackUnit),
                        'rmo_id' => $this->rawInteger($sousAction->agent_id ?? null),
                        'date_debut' => $this->rawDate($sousAction->date_debut ?? null),
                        'date_fin' => $this->rawDate($deadline),
                        'observations' => (string) ($sousAction->commentaire ?? ''),
                    ],
                    'proof_preview_url' => $proofPreview['preview_url'],
                    'proof_download_url' => $proofPreview['download_url'],
                    'proof_title' => $proofPreview['title'],
                    'proof_subtitle' => $proofPreview['subtitle'],
                    'proof_mime' => $proofPreview['mime'],
                    'libelle' => (string) ($sousAction->libelle ?: '-'),
                    'type_indicateur' => $typeIndicateur->value,
                    'type_indicateur_label' => $typeIndicateur->label(),
                    'indicateur' => $indicatorText ?? 'À renseigner',
                    'indicateur_affichage' => $this->indicatorDisplayLabel($typeIndicateur, $indicatorText, $quantityToRealize, $unit, $deliverable),
                    'responsable' => (string) ($sousAction->agent?->name ?? $fallbackResponsable),
                    'ratio' => $target > 0 ? $this->numberLabel($realized).' / '.$this->numberLabel($target) : 'À paramétrer',
                    'taux_realisation' => $rate ?? 0.0,
                    'taux_realisation_display' => $displayRate,
                    'taux_realisation_label' => $this->percentLabel($rate),
                    'cible' => $thresholdLabel,
                    'seuil' => $thresholdLabel,
                    'seuil_label' => $thresholdLabel,
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

    private function actionParameterUrl(Action $action, ?SousAction $sousAction = null): string
    {
        if ($action->pta_id === null) {
            return '';
        }

        // `action_id` est la cible faisant foi : l'ancre seule dependait du saut
        // natif du navigateur, execute avant l'ouverture des accordeons, ce qui
        // laissait l'utilisateur sur une action voisine.
        $query = $sousAction instanceof SousAction
            ? '?focus=sub_action&action_id='.(int) $action->id.'&sub_action_id='.(int) $sousAction->id
            : '?focus=action&action_id='.(int) $action->id;

        return route('workspace.pta.edit', $action->pta_id).$query.'#action-'.(int) $action->id;
    }

    /**
     * @return array{preview_url:?string,download_url:?string,title:?string,subtitle:?string,mime:?string}
     */
    private function proofPreviewData(Action $action, ?SousAction $sousAction = null): array
    {
        $proof = $this->firstProof($action, $sousAction);
        if (! $proof instanceof Justificatif) {
            return [
                'preview_url' => null,
                'download_url' => null,
                'title' => null,
                'subtitle' => null,
                'mime' => null,
            ];
        }

        return [
            'preview_url' => route('workspace.actions.justificatifs.preview', [$action, $proof]),
            'download_url' => route('workspace.actions.justificatifs.download', [$action, $proof]),
            'title' => (string) ($proof->nom_original ?? 'Piece justificative'),
            'subtitle' => trim((string) ($proof->description ?: $proof->categorie)) ?: 'Piece justificative',
            'mime' => (string) ($proof->mime_type ?? ''),
        ];
    }

    private function firstProof(Action $action, ?SousAction $sousAction = null): ?Justificatif
    {
        if ($sousAction instanceof SousAction) {
            if ($sousAction->relationLoaded('justificatifs')) {
                return $sousAction->justificatifs
                    ->sortByDesc(fn (Justificatif $proof): int => (int) ($proof->created_at?->timestamp ?? 0))
                    ->first();
            }

            return $sousAction->exists
                ? $sousAction->justificatifs()->latest()->first()
                : null;
        }

        if ($action->relationLoaded('justificatifs')) {
            $proof = $action->justificatifs
                ->sortByDesc(fn (Justificatif $attachment): int => (int) ($attachment->created_at?->timestamp ?? 0))
                ->first();

            if ($proof instanceof Justificatif) {
                return $proof;
            }
        } elseif ($action->exists) {
            $proof = $action->justificatifs()->latest()->first();
            if ($proof instanceof Justificatif) {
                return $proof;
            }
        }

        if ($action->relationLoaded('sousActions')) {
            return $action->sousActions
                ->flatMap(function (SousAction $subAction): Collection {
                    if ($subAction->relationLoaded('justificatifs')) {
                        return $subAction->justificatifs;
                    }

                    return $subAction->exists
                        ? $subAction->justificatifs()->latest()->get()
                        : collect();
                })
                ->sortByDesc(fn (Justificatif $proof): int => (int) ($proof->created_at?->timestamp ?? 0))
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function pasHierarchyGroups(array $filters, User $user): Collection
    {
        $query = Pas::query()
            ->with([
                'axes' => fn ($axisQuery) => $axisQuery->orderBy('ordre')->orderBy('id'),
                'axes.objectifs' => fn ($objectiveQuery) => $objectiveQuery->orderBy('ordre')->orderBy('id'),
                'axes.objectifs.objectifsOperationnels' => function ($objectiveQuery) use ($filters, $user): void {
                    $objectiveQuery->select([
                        'id',
                        'pao_id',
                        'pas_id',
                        'pas_axe_id',
                        'pas_objectif_id',
                        'direction_id',
                        'service_id',
                        'code',
                        'libelle',
                        'echeance',
                        'import_ordre',
                    ]);
                    if (! $this->hasInlineControlProfile($user)) {
                        $this->scopeByUserDirection($objectiveQuery, $user, 'direction_id', 'service_id');
                    }

                    if (($directionId = $filters['direction_id']) !== null) {
                        $objectiveQuery->where('direction_id', (int) $directionId);
                    }

                    if (($serviceId = $filters['service_id']) !== null) {
                        $objectiveQuery->where('service_id', (int) $serviceId);
                    }

                    if (($objectiveId = $filters['objectif_operationnel_id']) !== null) {
                        $objectiveQuery->whereKey((int) $objectiveId);
                    }

                    if (($year = $filters['annee']) !== null) {
                        $objectiveQuery->whereHas('pao', fn (Builder $paoQuery) => $paoQuery->where('annee', (int) $year));
                    }
                },
            ])
            ->orderByDesc('periode_fin')
            ->orderBy('titre');

        $this->scopePasByUser($query, $user);
        if (($year = $filters['annee']) !== null) {
            $this->exerciceContext->applyToPas($query, (int) $year);
        } else {
            $this->exerciceContext->applyToPas($query);
        }

        if (($directionId = $filters['direction_id']) !== null) {
            $query->where(function (Builder $pasQuery) use ($directionId): void {
                $pasQuery->whereHas('paos', fn (Builder $paoQuery) => $paoQuery->where('direction_id', (int) $directionId))
                    ->orWhereHas('directions', fn (Builder $directionQuery) => $directionQuery->whereKey((int) $directionId));
            });
        }

        if (($serviceId = $filters['service_id']) !== null) {
            $query->where(function (Builder $pasQuery) use ($serviceId): void {
                $pasQuery->whereHas('paos.objectifsOperationnels', fn (Builder $objectiveQuery) => $objectiveQuery->where('service_id', (int) $serviceId))
                    ->orWhereHas('paos.ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', (int) $serviceId));
            });
        }

        if (($objectiveId = $filters['objectif_operationnel_id']) !== null) {
            $query->whereHas('paos.objectifsOperationnels', fn (Builder $objectiveQuery) => $objectiveQuery->whereKey((int) $objectiveId));
        }

        return $query
            ->get()
            ->map(fn (Pas $pas): array => $this->emptyPasGroup($pas, $filters['objectif_operationnel_id']))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPasGroup(Pas $pas, ?int $filteredOperationalObjectiveId = null): array
    {
        $axes = $pas->axes
            ->sortBy(fn (PasAxe $axis): int => (int) ($axis->ordre ?? $axis->id))
            ->values()
            ->map(fn (PasAxe $axis): array => $this->emptyAxisGroup($axis, $filteredOperationalObjectiveId))
            ->when(
                $filteredOperationalObjectiveId !== null,
                fn (Collection $groups): Collection => $groups
                    ->filter(fn (array $axisGroup): bool => collect($axisGroup['objectifs'] ?? [])->isNotEmpty())
                    ->values()
            )
            ->values();

        return array_merge($this->emptyRollup(), [
            'key' => (string) $pas->id,
            'code' => $this->pasCode($pas, null),
            'label' => $this->pasLabel($pas, null),
            'axes' => $axes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAxisGroup(PasAxe $axis, ?int $filteredOperationalObjectiveId = null): array
    {
        $objectives = $axis->objectifs
            ->sortBy(fn (PasObjectif $objective): int => (int) ($objective->ordre ?? $objective->id))
            ->values()
            ->map(fn (PasObjectif $objective): array => $this->emptyStrategicObjectiveGroup($objective, $filteredOperationalObjectiveId))
            ->when(
                $filteredOperationalObjectiveId !== null,
                fn (Collection $groups): Collection => $groups
                    ->filter(fn (array $objectiveGroup): bool => collect($objectiveGroup['objectifs_operationnels'] ?? [])->isNotEmpty())
                    ->values()
            )
            ->values();

        if ($objectives->isEmpty() && $filteredOperationalObjectiveId === null) {
            $objectives = collect([$this->emptyStrategicPlaceholderGroup('os-empty-'.$axis->id)]);
        }

        return array_merge($this->emptyRollup(), [
            'key' => (string) $axis->id,
            'label' => $this->entityLabel($axis->code, $axis->libelle, 'Axe strategique sans libelle'),
            'objectifs' => $objectives,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStrategicObjectiveGroup(PasObjectif $objective, ?int $filteredOperationalObjectiveId = null): array
    {
        $operationalObjectives = $objective->objectifsOperationnels
            ->values()
            ->map(fn (ObjectifOperationnel $operationalObjective): array => $this->emptyOperationalObjectiveGroup($operationalObjective))
            ->values();

        if ($operationalObjectives->isEmpty() && $filteredOperationalObjectiveId === null) {
            $operationalObjectives = collect([$this->emptyOperationalPlaceholderGroup('oo-empty-'.$objective->id)]);
        }

        return array_merge($this->emptyRollup(), [
            'key' => (string) $objective->id,
            'label' => $this->entityLabel($objective->code, $objective->libelle, 'Objectif strategique sans libelle'),
            'objectifs_operationnels' => $operationalObjectives,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOperationalObjectiveGroup(ObjectifOperationnel $objective): array
    {
        return array_merge($this->emptyRollup(), [
            'key' => (string) $objective->id,
            'label' => $this->entityLabel($objective->code, $objective->libelle, 'Objectif operationnel sans libelle'),
            'actions' => collect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStrategicPlaceholderGroup(string $key): array
    {
        return array_merge($this->emptyRollup(), [
            'key' => $key,
            'label' => 'Aucun objectif strategique rattache',
            'objectifs_operationnels' => collect([$this->emptyOperationalPlaceholderGroup($key.'-oo')]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOperationalPlaceholderGroup(string $key): array
    {
        return array_merge($this->emptyRollup(), [
            'key' => $key,
            'label' => 'Aucun objectif operationnel rattache',
            'actions' => collect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRollup(): array
    {
        return [
            'performance' => 0.0,
            'performance_display' => 0.0,
            'performance_label' => $this->percentLabel(null),
            'cible_cumulee' => 0.0,
            'realisation_cumulee' => 0.0,
            'calcul_configured' => false,
            'calcul_status' => PtaOfficialCalculationService::STATUS_TO_CONFIGURE,
            'calcul_status_label' => 'À paramétrer',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $skeleton
     * @param  Collection<int, array<string, mixed>>  $actual
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeHierarchyGroups(Collection $skeleton, Collection $actual): Collection
    {
        return $this->mergeGroupCollections($skeleton, $actual, fn (array $base, array $row): array => $this->mergePasGroup($base, $row));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $base
     * @param  Collection<int, array<string, mixed>>  $actual
     * @param  callable(array<string, mixed>, array<string, mixed>): array<string, mixed>  $merge
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeGroupCollections(Collection $base, Collection $actual, callable $merge): Collection
    {
        $groups = $base->keyBy(fn (array $group): string => (string) ($group['key'] ?? ''));

        foreach ($actual as $actualGroup) {
            $key = (string) ($actualGroup['key'] ?? '');
            $groups[$key] = $groups->has($key)
                ? $merge($groups[$key], $actualGroup)
                : $actualGroup;
        }

        return $groups->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergePasGroup(array $base, array $actual): array
    {
        $merged = array_merge($base, $actual);
        $merged['axes'] = $this->mergeGroupCollections(
            collect($base['axes'] ?? []),
            collect($actual['axes'] ?? []),
            fn (array $baseAxis, array $actualAxis): array => $this->mergeAxisGroup($baseAxis, $actualAxis)
        );

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeAxisGroup(array $base, array $actual): array
    {
        $merged = array_merge($base, $actual);
        $merged['objectifs'] = $this->mergeGroupCollections(
            collect($base['objectifs'] ?? []),
            collect($actual['objectifs'] ?? []),
            fn (array $baseObjective, array $actualObjective): array => $this->mergeStrategicObjectiveGroup($baseObjective, $actualObjective)
        );

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeStrategicObjectiveGroup(array $base, array $actual): array
    {
        $merged = array_merge($base, $actual);
        $merged['objectifs_operationnels'] = $this->mergeGroupCollections(
            collect($base['objectifs_operationnels'] ?? []),
            collect($actual['objectifs_operationnels'] ?? []),
            fn (array $baseOperationalObjective, array $actualOperationalObjective): array => $this->mergeOperationalObjectiveGroup($baseOperationalObjective, $actualOperationalObjective)
        );

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeOperationalObjectiveGroup(array $base, array $actual): array
    {
        $merged = array_merge($base, $actual);
        $merged['actions'] = collect($actual['actions'] ?? $base['actions'] ?? [])->values();

        return $merged;
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
        if (! $this->hasInlineControlProfile($user) && ! $user->hasGlobalReadAccess()) {
            if ($user->direction_id !== null) {
                $directionQuery->whereKey((int) $user->direction_id);
            } else {
                $directionQuery->whereRaw('1 = 0');
            }
        }
        $directions = $directionQuery->get(['id', 'code', 'libelle']);

        $serviceQuery = Service::query()->where('actif', true)->orderBy('code')->orderBy('libelle');
        if (! $this->hasInlineControlProfile($user) && ! $user->hasGlobalReadAccess()) {
            if ($user->service_id !== null) {
                $serviceQuery->whereKey((int) $user->service_id);
            } elseif ($user->direction_id !== null) {
                $serviceQuery->where('direction_id', (int) $user->direction_id);
            } else {
                $serviceQuery->whereRaw('1 = 0');
            }
        }
        $services = $serviceQuery->get(['id', 'direction_id', 'code', 'libelle']);

        $objectiveQuery = ObjectifOperationnel::query()
            ->with('service:id,direction_id,code,libelle')
            ->orderBy('import_ordre')
            ->orderBy('code')
            ->orderBy('libelle');
        if (! $this->hasInlineControlProfile($user) && ! $user->hasGlobalReadAccess()) {
            if ($user->service_id !== null) {
                $objectiveQuery->where('service_id', (int) $user->service_id);
            } elseif ($user->direction_id !== null) {
                $objectiveQuery->where('direction_id', (int) $user->direction_id);
            } else {
                $objectiveQuery->whereRaw('1 = 0');
            }
        }
        if ($filters['annee'] !== null) {
            $objectiveQuery->whereHas('pao', fn (Builder $paoQuery) => $paoQuery->where('annee', (int) $filters['annee']));
        }
        $objectives = $objectiveQuery->get(['id', 'pao_id', 'direction_id', 'service_id', 'code', 'libelle']);

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
            'objectifs_operationnels' => $objectives->map(fn (ObjectifOperationnel $objective): array => [
                'id' => (int) $objective->id,
                'direction_id' => (int) $objective->direction_id,
                'service_id' => (int) $objective->service_id,
                'label' => $this->entityLabel($objective->code, $objective->libelle, 'Objectif operationnel'),
                'service_label' => $objective->service instanceof Service
                    ? $this->entityLabel($objective->service->code, $objective->service->libelle, 'Service')
                    : '',
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
     * @return list<array{id:int,label:string}>
     */
    private function rmoOptions(User $user): array
    {
        $query = User::query()
            ->where('is_active', true)
            ->orderBy('role')
            ->orderBy('name');

        if (! $this->hasInlineControlProfile($user) && ! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if (! $this->hasInlineControlProfile($user) && $this->hasOwnServicePlanningScope($user)) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query
            ->get(['id', 'name', 'email', 'role', 'direction_id', 'service_id'])
            ->map(fn (User $rmo): array => [
                'id' => (int) $rmo->id,
                'label' => trim((string) $rmo->name) !== '' ? (string) $rmo->name : (string) $rmo->email,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $result = $this->officialCalculation->targetWeightedRows($rows, 'summary');
        $execution = $this->officialCalculation->executionRate($rows);
        $completion = $this->officialCalculation->globalCompletionRate($rows);

        return [
            'actions' => $rows->count(),
            'performance' => (float) ($result['rate'] ?? 0.0),
            'performance_display' => (float) $result['display_rate'],
            'cible_cumulee' => (float) $result['target'],
            'realisation_cumulee' => (float) $result['realized'],
            // Indicateurs PAS bases sur le comptage d'actions terminees.
            'taux_execution' => (float) $execution['display_rate'],
            'taux_execution_label' => $this->percentLabel($execution['rate']),
            'actions_echues' => (int) $execution['due'],
            'actions_echues_realisees' => (int) $execution['done'],
            'taux_avancement_global' => (float) $completion['display_rate'],
            'taux_avancement_global_label' => $this->percentLabel($completion['rate']),
            'actions_realisees' => (int) $completion['done'],
            'actions_programmees' => (int) $completion['due'],
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
                ['label' => 'À paramétrer', 'color' => '#6b7280'],
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
                ['label' => 'À paramétrer', 'color' => '#6b7280'],
                ['label' => 'Non demarre', 'color' => '#cbd5e1', 'text' => '#111827'],
                ['label' => 'En cours', 'color' => '#3996d3'],
                ['label' => 'En validation chef', 'color' => '#9333ea'],
                ['label' => 'En validation controleur', 'color' => '#4f46e5'],
                ['label' => 'Clôture', 'color' => '#00b050'],
            ],
            'Alerte echeance' => [
                ['label' => 'Aucune alerte', 'color' => '#d9ead3', 'text' => '#14532d'],
                ['label' => 'Echeance proche', 'color' => '#fff200', 'text' => '#111827'],
                ['label' => 'Critique', 'color' => '#f9b13c', 'text' => '#111827'],
                ['label' => 'En retard', 'color' => '#ff0000'],
                ['label' => 'Clôturée', 'color' => '#00b050'],
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

        if (in_array($validation, ['validee_chef', 'soumise_controle'], true)) {
            return 'validation_controleur';
        }

        // 3e visa : l'action a ete visee par le controle et attend la
        // validation finale de la planification.
        if (in_array($validation, ['soumise_planification', 'correction_planification'], true)) {
            return 'validation_planification';
        }

        if (in_array($validation, ['validee_planification', 'validee_controle', 'validee_direction'], true)) {
            return 'cloture';
        }

        if (in_array($validation, ['soumise_chef', 'en_validation_chef'], true)) {
            return 'validation_chef';
        }

        if ($this->actionStatusService->isNotStarted($action)) {
            return 'non_demarre';
        }

        return 'en_cours';
    }

    private function delayStatus(Action $action, ?float $rate = null, float $completionThreshold = 100.0): string
    {
        return $this->delayStatusForDates(
            $this->deadline($action),
            $this->completedAt($action),
            $rate,
            $completionThreshold,
            $this->thresholdReachedAt($action)
        );
    }

    /**
     * Statut delai d'une action.
     *
     * Regle : on compare toujours une DATE a l'echeance, jamais le seul fait
     * d'avoir atteint le seuil. Auparavant, une action ayant atteint son seuil
     * sans etre cloturee etait declaree « dans les delais » meme si l'echeance
     * etait depassee depuis des mois — une decision metier trompeuse.
     *
     * Date de reference retenue, par ordre de priorite :
     *   1. date de fin reelle / cloture (`$completedAt`) ;
     *   2. date d'atteinte du seuil (`seuil_atteint_le`) ;
     *   3. a defaut, la date du jour.
     */
    private function delayStatusForDates(
        ?Carbon $deadline,
        ?Carbon $completedAt = null,
        ?float $rate = null,
        float $completionThreshold = 100.0,
        ?Carbon $thresholdReachedAt = null
    ): string {
        if ($deadline === null) {
            return 'dans_les_delais';
        }

        $limit = $deadline->copy()->endOfDay();

        if ($completedAt !== null) {
            return $completedAt->gt($limit) ? 'hors_delai' : 'dans_les_delais';
        }

        if ($rate !== null && $rate >= $completionThreshold) {
            // Seuil atteint : c'est la date d'atteinte qui fait foi. Si elle n'a
            // pas ete enregistree (donnees anterieures a son introduction), on
            // compare la date du jour plutot que de supposer le respect du delai.
            $reference = $thresholdReachedAt ?? now();

            return $reference->copy()->startOfDay()->gt($limit) ? 'hors_delai' : 'dans_les_delais';
        }

        return now()->startOfDay()->gt($limit) ? 'hors_delai' : 'dans_les_delais';
    }

    private function alertStatus(
        Action $action,
        ?float $rate = null,
        float $completionThreshold = 100.0,
        ?string $workflowStatus = null
    ): string {
        if ($this->completedAt($action) !== null
            || ($workflowStatus ?? $this->workflowStatus($action)) === 'cloture'
            || ($rate !== null && $rate >= $completionThreshold)
        ) {
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
    private function actionDisplayStatus(array $officialResult, string $delayStatus, string $workflowStatus, float $completionThreshold = 100.0): string
    {
        if ($workflowStatus === 'a_parametrer' || ! (bool) ($officialResult['is_configured'] ?? false)) {
            return PtaOfficialCalculationService::STATUS_TO_CONFIGURE;
        }

        $rate = $officialResult['rate'] !== null ? (float) $officialResult['rate'] : null;

        return $this->officialCalculation->statusForRate($rate, $delayStatus === 'hors_delai', $completionThreshold);
    }

    /**
     * @param  array<string, mixed>  $officialResult
     */
    private function subActionDisplayStatus(array $officialResult, string $delayStatus, float $completionThreshold = 100.0): string
    {
        if (! (bool) ($officialResult['is_configured'] ?? false)) {
            return PtaOfficialCalculationService::STATUS_TO_CONFIGURE;
        }

        $rate = $officialResult['rate'] !== null ? (float) $officialResult['rate'] : null;

        return $this->officialCalculation->statusForRate($rate, $delayStatus === 'hors_delai', $completionThreshold);
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
            'a_parametrer' => 'À paramétrer',
            'non_demarre' => 'Non demarre',
            'en_cours' => 'En cours',
            'validation_chef' => 'En validation chef',
            'validation_controleur' => 'En validation controleur',
            'validation_planification' => 'En validation planification',
            'cloture' => 'Clôture',
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
            'cloturee' => 'Clôturée',
            'a_parametrer' => 'À paramétrer',
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

    /**
     * Date d'atteinte du seuil de completude, si elle a ete enregistree.
     */
    private function thresholdReachedAt(Action $action): ?Carbon
    {
        $value = $action->seuil_atteint_le ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
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

    public function canInlineEditAction(Action $action, User $user): bool
    {
        $pta = $action->pta;
        if ($pta === null || (string) ($pta->statut ?? '') === 'archive') {
            return false;
        }

        if (! $this->canAccess($user)) {
            return false;
        }

        return $this->hasInlineControlProfile($user);
    }

    public function hasInlineControlProfile(User $user): bool
    {
        return $user->hasRole(
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ
        ) || $user->isPlanningControlChief();
    }

    private function canReadPtaSuiviDirection(User $user, ?int $directionId): bool
    {
        if ($this->hasInlineControlProfile($user) || $user->hasGlobalReadAccess()) {
            return true;
        }

        return $directionId !== null && (int) $user->direction_id === $directionId;
    }

    private function canReadPtaSuiviService(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($this->hasInlineControlProfile($user) || $user->hasGlobalReadAccess()) {
            return true;
        }

        if ($directionId === null || (int) $user->direction_id !== $directionId) {
            return false;
        }

        return $user->service_id === null || ($serviceId !== null && (int) $user->service_id === $serviceId);
    }

    /**
     * @param  Builder<Action>  $query
     */
    private function scopeVisibleActions(Builder $query, User $user): void
    {
        if ($this->hasInlineControlProfile($user) || $user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(function (Builder $responsibilityQuery) use ($user): void {
                $responsibilityQuery
                    ->where('responsable_id', $user->id)
                    ->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey($user->id))
                    ->orWhereHas('sousActions', fn (Builder $subActionQuery) => $subActionQuery->where('agent_id', $user->id));
            });

            return;
        }

        if ($user->service_id !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery
                ->where('direction_id', (int) $user->direction_id)
                ->where('service_id', (int) $user->service_id));

            return;
        }

        if ($user->direction_id !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', (int) $user->direction_id));

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @return array<string, string>
     */
    private function indicatorTypeOptions(): array
    {
        return collect(TypeIndicateur::cases())
            ->mapWithKeys(fn (TypeIndicateur $type): array => [$type->value => $type->label()])
            ->all();
    }

    private function rawDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function rawDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function rawInteger(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '';
        }

        $value = (int) $value;

        return $value > 0 ? (string) $value : '';
    }

    private function indicatorDisplayLabel(TypeIndicateur $type, ?string $indicator, string $quantity, string $unit, string $deliverable): string
    {
        $indicator = trim((string) $indicator);
        $quantity = trim($quantity.' '.$unit);
        $deliverable = trim($deliverable);
        $parts = [
            'Type : '.$type->label(),
            'Indicateur : '.($indicator !== '' ? $indicator : 'À renseigner'),
        ];

        if ($type->tracksQuantity()) {
            $parts[] = 'Quantité à réaliser : '.($quantity !== '' ? $quantity : 'À renseigner');
        }

        if ($type->tracksDeliverable()) {
            $parts[] = 'Livrable attendu : '.($deliverable !== '' ? $deliverable : 'À renseigner');
        }

        return implode(' | ', $parts);
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
                'statut' => $this->workflowStatus($action) === 'cloture' ? 'Clôture' : ($this->workflowStatus($action) === 'validation_controleur' ? 'En attente controle' : 'Non transmis'),
                'validateur' => (string) ($action->controleReviewedBy?->name ?? $action->clotureePar?->name ?? '-'),
                'date' => $action->controle_reviewed_at?->format('Y-m-d H:i') ?? $action->cloture_le?->format('Y-m-d H:i') ?? '-',
                'commentaire' => $this->dash($action->controle_comment ?? $action->justification_cloture ?? $action->rapport_final ?? null),
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

    public function titleScopeLabel(array $filters): string
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
        $objective = ($filters['objectif_operationnel_id'] ?? null) !== null
            ? ObjectifOperationnel::query()->find((int) $filters['objectif_operationnel_id'])
            : null;

        return implode(' | ', array_filter([
            $service instanceof Service ? 'Service : '.$this->entityLabel($service->code, $service->libelle, 'Service') : null,
            $direction instanceof Direction ? 'Direction : '.$this->entityLabel($direction->code, $direction->libelle, 'Direction') : null,
            $objective instanceof ObjectifOperationnel ? 'Objectif operationnel : '.$this->entityLabel($objective->code, $objective->libelle, 'Objectif operationnel') : null,
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
        if (! is_scalar($value)) {
            return 'all';
        }

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

    private function thresholdLabel(float $completionThreshold): string
    {
        return $this->numberLabel($completionThreshold).'%';
    }

    private function completionThreshold(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 80.0;
        }

        return min(100.0, max(0.0, (float) $value));
    }

    /**
     * @param  list<mixed>  $values
     */
    /**
     * Ressources requises telles qu'attendues par le modele institutionnel du
     * rapport d'evolution : un champ de synthese s'il est renseigne, sinon la
     * concatenation des ressources humaines / materielles / techniques /
     * financieres saisies.
     */
    private function resourcesLabel(Action $action): string
    {
        // `resourceLabels()` est la lecture canonique : elle gere le tableau JSON
        // `ressources_necessaires` et retombe sur les anciennes colonnes booleennes.
        $parts = collect($action->resourceLabels())
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter();

        $details = $this->firstFilledText([$action->ressources_details ?? null]);
        if ($details !== null) {
            $parts->push($details);
        }

        return $parts->unique()->isNotEmpty() ? $parts->unique()->implode(', ') : '-';
    }

    /**
     * Risques potentiels, complementes du niveau de risque quand il est saisi.
     */
    private function risksLabel(Action $action): string
    {
        $risk = $this->firstFilledText([
            $action->risque_potentiel ?? null,
            $action->risque_lie ?? null,
        ]);

        if ($risk === null) {
            return '-';
        }

        $level = trim((string) ($action->niveau_risque ?? ''));

        return $level !== '' ? $risk.' ('.UiLabel::alertLevel($level).')' : $risk;
    }

    /**
     * Livrable attendu, exprime tel qu'il figure sur le modele institutionnel :
     * la quantite suivie de son unite (« 100 dossiers ») lorsque l'action est
     * quantifiee, sinon le libelle du livrable, sinon l'indicateur.
     */
    private function expectedDeliverableLabel(
        mixed $quantity,
        string $unit,
        string $deliverable,
        ?string $indicator
    ): string {
        $quantity = $quantity !== null ? (float) $quantity : 0.0;

        if ($quantity > 0.0) {
            return trim($this->numberLabel($quantity).' '.$unit);
        }

        return $this->firstFilledText([$deliverable, $indicator]) ?? '-';
    }

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
            return 'À paramétrer';
        }

        return $this->formatPercent($value);
    }

    /**
     * Formatte un pourcentage sans decimales inutiles.
     *
     * Les decimales n'apparaissent que si elles portent une information :
     * `0` s'affiche « 0% » et non « 0.00% », `100` s'affiche « 100% »,
     * tandis que `12.5` reste « 12.5% » et `0.01` reste « 0.01% ».
     */
    private function formatPercent(float $value): string
    {
        $rounded = round($value, 2);

        if (abs($rounded - round($rounded)) < 0.005) {
            return number_format($rounded, 0, '.', ' ').'%';
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ' '), '0'), '.').'%';
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
