<?php

namespace App\Services\Notifications;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\DeadlineExtensionRequest;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\SousAction;
use App\Models\UniteDg;
use App\Models\User;
use App\Services\Alerting\AlertRoutingService;
use App\Services\Governance\DelegationService;
use App\Services\NotificationPolicySettings;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class WorkspaceNotificationService
{
    public function __construct(
        private readonly DelegationService $delegationService,
        private readonly AlertRoutingService $alertRoutingService,
        private readonly NotificationPolicySettings $notificationPolicySettings,
        private readonly BrevoMailService $brevoMailService
    ) {
    }

    public function notifyActionAssigned(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_assigned')) {
            return;
        }

        if ($action->responsable_id === null && ! Schema::hasTable('action_responsables')) {
            return;
        }

        /** @var EloquentCollection<int, User> $users */
        $users = Schema::hasTable('action_responsables')
            ? $action->responsables()->get(['users.id', 'users.name', 'users.email'])
            : User::query()->whereKey((int) $action->responsable_id)->get();

        if ($users->isEmpty() && $action->responsable_id !== null) {
            $users = User::query()
                ->whereKey((int) $action->responsable_id)
                ->get();
        }

        $this->dispatchEvent(
            'action_assigned',
            $users,
            [
                'title' => 'Nouvelle action attribuée',
                'message' => sprintf('L\'action « %s » vous a été attribuée. Consultez-la dès maintenant.', (string) $action->libelle),
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
        $users = $this->mergeRecipients($users, $this->unitChiefRecipientsForAction($action));

        $this->dispatchEvent(
            'action_submitted_to_chef',
            $users,
            [
                'title' => 'Action en attente de validation',
                'message' => sprintf('L\'action « %s » a été soumise par l\'agent et attend votre évaluation.', (string) $action->libelle),
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
        $directionRecipients = $this->mergeRecipients($directionRecipients, $this->unitChiefRecipientsForAction($action));

        $this->dispatchEvent(
            'action_submitted_to_direction',
            $directionRecipients,
            [
                'title' => 'Action transmise à la direction',
                'message' => sprintf('L\'action « %s » attend votre évaluation finale.', (string) $action->libelle),
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
                'title' => 'Action clôturée',
                'message' => sprintf('Votre action « %s » a été finalisée dans le circuit actif. Bravo !', (string) $action->libelle),
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

            $this->dispatchEvent(
                'action_reviewed_by_chef',
                $directionRecipients,
                [
                    'title' => 'Action validée par le chef de service',
                    'message' => sprintf('L\'action « %s » vient d\'être validée par le chef de service. Vous pouvez la consulter en lecture.', (string) $action->libelle),
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
                    'decision' => 'Action validée par le chef de service',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            $this->dispatchEvent(
                'action_reviewed_by_chef',
                $agentRecipients,
                [
                    'title' => 'Votre action a été validée',
                    'message' => sprintf('Bonne nouvelle : votre action « %s » vient d\'être validée par le chef de service.', (string) $action->libelle),
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
                    'decision' => 'Action validée par le chef de service',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            return;
        }

        $this->dispatchEvent(
            'action_reviewed_by_chef',
            $this->mergeRecipients(
                $agentRecipients,
                $this->mergeRecipients(
                    $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
                    $this->delegationService->delegatedDirectionReviewers($directionId)
                )
            ),
            [
                'title' => 'Action à corriger',
                'message' => sprintf('Votre action « %s » a été renvoyée par le chef de service pour correction. Consultez le motif et resoumettez-la.', (string) $action->libelle),
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
                'decision' => 'Action renvoyée par le chef pour correction',
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
        $serviceRecipients = $this->mergeRecipients($serviceRecipients, $this->unitChiefRecipientsForAction($action));
        if ($approved) {
            $this->dispatchEvent(
                'action_reviewed_by_direction',
                $serviceRecipients,
                [
                    'title' => 'Action validée par la direction',
                    'message' => sprintf('L\'action « %s » est désormais validée et comptabilisée dans les statistiques.', (string) $action->libelle),
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
                    'decision' => 'Action validée par la direction',
                    'actor_name' => (string) ($actor?->name ?? ''),
                ],
                $actor?->id
            );

            return;
        }

        $this->dispatchEvent(
            'action_reviewed_by_direction',
            $serviceRecipients,
            [
                'title' => 'Action renvoyée par la direction',
                'message' => sprintf('L\'action « %s » doit être corrigée puis resoumise. Consultez les observations de la direction.', (string) $action->libelle),
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
                'decision' => 'Action renvoyée par la direction pour correction',
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

        $targets = $this->mergeRecipients(
            $this->agentRecipient($action),
            $this->mergeRecipients(
                $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
                $this->delegationService->delegatedDirectionReviewers($directionId)
            )
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->unitSupervisionRecipients($actor)
        );

        $this->dispatchEvent(
            'action_finalized_by_chef',
            $targets,
            [
                'title' => 'Action finalisée par le chef',
                'message' => sprintf('L\'action « %s » a été finalisée par le chef de service, sans étape direction supplémentaire.', (string) $action->libelle),
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
        $targets = $this->mergeRecipients($targets, $this->unitChiefRecipientsForAction($action));
        $targets = $this->mergeRecipients(
            $targets,
            $this->globalUsers([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
        );

        $this->dispatchEvent(
            'action_finalized_without_workflow',
            $targets,
            [
                'title' => 'Action clôturée',
                'message' => sprintf('L\'action « %s » a été clôturée sans circuit de validation supplémentaire.', (string) $action->libelle),
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
            'action:id,pta_id,libelle,responsable_id',
            'action.pta:id,direction_id,service_id'
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
                    'urgence' => 'Urgence sur une action',
                    'critical' => 'Problème important sur une action',
                    default => 'Action à surveiller',
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

    public function notifyActionCommentAdded(Action $action, string $comment, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_comment_added')) {
            return;
        }

        $action->loadMissing(
            'pta:id,pao_id,direction_id,service_id',
            'pta.pao:id,pas_id',
            'responsable:id,unite_dg_id'
        );

        $targets = $this->mergeRecipients(
            $this->actionSupervisorRecipients($action),
            $this->agentRecipient($action)
        );
        $actorName = (string) ($actor?->name ?? 'Un utilisateur');
        $commentExcerpt = Str::limit(trim($comment), 140, '');

        $this->dispatchEvent(
            'action_comment_added',
            $targets,
            [
                'title' => 'Nouveau commentaire sur une action',
                'message' => sprintf('%s a ajouté un commentaire sur l\'action « %s ».', $actorName, (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action).'#action-logs',
                'icon' => 'message-square',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'info',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $action->pta?->direction_id,
                'service_id' => $action->pta?->service_id,
                'unite_dg_id' => $action->unite_dg_id ?: $action->responsable?->unite_dg_id,
                'action_id' => $action->id,
                'pta_id' => $action->pta_id,
                'pao_id' => $action->pao_id ?: $action->pta?->pao_id,
                'pas_id' => $action->pta?->pao?->pas_id,
                'meta' => [
                    'event' => 'action_comment_added',
                    'action_label' => (string) $action->libelle,
                    'comment_excerpt' => $commentExcerpt,
                ],
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => $actorName,
                'comment_excerpt' => $commentExcerpt,
            ],
            $actor?->id
        );
    }

    public function notifyActionFinancingRequested(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_financing_requested')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients($this->dafDirectionUsers(), $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]));
        $targets = $this->mergeRecipients($targets, $this->directionUsers($directionId, [User::ROLE_DIRECTION]));
        $targets = $this->mergeRecipients($targets, $this->globalUsers([User::ROLE_DG, User::ROLE_PLANIFICATION]));

        $this->dispatchEvent(
            'action_financing_requested',
            $targets,
            [
                'title' => 'Demande de financement à instruire',
                'message' => sprintf('L\'action « %s » nécessite un financement. Traitement DAF requis.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action).'#action-financement',
                'icon' => 'banknote',
                'status' => 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
                'montant_estime' => (string) ($action->montant_estime ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionFinancingReviewedByDaf(Action $action, bool $approved, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_financing_reviewed_by_daf')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients($this->agentRecipient($action), $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]));
        $targets = $this->mergeRecipients($targets, $this->directionUsers($directionId, [User::ROLE_DIRECTION]));
        $targets = $this->mergeRecipients($targets, $this->globalUsers($approved ? [User::ROLE_DG, User::ROLE_PLANIFICATION] : [User::ROLE_PLANIFICATION]));

        $this->dispatchEvent(
            'action_financing_reviewed_by_daf',
            $targets,
            [
                'title' => $approved ? 'Financement validé par la DAF' : 'Financement refusé par la DAF',
                'message' => $approved
                    ? sprintf('La DAF a validé le financement de l\'action « %s ». Accord DG requis.', (string) $action->libelle)
                    : sprintf('La DAF a refusé le financement de l\'action « %s ». Consultez le motif.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action).'#action-financement',
                'icon' => $approved ? 'badge-check' : 'x-circle',
                'status' => $approved ? 'info' : 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'decision' => $approved ? 'valide_daf' : 'rejete_daf',
                'actor_name' => (string) ($actor?->name ?? ''),
                'montant_valide' => (string) ($action->financement_montant_valide ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionFinancingComplementRequested(Action $action, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_financing_reviewed_by_daf')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients($this->agentRecipient($action), $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]));
        $targets = $this->mergeRecipients($targets, $this->directionUsers($directionId, [User::ROLE_DIRECTION]));
        $targets = $this->mergeRecipients($targets, $this->globalUsers([User::ROLE_PLANIFICATION]));

        $this->dispatchEvent(
            'action_financing_reviewed_by_daf',
            $targets,
            [
                'title' => 'Complément demandé par la DAF',
                'message' => sprintf('La DAF demande un complément sur le financement de l\'action « %s ».', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action).'#action-financement',
                'icon' => 'alert-circle',
                'status' => 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'decision' => 'complement_demande',
                'actor_name' => (string) ($actor?->name ?? ''),
                'commentaire' => (string) ($action->financement_daf_commentaire ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyActionFinancingReviewedByDg(Action $action, bool $approved, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('action_financing_reviewed_by_dg')) {
            return;
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients($this->dafDirectionUsers(), $this->agentRecipient($action));
        $targets = $this->mergeRecipients($targets, $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]));
        $targets = $this->mergeRecipients($targets, $this->directionUsers($directionId, [User::ROLE_DIRECTION]));
        $targets = $this->mergeRecipients($targets, $this->globalUsers([User::ROLE_PLANIFICATION]));

        $this->dispatchEvent(
            'action_financing_reviewed_by_dg',
            $targets,
            [
                'title' => $approved ? 'Accord DG sur le financement' : 'Refus DG sur le financement',
                'message' => $approved
                    ? sprintf('La Direction Générale a donné son accord de financement pour l\'action « %s ».', (string) $action->libelle)
                    : sprintf('La Direction Générale a refusé le financement de l\'action « %s ». Consultez le motif.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action).'#action-financement',
                'icon' => $approved ? 'badge-check' : 'x-circle',
                'status' => $approved ? 'success' : 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'decision' => $approved ? 'accorde_dg' : 'refuse_dg',
                'actor_name' => (string) ($actor?->name ?? ''),
                'montant_valide' => (string) ($action->financement_montant_valide ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPaoTransmittedToServices(Pao $pao, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pao_transmitted_to_service')) {
            return;
        }

        $pao->loadMissing([
            'direction:id,code,libelle',
            'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
        ]);

        $serviceIds = $this->paoServiceIds($pao);
        $targets = $this->paoServiceRecipients($pao);

        $this->dispatchEvent(
            'pao_transmitted_to_service',
            $targets,
            [
                'title' => 'Nouveau PAO reçu',
                'message' => sprintf('Un nouveau PAO vient d\'être transmis à votre service pour l\'exercice %s. Préparez votre PTA.', (string) ($pao->annee ?? now()->year)),
                'module' => 'pao',
                'entity_type' => 'pao',
                'entity_id' => $pao->id,
                'url' => route('workspace.pao.index', ['service_id' => $serviceIds[0] ?? null]),
                'icon' => 'folder-input',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'info',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $pao->direction_id,
                'service_id' => $serviceIds[0] ?? $pao->service_id,
                'pao_id' => $pao->id,
                'pas_id' => $pao->pas_id,
                'meta' => [
                    'event' => 'pao_transmitted_to_service',
                    'service_ids' => $serviceIds,
                    'direction_label' => $this->directionLabel($pao->direction),
                ],
            ],
            [
                'year' => (string) ($pao->annee ?? now()->year),
                'pao_title' => (string) $pao->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPaoUpdatedForServices(Pao $pao, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pao_updated_for_service')) {
            return;
        }

        $pao->loadMissing([
            'direction:id,code,libelle',
            'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
        ]);

        $serviceIds = $this->paoServiceIds($pao);
        $targets = $this->paoServiceRecipients($pao);

        $this->dispatchEvent(
            'pao_updated_for_service',
            $targets,
            [
                'title' => 'PAO mis à jour',
                'message' => 'Un objectif opérationnel de votre service vient d\'être modifié par votre direction. Vérifiez les ajustements.',
                'module' => 'pao',
                'entity_type' => 'pao',
                'entity_id' => $pao->id,
                'url' => route('workspace.pao.index', ['service_id' => $serviceIds[0] ?? null]),
                'icon' => 'folder-pen',
                'status' => 'warning',
                'priority' => 'high',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'warning',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $pao->direction_id,
                'service_id' => $serviceIds[0] ?? $pao->service_id,
                'pao_id' => $pao->id,
                'pas_id' => $pao->pas_id,
                'meta' => [
                    'event' => 'pao_updated_for_service',
                    'service_ids' => $serviceIds,
                    'direction_label' => $this->directionLabel($pao->direction),
                ],
            ],
            [
                'year' => (string) ($pao->annee ?? now()->year),
                'pao_title' => (string) $pao->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPtaCreatedToDirection(Pta $pta, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pta_created_to_direction')) {
            return;
        }

        $pta->loadMissing([
            'pao:id,annee,pas_id',
            'direction:id,code,libelle',
            'service:id,code,libelle',
        ]);

        $targets = $this->mergeRecipients(
            $this->ptaDirectionRecipients($pta),
            $this->unitSupervisionRecipients($actor)
        );

        $serviceLabel = $this->serviceLabel($pta->service);
        $year = (string) ($pta->pao?->annee ?? now()->year);

        $this->dispatchEvent(
            'pta_created_to_direction',
            $targets,
            [
                'title' => 'Nouveau PTA créé',
                'message' => sprintf('Le service %s vient de créer son PTA pour l\'exercice %s.', $serviceLabel, $year),
                'module' => 'pta',
                'entity_type' => 'pta',
                'entity_id' => $pta->id,
                'url' => route('workspace.pta.index', ['service_id' => $pta->service_id]),
                'icon' => 'calendar-plus',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'info',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $pta->direction_id,
                'service_id' => $pta->service_id,
                'pta_id' => $pta->id,
                'pao_id' => $pta->pao_id,
                'pas_id' => $pta->pao?->pas_id,
                'unite_dg_id' => $actor?->unite_dg_id,
                'meta' => [
                    'event' => 'pta_created_to_direction',
                    'service_label' => $serviceLabel,
                    'direction_label' => $this->directionLabel($pta->direction),
                ],
            ],
            [
                'service_label' => $serviceLabel,
                'year' => $year,
                'pta_title' => (string) $pta->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPtaSubmittedForValidation(Pta $pta, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pta_submitted_for_validation')) {
            return;
        }

        $pta->loadMissing([
            'pao:id,annee,pas_id',
            'direction:id,code,libelle',
            'service:id,code,libelle',
        ]);

        $targets = $this->mergeRecipients(
            $this->ptaDirectionRecipients($pta),
            $this->controlRecipients()
        );
        $targets = $this->mergeRecipients($targets, $this->unitSupervisionRecipients($actor));

        $serviceLabel = $this->serviceLabel($pta->service);

        $this->dispatchEvent(
            'pta_submitted_for_validation',
            $targets,
            [
                'title' => 'PTA actualisé',
                'message' => sprintf('Le service %s vient d\'actualiser son PTA.', $serviceLabel),
                'module' => 'pta',
                'entity_type' => 'pta',
                'entity_id' => $pta->id,
                'url' => route('workspace.pta.index', ['statut' => 'en_cours']),
                'icon' => 'clipboard-list',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'information',
                'categorie' => 'suivi',
                'niveau' => 'info',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $pta->direction_id,
                'service_id' => $pta->service_id,
                'pta_id' => $pta->id,
                'pao_id' => $pta->pao_id,
                'pas_id' => $pta->pao?->pas_id,
                'unite_dg_id' => $actor?->unite_dg_id,
                'meta' => [
                    'event' => 'pta_submitted_for_validation',
                    'service_label' => $serviceLabel,
                    'direction_label' => $this->directionLabel($pta->direction),
                ],
            ],
            [
                'service_label' => $serviceLabel,
                'year' => (string) ($pta->pao?->annee ?? now()->year),
                'pta_title' => (string) $pta->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyPtaReviewedByDirection(Pta $pta, bool $approved, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('pta_reviewed_by_direction')) {
            return;
        }

        $pta->loadMissing([
            'pao:id,annee,pas_id',
            'direction:id,code,libelle',
            'service:id,code,libelle',
        ]);

        $targets = $this->ptaServiceRecipients($pta);
        $serviceLabel = $this->serviceLabel($pta->service);

        $this->dispatchEvent(
            'pta_reviewed_by_direction',
            $targets,
            [
                'title' => $approved ? 'PTA validé par la direction' : 'PTA renvoyé par la direction',
                'message' => $approved
                    ? 'Bonne nouvelle : votre PTA vient d\'être validé par la direction.'
                    : 'Votre PTA a été renvoyé par la direction. Veuillez consulter les observations et resoumettre.',
                'module' => 'pta',
                'entity_type' => 'pta',
                'entity_id' => $pta->id,
                'url' => route('workspace.pta.index', ['service_id' => $pta->service_id]),
                'icon' => $approved ? 'badge-check' : 'x-circle',
                'status' => $approved ? 'success' : 'warning',
                'priority' => $approved ? 'normal' : 'high',
                'notification_type' => $approved ? 'evenement' : 'rejet',
                'categorie' => $approved ? 'metier' : 'rejet',
                'niveau' => $approved ? 'info' : 'warning',
                'user_id_declencheur' => $actor?->id,
                'direction_id' => $pta->direction_id,
                'service_id' => $pta->service_id,
                'pta_id' => $pta->id,
                'pao_id' => $pta->pao_id,
                'pas_id' => $pta->pao?->pas_id,
                'meta' => [
                    'event' => 'pta_reviewed_by_direction',
                    'decision' => $approved ? 'approved' : 'rejected',
                    'service_label' => $serviceLabel,
                ],
            ],
            [
                'service_label' => $serviceLabel,
                'decision' => $approved ? 'PTA validé' : 'PTA renvoyé pour correction',
                'pta_title' => (string) $pta->titre,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifySubActionCreated(Action $action, SousAction $sousAction, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('sub_action_created')) {
            return;
        }

        $this->notifyActionSupervisorEvent(
            'sub_action_created',
            $action,
            $actor,
            [
                'title' => 'Nouvelle sous-action créée',
                'message' => sprintf('%s vient d\'ajouter une sous-action dans l\'action « %s ».', (string) ($actor?->name ?? 'Un agent'), (string) $action->libelle),
                'icon' => 'list-plus',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'info',
                'meta' => [
                    'event' => 'sub_action_created',
                    'sous_action_id' => (int) $sousAction->id,
                    'sous_action_label' => (string) $sousAction->libelle,
                ],
            ],
            [
                'sous_action_label' => (string) $sousAction->libelle,
            ]
        );
    }

    public function notifySubActionCompleted(Action $action, SousAction $sousAction, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('sub_action_completed')) {
            return;
        }

        $this->notifyActionSupervisorEvent(
            'sub_action_completed',
            $action,
            $actor,
            [
                'title' => 'Sous-action terminée — à vérifier',
                'message' => sprintf('%s a marqué une sous-action comme réalisée. Elle attend votre validation.', (string) ($actor?->name ?? 'Un agent')),
                'icon' => 'clipboard-check',
                'status' => 'warning',
                'priority' => 'high',
                'notification_type' => 'validation',
                'categorie' => 'controle',
                'niveau' => 'warning',
                'meta' => [
                    'event' => 'sub_action_completed',
                    'sous_action_id' => (int) $sousAction->id,
                    'sous_action_label' => (string) $sousAction->libelle,
                ],
            ],
            [
                'sous_action_label' => (string) $sousAction->libelle,
            ]
        );
    }

    public function notifyJustificatifAdded(Action $action, ?User $actor = null, ?SousAction $sousAction = null, string $category = 'execution'): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('justificatif_added')) {
            return;
        }

        $this->notifyActionSupervisorEvent(
            'justificatif_added',
            $action,
            $actor,
            [
                'title' => 'Pièce justificative ajoutée',
                'message' => sprintf('%s vient d\'ajouter une pièce justificative sur l\'action « %s ».', (string) ($actor?->name ?? 'Un agent'), (string) $action->libelle),
                'icon' => 'paperclip',
                'status' => 'info',
                'priority' => 'normal',
                'notification_type' => 'evenement',
                'categorie' => 'metier',
                'niveau' => 'info',
                'meta' => [
                    'event' => 'justificatif_added',
                    'justificatif_category' => $category,
                    'sous_action_id' => $sousAction?->id,
                    'sous_action_label' => $sousAction?->libelle,
                ],
            ],
            [
                'justificatif_category' => $category,
                'sous_action_label' => (string) ($sousAction?->libelle ?? ''),
            ]
        );
    }

    public function notifyDeadlineExtensionRequested(DeadlineExtensionRequest $request, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('deadline_extension_requested')) {
            return;
        }

        $request->loadMissing('action');
        $action = $request->action;

        $this->dispatchEvent(
            'deadline_extension_requested',
            $this->controlRecipients(),
            [
                'title' => 'Demande de report d\'échéance',
                'message' => sprintf('Une demande de report d\'échéance vient d\'être soumise pour l\'action « %s ».', (string) $action->libelle),
                'module' => 'reports_echeance',
                'entity_type' => 'deadline_extension_request',
                'entity_id' => $request->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'calendar-clock',
                'status' => $request->is_critical ? 'warning' : 'info',
                'priority' => $request->is_critical ? 'high' : 'normal',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ],
            $actor?->id
        );
    }

    public function notifyDeadlineExtensionSciqReviewed(DeadlineExtensionRequest $request, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('deadline_extension_sciq_reviewed')) {
            return;
        }

        $request->loadMissing('action');
        $action = $request->action;
        $recipients = $request->sciq_avis === DeadlineExtensionRequest::AVIS_FAVORABLE
            ? $this->globalUsers([User::ROLE_DG, User::ROLE_SUPER_ADMIN])
            : $this->actionSupervisorRecipients($action);

        $this->dispatchEvent(
            'deadline_extension_sciq_reviewed',
            $recipients,
            [
                'title' => 'Avis SCIQ / Planification rendu',
                'message' => sprintf('Avis « %s » émis sur la demande de report de l\'action « %s ».', (string) $request->sciq_avis, (string) $action->libelle),
                'module' => 'reports_echeance',
                'entity_type' => 'deadline_extension_request',
                'entity_id' => $request->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'send',
                'status' => $request->sciq_avis === DeadlineExtensionRequest::AVIS_FAVORABLE ? 'info' : 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
                'avis' => (string) $request->sciq_avis,
            ],
            $actor?->id
        );
    }

    public function notifyDeadlineExtensionDgDecided(DeadlineExtensionRequest $request, ?User $actor = null): void
    {
        if (! $this->notificationPolicySettings->eventEnabled('deadline_extension_dg_decided')) {
            return;
        }

        $request->loadMissing('action');
        $action = $request->action;
        $recipients = $this->mergeRecipients(
            $this->mergeRecipients($this->actionSupervisorRecipients($action), $this->agentRecipient($action)),
            $this->controlRecipients()
        );

        $this->dispatchEvent(
            'deadline_extension_dg_decided',
            $recipients,
            [
                'title' => 'Décision DG sur le report',
                'message' => sprintf('Décision DG « %s » pour la demande de report de l\'action « %s ».', (string) $request->dg_decision, (string) $action->libelle),
                'module' => 'reports_echeance',
                'entity_type' => 'deadline_extension_request',
                'entity_id' => $request->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'calendar-check',
                'status' => $request->dg_decision === DeadlineExtensionRequest::DECISION_APPROUVER ? 'success' : 'warning',
                'priority' => 'high',
            ],
            [
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
                'decision' => (string) $request->dg_decision,
            ],
            $actor?->id
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
                'title' => 'Nouvelle délégation reçue',
                'message' => sprintf(
                    'Une délégation vous a été attribuée par %s sur le périmètre %s.',
                    (string) ($delegation->delegant?->name ?? 'un responsable'),
                    $scopeLabel !== '' ? $scopeLabel : 'non renseigné'
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
                'scope_label' => $scopeLabel !== '' ? $scopeLabel : 'non renseigné',
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
                sprintf('%s actualisé', $moduleLabel),
                sprintf('%s « %s » vient d\'être actualisé.', $moduleLabel, $titleValue),
                'info',
            ],
            'approved' => [
                sprintf('%s validé', $moduleLabel),
                sprintf('%s « %s » a été validé.', $moduleLabel, $titleValue),
                'success',
            ],
            'locked' => [
                sprintf('%s archivé', $moduleLabel),
                sprintf('%s « %s » a été archivé.', $moduleLabel, $titleValue),
                'info',
            ],
            'reopened' => [
                sprintf('%s remis en cours', $moduleLabel),
                sprintf('%s « %s » a été remis en cours.', $moduleLabel, $titleValue),
                'warning',
            ],
            default => [
                sprintf('%s mis à jour', $moduleLabel),
                sprintf('%s « %s » a été mis à jour.', $moduleLabel, $titleValue),
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

        if (Str::startsWith((string) $log->type_evenement, 'alerte_temporelle_')) {
            return route('workspace.actions.suivi', $action).'#action-status';
        }

        if (Str::startsWith((string) $log->type_evenement, 'anomalie_')) {
            return route('workspace.actions.suivi', $action).'#action-controle';
        }

        return match ((string) $log->type_evenement) {
            'progression_sous_seuil',
            'kpi_global_sous_seuil',
            'action_a_surveiller',
            'echeance_proche',
            'alerte_combinee_critique' => route('workspace.actions.suivi', $action).'#action-status',
            default => route('workspace.actions.suivi', $action).'#action-logs',
        };
    }

    /**
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    /**
     * @return EloquentCollection<int, User>
     */
    private function paoServiceIds(Pao $pao): array
    {
        $pao->loadMissing('objectifsOperationnels:id,pao_id,service_id');

        return $pao->objectifsOperationnels
            ->pluck('service_id')
            ->push($pao->service_id)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function paoServiceRecipients(Pao $pao): Collection
    {
        $directionId = (int) ($pao->direction_id ?? 0);
        $targets = collect();

        foreach ($this->paoServiceIds($pao) as $serviceId) {
            $targets = $this->mergeRecipients(
                $targets,
                $this->serviceUsers($directionId, $serviceId, $this->serviceManagerRoles())
            );
            $targets = $this->mergeRecipients(
                $targets,
                $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
            );
        }

        return $targets;
    }

    private function ptaDirectionRecipients(Pta $pta): Collection
    {
        $directionId = (int) ($pta->direction_id ?? 0);

        return $this->mergeRecipients(
            $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
            $this->delegationService->delegatedDirectionReviewers($directionId)
        );
    }

    private function ptaServiceRecipients(Pta $pta): Collection
    {
        $directionId = (int) ($pta->direction_id ?? 0);
        $serviceId = (int) ($pta->service_id ?? 0);

        return $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, $this->serviceManagerRoles()),
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );
    }

    private function controlRecipients(): EloquentCollection
    {
        return $this->globalUsers([
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
        ]);
    }

    /**
     * A20 — Destinataires de supervision en fonction de l'unite DG de l'actor.
     *
     * Regles d'escalade :
     *   - Le chef d'unite est TOUJOURS prevenu (relation hierarchique directe).
     *   - L'escalade transverse (DG + Planification) est reservee aux chefs
     *     d'unite, pas aux agents simples (qui ne doivent pas declencher des
     *     notifs systemes globales par leurs actions du quotidien).
     *   - Les unites SCIQ/DGA/Cabinet diffusent vers les profils de supervision
     *     croisee (instance de pilotage transversal).
     *   - L'unite UCAS, qui est une unite OPERATIONNELLE simple, NE diffuse PAS
     *     vers DGA/Cabinet/SCIQ — son chef et le DG suffisent. Cela evite la
     *     fuite d'info inter-unites pour les actions UCAS purement metier.
     */
    private function unitSupervisionRecipients(?User $actor): Collection
    {
        if (! $actor instanceof User || $actor->unite_dg_id === null) {
            return collect();
        }

        $unit = UniteDg::query()
            ->with('chef:id,name,email,role,unite_dg_id')
            ->find((int) $actor->unite_dg_id);

        if (! $unit instanceof UniteDg) {
            return collect();
        }

        $targets = collect();
        if ($unit->chef instanceof User) {
            $targets = $this->mergeRecipients($targets, new EloquentCollection([$unit->chef]));
        }

        if (! $actor->hasRole(
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_CHEF_UNITE_UCAS
        )) {
            return $targets;
        }

        $targets = $this->mergeRecipients(
            $targets,
            $this->globalUsers([User::ROLE_DG, User::ROLE_PLANIFICATION])
        );

        $unitCode = strtoupper((string) $unit->code);

        // A20 — UCAS est une unite operationnelle simple : on n elargit PAS
        // a DGA/Cabinet/SCIQ pour ne pas polluer ces destinataires avec des
        // events purement UCAS (cf. perimetre UCAS dans le rapport d'audit).
        if ($unitCode === UniteDg::CODE_UCAS) {
            return $targets;
        }

        if ($unitCode === UniteDg::CODE_SCIQ) {
            return $this->mergeRecipients(
                $targets,
                $this->globalUsers([User::ROLE_DGA_SUPERVISION, User::ROLE_CABINET_SUPERVISION, User::ROLE_CABINET, User::ROLE_COLLABORATEUR])
            );
        }

        if (in_array($unitCode, [UniteDg::CODE_DGA, UniteDg::CODE_CABINET], true)) {
            return $this->mergeRecipients(
                $targets,
                $this->globalUsers([User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL])
            );
        }

        return $targets;
    }

    private function unitChiefRecipientsForAction(Action $action): Collection
    {
        $action->loadMissing('responsable:id,unite_dg_id');
        $unitId = (int) ($action->unite_dg_id ?: $action->responsable?->unite_dg_id ?: 0);

        if ($unitId <= 0) {
            return collect();
        }

        $unit = UniteDg::query()
            ->with('chef:id,name,email,role,unite_dg_id')
            ->find($unitId);

        $targets = collect();
        if ($unit instanceof UniteDg && $unit->chef instanceof User) {
            $targets = $this->mergeRecipients($targets, new EloquentCollection([$unit->chef]));
        }

        $roleRecipients = User::query()
            ->where('unite_dg_id', $unitId)
            ->whereIn('role', [
                User::ROLE_CHEF_PLANIFICATION,
                User::ROLE_CHEF_UNITE_SCIQ,
                User::ROLE_CHEF_UNITE_DGA,
                User::ROLE_CHEF_UNITE_CABINET,
                User::ROLE_CHEF_UNITE_UCAS,
            ])
            ->get();

        return $this->mergeRecipients($targets, $roleRecipients);
    }

    private function actionSupervisorRecipients(Action $action): Collection
    {
        $action->loadMissing('pta:id,direction_id,service_id', 'responsable:id,unite_dg_id');
        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $targets = $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]),
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );

        return $this->mergeRecipients($targets, $this->unitChiefRecipientsForAction($action));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, scalar|null> $extraReplacements
     */
    private function notifyActionSupervisorEvent(
        string $event,
        Action $action,
        ?User $actor,
        array $payload,
        array $extraReplacements = []
    ): void {
        $action->loadMissing('pta:id,pao_id,direction_id,service_id', 'pta.pao:id,pas_id', 'responsable:id,unite_dg_id');

        $payload['module'] = 'actions';
        $payload['entity_type'] = 'action';
        $payload['entity_id'] = $action->id;
        $payload['url'] = route('workspace.actions.suivi', $action).'#action-logs';
        $payload['user_id_declencheur'] = $actor?->id;
        $payload['direction_id'] = $action->pta?->direction_id;
        $payload['service_id'] = $action->pta?->service_id;
        $payload['unite_dg_id'] = $action->unite_dg_id ?: $action->responsable?->unite_dg_id;
        $payload['action_id'] = $action->id;
        $payload['pta_id'] = $action->pta_id;
        $payload['pao_id'] = $action->pao_id ?: $action->pta?->pao_id;
        $payload['pas_id'] = $action->pta?->pao?->pas_id;
        $payload['meta'] = array_merge(
            [
                'event' => $event,
                'action_label' => (string) $action->libelle,
            ],
            is_array($payload['meta'] ?? null) ? $payload['meta'] : []
        );

        $this->dispatchEvent(
            $event,
            $this->actionSupervisorRecipients($action),
            $payload,
            array_merge([
                'action_label' => (string) $action->libelle,
                'actor_name' => (string) ($actor?->name ?? ''),
            ], $extraReplacements),
            $actor?->id
        );
    }

    private function serviceLabel(mixed $service): string
    {
        if ($service === null) {
            return 'non renseigné';
        }

        return trim((string) (($service->code ?? '') !== '' ? $service->code : ($service->libelle ?? ''))) ?: 'non renseigné';
    }

    private function directionLabel(mixed $direction): string
    {
        if ($direction === null) {
            return 'non renseignée';
        }

        return trim((string) (($direction->code ?? '') !== '' ? $direction->code : ($direction->libelle ?? ''))) ?: 'non renseignée';
    }

    private function dafDirectionUsers(): EloquentCollection
    {
        return User::query()
            ->where('role', User::ROLE_DIRECTION)
            ->whereHas('direction', fn ($query) => $query->where('code', 'DAF'))
            ->get();
    }
    private function globalUsers(array $roles): EloquentCollection
    {
        return User::query()
            ->whereIn('role', $roles)
            ->whereNotIn('role', User::serviceOrUnitChiefRoles())
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function serviceManagerRoles(): array
    {
        return [
            User::ROLE_SERVICE,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_CHEF_UNITE_UCAS,
        ];
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
        if (Schema::hasTable('action_responsables')) {
            $users = $action->responsables()->get(['users.id', 'users.name', 'users.email']);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

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

        // Canal email Brevo : COMPLÉMENTAIRE, non bloquant. Toujours déclenché
        // APRÈS la notification interne (best-effort fail-safe).
        // L'envoi effectif est conditionné par services.brevo.enabled.
        $shouldEmail = in_array('email', $channels, true);

        if (! in_array('in_app', $channels, true)) {
            if ($shouldEmail) {
                $this->dispatchEmail($event, $users, $rendered, $excludeUserId);
            }

            return;
        }

        unset($rendered['channels']);

        $this->dispatch($users, $rendered, $excludeUserId);

        if ($shouldEmail) {
            // On réinjecte le payload sans la clé channels pour le canal email.
            $this->dispatchEmail($event, $users, $rendered, $excludeUserId);
        }
    }

    /**
     * Délégation vers BrevoMailService — fail-safe absolu (try/catch global).
     *
     * @param  Collection<int, User>|EloquentCollection<int, User>  $users
     * @param  array<string, mixed>  $payload
     */
    private function dispatchEmail(
        string $event,
        Collection|EloquentCollection $users,
        array $payload,
        ?int $excludeUserId = null
    ): void {
        try {
            $targets = $users;

            if ($excludeUserId !== null) {
                $targets = $users
                    ->reject(static fn ($user): bool => $user instanceof User
                        && (int) $user->id === (int) $excludeUserId
                    )
                    ->values();
            }

            $this->brevoMailService->dispatch($event, $targets, $payload);
        } catch (Throwable $exception) {
            // FAIL-SAFE : un échec du canal email ne doit jamais casser le métier.
            Log::warning('Brevo email channel dispatch failed (non-blocking).', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
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

        // A07 — Trace audit best-effort : on logge l'echec en critical mais on ne
        // casse pas le workflow metier appelant (creation d'action, validation,
        // etc.). Un audit perdu reste anormal et doit etre supervise via les logs.
        try {
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
        } catch (Throwable $exception) {
            Log::critical('Audit trace notification failed (A07).', [
                'event' => $event,
                'recipient_count' => $targets->count(),
                'module' => (string) ($payload['module'] ?? 'notifications'),
                'entity_type' => (string) ($payload['entity_type'] ?? 'notification'),
                'entity_id' => isset($payload['entity_id']) ? (int) $payload['entity_id'] : null,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
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

        // A07 — Notification best-effort : si la queue/DB est indisponible, on
        // logge en critical mais on n interrompt PAS le metier appelant. Les
        // alertes perdues doivent etre surveillees via le canal logs.
        try {
            $notification = new WorkspaceModuleNotification($payload);

            Notification::sendNow($targets, $notification);
        } catch (Throwable $exception) {
            Log::critical('Workspace notification dispatch failed (A07).', [
                'recipient_count' => $targets->count(),
                'recipient_ids' => $targets->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'module' => (string) ($payload['module'] ?? 'unknown'),
                'entity_type' => (string) ($payload['entity_type'] ?? 'unknown'),
                'entity_id' => isset($payload['entity_id']) ? (int) $payload['entity_id'] : null,
                'title' => (string) ($payload['title'] ?? ''),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }
}
