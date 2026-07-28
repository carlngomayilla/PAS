<?php

namespace App\Services;

use App\Models\Action;
use App\Models\DeletionRequest;
use App\Models\JournalAudit;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\PaoObjectifOperationnel;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlatformSetting;
use App\Models\Pta;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeletionRequestService
{
    public function __construct(
        private readonly UserLifecycleService $userLifecycleService,
        private readonly RolePermissionSettings $rolePermissionSettings,
        private readonly RoleRegistryService $roleRegistry
    ) {}

    public function requestUserRoleChange(User $target, string $role, User $actor, string $reason): DeletionRequest
    {
        if (! $actor->isSuperAdmin() && ! $actor->hasRole(User::ROLE_ADMIN_FONCTIONNEL)) {
            abort(403, 'Acces non autorise.');
        }

        $baseRole = $this->roleRegistry->baseRole($role);
        $customRoleCode = $this->roleRegistry->isCustomRole($role) ? $role : null;
        $existing = DeletionRequest::query()
            ->where('entity_type', User::class)
            ->where('entity_id', (int) $target->id)
            ->where('requested_action', 'assign_role')
            ->whereIn('status', [
                DeletionRequest::STATUS_PENDING,
                DeletionRequest::STATUS_APPROVED,
                DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            ])
            ->first();

        if ($existing instanceof DeletionRequest) {
            throw ValidationException::withMessages([
                'role' => 'Une attribution de rôle est déjà en cours de validation pour cet utilisateur.',
            ]);
        }

        $request = DeletionRequest::query()->create([
            'requested_by' => (int) $actor->id,
            'module' => 'access_control',
            'entity_type' => User::class,
            'entity_id' => (int) $target->id,
            'entity_label' => $this->userLabel($target),
            'requested_action' => 'assign_role',
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => trim($reason) ?: 'Attribution ou modification d un rôle utilisateur.',
            'impact_summary' => [
                'governance_payload' => [
                    'role' => $baseRole,
                    'custom_role_code' => $customRoleCode,
                ],
                'current_role' => $target->role,
                'current_custom_role_code' => $target->custom_role_code,
                'requested_role' => $role,
            ],
        ]);

        $this->notifyPlanningChiefs($request, $actor);

        return $request;
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     */
    public function requestRolePermissionChange(array $permissions, User $actor, string $reason): DeletionRequest
    {
        if (! $actor->isSuperAdmin() && ! $actor->hasRole(User::ROLE_ADMIN_FONCTIONNEL)) {
            abort(403, 'Acces non autorise.');
        }

        $anchor = PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_permissions', 'key' => 'governance_anchor'],
            ['value' => 'approval_required']
        );

        $existing = DeletionRequest::query()
            ->where('module', 'access_control')
            ->where('requested_action', 'role_permissions_update')
            ->whereIn('status', [
                DeletionRequest::STATUS_PENDING,
                DeletionRequest::STATUS_APPROVED,
                DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            ])
            ->first();

        if ($existing instanceof DeletionRequest) {
            throw ValidationException::withMessages([
                'general' => 'Une modification des rôles et permissions est déjà en cours de validation.',
            ]);
        }

        $request = DeletionRequest::query()->create([
            'requested_by' => (int) $actor->id,
            'module' => 'access_control',
            'entity_type' => PlatformSetting::class,
            'entity_id' => (int) $anchor->id,
            'entity_label' => 'Matrice des rôles et permissions',
            'requested_action' => 'role_permissions_update',
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => trim($reason) ?: 'Mise à jour de la matrice des rôles et permissions.',
            'impact_summary' => [
                'governance_payload' => ['permissions' => $permissions],
                'current_permissions' => $this->rolePermissionSettings->all(),
                'roles_affected' => count($permissions),
            ],
        ]);

        $this->notifyPlanningChiefs($request, $actor);

        return $request;
    }

    public function canRequestUserDeletion(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return false;
        }

        if ($actor->isPlanningControlChief()) {
            return $target->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE, User::ROLE_AGENT);
        }

        if ($actor->isSuperAdmin()
            || $actor->hasGlobalReadAccess()
            || $actor->hasRole(
                User::ROLE_DG,
                User::ROLE_DGA_SUPERVISION,
                User::ROLE_CABINET,
                User::ROLE_CABINET_SUPERVISION,
                User::ROLE_SCIQ,
                User::ROLE_PLANIFICATION,
                User::ROLE_ADMIN_FONCTIONNEL,
            )
        ) {
            return true;
        }

        if ($actor->hasRole(User::ROLE_DIRECTION) && $actor->direction_id !== null) {
            return (int) $actor->direction_id === (int) $target->direction_id;
        }

        if ($actor->hasRole(User::ROLE_SERVICE, User::ROLE_CHEF_UNITE_UCAS, User::ROLE_UCAS)
            && $actor->service_id !== null
        ) {
            return (int) $actor->direction_id === (int) $target->direction_id
                && (int) $actor->service_id === (int) $target->service_id;
        }

        return false;
    }

    public function requestUserDeletion(User $target, User $actor, string $reason): DeletionRequest
    {
        if (! $this->canRequestUserDeletion($actor, $target)) {
            abort(403, 'Acces non autorise.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'motif' => 'Le motif de suppression est obligatoire.',
            ]);
        }

        $existing = DeletionRequest::query()
            ->where('entity_type', User::class)
            ->where('entity_id', (int) $target->id)
            ->whereIn('status', [
                DeletionRequest::STATUS_PENDING,
                DeletionRequest::STATUS_APPROVED,
                DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            ])
            ->first();

        if ($existing instanceof DeletionRequest) {
            throw ValidationException::withMessages([
                'general' => 'Une demande de suppression est deja ouverte pour ce compte.',
            ]);
        }

        $request = DeletionRequest::query()->create([
            'requested_by' => (int) $actor->id,
            'module' => 'referentiel_utilisateur',
            'entity_type' => User::class,
            'entity_id' => (int) $target->id,
            'entity_label' => $this->userLabel($target),
            'requested_action' => 'delete',
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => $reason,
            'impact_summary' => $this->impactForUser($target),
        ]);

        $this->notifyPlanningChiefs($request, $actor);

        return $request;
    }

    public function requestBusinessDeletion(Model $target, User $actor, string $reason, ?string $module = null): DeletionRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'motif' => 'Le motif de suppression est obligatoire.',
            ]);
        }

        if (! $this->isDeletionRequestable($target)) {
            throw ValidationException::withMessages([
                'general' => 'Ce type d element ne peut pas faire l objet d une demande de suppression.',
            ]);
        }

        $existing = DeletionRequest::query()
            ->where('entity_type', $target::class)
            ->where('entity_id', (int) $target->getKey())
            ->whereIn('status', [
                DeletionRequest::STATUS_PENDING,
                DeletionRequest::STATUS_APPROVED,
                DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            ])
            ->first();

        if ($existing instanceof DeletionRequest) {
            throw ValidationException::withMessages([
                'general' => 'Une demande de suppression est deja ouverte pour cet element.',
            ]);
        }

        $request = DeletionRequest::query()->create([
            'requested_by' => (int) $actor->id,
            'module' => $module ?: $this->moduleForTarget($target),
            'entity_type' => $target::class,
            'entity_id' => (int) $target->getKey(),
            'entity_label' => $this->entityLabel($target),
            'requested_action' => 'delete',
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => $reason,
            'impact_summary' => $this->impactForEntity($target),
        ]);

        $this->notifyPlanningChiefs($request, $actor);

        return $request;
    }

    public function resubmitComplement(DeletionRequest $request, User $actor, string $complement): DeletionRequest
    {
        if ((int) $request->requested_by !== (int) $actor->id) {
            abort(403, 'Acces non autorise.');
        }

        $complement = trim($complement);
        if ($complement === '') {
            throw ValidationException::withMessages([
                'complement' => 'Le complément est obligatoire.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $actor, $complement): DeletionRequest {
            $lockedRequest = DeletionRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedRequest->requested_by !== (int) $actor->id) {
                abort(403, 'Acces non autorise.');
            }

            if ($lockedRequest->status !== DeletionRequest::STATUS_COMPLEMENT_REQUESTED) {
                throw ValidationException::withMessages([
                    'complement' => 'Cette demande n’attend plus de complément.',
                ]);
            }

            $target = $this->resolveTarget($lockedRequest);
            $reason = trim((string) $lockedRequest->reason);
            $lockedRequest->forceFill([
                'status' => DeletionRequest::STATUS_PENDING,
                'reason' => $reason."\n\nComplément du demandeur : ".$complement,
                'impact_summary' => $target instanceof Model && $lockedRequest->requested_action === 'delete'
                    ? $this->impactForEntity($target)
                    : (array) ($lockedRequest->impact_summary ?? []),
                'reviewed_by' => null,
                'approved_by' => null,
                'decision' => null,
                'approval_note' => null,
                'decided_at' => null,
                'approved_at' => null,
                'executed_at' => null,
            ])->save();

            return $lockedRequest->refresh();
        });

        $this->notifyPlanningChiefs($request, $actor);

        return $request;
    }

    public function approve(
        DeletionRequest $request,
        User $actor,
        string $decision,
        string $note
    ): DeletionRequest {
        if (! $actor->isPlanningControlChief()) {
            abort(403, 'Acces non autorise.');
        }

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'approval_note' => 'Le motif de la decision est obligatoire.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $actor, $decision, $note): DeletionRequest {
            $lockedRequest = DeletionRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => 'Cette demande a déjà été traitée.',
                ]);
            }

            $status = match ($decision) {
                DeletionRequest::DECISION_APPROVE => DeletionRequest::STATUS_APPROVED,
                DeletionRequest::DECISION_REJECT => DeletionRequest::STATUS_REJECTED,
                DeletionRequest::DECISION_REQUEST_COMPLEMENT => DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
                default => throw ValidationException::withMessages(['decision' => 'Decision inconnue.']),
            };

            $target = $this->resolveTarget($lockedRequest);
            $lockedRequest->forceFill([
                'approved_by' => (int) $actor->id,
                'status' => $status,
                'decision' => $decision,
                'approval_note' => $note,
                'impact_summary' => $target instanceof Model && $lockedRequest->requested_action === 'delete'
                    ? $this->impactForEntity($target)
                    : (array) ($lockedRequest->impact_summary ?? []),
                'approved_at' => $decision === DeletionRequest::DECISION_APPROVE ? now() : null,
                'decided_at' => $decision !== DeletionRequest::DECISION_APPROVE ? now() : null,
            ])->save();

            return $lockedRequest->refresh();
        });

        $this->notifyApprovalOutcome($request, $actor);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        DeletionRequest $request,
        User $actor,
        string $decision,
        string $note,
        ?int $replacementId = null
    ): array {
        if (! $actor->isSuperAdmin() && ! $actor->hasRole(User::ROLE_ADMIN_FONCTIONNEL)) {
            abort(403, 'Acces non autorise.');
        }

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'reviewer_note' => 'Le motif d execution est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $decision, $note, $replacementId): array {
            $request = DeletionRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $request->isApproved() || $request->approved_by === null || $request->approved_at === null) {
                throw ValidationException::withMessages([
                    'decision' => 'L accord préalable du Chef Planification est obligatoire.',
                ]);
            }

            $target = $this->resolveTarget($request);
            $isAccessChange = in_array((string) $request->requested_action, [
                'role_permissions_update',
                'assign_role',
            ], true);
            $impact = $target instanceof Model && ! $isAccessChange
                ? $this->impactForEntity($target)
                : (array) ($request->impact_summary ?? []);
            $execution = [
                'decision' => $decision,
                'target_entity_type' => (string) $request->entity_type,
                'target_entity_id' => $target instanceof Model ? (int) $target->getKey() : (int) $request->entity_id,
                'target_entity_label' => $target instanceof Model ? $this->entityLabel($target) : $request->entity_label,
                'impact' => $impact,
            ];

            $status = match ($decision) {
                DeletionRequest::DECISION_DELETE => DeletionRequest::STATUS_DELETED,
                DeletionRequest::DECISION_DISABLE => DeletionRequest::STATUS_DISABLED,
                DeletionRequest::DECISION_ARCHIVE => DeletionRequest::STATUS_ARCHIVED,
                DeletionRequest::DECISION_CORRECT => DeletionRequest::STATUS_CORRECTED,
                DeletionRequest::DECISION_APPLY => DeletionRequest::STATUS_CORRECTED,
                default => throw ValidationException::withMessages(['decision' => 'Decision d execution inconnue.']),
            };

            if ($decision === DeletionRequest::DECISION_APPLY) {
                $payload = (array) data_get($request->impact_summary, 'governance_payload', []);
                if ((string) $request->requested_action === 'role_permissions_update') {
                    $execution['permissions'] = $this->rolePermissionSettings->update(
                        (array) ($payload['permissions'] ?? []),
                        $actor
                    );
                } elseif ((string) $request->requested_action === 'assign_role' && $target instanceof User) {
                    $target->forceFill([
                        'role' => (string) ($payload['role'] ?? $target->role),
                        'custom_role_code' => $payload['custom_role_code'] ?? null,
                    ])->save();
                    $execution['user_role'] = [
                        'user_id' => (int) $target->id,
                        'role' => (string) $target->role,
                        'custom_role_code' => $target->custom_role_code,
                    ];
                } else {
                    throw ValidationException::withMessages(['decision' => 'Cette demande ne contient aucun changement d accès applicable.']);
                }
            } elseif ($decision === DeletionRequest::DECISION_DELETE) {
                if (! $target instanceof Model) {
                    throw ValidationException::withMessages(['decision' => 'L element concerne est introuvable.']);
                }
                if ($target instanceof User && (int) $target->id === (int) $actor->id) {
                    throw ValidationException::withMessages(['decision' => 'Vous ne pouvez pas supprimer votre propre compte.']);
                }
                if ((int) ($impact['blocking_total'] ?? $this->blockingImpactTotal($target, (array) ($impact['linked_records'] ?? []))) > 0) {
                    throw ValidationException::withMessages([
                        'decision' => 'Suppression bloquee : l analyse d impact contient encore des rattachements metier. Choisissez une desactivation avec transfert, un refus ou une demande de complement.',
                    ]);
                }

                $this->deleteBusinessTarget($target);
                $execution['deleted_entity_id'] = (int) $target->getKey();
            } elseif ($decision === DeletionRequest::DECISION_DISABLE) {
                if (! $target instanceof User) {
                    throw ValidationException::withMessages(['decision' => 'La desactivation est reservee aux comptes utilisateurs.']);
                }
                if ((int) $target->id === (int) $actor->id) {
                    throw ValidationException::withMessages(['decision' => 'Vous ne pouvez pas desactiver votre propre compte.']);
                }

                $execution['lifecycle'] = $this->userLifecycleService->deactivate(
                    $target,
                    $actor,
                    $replacementId,
                    $note
                );
            } elseif ($decision === DeletionRequest::DECISION_ARCHIVE && $target instanceof Model) {
                $execution['archive'] = $this->archiveTarget($target);
            }

            $request->forceFill([
                'reviewed_by' => (int) $actor->id,
                'status' => $status,
                'decision' => $decision,
                'reviewer_note' => $note,
                'impact_summary' => $impact,
                'decided_at' => now(),
                'executed_at' => now(),
            ])->save();

            $this->notifyRequester($request, $actor);

            return $execution;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function impactForUser(User $target): array
    {
        $userId = (int) $target->id;
        $openAssignments = $this->userLifecycleService->openAssignmentSummary($target);

        $linked = [
            'objectifs_operationnels' => PaoObjectifOperationnel::query()
                ->where('responsable_id', $userId)
                ->count(),
            'actions_responsable' => Action::withTrashed()
                ->where('responsable_id', $userId)
                ->count(),
            'actions_rmo' => Schema::hasTable('action_responsables')
                ? DB::table('action_responsables')->where('user_id', $userId)->count()
                : 0,
            'sous_actions' => Schema::hasTable('sous_actions') && Schema::hasColumn('sous_actions', 'agent_id')
                ? DB::table('sous_actions')->where('agent_id', $userId)->count()
                : 0,
            'audit_events' => Schema::hasTable('journal_audit')
                ? JournalAudit::query()->where('user_id', $userId)->count()
                : 0,
        ];

        return [
            'open_assignments' => $openAssignments,
            'linked_records' => $linked,
            'total' => array_sum($linked),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function impactForEntity(Model $target): array
    {
        if ($target instanceof User) {
            return $this->impactForUser($target);
        }

        $linked = match (true) {
            $target instanceof Pas => $this->impactForPas($target),
            $target instanceof Pao => $this->impactForPao($target),
            $target instanceof Pta => $this->impactForPta($target),
            $target instanceof Action => $this->impactForAction($target),
            default => [],
        };

        return [
            'linked_records' => $linked,
            'total' => array_sum($linked),
            'blocking_total' => $this->blockingImpactTotal($target, $linked),
        ];
    }

    public function hasBlockingImpact(Model $target): bool
    {
        return (int) ($this->impactForEntity($target)['blocking_total'] ?? 0) > 0;
    }

    /**
     * Suppression cascade definitive d'une entite metier.
     *
     * Ordre de suppression (bottom-up) :
     *   Action : justificatifs + sous-actions + semaines puis Action
     *   PTA    : Actions (recursif) puis PTA
     *   PAO    : PTAs (recursif) + Objectifs operationnels puis PAO
     *   PAS    : PAOs (recursif) + Objectifs strategiques + Axes puis PAS
     *
     * Tout est encapsule dans une transaction pour eviter les etats intermediaires.
     * Les entites sont forceDelete pour liberer les contraintes uniques metier
     * (ex. paos.code) et permettre une recreation propre depuis l'application.
     */
    public function deleteBusinessTarget(Model $target): void
    {
        DB::transaction(function () use ($target): void {
            if ($target instanceof Pas) {
                $this->cascadeDeletePas($target);
            } elseif ($target instanceof Pao) {
                $this->cascadeDeletePao($target);
            } elseif ($target instanceof Pta) {
                $this->cascadeDeletePta($target);
            } elseif ($target instanceof Action) {
                $this->cascadeDeleteAction($target);
            } else {
                $target->delete();
            }
        });
    }

    private function cascadeDeletePas(Pas $pas): void
    {
        Pao::withTrashed()
            ->where('pas_id', $pas->id)
            ->get()
            ->each(fn (Pao $pao) => $this->cascadeDeletePao($pao));

        if (Schema::hasTable('pas_axes')) {
            PasAxe::withTrashed()
                ->where('pas_id', $pas->id)
                ->get()
                ->each(function (PasAxe $axe): void {
                    if (Schema::hasTable('pas_objectifs')) {
                        PasObjectif::withTrashed()
                            ->where('pas_axe_id', $axe->id)
                            ->get()
                            ->each(fn (PasObjectif $os) => $this->deleteModelPermanently($os));
                    }
                    $this->deleteModelPermanently($axe);
                });
        }

        if (Schema::hasTable('pas_directions')) {
            DB::table('pas_directions')->where('pas_id', (int) $pas->id)->delete();
        }

        $this->deleteModelPermanently($pas);
    }

    private function cascadeDeletePao(Pao $pao): void
    {
        Pta::withTrashed()
            ->where('pao_id', $pao->id)
            ->get()
            ->each(fn (Pta $pta) => $this->cascadeDeletePta($pta));

        Action::withTrashed()
            ->where('pao_id', $pao->id)
            ->get()
            ->each(fn (Action $action) => $this->cascadeDeleteAction($action));

        if (Schema::hasTable('objectifs_operationnels')) {
            ObjectifOperationnel::withTrashed()
                ->where('pao_id', $pao->id)
                ->get()
                ->each(fn (ObjectifOperationnel $oo) => $this->deleteModelPermanently($oo));
        }

        $this->deleteModelPermanently($pao);
    }

    private function cascadeDeletePta(Pta $pta): void
    {
        Action::withTrashed()
            ->where('pta_id', $pta->id)
            ->get()
            ->each(fn (Action $action) => $this->cascadeDeleteAction($action));

        $this->deleteModelPermanently($pta);
    }

    private function cascadeDeleteAction(Action $action): void
    {
        $sousActionIds = Schema::hasTable('sous_actions')
            ? DB::table('sous_actions')->where('action_id', $action->id)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        if (Schema::hasTable('justificatifs')) {
            DB::table('justificatifs')
                ->where('justifiable_type', Action::class)
                ->where('justifiable_id', (int) $action->id)
                ->delete();

            if ($sousActionIds !== [] && Schema::hasColumn('justificatifs', 'sous_action_id')) {
                DB::table('justificatifs')->whereIn('sous_action_id', $sousActionIds)->delete();
            }
        }
        if (Schema::hasTable('deadline_extension_requests')) {
            DB::table('deadline_extension_requests')->where('action_id', (int) $action->id)->delete();
        }
        if (Schema::hasTable('sous_actions')) {
            DB::table('sous_actions')->where('action_id', $action->id)->delete();
        }
        if (Schema::hasTable('action_weeks')) {
            DB::table('action_weeks')->where('action_id', $action->id)->delete();
        }
        if (Schema::hasTable('action_responsables')) {
            DB::table('action_responsables')->where('action_id', $action->id)->delete();
        }

        $this->deleteModelPermanently($action);
    }

    private function deleteModelPermanently(Model $model): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'forceDelete')) {
            $model->forceDelete();

            return;
        }

        $model->delete();
    }

    private function resolveTarget(DeletionRequest $request): ?Model
    {
        $class = (string) $request->entity_type;
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true)
            ? $class::withTrashed()
            : $class::query();

        return $query->whereKey((int) $request->entity_id)->first();
    }

    private function userLabel(User $user): string
    {
        return trim($user->name.' <'.$user->email.'>');
    }

    /**
     * @return array<string, int>
     */
    private function impactForPas(Pas $pas): array
    {
        $paoIds = Pao::withTrashed()->where('pas_id', (int) $pas->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ptaIds = Pta::withTrashed()->whereIn('pao_id', $paoIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $axeIds = Schema::hasTable('pas_axes')
            ? DB::table('pas_axes')->where('pas_id', (int) $pas->id)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        return [
            'axes_strategiques' => count($axeIds),
            'objectifs_strategiques' => Schema::hasTable('pas_objectifs') && $axeIds !== []
                ? DB::table('pas_objectifs')->whereIn('pas_axe_id', $axeIds)->count()
                : 0,
            'objectifs_operationnels' => Schema::hasTable('objectifs_operationnels')
                ? DB::table('objectifs_operationnels')->where('pas_id', (int) $pas->id)->count()
                : 0,
            'paos' => count($paoIds),
            'ptas' => count($ptaIds),
            'actions' => $this->actionCountForPaoAndPtaIds($paoIds, $ptaIds),
        ];
    }

    /**
     * @param  array<string, int>  $linked
     */
    private function blockingImpactTotal(Model $target, array $linked): int
    {
        if ($target instanceof Pas) {
            return (int) ($linked['objectifs_operationnels'] ?? 0)
                + (int) ($linked['paos'] ?? 0)
                + (int) ($linked['ptas'] ?? 0)
                + (int) ($linked['actions'] ?? 0);
        }

        return array_sum($linked);
    }

    /**
     * @return array<string, int>
     */
    private function impactForPao(Pao $pao): array
    {
        $ptaIds = Pta::withTrashed()->where('pao_id', (int) $pao->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [
            'objectifs_operationnels' => Schema::hasTable('objectifs_operationnels')
                ? DB::table('objectifs_operationnels')->where('pao_id', (int) $pao->id)->count()
                : (Schema::hasTable('pao_objectifs_operationnels') && Schema::hasColumn('pao_objectifs_operationnels', 'pao_id')
                    ? DB::table('pao_objectifs_operationnels')->where('pao_id', (int) $pao->id)->count()
                    : 0),
            'ptas' => count($ptaIds),
            'actions' => $this->actionCountForPaoAndPtaIds([(int) $pao->id], $ptaIds),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function impactForPta(Pta $pta): array
    {
        return [
            'actions' => Action::withTrashed()->where('pta_id', (int) $pta->id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function impactForAction(Action $action): array
    {
        return [
            'sous_actions' => Schema::hasTable('sous_actions')
                ? DB::table('sous_actions')->where('action_id', (int) $action->id)->count()
                : 0,
            'justificatifs' => Schema::hasTable('justificatifs')
                ? DB::table('justificatifs')
                    ->where('justifiable_type', Action::class)
                    ->where('justifiable_id', (int) $action->id)
                    ->count()
                : 0,
            'kpis' => Schema::hasTable('kpis')
                ? DB::table('kpis')->where('action_id', (int) $action->id)->count()
                : 0,
            'journaux_action' => Schema::hasTable('action_logs')
                ? DB::table('action_logs')->where('action_id', (int) $action->id)->count()
                : 0,
        ];
    }

    private function actionCountForPaoAndPtaIds(array $paoIds, array $ptaIds): int
    {
        if ($paoIds === [] && $ptaIds === []) {
            return 0;
        }

        return Action::withTrashed()
            ->where(function ($query) use ($paoIds, $ptaIds): void {
                if ($paoIds !== []) {
                    $query->whereIn('pao_id', $paoIds);
                }
                if ($ptaIds !== []) {
                    $method = $paoIds !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('pta_id', $ptaIds);
                }
            })
            ->count();
    }

    private function archiveTarget(Model $target): array
    {
        if (! $target instanceof Pas && ! $target instanceof Pao && ! $target instanceof Pta) {
            throw ValidationException::withMessages([
                'decision' => 'Archivage automatique indisponible pour ce type d element.',
            ]);
        }

        if (! Schema::hasColumn($target->getTable(), 'statut')) {
            throw ValidationException::withMessages([
                'decision' => 'Archivage impossible : aucun statut archivable sur cet element.',
            ]);
        }

        $before = (string) ($target->getAttribute('statut') ?? '');
        $target->forceFill(['statut' => 'archive'])->save();

        return [
            'previous_status' => $before,
            'new_status' => 'archive',
        ];
    }

    private function isDeletionRequestable(Model $target): bool
    {
        return $target instanceof User
            || $target instanceof Pas
            || $target instanceof Pao
            || $target instanceof Pta
            || $target instanceof Action;
    }

    private function moduleForTarget(Model $target): string
    {
        return match (true) {
            $target instanceof User => 'referentiel_utilisateur',
            $target instanceof Pas => 'pas',
            $target instanceof Pao => 'pao',
            $target instanceof Pta => 'pta',
            $target instanceof Action => 'action',
            default => 'gouvernance',
        };
    }

    private function entityLabel(Model $target): string
    {
        if ($target instanceof User) {
            return $this->userLabel($target);
        }

        foreach (['titre', 'libelle', 'name', 'code'] as $field) {
            $value = trim((string) ($target->getAttribute($field) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return class_basename($target).' #'.(string) $target->getKey();
    }

    private function notifyPlanningChiefs(DeletionRequest $request, User $actor): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'email', 'role', 'custom_role_code'])
            ->filter(fn (User $user): bool => $user->isPlanningControlChief());

        $this->sendNotification($recipients, [
            'title' => 'Demande de suppression a traiter',
            'message' => sprintf('%s demande la suppression de %s.', $actor->name, (string) $request->entity_label),
            'module' => 'gouvernance',
            'entity_type' => 'deletion_request',
            'entity_id' => $request->id,
            'url' => route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_PENDING]).'#request-'.$request->id,
            'icon' => 'shield-alert',
            'status' => 'warning',
            'priority' => 'high',
            'notification_type' => 'validation',
            'categorie' => 'gouvernance',
            'niveau' => 'warning',
            'user_id_declencheur' => (int) $actor->id,
            'meta' => [
                'event' => 'deletion_request_created',
                'request_id' => (int) $request->id,
                'target' => (string) $request->entity_label,
            ],
        ]);
    }

    private function notifyApprovalOutcome(DeletionRequest $request, User $actor): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $user): bool => $request->isApproved()
                ? $user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN_FONCTIONNEL)
                : (int) $user->id === (int) $request->requested_by);

        $this->sendNotification($recipients, [
            'title' => $request->isApproved()
                ? 'Suppression approuvee, execution requise'
                : 'Decision du Chef Planification',
            'message' => sprintf(
                'La demande concernant %s est maintenant au statut %s.',
                (string) $request->entity_label,
                (string) $request->status
            ),
            'module' => 'gouvernance',
            'entity_type' => 'deletion_request',
            'entity_id' => $request->id,
            'url' => route('workspace.deletion-requests.index', ['status' => $request->status]).'#request-'.$request->id,
            'icon' => 'shield-check',
            'status' => $request->isApproved() ? 'success' : 'info',
            'priority' => 'high',
            'notification_type' => 'validation',
            'categorie' => 'gouvernance',
            'niveau' => (string) $request->status,
            'user_id_declencheur' => (int) $actor->id,
            'meta' => [
                'event' => 'deletion_request_planning_reviewed',
                'request_id' => (int) $request->id,
                'status' => (string) $request->status,
            ],
        ]);
    }

    private function notifyRequester(DeletionRequest $request, User $actor): void
    {
        $requester = $request->requester()->first(['id', 'name', 'email']);
        if (! $requester instanceof User) {
            return;
        }

        $this->sendNotification(collect([$requester]), [
            'title' => 'Demande de suppression traitee',
            'message' => sprintf('Decision "%s" enregistree pour %s.', (string) $request->decision, (string) $request->entity_label),
            'module' => 'referentiel',
            'entity_type' => 'deletion_request',
            'entity_id' => $request->id,
            'url' => route('workspace.deletion-requests.index', ['status' => $request->status]).'#request-'.$request->id,
            'icon' => 'shield-check',
            'status' => in_array((string) $request->status, [DeletionRequest::STATUS_DELETED, DeletionRequest::STATUS_DISABLED], true) ? 'success' : 'info',
            'priority' => 'normal',
            'notification_type' => 'decision',
            'categorie' => 'gouvernance',
            'niveau' => (string) $request->status,
            'user_id_declencheur' => (int) $actor->id,
            'meta' => [
                'event' => 'deletion_request_decided',
                'request_id' => (int) $request->id,
                'decision' => (string) $request->decision,
            ],
        ]);
    }

    /**
     * @param  iterable<int, User>  $recipients
     * @param  array<string, mixed>  $payload
     */
    private function sendNotification(iterable $recipients, array $payload): void
    {
        try {
            Notification::sendNow($recipients, new WorkspaceModuleNotification($payload));
        } catch (Throwable $exception) {
            Log::critical('Deletion request notification failed.', [
                'entity_id' => $payload['entity_id'] ?? null,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }
}
