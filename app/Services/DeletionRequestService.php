<?php

namespace App\Services;

use App\Models\Action;
use App\Models\DeletionRequest;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\PaoObjectifOperationnel;
use App\Models\Pas;
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
        private readonly UserLifecycleService $userLifecycleService
    ) {
    }

    public function canRequestUserDeletion(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return false;
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
            ->whereIn('status', [DeletionRequest::STATUS_PENDING, DeletionRequest::STATUS_COMPLEMENT_REQUESTED])
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

        $this->notifySuperAdmins($request, $actor);

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
            ->whereIn('status', [DeletionRequest::STATUS_PENDING, DeletionRequest::STATUS_COMPLEMENT_REQUESTED])
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

        $this->notifySuperAdmins($request, $actor);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(DeletionRequest $request, User $actor, string $decision, string $note, ?int $replacementId = null): array
    {
        if (! $actor->isSuperAdmin()) {
            abort(403, 'Acces non autorise.');
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'decision' => 'Cette demande a deja ete traitee.',
            ]);
        }

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'reviewer_note' => 'Le motif de decision est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $decision, $note, $replacementId): array {
            $target = $this->resolveTarget($request);
            $impact = $target instanceof Model ? $this->impactForEntity($target) : (array) ($request->impact_summary ?? []);
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
                DeletionRequest::DECISION_REJECT => DeletionRequest::STATUS_REJECTED,
                DeletionRequest::DECISION_REQUEST_COMPLEMENT => DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
                DeletionRequest::DECISION_CORRECT => DeletionRequest::STATUS_CORRECTED,
                default => throw ValidationException::withMessages(['decision' => 'Decision inconnue.']),
            };

            if ($decision === DeletionRequest::DECISION_DELETE) {
                if (! $target instanceof Model) {
                    throw ValidationException::withMessages(['decision' => 'L element cible est introuvable.']);
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
                'executed_at' => in_array($decision, [
                    DeletionRequest::DECISION_DELETE,
                    DeletionRequest::DECISION_DISABLE,
                    DeletionRequest::DECISION_ARCHIVE,
                    DeletionRequest::DECISION_CORRECT,
                ], true) ? now() : null,
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
     * Suppression cascade SOFT-DELETE complete d'une entite metier.
     *
     * Ordre de suppression (bottom-up) :
     *   Action : sous-actions + semaines puis Action
     *   PTA    : Actions (recursif) puis PTA
     *   PAO    : PTAs (recursif) + Objectifs operationnels puis PAO
     *   PAS    : PAOs (recursif) + Objectifs strategiques + Axes puis PAS
     *
     * Tout est encapsule dans une transaction pour eviter les etats intermediaires.
     * Les entites sont supprimees via leurs modeles Eloquent (et non DB::delete)
     * pour que les events Eloquent (audit, observers) se declenchent normalement.
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
        Pao::where('pas_id', $pas->id)->get()->each(fn (Pao $pao) => $this->cascadeDeletePao($pao));

        if (Schema::hasTable('pas_axes')) {
            \App\Models\PasAxe::where('pas_id', $pas->id)
                ->get()
                ->each(function (\App\Models\PasAxe $axe): void {
                    if (Schema::hasTable('pas_objectifs')) {
                        \App\Models\PasObjectif::where('pas_axe_id', $axe->id)
                            ->get()
                            ->each(fn (\App\Models\PasObjectif $os) => $os->delete());
                    }
                    $axe->delete();
                });
        }

        $pas->delete();
    }

    private function cascadeDeletePao(Pao $pao): void
    {
        Pta::where('pao_id', $pao->id)->get()->each(fn (Pta $pta) => $this->cascadeDeletePta($pta));

        if (Schema::hasTable('objectifs_operationnels')) {
            \App\Models\ObjectifOperationnel::where('pao_id', $pao->id)
                ->get()
                ->each(fn (\App\Models\ObjectifOperationnel $oo) => $oo->delete());
        }

        $pao->delete();
    }

    private function cascadeDeletePta(Pta $pta): void
    {
        Action::where('pta_id', $pta->id)->get()->each(fn (Action $action) => $this->cascadeDeleteAction($action));
        $pta->delete();
    }

    private function cascadeDeleteAction(Action $action): void
    {
        if (Schema::hasTable('sous_actions')) {
            DB::table('sous_actions')->where('action_id', $action->id)->delete();
        }
        if (Schema::hasTable('action_weeks')) {
            DB::table('action_weeks')->where('action_id', $action->id)->delete();
        }
        if (Schema::hasTable('action_responsables')) {
            DB::table('action_responsables')->where('action_id', $action->id)->delete();
        }
        $action->delete();
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
     * @param array<string, int> $linked
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

    private function notifySuperAdmins(DeletionRequest $request, User $actor): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->get(['id', 'name', 'email']);

        $this->sendNotification($recipients, [
            'title' => 'Demande de suppression a traiter',
            'message' => sprintf('%s demande la suppression de %s.', $actor->name, (string) $request->entity_label),
            'module' => 'super_admin',
            'entity_type' => 'deletion_request',
            'entity_id' => $request->id,
            'url' => route('workspace.super-admin.organization.index').'#deletion-requests',
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
            'url' => route('workspace.referentiel.utilisateurs.index'),
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
