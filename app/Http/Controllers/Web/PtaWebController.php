<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePtaRequest;
use App\Http\Requests\UpdatePtaRequest;
use App\Models\Action;
use App\Models\JournalAudit;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\DeletionRequestService;
use App\Services\ExerciceContext;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\PlanningClosureReportService;
use App\Services\PlanningModificationLockService;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\WorkflowSettings;
use App\Support\SchemaIntrospectionCache;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Contrôleur des PTA — Plan de Travail Annuel.
 *
 * Un PTA est le plan d'un service pour une année donnée. Il est rattaché à un PAO
 * et contient les actions à réaliser. Il suit le cycle : en cours -> cloture -> archive.
 */
class PtaWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    /** Affiche la liste des PTA avec filtres (direction, service, statut) et statistiques. */
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        if ($user->isAgent()) {
            abort(403, 'Acces non autorise.');
        }

        $query = Pta::query();

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPta($query);

        $query->when(
            $request->filled('pao_id'),
            fn ($q) => $q->where('pao_id', (int) $request->integer('pao_id'))
        );
        $query->when(
            $request->filled('objectif_operationnel_id'),
            fn ($q) => $q->where('objectif_operationnel_id', (int) $request->integer('objectif_operationnel_id'))
        );
        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn ($q) => $q->where('service_id', (int) $request->integer('service_id'))
        );
        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            $query->where('statut', $statusFilter);
        }
        $query->when(
            $request->boolean('without_action'),
            fn ($q) => $q->doesntHave('actions')
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $statsBase = clone $query;
        $byStatus = (clone $statsBase)
            ->select('statut')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('statut')
            ->pluck('cnt', 'statut');
        $ptaIdsSubquery = (clone $statsBase)->select('id');
        $ptaStats = [
            'total' => (int) $byStatus->sum(),
            'en_cours' => (int) ($byStatus[Pta::STATUS_EN_COURS] ?? 0),
            'clotures' => (int) ($byStatus[Pta::STATUS_CLOTURE] ?? 0),
            'archives' => (int) ($byStatus[Pta::STATUS_ARCHIVE] ?? 0),
            'sans_action' => (clone $statsBase)->doesntHave('actions')->count(),
            'actions_total' => DB::table('actions')->whereIn('pta_id', $ptaIdsSubquery)->count(),
            'services' => (clone $statsBase)->distinct()->count('service_id'),
        ];

        $rows = $query
            ->with([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,libelle,echeance',
                'objectifOperationnel.pas:id,titre,periode_debut,periode_fin',
                'objectifOperationnel.pasAxe:id,pas_id,code,libelle',
                'objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ])
            ->withCount('actions')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.pta.index', [
            'rows' => $rows,
            'scope' => $user->accessScope(),
            'ptaStats' => $ptaStats,
            'objectifOperationnelOptions' => $this->objectifOperationnelOptions($user),
            'paoOptions' => $this->paoOptions($user),
            'serviceOptions' => $this->serviceOptions($user),
            'statusOptions' => $this->statusOptions($user),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pao_id' => $request->filled('pao_id') ? (int) $request->integer('pao_id') : null,
                'objectif_operationnel_id' => $request->filled('objectif_operationnel_id') ? (int) $request->integer('objectif_operationnel_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'statut' => $statusFilter,
                'without_action' => $request->boolean('without_action'),
            ],
        ]);
    }

    /** Affiche le formulaire de création d'un nouveau PTA. */
    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $prefilledObjectifId = $request->filled('objectif_operationnel_id')
            ? (int) $request->integer('objectif_operationnel_id')
            : null;
        if ($prefilledObjectifId === null && $request->filled('pao_id')) {
            $prefilledObjectifId = ObjectifOperationnel::query()
                ->where('pao_id', (int) $request->integer('pao_id'))
                ->when($user->service_id !== null, fn ($query) => $query->where('service_id', (int) $user->service_id))
                ->orderBy('id')
                ->value('id');
            $prefilledObjectifId = $prefilledObjectifId !== null ? (int) $prefilledObjectifId : null;
        }

        return view('workspace.pta.form', [
            'mode' => 'create',
            'row' => tap(new Pta, function (Pta $pta) use ($prefilledObjectifId): void {
                $pta->titre = 'PTA - SERVICE';
                $pta->objectif_operationnel_id = $prefilledObjectifId;
            }),
            'objectifOperationnelOptions' => $this->objectifOperationnelOptions($user, $prefilledObjectifId),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => [],
        ]);
    }

    /** Enregistre un nouveau PTA après validation du formulaire. */
    public function store(StorePtaRequest $request, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $objectifOperationnel = $this->resolveObjectifOperationnel((int) $validated['objectif_operationnel_id']);
        $pao = $objectifOperationnel->pao;
        $serviceId = (int) $objectifOperationnel->service_id;

        $statut = 'en_cours';

        $this->denyUnlessManagePta(
            $user,
            (int) $pao->direction_id,
            $serviceId
        );

        $payload = [
            'pao_id' => (int) $pao->id,
            'objectif_operationnel_id' => (int) $objectifOperationnel->id,
            'direction_id' => (int) $pao->direction_id,
            'service_id' => $serviceId,
            'titre' => $this->generatedPtaTitle($objectifOperationnel->service),
            'description' => null,
            'exercice_id' => $pao->exercice_id,
        ];

        $existingPta = $this->findServiceYearPta($serviceId, $pao);
        if ($existingPta instanceof Pta && $existingPta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors([
                'service_id' => 'Le PTA annuel de ce service est archive. Il ne peut plus recevoir d actions.',
            ])->withInput();
        }
        $lockService = app(PlanningModificationLockService::class);
        if ($existingPta instanceof Pta) {
            if ($message = $lockService->ensureUnlocked($existingPta, $user)) {
                return back()->withInput()->withErrors(['general' => $message]);
            }
        }

        $before = $existingPta?->toArray();
        $pta = $existingPta instanceof Pta ? $existingPta : new Pta;
        if ($existingPta instanceof Pta) {
            $payload['pao_id'] = (int) $existingPta->pao_id;
            $payload['objectif_operationnel_id'] = (int) ($existingPta->objectif_operationnel_id ?: $objectifOperationnel->id);
            $payload['direction_id'] = (int) $existingPta->direction_id;
            $payload['service_id'] = (int) $existingPta->service_id;
            $payload['exercice_id'] = $existingPta->exercice_id;
            $payload['titre'] = (string) ($existingPta->titre ?: $payload['titre']);
        }
        $currentStatus = in_array((string) $existingPta?->statut, [Pta::STATUS_CLOTURE, Pta::STATUS_ARCHIVE], true)
            ? (string) $existingPta->statut
            : $statut;
        $pta->fill($payload);
        // statut / valide_* ne sont plus mass-assignables (defense en profondeur
        // contre l'escalade de privileges). On les positionne via forceFill.
        $pta->forceFill([
            'statut' => $currentStatus,
            'valide_le' => $existingPta?->valide_le,
            'valide_par' => $existingPta?->valide_par,
        ])->save();

        $savedActions = $this->syncPtaActions(
            $pta,
            $objectifOperationnel,
            $this->withUploadedActionFiles((array) ($validated['actions'] ?? []), $request),
            $user
        );
        $lockService->lockAfterSave($pta, $user);

        $after = array_merge($pta->toArray(), [
            'actions_enregistrees' => collect($savedActions)->pluck('id')->all(),
        ]);

        $this->recordAudit(
            $request,
            'pta',
            $existingPta === null ? 'create' : 'update',
            $pta,
            $before,
            $after
        );

        if ($existingPta === null) {
            $notificationService->notifyPtaCreatedToDirection($pta, $user);
        }

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $existingPta === null
                ? $this->entityCreatedMessage(UiLabel::object('pta'))
                : $this->entityUpdatedMessage(UiLabel::object('pta')).' Le PTA annuel du service existait deja: les actions ont ete ajoutees ou mises a jour dedans.');
    }

    /** Affiche le formulaire de modification d'un PTA existant. */
    public function edit(Request $request, Pta $pta): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $selectedObjectifId = $this->selectedObjectifIdForPta($request, $pta);

        $pta->loadMissing([
            'actions' => fn ($query) => $query
                ->where('objectif_operationnel_id', $selectedObjectifId)
                ->select([
                    'id',
                    'pta_id',
                    'pao_id',
                    'objectif_operationnel_id',
                    'mode_evaluation',
                    'type_action',
                    'requires_comment',
                    'allows_difficulty',
                    'official_progress_percent',
                    'libelle',
                    'description',
                    'date_debut',
                    'date_fin',
                    'date_echeance',
                    'statut',
                    'priorite',
                    'intitule_cible',
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
                    'observations',
                    'montant_estime',
                    'nature_financement',
                    'description_financement',
                    'source_financement',
                    'commentaire_financement',
                    'justificatif_financement_path',
                    'ressources_necessaires',
                    'ressources_details',
                    'ressource_main_oeuvre',
                    'ressource_equipement',
                    'ressource_partenariat',
                    'ressource_autres',
                    'ressource_autres_details',
                    'risque_potentiel',
                    'niveau_risque',
                    'mesures_preventives',
                    'financement_requis',
                    'financement_statut',
                    'financement_soumis_le',
                    'financement_notifie_le',
                    'responsable_id',
                    'nombre_sous_actions_prevu',
                    'statut_parametrage',
                    'modification_locked_at',
                    'modification_unlocked_at',
                    'modification_unlock_expires_at',
                ])
                ->orderByRaw('CASE WHEN date_echeance IS NULL AND date_fin IS NULL AND date_debut IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('COALESCE(date_echeance, date_fin, date_debut) ASC')
                ->orderBy('date_debut')
                ->orderBy('id'),
            'actions.responsables:id,name,email',
            'actions.sousActions' => fn ($query) => $query
                ->select('id', 'action_id', 'agent_id', 'libelle', 'sub_action_type', 'weight', 'requires_proof', 'requires_comment', 'allows_difficulty', 'official_progress_percent', 'validation_status', 'description', 'resultat_attendu', 'cible_prevue', 'unite', 'commentaire', 'date_debut', 'date_fin', 'statut', 'est_effectuee')
                ->orderBy('date_debut')
                ->orderBy('date_fin')
                ->orderBy('id'),
        ]);

        return view('workspace.pta.form', [
            'mode' => 'edit',
            'row' => $pta,
            'selectedObjectifId' => $selectedObjectifId,
            'objectifOperationnelOptions' => $this->objectifOperationnelOptions($user, $selectedObjectifId),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => $this->validationTimeline($pta),
        ]);
    }

    /** Sauvegarde les modifications apportées à un PTA. */
    public function update(UpdatePtaRequest $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de modifier un PTA archivé.']);
        }
        $lockService = app(PlanningModificationLockService::class);
        if ($message = $lockService->ensureUnlocked($pta, $user)) {
            return back()->withErrors(['general' => $message]);
        }

        $validated = $request->validated();
        $objectifOperationnel = $this->resolveObjectifOperationnel((int) $validated['objectif_operationnel_id']);
        $targetPao = $objectifOperationnel->pao;
        $targetServiceId = (int) $objectifOperationnel->service_id;

        $statut = in_array((string) $pta->statut, [Pta::STATUS_CLOTURE, Pta::STATUS_ARCHIVE], true)
            ? (string) $pta->statut
            : Pta::STATUS_EN_COURS;

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );
        $this->denyUnlessManagePta(
            $user,
            (int) $targetPao->direction_id,
            $targetServiceId
        );

        $existingServiceYearPta = $this->findServiceYearPta($targetServiceId, $targetPao, (int) $pta->id);
        if ($existingServiceYearPta instanceof Pta) {
            return back()->withErrors([
                'service_id' => 'Un PTA existe deja pour ce service et cette annee. Modifiez ce PTA existant au lieu de rattacher celui-ci au meme perimetre.',
            ])->withInput();
        }

        $payload = [
            'pao_id' => (int) $targetPao->id,
            'objectif_operationnel_id' => (int) $objectifOperationnel->id,
            'direction_id' => (int) $targetPao->direction_id,
            'service_id' => $targetServiceId,
            'titre' => $this->generatedPtaTitle($objectifOperationnel->service),
            'description' => null,
            'exercice_id' => $targetPao->exercice_id,
        ];

        $before = $pta->toArray();
        $pta->fill($payload);
        // statut n est plus mass-assignable : on conserve l etat workflow courant.
        $pta->forceFill(['statut' => $statut])->save();

        $savedActions = $this->syncPtaActions(
            $pta,
            $objectifOperationnel,
            $this->withUploadedActionFiles((array) ($validated['actions'] ?? []), $request),
            $user
        );
        $lockService->lockAfterSave($pta->refresh(), $user);

        $after = array_merge($pta->toArray(), [
            'actions_enregistrees' => collect($savedActions)->pluck('id')->all(),
        ]);

        $this->recordAudit($request, 'pta', 'update', $pta, $before, $after);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pta')));
    }

    /** Supprime un PTA si aucun impact metier ne bloque l'operation. */
    public function destroy(Request $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de supprimer directement un PTA archivé.']);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $deletionRequests = app(DeletionRequestService::class);
        // Super Admin et DG peuvent supprimer directement avec cascade (Actions et
        // sous-actions). Les autres roles passent par le workflow de demande validee.
        $canDeleteDirectly = $user->isSuperAdmin() || $user->hasRole(User::ROLE_DG);
        if (! $canDeleteDirectly) {
            $deletionRequest = $deletionRequests->requestBusinessDeletion($pta, $user, (string) $validated['motif'], 'pta');
            $this->recordAudit($request, 'pta', 'deletion_request_create', $deletionRequest, null, $deletionRequest->toArray());

            return redirect()
                ->route('workspace.pta.index')
                ->with('success', 'Demande de suppression du PTA transmise au Super Admin.');
        }

        $before = $pta->toArray();
        $impact = $deletionRequests->impactForEntity($pta);
        $deletionRequests->deleteBusinessTarget($pta);

        $this->recordAudit($request, 'pta', 'delete', $pta, [
            ...$before,
            'deletion_reason' => (string) $validated['motif'],
            'impact' => $impact,
        ], null);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pta')));
    }

    /**
     * Sauvegarde inline d'une seule action via AJAX depuis le formulaire PTA.
     *
     * Le frontend envoie le payload d'UNE action (sans le prefixe `actions[0]`).
     * On encapsule dans un tableau a un element pour reutiliser syncPtaActions(),
     * puis on retourne JSON avec l'id (re)cree et eventuels erreurs de validation.
     */
    public function upsertActionInline(Request $request, Pta $pta): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'message' => 'Non authentifie.'], 401);
        }

        if ($pta->statut === Pta::STATUS_ARCHIVE) {
            return response()->json(['ok' => false, 'message' => 'Impossible de modifier un PTA archivé.'], 422);
        }

        $this->denyUnlessManagePta($user, (int) $pta->direction_id, (int) $pta->service_id);

        $lockService = app(PlanningModificationLockService::class);
        if ($message = $lockService->ensureUnlocked($pta, $user)) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        try {
            $validated = $request->validate([
                'id' => ['nullable', 'integer', 'exists:actions,id'],
                'objectif_operationnel_id' => ['nullable', 'integer', 'exists:objectifs_operationnels,id'],
                'libelle' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'resultat_attendu' => ['nullable', 'string'],
                'date_debut' => ['required', 'date', 'date_format:Y-m-d'],
                'date_fin' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
                // Workflow V2 : type_action pilote. mode_evaluation devient optionnel
                // (dérivé ci-dessous) pour compat avec l'ancien front éventuel.
                'type_action' => ['required', Rule::in([
                    Action::TYPE_QUANTITATIVE,
                    Action::TYPE_NON_QUANTITATIVE,
                    Action::TYPE_COMPOSEE,
                ])],
                'mode_evaluation' => ['nullable', Rule::in([
                    Action::MODE_SOUS_ACTIONS,
                    Action::MODE_QUANTITATIF,
                    Action::MODE_SANS_QUANTITE,
                    Action::MODE_MIXTE,
                ])],
                'requires_comment' => ['nullable', 'boolean'],
                'allows_difficulty' => ['nullable', 'boolean'],
                'rmo_ids' => ['required', 'array', 'min:1'],
                'rmo_ids.*' => ['integer', 'exists:users,id'],
                'quantite_cible' => ['nullable', 'integer', 'min:1'],
                'unite_cible' => ['nullable', 'string', 'max:100'],
                'seuil_mode' => ['nullable', Rule::in(['unique', 'trimestriel'])],
                'seuil_minimum' => ['nullable', 'integer', 'min:0', 'max:100'],
                'justificatif_obligatoire' => ['nullable', 'boolean'],
                'financement_requis' => ['nullable', 'boolean'],
                'montant_estime' => ['nullable', 'integer', 'min:0'],
                'nature_financement' => ['nullable', 'string', 'max:255'],
                'ressources_necessaires' => ['nullable', 'array'],
                'ressources_necessaires.*' => ['string', 'max:255'],
                'sous_actions' => ['nullable', 'array'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $this->validationErrorMessage($e),
                'errors' => $e->errors(),
            ], 422);
        }

        // Workflow V2 : dérive mode_evaluation depuis type_action si non fourni.
        if (empty($validated['mode_evaluation'])) {
            $validated['mode_evaluation'] = match ($validated['type_action'] ?? '') {
                Action::TYPE_QUANTITATIVE => Action::MODE_QUANTITATIF,
                Action::TYPE_COMPOSEE => Action::MODE_SOUS_ACTIONS,
                default => Action::MODE_SANS_QUANTITE,
            };
        }

        try {
            $objectifOperationnel = isset($validated['objectif_operationnel_id'])
                ? $this->resolveObjectifOperationnel((int) $validated['objectif_operationnel_id'])
                : $pta->objectifOperationnel;
            if (! $objectifOperationnel instanceof ObjectifOperationnel) {
                return response()->json(['ok' => false, 'message' => 'PTA sans objectif operationnel rattache.'], 422);
            }

            if ((int) $objectifOperationnel->direction_id !== (int) $pta->direction_id
                || (int) $objectifOperationnel->service_id !== (int) $pta->service_id) {
                return response()->json(['ok' => false, 'message' => 'Objectif operationnel hors perimetre du PTA.'], 422);
            }

            $submittedActionId = isset($validated['id']) && is_numeric($validated['id'])
                ? (int) $validated['id']
                : 0;
            if ($submittedActionId > 0) {
                $actionMatchesObjective = Action::query()
                    ->whereKey($submittedActionId)
                    ->where('pta_id', (int) $pta->id)
                    ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
                    ->exists();

                if (! $actionMatchesObjective) {
                    return response()->json(['ok' => false, 'message' => 'Cette action ne peut pas etre modifiee depuis cet objectif operationnel.'], 422);
                }
            }

            $saved = $this->syncPtaActions($pta, $objectifOperationnel, [$validated], $user);
            $action = $saved[0] ?? null;

            if (! $action instanceof Action) {
                return response()->json(['ok' => false, 'message' => 'Echec de la sauvegarde.'], 500);
            }

            // Recharger les sous-actions dans l'ordre (id ASC) pour que le front
            // puisse les associer 1:1 aux blocs DOM par index — indispensable pour
            // mettre a jour les inputs cachés sous_actions[N][id] et eviter les
            // doublons aux saves suivants.
            $action->refresh();
            $subActionIds = $action->sousActions()->orderBy('id')->pluck('id')->all();
            $lockMessageAfterSave = $lockService->ensureUnlocked($action, $user);

            return response()->json([
                'ok' => true,
                'message' => $validated['id'] ?? null ? 'Action mise à jour.' : 'Action creee.',
                'action' => [
                    'id' => $action->id,
                    'code' => $action->code,
                    'libelle' => $action->libelle,
                    'sous_action_ids' => $subActionIds,
                    'locked' => $lockMessageAfterSave !== null,
                    'lock_message' => $lockMessageAfterSave,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $this->validationErrorMessage($e),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Erreur serveur : '.$e->getMessage(),
            ], 500);
        }
    }

    private function validationErrorMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                $message = trim((string) $message);
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return 'Donnees invalides.';
    }

    public function close(Request $request, Pta $pta, PlanningClosureReportService $closureReportService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePta($user, (int) $pta->direction_id, (int) $pta->service_id);

        if ((string) $pta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de cloturer un PTA archivé.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
            'force_close' => ['nullable', 'boolean'],
        ]);

        $report = $closureReportService->forPta($pta);
        $forceClose = $request->boolean('force_close');

        if ($closureReportService->hasAnomalies($report) && ! $forceClose) {
            return back()
                ->withInput()
                ->with('closure_report', $report)
                ->withErrors(['general' => $this->closureReportErrorMessage($report)]);
        }

        $before = $pta->toArray();
        $pta->forceFill([
            'statut' => Pta::STATUS_CLOTURE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pta', 'close', $pta, $before, [
            ...$pta->toArray(),
            'motif' => (string) $validated['motif'],
            'closure_report' => $report,
            'forced_with_anomalies' => $forceClose,
        ]);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', 'PTA clôturé avec rapport d\'anomalies tracé.');
    }

    public function archive(Request $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePta($user, (int) $pta->direction_id, (int) $pta->service_id);

        if ((string) $pta->statut !== Pta::STATUS_CLOTURE) {
            return back()->withErrors(['general' => 'Le PTA doit etre cloture avant archivage.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $before = $pta->toArray();
        $pta->forceFill([
            'statut' => Pta::STATUS_ARCHIVE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pta', 'archive', $pta, $before, [
            ...$pta->toArray(),
            'motif' => (string) $validated['motif'],
        ]);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', 'PTA archivé.');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasPermission('planning.write.service');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function closureReportErrorMessage(array $report): string
    {
        $labels = collect($report['issues'] ?? [])
            ->map(fn (array $issue): string => $issue['label'].' ('.$issue['count'].')')
            ->implode(', ');

        return 'Rapport d anomalies obligatoire : '.$labels.'. Ajoutez une justification et cochez la cloture avec anomalies pour continuer.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validationTimeline(Pta $pta): array
    {
        return JournalAudit::query()
            ->with('user:id,name,email,role')
            ->where('module', 'pta')
            ->where('entite_type', $pta::class)
            ->where('entite_id', (int) $pta->id)
            ->whereIn('action', ['create', 'update', 'close', 'archive'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (JournalAudit $log): bool {
                if ($log->action !== 'update') {
                    return true;
                }

                $before = is_array($log->ancienne_valeur) ? $log->ancienne_valeur : [];
                $after = is_array($log->nouvelle_valeur) ? $log->nouvelle_valeur : [];

                return ($before['statut'] ?? null) !== ($after['statut'] ?? null);
            })
            ->map(function (JournalAudit $log): array {
                $before = is_array($log->ancienne_valeur) ? $log->ancienne_valeur : [];
                $after = is_array($log->nouvelle_valeur) ? $log->nouvelle_valeur : [];
                $from = isset($before['statut']) ? (string) $before['statut'] : null;
                $to = isset($after['statut']) ? (string) $after['statut'] : null;

                $label = match ($log->action) {
                    'create' => 'Creation',
                    'close' => 'Cloture',
                    'archive' => 'Archivage',
                    'update' => 'Changement statut',
                    default => ucfirst((string) $log->action),
                };

                return [
                    'date' => $log->created_at?->format('Y-m-d H:i:s'),
                    'action' => $label,
                    'from' => $from,
                    'to' => $to,
                    'reason' => isset($after['motif_retour']) ? (string) $after['motif_retour'] : null,
                    'user' => $log->user?->name ?? 'Systeme',
                    'user_role' => $log->user?->role ?? '-',
                ];
            })
            ->values()
            ->all();
    }

    private function findServiceYearPta(int $serviceId, Pao $pao, ?int $ignorePtaId = null): ?Pta
    {
        return Pta::query()
            ->where('service_id', $serviceId)
            ->where(function ($query) use ($pao): void {
                if ($pao->exercice_id !== null) {
                    $query->where('exercice_id', (int) $pao->exercice_id);

                    return;
                }

                $query->whereHas('pao', fn ($paoQuery) => $paoQuery->where('annee', (int) $pao->annee));
            })
            ->when($ignorePtaId !== null, fn ($query) => $query->whereKeyNot($ignorePtaId))
            ->orderBy('id')
            ->first();
    }

    private function selectedObjectifIdForPta(Request $request, Pta $pta): int
    {
        $candidateId = $request->filled('objectif_operationnel_id')
            ? (int) $request->integer('objectif_operationnel_id')
            : (int) $pta->objectif_operationnel_id;

        $baseQuery = ObjectifOperationnel::query()
            ->where('direction_id', (int) $pta->direction_id)
            ->where('service_id', (int) $pta->service_id);

        if ($candidateId > 0 && (clone $baseQuery)->whereKey($candidateId)->exists()) {
            return $candidateId;
        }

        $firstActionObjectifId = Action::query()
            ->where('pta_id', (int) $pta->id)
            ->whereNotNull('objectif_operationnel_id')
            ->orderBy('objectif_operationnel_id')
            ->value('objectif_operationnel_id');

        if (is_numeric($firstActionObjectifId) && (int) $firstActionObjectifId > 0) {
            return (int) $firstActionObjectifId;
        }

        if ((int) $pta->objectif_operationnel_id > 0) {
            return (int) $pta->objectif_operationnel_id;
        }

        return (int) ((clone $baseQuery)->orderBy('id')->value('id') ?? 0);
    }

    /**
     * @param  array<int, mixed>  $actionsPayload
     * @return list<Action>
     */
    private function syncPtaActions(Pta $pta, ObjectifOperationnel $objectifOperationnel, array $actionsPayload, User $actor): array
    {
        $savedActions = [];
        $trackingService = app(ActionTrackingService::class);
        $secureStorage = app(SecureJustificatifStorage::class);
        $notificationService = app(WorkspaceNotificationService::class);
        $lockService = app(PlanningModificationLockService::class);

        foreach ($actionsPayload as $actionPayload) {
            if (! is_array($actionPayload)) {
                continue;
            }

            $rmoIds = collect($actionPayload['rmo_ids'] ?? [])
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($rmoIds === []) {
                continue;
            }

            $actionId = isset($actionPayload['id']) && is_numeric($actionPayload['id'])
                ? (int) $actionPayload['id']
                : null;
            $isNewAction = $actionId === null;
            $action = $isNewAction
                ? new Action
                : Action::query()
                    ->whereKey($actionId)
                    ->where('pta_id', (int) $pta->id)
                    ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
                    ->firstOrFail();

            if (! $isNewAction) {
                // ensureUnlocked tient compte du role (SA/DG bypassent automatiquement).
                if ($message = $lockService->ensureUnlocked($action, $actor)) {
                    throw ValidationException::withMessages([
                        'actions' => $message,
                    ]);
                }
            }

            // Capture du statut_parametrage AVANT save pour detecter la transition
            // a_parametrer → parametre. Regle metier ANBG (2026-05-29) : les actions
            // ne partent au RMO que LORSQU'ELLES SONT EFFECTIVEMENT PARAMETREES ET
            // ENREGISTREES, pas a l'import. La notification est donc declenchee soit :
            //  - sur une action nouvelle creee directement (isNewAction = true), soit
            //  - sur une action existante qui passe de 'a_parametrer' a 'parametre'.
            $wasUnparametre = ! $isNewAction
                && (string) ($action->getOriginal('statut_parametrage') ?? '') === 'a_parametrer';

            $payload = $this->normalizePtaActionPayload($actionPayload, $pta, $objectifOperationnel, $rmoIds, $isNewAction, $action);
            // forceFill : le payload est integralement construit par normalizePtaActionPayload
            // (entrees utilisateur deja filtrees) et contient des champs workflow/calculs
            // qui ne sont plus mass-assignables (cf. A02).
            $action->forceFill($payload)->save();

            $this->syncActionRmos($action, $rmoIds);
            $this->syncPlannedSubActions($action, $actionPayload, $rmoIds);

            $becameParametre = (string) ($action->statut_parametrage ?? '') === 'parametre';

            if ($isNewAction) {
                $trackingService->initializeActionTracking($action, $actor);
                if ($becameParametre) {
                    $notificationService->notifyActionAssigned($action, $actor);
                }
            } else {
                $trackingService->regenerateWeeks($action);
                $trackingService->refreshActionMetrics($action);
                // Notification au(x) RMO uniquement a la PREMIERE bascule parametrage.
                if ($wasUnparametre && $becameParametre) {
                    $notificationService->notifyActionAssigned($action, $actor);
                }
            }

            if ((bool) $action->financement_requis) {
                $action = $trackingService->syncFinancingRequest($action, $actor);

                $file = $actionPayload['justificatif_financement'] ?? null;
                if ($file instanceof UploadedFile) {
                    $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                    $trackingService->addActionJustificatif(
                        $action,
                        null,
                        'financement',
                        $storedFile['path'],
                        $storedFile['nom_original'],
                        $storedFile['mime_type'],
                        $storedFile['taille_octets'],
                        'Justificatif du besoin de financement',
                        $actor,
                        $storedFile['est_chiffre']
                    );
                    $action->forceFill(['justificatif_financement_path' => $storedFile['path']])->save();
                }

                if ($action->financement_notifie_le === null) {
                    $notificationService->notifyActionFinancingRequested($action, $actor);
                    $action = $trackingService->markFinancingNotificationSent($action);
                }
            } else {
                $action->forceFill([
                    'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
                    'financement_soumis_le' => null,
                    'financement_notifie_le' => null,
                ])->save();
            }

            $lockService->lockAfterSave($action->refresh(), $actor);

            $savedActions[] = $action;
        }

        // Transition automatique BROUILLON → EN_COURS : si le PTA est en
        // brouillon ET que toutes ses actions ont statut_parametrage = 'parametre'
        // (i.e. plus aucune action a parametrer), il passe en cours.
        $this->maybePromoteBrouillonToEnCours($pta);

        return $savedActions;
    }

    /**
     * Si le PTA est en BROUILLON et que toutes ses actions ont ete parametrees
     * (statut_parametrage = 'parametre'), bascule automatiquement en EN_COURS.
     * Reflete la regle metier : le PTA est "enregistre" quand le chef de service
     * a termine de parametrer chaque action une par une.
     */
    private function maybePromoteBrouillonToEnCours(Pta $pta): void
    {
        if ($pta->statut !== Pta::STATUS_BROUILLON) {
            return;
        }

        $pendingActionsCount = Action::query()
            ->where('pta_id', $pta->id)
            ->where('statut_parametrage', 'a_parametrer')
            ->count();

        if ($pendingActionsCount > 0) {
            return;
        }

        // Toutes les actions sont parametrees → le PTA est officiellement enregistre.
        $pta->forceFill(['statut' => Pta::STATUS_EN_COURS])->save();
    }

    /**
     * @param  array<int, mixed>  $actionsPayload
     * @return array<int, mixed>
     */
    private function withUploadedActionFiles(array $actionsPayload, Request $request): array
    {
        $uploadedActions = $request->file('actions', []);
        if (! is_array($uploadedActions)) {
            return $actionsPayload;
        }

        foreach ($uploadedActions as $index => $files) {
            if (! is_array($files) || ! isset($actionsPayload[$index]) || ! is_array($actionsPayload[$index])) {
                continue;
            }

            $file = $files['justificatif_financement'] ?? null;
            if ($file instanceof UploadedFile) {
                $actionsPayload[$index]['justificatif_financement'] = $file;
            }
        }

        return $actionsPayload;
    }

    /**
     * @param  array<string, mixed>  $actionPayload
     * @param  list<int>  $rmoIds
     * @return array<string, mixed>
     */
    private function normalizePtaActionPayload(
        array $actionPayload,
        Pta $pta,
        ObjectifOperationnel $objectifOperationnel,
        array $rmoIds,
        bool $isNewAction,
        ?Action $existingAction = null
    ): array {
        $status = $isNewAction
            ? 'non_demarre'
            : (string) ($existingAction?->statut ?: 'non_demarre');
        $dynamicStatus = $isNewAction
            ? ActionTrackingService::STATUS_NON_DEMARRE
            : (string) ($existingAction?->statut_dynamique ?: ActionTrackingService::STATUS_NON_DEMARRE);

        $quantiteCible = $actionPayload['quantite_cible'] ?? null;
        $quantiteCible = ($quantiteCible === '' || $quantiteCible === null) ? null : (int) round((float) $quantiteCible);
        $typeAction = trim((string) ($actionPayload['type_action'] ?? ''));
        $modeEvaluation = trim((string) ($actionPayload['mode_evaluation'] ?? ''));
        if ($modeEvaluation === Action::MODE_MIXTE) {
            $modeEvaluation = filled($quantiteCible) ? Action::MODE_QUANTITATIF : Action::MODE_SOUS_ACTIONS;
        }
        if (! in_array($modeEvaluation, [
            Action::MODE_QUANTITATIF,
            Action::MODE_SANS_QUANTITE,
            Action::MODE_SOUS_ACTIONS,
        ], true)) {
            $modeEvaluation = filled($quantiteCible) ? Action::MODE_QUANTITATIF : Action::MODE_SANS_QUANTITE;
        }

        $montantEstime = $actionPayload['montant_estime'] ?? null;
        $montantEstime = ($montantEstime === '' || $montantEstime === null) ? null : (int) round((float) $montantEstime);
        $financementRequis = filter_var($actionPayload['financement_requis'] ?? false, FILTER_VALIDATE_BOOL);
        $selectedResources = collect($actionPayload['ressources_necessaires'] ?? [])
            ->filter(fn ($value): bool => is_string($value) && array_key_exists($value, Action::resourceOptions()))
            ->unique()
            ->values()
            ->all();
        $resourceDetails = ($value = trim((string) ($actionPayload['ressources_details'] ?? ''))) !== '' ? $value : null;
        $riskPotential = ($value = trim((string) (
            $actionPayload['risque_potentiel']
            ?? $actionPayload['risques']
            ?? ($isNewAction ? '' : $existingAction?->risque_potentiel)
            ?? ''
        ))) !== '' ? $value : null;
        $riskLevel = ($value = trim((string) (
            $actionPayload['niveau_risque']
            ?? ($isNewAction ? '' : $existingAction?->niveau_risque)
            ?? ''
        ))) !== '' ? $value : null;
        $preventiveMeasures = ($value = trim((string) (
            $actionPayload['mesures_preventives']
            ?? ($isNewAction ? '' : $existingAction?->mesures_preventives)
            ?? ''
        ))) !== '' ? $value : null;
        $natureFinancement = ($value = trim((string) ($actionPayload['nature_financement'] ?? ''))) !== '' ? $value : null;
        $dateFin = $actionPayload['date_fin']
            ?? optional($existingAction?->date_fin)->format('Y-m-d')
            ?? optional($objectifOperationnel->echeance)->format('Y-m-d')
            ?? ($actionPayload['date_debut'] ?? null);
        $isQuantitative = $modeEvaluation === Action::MODE_QUANTITATIF;
        $preserveQuantitativeTarget = $isQuantitative
            || ($typeAction === Action::TYPE_COMPOSEE && filled($quantiteCible));
        $actionPaoId = (int) $objectifOperationnel->pao_id;
        $actionObjectifId = (int) $objectifOperationnel->id;
        $thresholdMode = (string) ($actionPayload['seuil_mode'] ?? $existingAction?->seuil_mode ?? 'unique');
        $primaryRmoUniteId = isset($rmoIds[0])
            ? User::query()->whereKey((int) $rmoIds[0])->value('unite_dg_id')
            : null;

        $payload = [
            'exercice_id' => $pta->exercice_id,
            'pta_id' => (int) $pta->id,
            'pao_id' => $actionPaoId,
            'objectif_operationnel_id' => $actionObjectifId,
            'unite_dg_id' => $primaryRmoUniteId !== null ? (int) $primaryRmoUniteId : $existingAction?->unite_dg_id,
            'mode_evaluation' => $modeEvaluation,
            // Workflow V2 : type_action + conditions de conformité.
            'type_action' => match ($modeEvaluation) {
                Action::MODE_QUANTITATIF => Action::TYPE_QUANTITATIVE,
                Action::MODE_SOUS_ACTIONS => Action::TYPE_COMPOSEE,
                default => Action::TYPE_NON_QUANTITATIVE,
            },
            'requires_comment' => filter_var($actionPayload['requires_comment'] ?? $existingAction?->requires_comment ?? false, FILTER_VALIDATE_BOOL),
            'allows_difficulty' => filter_var($actionPayload['allows_difficulty'] ?? $existingAction?->allows_difficulty ?? true, FILTER_VALIDATE_BOOL),
            'libelle' => trim((string) ($actionPayload['libelle'] ?? '')),
            'description' => ($value = trim((string) ($actionPayload['description'] ?? ''))) !== ''
                ? $value
                : ($isNewAction ? null : $existingAction?->description),
            'type_cible' => $isQuantitative ? 'quantitative' : ($preserveQuantitativeTarget ? 'mixte' : 'qualitative'),
            'intitule_cible' => $existingAction?->intitule_cible,
            'priorite' => $actionPayload['priorite'] ?? null,
            'unite_cible' => $preserveQuantitativeTarget
                ? ($actionPayload['unite_cible'] ?? null)
                : null,
            'quantite_cible' => $preserveQuantitativeTarget
                ? $quantiteCible
                : null,
            'seuil_minimum' => (int) round((float) ($actionPayload['seuil_minimum'] ?? $existingAction?->seuil_minimum ?? 80)),
            'seuil_mode' => in_array($thresholdMode, ['unique', 'trimestriel'], true)
                ? $thresholdMode
                : 'unique',
            'seuil_t1' => $this->nullableFloat($actionPayload['seuil_t1'] ?? $existingAction?->seuil_t1 ?? null),
            'seuil_t2' => $this->nullableFloat($actionPayload['seuil_t2'] ?? $existingAction?->seuil_t2 ?? null),
            'seuil_t3' => $this->nullableFloat($actionPayload['seuil_t3'] ?? $existingAction?->seuil_t3 ?? null),
            'seuil_t4' => $this->nullableFloat($actionPayload['seuil_t4'] ?? $existingAction?->seuil_t4 ?? null),
            'methode_calcul' => match ($modeEvaluation) {
                Action::MODE_QUANTITATIF => 'cumulative_quantity',
                Action::MODE_SANS_QUANTITE => 'binary_completion',
                default => 'sum_sous_actions',
            },
            'justificatif_obligatoire' => filter_var($actionPayload['justificatif_obligatoire'] ?? $existingAction?->justificatif_obligatoire ?? false, FILTER_VALIDATE_BOOL),
            'echeance_cible' => $existingAction?->echeance_cible,
            'resultat_attendu' => ($value = trim((string) ($actionPayload['resultat_attendu'] ?? ''))) !== ''
                ? $value
                : ($isNewAction ? null : $existingAction?->resultat_attendu),
            'observations' => $isNewAction ? null : $existingAction?->observations,
            'date_debut' => $actionPayload['date_debut'] ?? null,
            'date_fin' => $dateFin,
            'date_echeance' => $dateFin,
            'responsable_id' => $rmoIds[0] ?? null,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'origine_action' => Action::ORIGIN_PTA,
            'statut' => $status,
            'statut_dynamique' => $dynamicStatus,
            'statut_parametrage' => 'parametre',
            'montant_estime' => $financementRequis ? $montantEstime : null,
            'financement_requis' => $financementRequis,
            'ressources_necessaires' => $selectedResources,
            'ressources_details' => $resourceDetails,
            'ressource_main_oeuvre' => in_array('ressources_humaines', $selectedResources, true)
                || in_array('main_oeuvre', $selectedResources, true),
            'ressource_equipement' => in_array('ressources_materielles', $selectedResources, true)
                || in_array('ressources_documentaires', $selectedResources, true)
                || in_array('ressources_informatiques', $selectedResources, true),
            'ressource_partenariat' => in_array('partenariat', $selectedResources, true),
            'ressource_autres' => in_array('autres_ressources', $selectedResources, true),
            'ressource_autres_details' => $resourceDetails,
            'risque_potentiel' => $riskPotential,
            'niveau_risque' => $riskLevel,
            'mesures_preventives' => $preventiveMeasures,
            'nature_financement' => $financementRequis ? $natureFinancement : null,
            'description_financement' => $financementRequis ? $natureFinancement : null,
            'source_financement' => null,
            'commentaire_financement' => null,
        ];

        if (! $financementRequis) {
            $payload['financement_statut'] = Action::FINANCEMENT_NON_REQUIS;
            $payload['financement_soumis_le'] = null;
            $payload['financement_notifie_le'] = null;
        } elseif ($isNewAction) {
            $payload['financement_statut'] = Action::FINANCEMENT_PRE_SIGNALE_DAF;
            $payload['financement_soumis_le'] = null;
            $payload['financement_notifie_le'] = now();
        }

        if ($isNewAction) {
            $payload['quantite_realisee'] = 0;
            $payload['progression_reelle'] = 0;
            $payload['progression_theorique'] = 0;
            $payload['avancement_operationnel'] = 0;
            $payload['taux_atteinte_cible'] = 0;
            $payload['taux_global'] = 0;
            $payload['seuil_alerte_progression'] = 10;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $actionPayload
     * @param  list<int>  $rmoIds
     */
    private function syncPlannedSubActions(Action $action, array $actionPayload, array $rmoIds): void
    {
        $subActionsPayload = $actionPayload['sous_actions'] ?? [];
        if (! is_array($subActionsPayload)) {
            return;
        }

        $defaultAgentId = $rmoIds[0] ?? $action->responsable_id;
        if (! is_numeric($defaultAgentId) || (int) $defaultAgentId <= 0) {
            return;
        }

        // Etape 1 — Determiner les ids preserves (ceux presents dans le payload).
        // Toute sous-action existante dont l'id n'est PAS dans le payload sera
        // supprimee : cela couvre le cas du save inline AJAX repete (sans cet
        // appel, chaque save creait des doublons).
        $preservedIds = [];
        foreach ($subActionsPayload as $subActionPayload) {
            if (! is_array($subActionPayload)) {
                continue;
            }
            if (isset($subActionPayload['id']) && is_numeric($subActionPayload['id']) && (int) $subActionPayload['id'] > 0) {
                $preservedIds[] = (int) $subActionPayload['id'];
            }
        }

        $obsoleteQuery = SousAction::query()->where('action_id', (int) $action->id);
        if ($preservedIds !== []) {
            $obsoleteQuery->whereNotIn('id', $preservedIds);
        }
        $obsoleteQuery->delete();

        foreach ($subActionsPayload as $subActionIndex => $subActionPayload) {
            if (! is_array($subActionPayload)) {
                continue;
            }

            $label = trim((string) ($subActionPayload['libelle'] ?? ''));
            if ($label === '') {
                continue;
            }

            $subActionId = isset($subActionPayload['id']) && is_numeric($subActionPayload['id'])
                ? (int) $subActionPayload['id']
                : null;
            $subAction = $subActionId !== null
                ? SousAction::query()
                    ->whereKey($subActionId)
                    ->where('action_id', (int) $action->id)
                    ->first()
                : null;

            // Workflow V2 : type + poids + conditions de la sous-action.
            $saType = trim((string) ($subActionPayload['sub_action_type'] ?? ''));
            if (! in_array($saType, [SousAction::TYPE_QUANTITATIVE, SousAction::TYPE_NON_QUANTITATIVE], true)) {
                $saType = $this->nullableFloat($subActionPayload['cible_prevue'] ?? null) !== null
                    ? SousAction::TYPE_QUANTITATIVE
                    : SousAction::TYPE_NON_QUANTITATIVE;
            }

            $payload = [
                'agent_id' => $this->resolveSubActionAgentId($subActionPayload, $subAction, $rmoIds, (int) $defaultAgentId, (int) $subActionIndex),
                'libelle' => $label,
                'sub_action_type' => $saType,
                'weight' => $this->nullableFloat($subActionPayload['weight'] ?? null),
                'requires_proof' => filter_var($subActionPayload['requires_proof'] ?? true, FILTER_VALIDATE_BOOL),
                'requires_comment' => filter_var($subActionPayload['requires_comment'] ?? false, FILTER_VALIDATE_BOOL),
                'allows_difficulty' => filter_var($subActionPayload['allows_difficulty'] ?? true, FILTER_VALIDATE_BOOL),
                'description' => ($value = trim((string) ($subActionPayload['description'] ?? ''))) !== '' ? $value : null,
                'resultat_attendu' => ($value = trim((string) ($subActionPayload['resultat_attendu'] ?? ''))) !== '' ? $value : null,
                'cible_prevue' => $saType === SousAction::TYPE_QUANTITATIVE ? $this->nullableFloat($subActionPayload['cible_prevue'] ?? null) : null,
                'unite' => ($value = trim((string) ($subActionPayload['unite'] ?? ''))) !== '' ? $value : $action->unite_cible,
                'commentaire' => ($value = trim((string) ($subActionPayload['commentaire'] ?? ''))) !== '' ? $value : null,
                'date_debut' => $subActionPayload['date_debut'] ?? optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
                'date_fin' => $subActionPayload['date_fin'] ?? optional($action->date_fin)->format('Y-m-d') ?? optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
            ];

            if ($subAction instanceof SousAction) {
                $subAction->fill($payload)->save();

                continue;
            }

            $action->sousActions()->create($payload + [
                'quantite_realisee' => 0,
                'resultat_obtenu' => null,
                'taux_realisation' => 0,
                'statut' => 'non_demarre',
                'est_effectuee' => false,
                'taux_execution' => 0,
            ]);
        }

        $action->refresh()->recalculateRealization();
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) (int) round((float) $value);
    }

    /**
     * @param  array<string, mixed>  $subActionPayload
     * @param  list<int>  $rmoIds
     */
    private function resolveSubActionAgentId(array $subActionPayload, ?SousAction $subAction, array $rmoIds, int $defaultAgentId, int $subActionIndex = 0): int
    {
        $allowedAgentIds = collect($rmoIds)
            ->push($defaultAgentId)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $submittedAgentId = isset($subActionPayload['agent_id']) && is_numeric($subActionPayload['agent_id'])
            ? (int) $subActionPayload['agent_id']
            : 0;

        if ($submittedAgentId > 0 && $allowedAgentIds->contains($submittedAgentId)) {
            return $submittedAgentId;
        }

        $existingAgentId = (int) ($subAction?->agent_id ?? 0);
        if ($existingAgentId > 0 && $allowedAgentIds->contains($existingAgentId)) {
            return $existingAgentId;
        }

        return (int) ($allowedAgentIds->get($subActionIndex % max(1, $allowedAgentIds->count())) ?: $defaultAgentId);
    }

    /**
     * @param  list<int>  $rmoIds
     */
    private function syncActionRmos(Action $action, array $rmoIds): void
    {
        if (! SchemaIntrospectionCache::hasTable('action_responsables')) {
            return;
        }

        $primaryId = (int) ($action->responsable_id ?: ($rmoIds[0] ?? 0));
        $ids = collect($rmoIds)
            ->push($primaryId)
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $action->responsables()->sync(
            $ids->mapWithKeys(fn (int $id): array => [
                $id => ['is_primary' => $id === $primaryId],
            ])->all()
        );
    }

    /**
     * @return Collection<int, ObjectifOperationnel>
     */
    private function objectifOperationnelOptions(User $user, ?int $forceObjectifId = null)
    {
        $query = ObjectifOperationnel::query()
            ->with([
                'pao:id,pas_id,direction_id,annee,titre,statut,exercice_id',
                'pas:id,titre,periode_debut,periode_fin',
                'pasAxe:id,pas_id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ])
            ->orderByDesc('id');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->get([
            'id',
            'pao_id',
            'pas_id',
            'pas_axe_id',
            'pas_objectif_id',
            'direction_id',
            'service_id',
            'libelle',
            'description',
            'echeance',
            'statut',
        ]);
    }

    private function resolveObjectifOperationnel(int $id): ObjectifOperationnel
    {
        return ObjectifOperationnel::query()
            ->with([
                'pao:id,pas_id,direction_id,annee,titre,statut,exercice_id',
                'service:id,direction_id,code,libelle',
            ])
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, Pao>
     */
    private function paoOptions(User $user)
    {
        $query = Pao::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle',
                'pasObjectif.pasAxe:id,pas_id,code,libelle',
                'pasObjectif.pasAxe.pas:id,titre,periode_debut,periode_fin',
            ])
            ->orderByDesc('annee')
            ->orderByDesc('id');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPao($query);

        return $query
            ->get(['id', 'pas_id', 'direction_id', 'service_id', 'pas_objectif_id', 'annee', 'titre', 'objectif_operationnel']);
    }

    private function generatedPtaTitle(?Service $service): string
    {
        $serviceLabel = trim((string) ($service?->code ?: $service?->libelle ?: 'SERVICE'));

        return 'PTA - '.$serviceLabel;
    }

    /**
     * @return Collection<int, Service>
     */
    private function serviceOptions(User $user)
    {
        $query = Service::query()
            ->with('direction:id,code,libelle')
            ->where('actif', true)
            ->orderBy('direction_id')
            ->orderBy('code');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            $query->where('id', (int) $user->service_id);
        }

        return $query->get(['id', 'direction_id', 'code', 'libelle']);
    }

    /**
     * @return Collection<int, User>
     */
    private function responsableOptions(User $user)
    {
        $query = User::query()
            ->where('is_active', true)
            ->orderBy('role')
            ->orderBy('name');

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->get([
            'id',
            'name',
            'email',
            'role',
            'direction_id',
            'service_id',
            'agent_matricule',
            'agent_fonction',
            'agent_telephone',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function statusOptions(User $user): array
    {
        $workflow = $this->planningWorkflowSummary();

        if ($user->hasGlobalWriteAccess()) {
            return $workflow['status_options_global'];
        }

        return $workflow['status_options_writer'];
    }

    /**
     * @return array<string, mixed>
     */
    private function planningWorkflowSummary(): array
    {
        return app(WorkflowSettings::class)->planningWorkflowSummary('pta');
    }
}
