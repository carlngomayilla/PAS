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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use App\Services\ExerciceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * Contrôleur des PTA — Plan de Travail Annuel.
 *
 * Un PTA est le plan d'un service pour une année donnée. Il est rattaché à un PAO
 * et contient les actions à réaliser. Il suit un workflow : brouillon → soumis → validé → verrouillé.
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
            if ($statusFilter === 'valide_ou_verrouille') {
                $query->whereIn('statut', ['valide', 'verrouille']);
            } else {
                $query->where('statut', $statusFilter);
            }
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
            'total'          => (int) $byStatus->sum(),
            'actifs'         => (int) (($byStatus['valide'] ?? 0) + ($byStatus['verrouille'] ?? 0)),
            'brouillons'     => (int) ($byStatus['brouillon'] ?? 0),
            'sans_action'    => (clone $statsBase)->doesntHave('actions')->count(),
            'actions_total'  => DB::table('actions')->whereIn('pta_id', $ptaIdsSubquery)->count(),
            'services'       => (clone $statsBase)->distinct()->count('service_id'),
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
            'statusOptions' => array_merge($this->statusOptions($user), ['valide_ou_verrouille']),
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
            'row' => tap(new Pta(), function (Pta $pta) use ($prefilledObjectifId): void {
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

        $statut = 'brouillon';

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
        if ($existingPta instanceof Pta && $existingPta->statut === 'verrouille') {
            return back()->withErrors([
                'service_id' => 'Le PTA annuel de ce service est verrouille. Il doit etre rouvre avant ajout ou modification d actions.',
            ])->withInput();
        }

        $before = $existingPta?->toArray();
        $pta = $existingPta instanceof Pta ? $existingPta : new Pta();
        if ($existingPta instanceof Pta) {
            $payload['pao_id'] = (int) $existingPta->pao_id;
            $payload['objectif_operationnel_id'] = (int) ($existingPta->objectif_operationnel_id ?: $objectifOperationnel->id);
            $payload['direction_id'] = (int) $existingPta->direction_id;
            $payload['service_id'] = (int) $existingPta->service_id;
            $payload['exercice_id'] = $existingPta->exercice_id;
            $payload['titre'] = (string) ($existingPta->titre ?: $payload['titre']);
        }
        $pta->fill($payload);
        // statut / valide_* ne sont plus mass-assignables (defense en profondeur
        // contre l'escalade de privileges). On les positionne via forceFill.
        $pta->forceFill([
            'statut' => $existingPta?->statut ?: $statut,
            'valide_le' => $existingPta?->valide_le,
            'valide_par' => $existingPta?->valide_par,
        ])->save();

        $savedActions = $this->syncPtaActions(
            $pta,
            $objectifOperationnel,
            $this->withUploadedActionFiles((array) ($validated['actions'] ?? []), $request),
            $user
        );

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

        $pta->loadMissing([
            'actions.responsables:id,name,email',
            'actions.sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible_prevue,unite,commentaire,date_debut,date_fin,statut,est_effectuee',
            'actions:id,pta_id,pao_id,objectif_operationnel_id,mode_evaluation,libelle,description,date_debut,date_fin,statut,priorite,intitule_cible,unite_cible,quantite_cible,seuil_minimum,seuil_mode,seuil_t1,seuil_t2,seuil_t3,seuil_t4,methode_calcul,justificatif_obligatoire,echeance_cible,resultat_attendu,observations,montant_estime,nature_financement,source_financement,commentaire_financement,justificatif_financement_path,ressources_necessaires,ressources_details,ressource_main_oeuvre,ressource_equipement,ressource_partenariat,ressource_autres,ressource_autres_details,risque_potentiel,niveau_risque,mesures_preventives,financement_requis,financement_statut,financement_soumis_le,financement_notifie_le,responsable_id',
        ]);

        return view('workspace.pta.form', [
            'mode' => 'edit',
            'row' => $pta,
            'objectifOperationnelOptions' => $this->objectifOperationnelOptions($user, (int) $pta->objectif_operationnel_id),
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

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'plus etre modifie')]);
        }

        $validated = $request->validated();
        $objectifOperationnel = $this->resolveObjectifOperationnel((int) $validated['objectif_operationnel_id']);
        $targetPao = $objectifOperationnel->pao;
        $targetServiceId = (int) $objectifOperationnel->service_id;

        $statut = (string) ($pta->statut ?: 'brouillon');

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

        $after = array_merge($pta->toArray(), [
            'actions_enregistrees' => collect($savedActions)->pluck('id')->all(),
        ]);

        $this->recordAudit($request, 'pta', 'update', $pta, $before, $after);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pta')));
    }

    /** Supprime un PTA (uniquement si en brouillon et sans actions associées). */
    public function destroy(Request $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'etre supprime')]);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $before = $pta->toArray();
        $pta->delete();

        $this->recordAudit($request, 'pta', 'delete', $pta, $before, null);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pta')));
    }

    /** Soumet le PTA pour validation par le chef de direction. */
    public function submit(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'etre soumis')]);
        }

        if ($pta->statut !== 'brouillon') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'brouillon', 'soumis')]);
        }

        $this->denyUnlessManagePta($user, (int) $pta->direction_id, (int) $pta->service_id);
        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['submit_enabled']) {
            return back()->withErrors(['general' => 'La soumission est desactivee pour le workflow PTA actif.']);
        }

        $before = $pta->toArray();
        $targetStatus = (string) $workflow['submit_target_status'];
        $pta->forceFill([
            'statut' => $targetStatus,
            'valide_le' => in_array($targetStatus, ['valide', 'verrouille'], true) ? now() : null,
            'valide_par' => in_array($targetStatus, ['valide', 'verrouille'], true) ? $user->id : null,
        ])->save();

        $this->recordAudit($request, 'pta', 'submit', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, $targetStatus === 'soumis' ? 'submitted' : 'approved', $user);
        if ($targetStatus === 'soumis') {
            $notificationService->notifyPtaSubmittedForValidation($pta, $user);
        } else {
            $notificationService->notifyPtaReviewedByDirection($pta, true, $user);
        }

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $targetStatus === 'soumis'
                ? 'PTA soumis pour validation.'
                : 'PTA valide directement selon le workflow configure.');
    }

    /** Valide un PTA soumis (rôle direction ou admin). */
    public function approve(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user, $pta)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['approve_enabled']) {
            return back()->withErrors(['general' => 'La validation intermediaire est desactivee pour le workflow PTA actif.']);
        }

        if ($pta->statut !== 'soumis') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'soumis', 'valide')]);
        }

        $before = $pta->toArray();
        $pta->forceFill([
            'statut' => 'valide',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pta', 'approve', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, 'approved', $user);
        $notificationService->notifyPtaReviewedByDirection($pta, true, $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->transitionedStateMessage('PTA', 'valide'));
    }

    /** Verrouille un PTA validé : il passe en lecture seule, plus aucune modification possible. */
    public function lock(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canLock($user)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['lock_enabled']) {
            return back()->withErrors(['general' => 'Le verrouillage final est desactive pour le workflow PTA actif.']);
        }

        if ($pta->statut !== 'valide') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'valide', 'verrouille')]);
        }

        $before = $pta->toArray();
        $pta->forceFill([
            'statut' => 'verrouille',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pta', 'lock', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, 'locked', $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->transitionedStateMessage('PTA', 'verrouille'));
    }

    /** Retourne un PTA en brouillon avec un motif obligatoire (correction avant re-soumission). */
    public function reopen(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedCannotBeReopenedMessage('PTA')]);
        }

        $workflow = $this->planningWorkflowSummary();
        $allowedStatuses = $workflow['reopen_allowed_statuses'];

        if (! in_array($pta->statut, $allowedStatuses, true)) {
            return back()->withErrors(['general' => $this->reopenAllowedStatusesMessage($allowedStatuses)]);
        }

        if ($pta->statut === 'soumis'
            && ! ($this->canApprove($user, $pta) || $this->canManagePta($user, (int) $pta->direction_id, (int) $pta->service_id))
        ) {
            abort(403, 'Acces non autorise.');
        }

        if ($pta->statut === 'valide'
            && $workflow['approve_enabled']
            && ! $this->canApprove($user, $pta)
        ) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'motif_retour' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $motifRetour = trim((string) $validated['motif_retour']);

        $before = $pta->toArray();
        $pta->forceFill([
            'statut' => 'brouillon',
            'valide_le' => null,
            'valide_par' => null,
        ])->save();

        $after = array_merge($pta->toArray(), ['motif_retour' => $motifRetour]);
        $this->recordAudit($request, 'pta', 'reopen', $pta, $before, $after);
        $notificationService->notifyPtaStatus($pta, 'reopened', $user);
        $notificationService->notifyPtaReviewedByDirection($pta, false, $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->reopenedStateMessage('PTA'));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_SERVICE);
    }

    private function canApprove(User $user, Pta $pta): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        return $user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $pta->direction_id;
    }

    private function canLock(User $user): bool
    {
        return $user->hasGlobalWriteAccess();
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
            ->whereIn('action', ['create', 'submit', 'approve', 'lock', 'reopen', 'update'])
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
                    'submit' => 'Soumission',
                    'approve' => 'Validation',
                    'lock' => 'Verrouillage',
                    'reopen' => 'Retour brouillon',
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

    /**
     * @param array<int, mixed> $actionsPayload
     * @return list<Action>
     */
    private function syncPtaActions(Pta $pta, ObjectifOperationnel $objectifOperationnel, array $actionsPayload, User $actor): array
    {
        $savedActions = [];
        $trackingService = app(ActionTrackingService::class);
        $secureStorage = app(SecureJustificatifStorage::class);
        $notificationService = app(WorkspaceNotificationService::class);

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
                ? new Action()
                : Action::query()
                    ->whereKey($actionId)
                    ->where('pta_id', (int) $pta->id)
                    ->firstOrFail();

            $payload = $this->normalizePtaActionPayload($actionPayload, $pta, $objectifOperationnel, $rmoIds, $isNewAction, $action);
            // forceFill : le payload est integralement construit par normalizePtaActionPayload
            // (entrees utilisateur deja filtrees) et contient des champs workflow/calculs
            // qui ne sont plus mass-assignables (cf. A02).
            $action->forceFill($payload)->save();

            $this->syncActionRmos($action, $rmoIds);
            $this->syncPlannedSubActions($action, $actionPayload, $rmoIds);

            if ($isNewAction) {
                $trackingService->initializeActionTracking($action, $actor);
                $notificationService->notifyActionAssigned($action, $actor);
            } else {
                $trackingService->regenerateWeeks($action);
                $trackingService->refreshActionMetrics($action);
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

            $savedActions[] = $action;
        }

        return $savedActions;
    }

    /**
     * @param array<int, mixed> $actionsPayload
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
     * @param array<string, mixed> $actionPayload
     * @param list<int> $rmoIds
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
        $quantiteCible = ($quantiteCible === '' || $quantiteCible === null) ? null : $quantiteCible;
        $modeEvaluation = trim((string) ($actionPayload['mode_evaluation'] ?? ''));
        if (! in_array($modeEvaluation, [Action::MODE_QUANTITATIF, Action::MODE_SOUS_ACTIONS, Action::MODE_MIXTE], true)) {
            $modeEvaluation = filled($quantiteCible) ? Action::MODE_QUANTITATIF : Action::MODE_SOUS_ACTIONS;
        }
        if (! filled($quantiteCible) && ! in_array($modeEvaluation, [Action::MODE_QUANTITATIF, Action::MODE_MIXTE], true)) {
            $modeEvaluation = Action::MODE_SOUS_ACTIONS;
        }

        $montantEstime = $actionPayload['montant_estime'] ?? null;
        $montantEstime = ($montantEstime === '' || $montantEstime === null) ? null : $montantEstime;
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
        $sourceFinancement = ($value = trim((string) ($actionPayload['source_financement'] ?? ''))) !== '' ? $value : null;
        $commentaireFinancement = ($value = trim((string) ($actionPayload['commentaire_financement'] ?? ''))) !== '' ? $value : null;
        $dateFin = $actionPayload['date_fin']
            ?? optional($existingAction?->date_fin)->format('Y-m-d')
            ?? optional($objectifOperationnel->echeance)->format('Y-m-d')
            ?? ($actionPayload['date_debut'] ?? null);
        $isQuantitative = in_array($modeEvaluation, [Action::MODE_QUANTITATIF, Action::MODE_MIXTE], true);
        $actionPaoId = $isNewAction
            ? (int) $objectifOperationnel->pao_id
            : (int) ($existingAction?->pao_id ?: $objectifOperationnel->pao_id);
        $actionObjectifId = $isNewAction
            ? (int) $objectifOperationnel->id
            : (int) ($existingAction?->objectif_operationnel_id ?: $objectifOperationnel->id);
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
            'libelle' => trim((string) ($actionPayload['libelle'] ?? '')),
            'description' => ($value = trim((string) ($actionPayload['description'] ?? ''))) !== ''
                ? $value
                : ($isNewAction ? null : $existingAction?->description),
            'type_cible' => $isQuantitative ? 'quantitative' : 'qualitative',
            'intitule_cible' => $existingAction?->intitule_cible,
            'priorite' => $actionPayload['priorite'] ?? null,
            'unite_cible' => $isQuantitative
                ? ($actionPayload['unite_cible'] ?? null)
                : null,
            'quantite_cible' => $isQuantitative
                ? $quantiteCible
                : null,
            'seuil_minimum' => (float) ($actionPayload['seuil_minimum'] ?? $existingAction?->seuil_minimum ?? 80),
            'seuil_mode' => in_array($thresholdMode, ['unique', 'trimestriel'], true)
                ? $thresholdMode
                : 'unique',
            'seuil_t1' => $this->nullableFloat($actionPayload['seuil_t1'] ?? $existingAction?->seuil_t1 ?? null),
            'seuil_t2' => $this->nullableFloat($actionPayload['seuil_t2'] ?? $existingAction?->seuil_t2 ?? null),
            'seuil_t3' => $this->nullableFloat($actionPayload['seuil_t3'] ?? $existingAction?->seuil_t3 ?? null),
            'seuil_t4' => $this->nullableFloat($actionPayload['seuil_t4'] ?? $existingAction?->seuil_t4 ?? null),
            'methode_calcul' => 'sum_sous_actions',
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
            'source_financement' => $financementRequis ? $sourceFinancement : null,
            'commentaire_financement' => $financementRequis ? $commentaireFinancement : null,
        ];

        if (! $financementRequis) {
            $payload['financement_statut'] = Action::FINANCEMENT_NON_REQUIS;
            $payload['financement_soumis_le'] = null;
            $payload['financement_notifie_le'] = null;
        } elseif ($isNewAction) {
            $payload['financement_statut'] = Action::FINANCEMENT_EN_ATTENTE_DAF;
            $payload['financement_soumis_le'] = now();
            $payload['financement_notifie_le'] = null;
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
     * @param array<string, mixed> $actionPayload
     * @param list<int> $rmoIds
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

        foreach ($subActionsPayload as $subActionPayload) {
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

            $payload = [
                'agent_id' => (int) ($subAction?->agent_id ?: $defaultAgentId),
                'libelle' => $label,
                'description' => ($value = trim((string) ($subActionPayload['description'] ?? ''))) !== '' ? $value : null,
                'resultat_attendu' => ($value = trim((string) ($subActionPayload['resultat_attendu'] ?? ''))) !== '' ? $value : null,
                'cible_prevue' => $this->nullableFloat($subActionPayload['cible_prevue'] ?? null),
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
                'statut' => 'a_faire',
                'est_effectuee' => false,
                'taux_execution' => 0,
            ]);
        }

        $action->refresh()->recalculateRealization();
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * @param list<int> $rmoIds
     */
    private function syncActionRmos(Action $action, array $rmoIds): void
    {
        if (! Schema::hasTable('action_responsables')) {
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
     * @return \Illuminate\Database\Eloquent\Collection<int, ObjectifOperationnel>
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

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where(function ($scopedQuery) use ($user, $forceObjectifId): void {
                $scopedQuery->where('service_id', (int) $user->service_id);

                if ($forceObjectifId !== null) {
                    $scopedQuery->orWhere('id', $forceObjectifId);
                }
            });
        } elseif ($forceObjectifId !== null) {
            $query->orWhere('id', $forceObjectifId);
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Pao>
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
            ->whereNotNull('service_id')
            ->get(['id', 'pas_id', 'direction_id', 'service_id', 'pas_objectif_id', 'annee', 'titre', 'objectif_operationnel']);
    }

    private function generatedPtaTitle(?Service $service): string
    {
        $serviceLabel = trim((string) ($service?->code ?: $service?->libelle ?: 'SERVICE'));

        return 'PTA - '.$serviceLabel;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Service>
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

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('id', (int) $user->service_id);
        }

        return $query->get(['id', 'direction_id', 'code', 'libelle']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
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

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
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

