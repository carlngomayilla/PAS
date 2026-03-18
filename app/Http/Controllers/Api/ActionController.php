<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\UpdateActionRequest;
use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Governance\DelegationService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActionController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = Action::query()
            ->with([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite',
            ])
            ->withCount([
                'kpis',
                'weeks as semaines_total',
                'weeks as semaines_renseignees' => fn ($q) => $q->where('est_renseignee', true),
            ]);

        $this->scopeActionQuery($query, $user);

        $query->when(
            $request->filled('pta_id'),
            fn ($q) => $q->where('pta_id', (int) $request->integer('pta_id'))
        );

        $query->when(
            $request->filled('responsable_id'),
            fn ($q) => $q->where('responsable_id', (int) $request->integer('responsable_id'))
        );

        $query->when(
            $request->filled('statut'),
            fn ($q) => $q->where('statut_dynamique', (string) $request->string('statut'))
        );

        $query->when(
            $request->filled('financement_requis'),
            fn ($q) => $q->where('financement_requis', $request->boolean('financement_requis'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('resultat_attendu', 'like', "%{$search}%")
                    ->orWhere('description_financement', 'like', "%{$search}%")
                    ->orWhere('source_financement', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(
        StoreActionRequest $request,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $pta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($pta->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Creation impossible.',
            ], 409);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $action = DB::transaction(function () use ($validated, $request, $trackingService, $user, $secureStorage): Action {
            $payload = $validated;
            $payload['statut'] = 'non_demarre';
            $payload['statut_dynamique'] = ActionTrackingService::STATUS_NON_DEMARRE;
            $payload['progression_reelle'] = 0;
            $payload['progression_theorique'] = 0;
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            $payload['date_echeance'] = $payload['date_fin'];

            $action = Action::query()->create($payload);
            $trackingService->initializeActionTracking($action, $user);

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
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
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            return $action;
        });

        $this->recordAudit($request, 'action', 'create', $action, null, $action->toArray());

        return response()->json([
            'message' => 'Action creee avec succes.',
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'weeks:id,action_id,numero_semaine,date_debut,date_fin,est_renseignee',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite',
            ]),
        ], 201);
    }

    public function show(Request $request, Action $action, ActionTrackingService $trackingService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $trackingService->refreshActionMetrics($action);

        return response()->json([
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'pta.pao:id,pas_id,annee,titre,statut',
                'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
                'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
                'soumisPar:id,name,email',
                'evaluePar:id,name,email',
                'directionValidePar:id,name,email',
                'kpis:id,action_id,libelle,periodicite',
                'weeks' => fn ($q) => $q->orderBy('numero_semaine'),
                'actionKpi',
                'actionLogs' => fn ($q) => $q->latest()->limit(50),
                'justificatifs' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function update(
        UpdateActionRequest $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $validated = $request->validated();
        $targetPta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($targetPta->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA cible est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        $this->denyUnlessActionManager(
            $user,
            (int) $targetPta->direction_id,
            (int) $targetPta->service_id
        );

        $dateChanged = (string) $action->date_debut !== (string) ($validated['date_debut'] ?? null)
            || (string) $action->date_fin !== (string) ($validated['date_fin'] ?? null);
        $frequencyChanged = (string) ($action->frequence_execution ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE)
            !== (string) ($validated['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE);
        $targetTypeChanged = (string) $action->type_cible !== (string) ($validated['type_cible'] ?? '');

        if (($dateChanged || $frequencyChanged || $targetTypeChanged) && ! $trackingService->canRegenerateWeeks($action)) {
            return response()->json([
                'message' => 'Impossible de modifier la planification/frequence/type: des periodes sont deja renseignees.',
            ], 422);
        }

        $before = $action->toArray();

        DB::transaction(function () use ($action, $validated, $trackingService, $dateChanged, $frequencyChanged, $targetTypeChanged, $request, $user, $secureStorage): void {
            $payload = $validated;
            $payload['date_echeance'] = $payload['date_fin'];
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;

            $action->fill($payload);
            $action->save();

            if ($dateChanged || $frequencyChanged || $targetTypeChanged) {
                $trackingService->regenerateWeeks($action);
            }

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
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
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            $trackingService->refreshActionMetrics($action);
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'update', $action, $before, $action->toArray());

        return response()->json([
            'message' => 'Action mise a jour avec succes.',
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'weeks:id,action_id,numero_semaine,date_debut,date_fin,est_renseignee',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite',
            ]),
        ]);
    }

    public function destroy(
        Request $request,
        Action $action,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Suppression impossible.',
            ], 409);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        $before = $action->toArray();
        DB::transaction(function () use ($action, $secureStorage): void {
            $documents = $action->justificatifs()->get(['id', 'chemin_stockage']);
            $paths = $documents->pluck('chemin_stockage')->filter()->all();

            $action->justificatifs()->delete();
            $action->delete();

            foreach ($paths as $path) {
                $secureStorage->deleteByPath((string) $path);
            }
        });
        $this->recordAudit($request, 'action', 'delete', $action, $before, null);

        return response()->json([], 204);
    }

    public function weeks(Request $request, Action $action, ActionTrackingService $trackingService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $trackingService->refreshActionMetrics($action);

        return response()->json([
            'data' => $action->weeks()
                ->with('saisiPar:id,name,email')
                ->orderBy('numero_semaine')
                ->get(),
        ]);
    }

    public function submitWeek(
        Request $request,
        Action $action,
        ActionWeek $actionWeek,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');
        $this->denyUnlessActionTracker($user, $action);

        if ((int) $actionWeek->action_id !== (int) $action->id) {
            abort(404);
        }

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Saisie impossible.',
            ], 409);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return response()->json([
                'message' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ], 409);
        }

        $rules = [
            'commentaire' => ['nullable', 'string'],
            'difficultes' => ['required', 'string'],
            'mesures_correctives' => ['required', 'string'],
            'justificatif' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ];

        if ($action->type_cible === 'quantitative') {
            $rules['quantite_realisee'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['taches_realisees'] = ['required', 'string'];
            $rules['avancement_estime'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);

        DB::transaction(function () use ($trackingService, $actionWeek, $validated, $request, $action, $user, $secureStorage): void {
            $trackingService->submitWeek($actionWeek, $validated, $user);

            $file = $request->file('justificatif');
            $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
            $trackingService->addActionJustificatif(
                $action,
                $actionWeek,
                'hebdomadaire',
                $storedFile['path'],
                $storedFile['nom_original'],
                $storedFile['mime_type'],
                $storedFile['taille_octets'],
                'Justificatif hebdomadaire',
                $user,
                $storedFile['est_chiffre']
            );
        });

        return response()->json([
            'message' => 'Semaine renseignee avec succes.',
            'data' => $actionWeek->fresh(),
        ]);
    }

    public function close(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id');
        if (! $this->canSubmitClosure($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Soumission impossible.',
            ], 409);
        }

        $currentValidationStatus = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);
        if (in_array($currentValidationStatus, [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ], true)) {
            return response()->json([
                'message' => 'Action deja soumise. En attente de validation hierarchique.',
            ], 409);
        }

        if ($currentValidationStatus === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION) {
            return response()->json([
                'message' => 'Action deja validee par la direction.',
            ], 409);
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->where('categorie', 'hebdomadaire')
            ->exists();
        if (! $hasExecutionJustificatif) {
            return response()->json([
                'message' => 'Soumission impossible: aucun justificatif d execution trouve dans les periodes de suivi.',
            ], 422);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'date_fin_reelle' => ['required', 'date'],
            'rapport_final' => ['required', 'string'],
            'justificatif_final' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        if ($action->date_debut !== null && (string) $validated['date_fin_reelle'] < (string) $action->date_debut) {
            return response()->json([
                'message' => 'La date de fin reelle doit etre superieure ou egale a la date debut.',
            ], 422);
        }

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->submitClosureForReview($action, $validated, $user);

            if ($request->hasFile('justificatif_final')) {
                $file = $request->file('justificatif_final');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'final',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif final de cloture transmis pour validation',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        return response()->json([
            'message' => 'Action soumise au chef de service pour evaluation.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function review(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByChef($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Validation impossible.',
            ], 409);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation chef de service.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'validation_sans_correction' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'justificatif_evaluation' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        $validated['validation_sans_correction'] = $request->filled('validation_sans_correction')
            ? (bool) $request->boolean('validation_sans_correction')
            : null;

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByChef($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation')) {
                $file = $request->file('justificatif_evaluation');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'evaluation_chef',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de revue chef de service',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');

        return response()->json([
            'message' => $decision === 'valider'
                ? 'Action validee par le chef de service et transmise a la direction.'
                : 'Action rejetee. L agent peut mettre a jour et resoumettre.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function reviewDirection(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByDirection($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Validation impossible.',
            ], 409);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_VALIDEE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation direction.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'justificatif_evaluation_direction' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByDirection($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation_direction')) {
                $file = $request->file('justificatif_evaluation_direction');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'evaluation_direction',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de revue direction',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');

        return response()->json([
            'message' => $decision === 'valider'
                ? 'Action validee par la direction. Elle est maintenant prise en compte dans les statistiques.'
                : 'Action rejetee par la direction. Retour au chef de service.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function comment(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,responsable_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array{message:string} $validated */
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);

        $entry = $trackingService->addDiscussionEntry(
            $action,
            $validated['message'],
            'commentaire',
            'info',
            [],
            $user
        );

        return response()->json([
            'message' => 'Commentaire enregistre.',
            'data' => $entry->load('utilisateur:id,name,email'),
        ], 201);
    }

    public function logs(Request $request, Action $action): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $action->actionLogs()
                ->with(['week:id,action_id,numero_semaine', 'utilisateur:id,name,email'])
                ->latest()
                ->paginate(max(1, min(100, (int) $request->integer('per_page', 20))))
                ->withQueryString(),
        ]);
    }

    private function denyUnlessActionManager(User $user, ?int $directionId, ?int $serviceId): void
    {
        if (! $this->canManageAction($user, $directionId, $serviceId)) {
            abort(403, 'Acces non autorise.');
        }
    }

    private function denyUnlessActionTracker(User $user, Action $action): void
    {
        if (! $this->canTrackAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }
    }

    private function canTrackAction(User $user, Action $action): bool
    {
        return $user->isAgent()
            && (int) $action->responsable_id === (int) $user->id;
    }

    private function canManageAction(User $user, ?int $directionId, ?int $serviceId): bool
    {
        return ! $user->isAgent() && $this->canWriteService($user, $directionId, $serviceId);
    }

    private function canSubmitClosure(User $user, Action $action): bool
    {
        return $user->hasRole(User::ROLE_AGENT)
            && (int) $action->responsable_id === (int) $user->id;
    }

    private function canReviewByChef(User $user, Action $action): bool
    {
        return ($user->hasRole(User::ROLE_SERVICE)
                && $this->canManageAction($user, (int) $action->pta?->direction_id, (int) $action->pta?->service_id))
            || app(DelegationService::class)->canReviewServiceAction(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            );
    }

    private function canReviewByDirection(User $user, Action $action): bool
    {
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return (int) $user->direction_id === (int) $action->pta?->direction_id;
        }

        return app(DelegationService::class)->canReviewDirectionAction(
            $user,
            (int) $action->pta?->direction_id
        );
    }

    private function canReadActions(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent()
            || $user->hasDelegatedPermission('action_review')
            || $user->hasDelegatedPermission('planning_write');
    }

    private function canReadAction(User $user, Action $action): bool
    {
        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id;
        }

        $delegationService = app(DelegationService::class);
        if ($delegationService->canReviewServiceAction($user, (int) $action->pta?->direction_id, (int) $action->pta?->service_id)) {
            return true;
        }
        if ($delegationService->canReviewDirectionAction($user, (int) $action->pta?->direction_id)) {
            return true;
        }
        if ($user->hasDelegatedDirectionScope((int) $action->pta?->direction_id, 'planning_write')) {
            return true;
        }
        if ($user->hasDelegatedServiceScope((int) $action->pta?->direction_id, (int) $action->pta?->service_id, 'planning_write')) {
            return true;
        }

        if (! $this->canReadDirection($user, (int) $action->pta?->direction_id)) {
            return false;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && (int) $user->service_id !== (int) $action->pta?->service_id) {
            return false;
        }

        return true;
    }

    private function isExecutionEditableByAgent(Action $action): bool
    {
        $status = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);

        return in_array($status, [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ], true);
    }

    private function scopeActionQuery($query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where('responsable_id', (int) $user->id);

            return;
        }

        $delegatedDirectionIds = array_values(array_unique(array_merge(
            $user->delegatedDirectionIds('action_review'),
            $user->delegatedDirectionIds('planning_write')
        )));
        $delegatedServiceScopes = array_merge(
            $user->delegatedServiceScopes('action_review'),
            $user->delegatedServiceScopes('planning_write')
        );

        $query->where(function ($scopedQuery) use ($user, $delegatedDirectionIds, $delegatedServiceScopes): void {
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('direction_id', (int) $user->direction_id));
            }

            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('service_id', (int) $user->service_id));
            }

            foreach ($delegatedDirectionIds as $directionId) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('direction_id', (int) $directionId));
            }

            foreach ($delegatedServiceScopes as $scope) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery
                    ->where('direction_id', (int) $scope['direction_id'])
                    ->where('service_id', (int) $scope['service_id']));
            }
        });
    }
}
