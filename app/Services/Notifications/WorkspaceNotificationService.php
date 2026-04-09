<?php

namespace App\Services\Notifications;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Alerting\AlertRoutingService;
use App\Services\Governance\DelegationService;
use App\Services\NotificationPolicySettings;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class WorkspaceNotificationService
{
    public function __construct(
        private readonly DelegationService $delegationService,
        private readonly AlertRoutingService $alertRoutingService,
        private readonly NotificationPolicySettings $notificationPolicySettings
    ) {
    }

    public function notifyActionAssigned(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_assigned')) {
            return;
        }

        if ($action->responsable_id === null) {
            return;
        }

        /** @var EloquentCollection<int, User> $users */
        $users = User::query()
            ->whereKey((int) $action->responsable_id)
            ->get();

        $this->dispatchEvent(
            'action_assigned',
            $users,
            [
                'title' => 'Nouvelle action attribuee',
                'message' => sprintf('L action "%s" vous a ete attribuee.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'bolt',
                'status' => 'info',
                'priority' => 'normal',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            null
        );
    }

    public function notifyActionSubmittedToChef(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_submitted_to_chef')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $users = $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]);
        $users = $this->mergeRecipients(
            $users,
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );

        $this->dispatchEvent(
            'action_submitted_to_chef',
            $users,
            [
                'title' => 'Action soumise pour validation',
                'message' => sprintf('L action "%s" attend votre evaluation.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'check-circle',
                'status' => 'info',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionSubmittedToDirection(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_submitted_to_direction')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $directionRecipients = $this->mergeRecipients(
            $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
            $this->delegationService->delegatedDirectionReviewers($directionId)
        );
        $directionRecipients = $this->mergeRecipients(
            $directionRecipients,
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
        );

        $this->dispatchEvent(
            'action_submitted_to_direction',
            $directionRecipients,
            [
                'title' => 'Action soumise a la direction',
                'message' => sprintf('L action "%s" attend directement votre evaluation finale.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'arrow-up-right',
                'status' => 'info',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );

        $this->dispatchEvent(
            'action_submitted_to_direction',
            $this->agentRecipient($action),
            [
                'title' => 'Action transmise directement a la direction',
                'message' => sprintf('Votre action "%s" est en attente de validation direction.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'check-circle',
                'status' => 'info',
                'priority' => 'normal',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionReviewedByChef(Action $action, bool $approved, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_reviewed_by_chef')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $agentRecipients = $this->agentRecipient($action);

        if ($approved) {
            $directionRecipients = $this->mergeRecipients(
                $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
                $this->delegationService->delegatedDirectionReviewers($directionId)
            );
            $directionRecipients = $this->mergeRecipients(
                $directionRecipients,
                $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
            );

            $this->dispatchEvent(
                'action_reviewed_by_chef',
                $directionRecipients,
                [
                    'title' => 'Action validee par le chef',
                    'message' => sprintf('L action "%s" est prete pour validation direction.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'arrow-up-right',
                    'status' => 'info',
                    'priority' => 'high',
                ],
                [
                    'action_label' => (string) $action->libelle,
                    'decision' => 'Action validee par le chef',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            $this->dispatchEvent(
                'action_reviewed_by_chef',
                $agentRecipients,
                [
                    'title' => 'Action transmise a la direction',
                    'message' => sprintf('Votre action "%s" a ete validee par le chef de service.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'check-circle',
                    'status' => 'success',
                    'priority' => 'normal',
                ],
                [
                    'action_label' => (string) $action->libelle,
                    'decision' => 'Action validee par le chef',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            return;
        }

        $this->dispatchEvent(
            'action_reviewed_by_chef',
            $agentRecipients,
            [
                'title' => 'Action rejetee par le chef',
                'message' => sprintf('Votre action "%s" a ete rejetee. Consultez le motif.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'x-circle',
                'status' => 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'decision' => 'Action rejetee par le chef',
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionReviewedByDirection(Action $action, bool $approved, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_reviewed_by_direction')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $serviceRecipients = $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]),
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );
        $agentRecipients = $this->agentRecipient($action);

        if ($approved) {
            $targets = $this->mergeRecipients($serviceRecipients, $agentRecipients);
            $targets = $this->mergeRecipients(
                $targets,
                $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET])
            );

            $this->dispatchEvent(
                'action_reviewed_by_direction',
                $targets,
                [
                    'title' => 'Action validee par la direction',
                    'message' => sprintf('L action "%s" est officiellement comptabilisee.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'badge-check',
                    'status' => 'success',
                    'priority' => 'normal',
                ],
                [
                    'action_label' => (string) $action->libelle,
                    'decision' => 'Action validee par la direction',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            return;
        }

        $targets = $this->mergeRecipients($serviceRecipients, $agentRecipients);
        $this->dispatchEvent(
            'action_reviewed_by_direction',
            $targets,
            [
                'title' => 'Action rejetee par la direction',
                'message' => sprintf('L action "%s" doit etre corrigee puis resoumise.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'x-circle',
                'status' => 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'decision' => 'Action rejetee par la direction',
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionFinalizedByChef(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_finalized_by_chef')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]),
            $this->agentRecipient($action)
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET])
        );

        $this->dispatchEvent(
            'action_finalized_by_chef',
            $targets,
            [
                'title' => 'Action validee par le chef',
                'message' => sprintf('L action "%s" est finalisee sans etape direction supplementaire.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'badge-check',
                'status' => 'success',
                'priority' => 'normal',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionFinalizedWithoutWorkflow(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_finalized_without_workflow')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients(
            $this->agentRecipient($action),
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE])
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->directionUsers($directionId, [User::ROLE_DIRECTION])
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
        );

        $this->dispatchEvent(
            'action_finalized_without_workflow',
            $targets,
            [
                'title' => 'Action cloturee',
                'message' => sprintf('L action "%s" a ete cloturee sans circuit de validation supplementaire.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'badge-check',
                'status' => 'success',
                'priority' => 'normal',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionAlertEscalation(ActionLog $log, ?int $excludeUserId = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_alert_escalation')) {
            return;
        }

        if (! in_array((string) $log->niveau, ['warning', 'critical', 'urgence'], true)) {
            return;
        }

        $log->loadMissing(
            'action:id,pta_id,libelle',
            'action.pta:id,direction_id,service_id',
            'week:id,action_id,numero_semaine'
        );

        $action = $log->action;
        if (! $action instanceof Action) {
            return;
        }

        $targets = $this->alertRoutingService->recipientsForActionLog($log);
        $rawLevel = strtolower((string) $log->niveau);
        $level = match ($rawLevel) {
            'urgence' => 'urgence',
            'critical' => 'critical',
            default => 'warning',
        };

        if (! $this->notificationPolicySettings->alertLevelEnabled($level)) {
            return;
        }

        $this->dispatchEvent(
            'action_alert_escalation',
            $targets,
            [
                'title' => match ($level) {
                    'urgence' => 'Urgence action',
                    'critical' => 'Alerte critique action',
                    default => 'Alerte action',
                },
                'message' => $this->notificationPolicySettings->renderActionAlertMessage($log),
                'module' => 'alertes',
                'entity_type' => 'action_log',
                'entity_id' => $log->id,
                'url' => $this->resolveActionAlertUrl($log),
                'icon' => $level === 'warning' ? 'alert-triangle' : 'alert-octagon',
                'status' => $level === 'warning' ? 'warning' : 'critical',
                'priority' => $level === 'urgence' ? 'urgent' : ($level === 'critical' ? 'high' : 'normal'),
                'meta' => [
                    'event' => 'action_alert',
                    'type_evenement' => (string) $log->type_evenement,
                    'cible_role' => (string) ($log->cible_role ?? ''),
                    'action_id' => (int) $action->id,
                    'niveau' => $level,
                ],
            ],
            [
                'action_label' => (string) $action->libelle,
                'level' => $level,
                'message' => (string) $log->message,
                'actor_name' => (string) ($log->utilisateur?->name ?? ''),
            ],
            $excludeUserId
        );
    }

    public function notifyPasStatus(Pas $pas, string $event, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pas_status')) {
            return;
        }

        $pas->loadMissing('directions:id');
        $directionIds = $pas->directions
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionIds, [User::ROLE_DIRECTION, User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PAS', (string) $pas->titre, $event);

        $this->dispatchEvent(
            'pas_status',
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pas',
                'entity_type' => 'pas',
                'entity_id' => $pas->id,
                'url' => route('workspace.pas.index'),
                'icon' => 'target',
                'status' => $status,
                'priority' => 'normal',
            ],
            [
                'module_label' => 'PAS',
                'entity_title' => (string) $pas->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPaoStatus(Pao $pao, string $event, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pao_status')) {
            return;
        }

        $directionId = (int) $pao->direction_id;
        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionId, [User::ROLE_DIRECTION, User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PAO', (string) $pao->titre, $event);

        $this->dispatchEvent(
            'pao_status',
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pao',
                'entity_type' => 'pao',
                'entity_id' => $pao->id,
                'url' => route('workspace.pao.index'),
                'icon' => 'folder',
                'status' => $status,
                'priority' => 'normal',
            ],
            [
                'module_label' => 'PAO',
                'entity_title' => (string) $pao->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPtaStatus(Pta $pta, string $event, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pta_status')) {
            return;
        }

        $directionId = (int) $pta->direction_id;
        $serviceId = (int) $pta->service_id;

        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionId, [User::ROLE_DIRECTION])
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PTA', (string) $pta->titre, $event);

        $this->dispatchEvent(
            'pta_status',
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pta',
                'entity_type' => 'pta',
                'entity_id' => $pta->id,
                'url' => route('workspace.pta.index'),
                'icon' => 'calendar',
                'status' => $status,
                'priority' => 'normal',
            ],
            [
                'module_label' => 'PTA',
                'entity_title' => (string) $pta->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyDelegationCreated(Delegation $delegation, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('delegation_created')) {
            return;
        }

        $delegation->loadMissing([
            'delegant:id,name',
            'delegue:id,name',
            'direction:id,code,libelle',
            'service:id,code,libelle',
        ]);

        $delegate = $delegation->delegue;
        if (! $delegate instanceof User) {
            return;
        }

        $scopeLabel = $delegation->service !== null
            ? trim((string) ($delegation->direction?->code.' / '.$delegation->service?->code.' - '.$delegation->service?->libelle))
            : trim((string) ($delegation->direction?->code.' - '.$delegation->direction?->libelle));

        $this->dispatchEvent(
            'delegation_created',
            new EloquentCollection([$delegate]),
            [
                'title' => 'Nouvelle delegation recue',
                'message' => sprintf(
                    'Une delegation de %s vous a ete attribuee sur le perimetre %s.',
                    (string) ($delegation->delegant?->name ?? 'un responsable'),
                    $scopeLabel !== '' ? $scopeLabel : 'non renseigne'
                ),
                'module' => 'delegations',
                'entity_type' => 'delegation',
                'entity_id' => $delegation->id,
                'url' => route('workspace.delegations.index'),
                'icon' => 'users',
                'status' => 'info',
                'priority' => 'high',
            ],
            [
                'actor_name' => (string) ($delegation->delegant?->name ?? 'un responsable'),
                'scope_label' => $scopeLabel !== '' ? $scopeLabel : 'non renseigne',
            ],
            $actor?->id
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveStatusPayload(string $moduleLabel, string $titleValue, string $event): array
    {
        return match ($event) {
            'submitted' => [
                sprintf('%s soumis', $moduleLabel),
                sprintf('%s "%s" a ete soumis pour validation.', $moduleLabel, $titleValue),
                'info',
            ],
            'approved' => [
                sprintf('%s valide', $moduleLabel),
                sprintf('%s "%s" a ete valide.', $moduleLabel, $titleValue),
                'success',
            ],
            'locked' => [
                sprintf('%s verrouille', $moduleLabel),
                sprintf('%s "%s" a ete verrouille.', $moduleLabel, $titleValue),
                'info',
            ],
            'reopened' => [
                sprintf('%s remis en brouillon', $moduleLabel),
                sprintf('%s "%s" a ete remis en brouillon.', $moduleLabel, $titleValue),
                'warning',
            ],
            default => [
                sprintf('%s mis a jour', $moduleLabel),
                sprintf('%s "%s" a ete mis a jour.', $moduleLabel, $titleValue),
                'info',
            ],
        };
    }

    private function resolveActionAlertUrl(ActionLog $log): string
    {
        $action = $log->action;
        if (! $action instanceof Action) {
            return route('workspace.alertes');
        }

        if ($log->week !== null) {
            return route('workspace.actions.suivi', $action).'#action-week-'.$log->week->id;
        }

        if (Str::startsWith((string) $log->type_evenement, 'alerte_temporelle_')) {
            return route('workspace.actions.suivi', $action).'#action-status';
        }

        return match ((string) $log->type_evenement) {
            'progression_sous_seuil',
            'kpi_global_sous_seuil',
            'action_a_risque',
            'echeance_proche',
            'alerte_combinee_critique' => route('workspace.actions.suivi', $action).'#action-status',
            default => route('workspace.actions.suivi', $action).'#action-logs',
        };
    }

    /**
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function globalUsers(array $roles): EloquentCollection
    {
        return User::query()
            ->whereIn('role', $roles)
            ->get();
    }

    /**
     * @param int|array<int, int> $directionIds
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function directionUsers(int|array $directionIds, array $roles): EloquentCollection
    {
        $ids = is_array($directionIds) ? $directionIds : [$directionIds];
        $ids = array_values(array_filter($ids, static fn (int $value): bool => $value > 0));

        if ($ids === []) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereIn('role', $roles)
            ->whereIn('direction_id', $ids)
            ->get();
    }

    /**
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function serviceUsers(int $directionId, int $serviceId, array $roles): EloquentCollection
    {
        if ($directionId <= 0 || $serviceId <= 0) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereIn('role', $roles)
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function agentRecipient(Action $action): EloquentCollection
    {
        if ($action->responsable_id === null) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereKey((int) $action->responsable_id)
            ->get();
    }

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $first
     * @param Collection<int, User>|EloquentCollection<int, User> $second
     * @return Collection<int, User>
     */
    private function mergeRecipients(Collection|EloquentCollection $first, Collection|EloquentCollection $second): Collection
    {
        return $first
            ->concat($second)
            ->filter(static fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $users
     * @param array<string, mixed> $payload
     * @param array<string, scalar|null> $replacements
     */
    private function dispatchEvent(
        string $event,
        Collection|EloquentCollection $users,
        array $payload,
        array $replacements = [],
        ?int $excludeUserId = null
    ): void {
        if (! $this->notificationPolicySettings->eventEnabled($event)) {
            return;
        }

        $rendered = $this->notificationPolicySettings->renderEventPayload($event, $payload, $replacements);
        $channels = collect(is_array($rendered['channels'] ?? null) ? $rendered['channels'] : [])
            ->map(fn ($channel): string => trim((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (in_array('audit', $channels, true)) {
            $this->dispatchAuditTrace($event, $users, $rendered, $excludeUserId);
        }

        if (! in_array('in_app', $channels, true)) {
            return;
        }

        unset($rendered['channels']);

        $this->dispatch($users, $rendered, $excludeUserId);
    }

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $users
     * @param array<string, mixed> $payload
     */
    private function dispatchAuditTrace(string $event, Collection|EloquentCollection $users, array $payload, ?int $excludeUserId = null): void
    {
        $targets = $users
            ->filter(static fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($excludeUserId !== null) {
            $targets = $targets
                ->reject(static fn (User $user): bool => (int) $user->id === (int) $excludeUserId)
                ->values();
        }

        if ($targets->isEmpty()) {
            return;
        }

        JournalAudit::query()->create([
            'user_id' => $excludeUserId,
            'module' => (string) ($payload['module'] ?? 'notifications'),
            'entite_type' => (string) ($payload['entity_type'] ?? 'notification'),
            'entite_id' => isset($payload['entity_id']) ? (int) $payload['entity_id'] : null,
            'action' => 'notification_'.$event,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => [
                'title' => (string) ($payload['title'] ?? ''),
                'message' => (string) ($payload['message'] ?? ''),
                'channels' => $payload['channels'] ?? [],
                'recipient_ids' => $targets->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'recipient_count' => $targets->count(),
            ],
            'adresse_ip' => null,
            'user_agent' => 'workspace_notification_service',
        ]);
    }

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $users
     * @param array<string, mixed> $payload
     */
    private function dispatch(Collection|EloquentCollection $users, array $payload, ?int $excludeUserId = null): void
    {
        $targets = $users
            ->filter(static fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($excludeUserId !== null) {
            $targets = $targets
                ->reject(static fn (User $user): bool => (int) $user->id === (int) $excludeUserId)
                ->values();
        }

        if ($targets->isEmpty()) {
            return;
        }

        Notification::send($targets, new WorkspaceModuleNotification($payload));
    }
}
