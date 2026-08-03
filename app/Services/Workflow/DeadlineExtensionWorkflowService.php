<?php

namespace App\Services\Workflow;

use App\Events\DeadlineExtensionApproved;
use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeadlineExtensionWorkflowService
{
    public function __construct(
        private readonly WorkspaceNotificationService $notificationService,
        private readonly DeadlineExtensionChangeSet $changeSet
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{path:string,mime_type:?string,taille_octets:int,nom_original:string,est_chiffre:bool}  $storedFile
     */
    public function submit(Action $action, array $payload, User $actor, array $storedFile): DeadlineExtensionRequest
    {
        $action->loadMissing('pta:id,direction_id,service_id');
        $sousAction = $this->targetSousAction($action, $payload['sous_action_id'] ?? null);
        $oldDeadline = $this->targetDeadline($action, $sousAction);
        $requestedChanges = $this->changeSet->requestedChanges($payload, $sousAction instanceof SousAction);
        $originalValues = $this->changeSet->snapshot($action, $sousAction, array_keys($requestedChanges));
        $requestedDeadline = array_key_exists(DeadlineExtensionChangeSet::FIELD_DEADLINE, $requestedChanges)
            ? Carbon::parse((string) $requestedChanges[DeadlineExtensionChangeSet::FIELD_DEADLINE])->startOfDay()
            : $oldDeadline;

        $request = DB::transaction(function () use ($action, $actor, $payload, $storedFile, $sousAction, $oldDeadline, $requestedDeadline, $requestedChanges, $originalValues): DeadlineExtensionRequest {
            Action::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $this->ensureNoActiveRequest($action, $sousAction);

            $deadlineExtensionRequest = DeadlineExtensionRequest::query()->create([
                'action_id' => $action->id,
                'sous_action_id' => $sousAction?->id,
                'target_type' => $sousAction instanceof SousAction ? 'sous_action' : 'action',
                'old_deadline' => $oldDeadline->toDateString(),
                'requested_deadline' => $requestedDeadline->toDateString(),
                'requested_changes' => $requestedChanges,
                'original_values' => $originalValues,
                'requested_by' => $actor->id,
                'motif' => (string) ($payload['motif'] ?? ''),
                'justification' => (string) ($payload['justification'] ?? ''),
                'attachment_path' => $storedFile['path'],
                'attachment_name' => $storedFile['nom_original'],
                'attachment_mime' => $storedFile['mime_type'],
                'attachment_size' => $storedFile['taille_octets'],
                'is_critical' => $oldDeadline->isPast(),
                'status' => DeadlineExtensionRequest::STATUS_SOUMISE,
                'metadata' => [
                    'encrypted_attachment' => $storedFile['est_chiffre'],
                    'submitted_target_label' => $sousAction?->libelle ?? $action->libelle,
                ],
            ]);

            $this->logAction(
                $action,
                'deadline_extension_requested',
                'info',
                'Demande de report d echeance soumise.',
                $actor,
                [
                    'deadline_extension_request_id' => $deadlineExtensionRequest->id,
                    'target_type' => $deadlineExtensionRequest->target_type,
                    'old_deadline' => $oldDeadline->toDateString(),
                    'requested_deadline' => $requestedDeadline->toDateString(),
                    'change_fields' => array_keys($requestedChanges),
                ],
                User::ROLE_SERVICE
            );

            return $deadlineExtensionRequest;
        });

        $this->notificationService->notifyDeadlineExtensionRequested($request->fresh(['action']), $actor);

        return $request->fresh(['action', 'sousAction', 'requestedBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{path:string,mime_type:?string,taille_octets:int,nom_original:string,est_chiffre:bool}  $storedFile
     */
    public function resubmit(
        DeadlineExtensionRequest $request,
        array $payload,
        User $actor,
        array $storedFile
    ): DeadlineExtensionRequest {
        $resubmitted = DB::transaction(function () use ($request, $payload, $actor, $storedFile): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()
                ->with(['action', 'sousAction'])
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStatus($lockedRequest, [DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE]);

            if ((int) $lockedRequest->requested_by !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'decision' => 'Seul le demandeur peut completer cette demande de report.',
                ]);
            }

            if ($lockedRequest->target_type === 'sous_action' && ! $lockedRequest->sousAction instanceof SousAction) {
                throw ValidationException::withMessages([
                    'decision' => 'La sous-action ciblée est supprimée ou indisponible.',
                ]);
            }

            $oldDeadline = $this->targetDeadline($lockedRequest->action, $lockedRequest->sousAction);
            $requestedChanges = $this->changeSet->requestedChanges(
                $payload,
                $lockedRequest->sousAction instanceof SousAction
            );
            $originalValues = $this->changeSet->snapshot(
                $lockedRequest->action,
                $lockedRequest->sousAction,
                array_keys($requestedChanges)
            );
            $requestedDeadline = array_key_exists(DeadlineExtensionChangeSet::FIELD_DEADLINE, $requestedChanges)
                ? Carbon::parse((string) $requestedChanges[DeadlineExtensionChangeSet::FIELD_DEADLINE])->startOfDay()
                : $oldDeadline;

            $changeSetChanged = $this->normalizedChangeSet($lockedRequest->requested_changes) !== $this->normalizedChangeSet($requestedChanges);
            $nextStatus = $changeSetChanged
                ? DeadlineExtensionRequest::STATUS_SOUMISE
                : $this->resubmissionStatus($lockedRequest);
            $metadata = is_array($lockedRequest->metadata) ? $lockedRequest->metadata : [];
            $history = is_array($metadata['revision_history'] ?? null) ? $metadata['revision_history'] : [];
            $history[] = [
                'resubmitted_at' => now()->toIso8601String(),
                'resubmitted_by' => $actor->id,
                'previous_requested_deadline' => optional($lockedRequest->requested_deadline)->format('Y-m-d'),
                'previous_requested_changes' => $lockedRequest->requested_changes,
                'previous_original_values' => $lockedRequest->original_values,
                'previous_motif' => $lockedRequest->motif,
                'previous_justification' => $lockedRequest->justification,
                'previous_attachment_path' => $lockedRequest->attachment_path,
                'previous_attachment_name' => $lockedRequest->attachment_name,
                'previous_attachment_mime' => $lockedRequest->attachment_mime,
                'previous_attachment_size' => $lockedRequest->attachment_size,
                'previous_attachment_encrypted' => (bool) ($metadata['encrypted_attachment'] ?? false),
            ];

            $metadata['revision_history'] = $history;
            $metadata['revision_count'] = count($history);
            $metadata['encrypted_attachment'] = $storedFile['est_chiffre'];
            $metadata['last_resubmitted_at'] = now()->toIso8601String();
            $metadata['last_resubmitted_by'] = $actor->id;

            $resetFields = match ($nextStatus) {
                DeadlineExtensionRequest::STATUS_SOUMISE => [
                    'chef_avis' => null,
                    'chef_comment' => null,
                    'chef_reviewed_by' => null,
                    'chef_reviewed_at' => null,
                    'director_decision' => null,
                    'director_comment' => null,
                    'director_reviewed_by' => null,
                    'director_reviewed_at' => null,
                    'final_decision' => null,
                    'final_comment' => null,
                    'final_decided_by' => null,
                    'final_decided_at' => null,
                    'final_approver_role' => null,
                    'dg_decision' => null,
                    'dg_comment' => null,
                    'dg_decided_by' => null,
                    'dg_decided_at' => null,
                ],
                DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => [
                    'director_decision' => null,
                    'director_comment' => null,
                    'director_reviewed_by' => null,
                    'director_reviewed_at' => null,
                ],
                default => [
                    'final_decision' => null,
                    'final_comment' => null,
                    'final_decided_by' => null,
                    'final_decided_at' => null,
                    'final_approver_role' => null,
                    'dg_decision' => null,
                    'dg_comment' => null,
                    'dg_decided_by' => null,
                    'dg_decided_at' => null,
                ],
            };

            $lockedRequest->forceFill(array_merge([
                'status' => $nextStatus,
                'old_deadline' => $oldDeadline->toDateString(),
                'requested_deadline' => $requestedDeadline->toDateString(),
                'requested_changes' => $requestedChanges,
                'original_values' => $originalValues,
                'applied_values' => null,
                'approved_deadline' => null,
                'motif' => (string) $payload['motif'],
                'justification' => (string) $payload['justification'],
                'attachment_path' => $storedFile['path'],
                'attachment_name' => $storedFile['nom_original'],
                'attachment_mime' => $storedFile['mime_type'],
                'attachment_size' => $storedFile['taille_octets'],
                'metadata' => $metadata,
            ], $resetFields))->save();

            $this->logAction(
                $lockedRequest->action,
                'deadline_extension_resubmitted',
                'info',
                'Le complement demande a ete ajoute et le report retransmis.',
                $actor,
                [
                    'deadline_extension_request_id' => $lockedRequest->id,
                    'next_status' => $nextStatus,
                    'revision_count' => count($history),
                    'requested_deadline' => $requestedDeadline->toDateString(),
                    'change_fields' => array_keys($requestedChanges),
                    'change_set_changed' => $changeSetChanged,
                ],
                match ($nextStatus) {
                    DeadlineExtensionRequest::STATUS_SOUMISE => User::ROLE_SERVICE,
                    DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => User::ROLE_DIRECTION,
                    default => User::ROLE_DG,
                }
            );

            return $lockedRequest;
        });

        $this->notificationService->notifyDeadlineExtensionResubmitted($resubmitted->fresh(['action']), $actor);

        return $resubmitted->fresh(['action', 'sousAction', 'requestedBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewByChef(DeadlineExtensionRequest $request, array $payload, User $actor): DeadlineExtensionRequest
    {
        $decision = (string) $payload['decision'];
        $status = match ($decision) {
            DeadlineExtensionRequest::AVIS_FAVORABLE => DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION,
            DeadlineExtensionRequest::AVIS_COMPLEMENT => DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
            default => DeadlineExtensionRequest::STATUS_REJETEE,
        };

        $reviewed = DB::transaction(function () use ($request, $payload, $actor, $decision, $status): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()
                ->with('action')
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStatus($lockedRequest, [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
            ]);
            $this->ensureDistinctReviewer($lockedRequest, $actor);

            $lockedRequest->forceFill([
                'status' => $status,
                'chef_avis' => $decision,
                'chef_comment' => (string) ($payload['comment'] ?? ''),
                'chef_reviewed_by' => $actor->id,
                'chef_reviewed_at' => now(),
            ])->save();

            $this->logAction(
                $lockedRequest->action,
                'deadline_extension_chef_reviewed',
                $decision === DeadlineExtensionRequest::AVIS_FAVORABLE ? 'info' : 'warning',
                'Avis du chef de service enregistré sur une demande de report.',
                $actor,
                [
                    'deadline_extension_request_id' => $lockedRequest->id,
                    'decision' => $decision,
                    'status' => $status,
                ],
                $decision === DeadlineExtensionRequest::AVIS_FAVORABLE ? User::ROLE_DIRECTION : null
            );

            return $lockedRequest;
        });

        $this->notificationService->notifyDeadlineExtensionChefReviewed($reviewed->fresh(['action']), $actor);

        return $reviewed->fresh(['action', 'sousAction', 'chefReviewedBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewByDirector(DeadlineExtensionRequest $request, array $payload, User $actor): DeadlineExtensionRequest
    {
        $decision = (string) $payload['decision'];
        $status = match ($decision) {
            DeadlineExtensionRequest::AVIS_FAVORABLE => DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
            DeadlineExtensionRequest::AVIS_COMPLEMENT => DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
            default => DeadlineExtensionRequest::STATUS_REJETEE,
        };

        $reviewed = DB::transaction(function () use ($request, $payload, $actor, $decision, $status): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()
                ->with('action')
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStatus($lockedRequest, [DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION]);
            $this->ensureDistinctReviewer($lockedRequest, $actor, [$lockedRequest->chef_reviewed_by]);

            $lockedRequest->forceFill([
                'status' => $status,
                'director_decision' => $decision,
                'director_comment' => (string) ($payload['comment'] ?? ''),
                'director_reviewed_by' => $actor->id,
                'director_reviewed_at' => now(),
            ])->save();

            $this->logAction(
                $lockedRequest->action,
                'deadline_extension_director_reviewed',
                $decision === DeadlineExtensionRequest::AVIS_FAVORABLE ? 'info' : 'warning',
                'Accord du directeur enregistré sur une demande de modification.',
                $actor,
                [
                    'deadline_extension_request_id' => $lockedRequest->id,
                    'decision' => $decision,
                    'status' => $status,
                ],
                $decision === DeadlineExtensionRequest::AVIS_FAVORABLE ? User::ROLE_DG : null
            );

            return $lockedRequest;
        });

        $this->notificationService->notifyDeadlineExtensionDirectorReviewed($reviewed->fresh(['action']), $actor);

        return $reviewed->fresh(['action', 'sousAction', 'directorReviewedBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewFinal(DeadlineExtensionRequest $request, array $payload, User $actor): DeadlineExtensionRequest
    {
        $decision = (string) $payload['decision'];
        $status = match ($decision) {
            DeadlineExtensionRequest::DECISION_APPROUVER => DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE,
            DeadlineExtensionRequest::DECISION_COMPLEMENT => DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
            default => DeadlineExtensionRequest::STATUS_REJETEE,
        };

        $reviewed = DB::transaction(function () use ($request, $payload, $actor, $decision, $status): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()
                ->with(['action', 'sousAction'])
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStatus($lockedRequest, [
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
            ]);
            $this->ensureDistinctReviewer($lockedRequest, $actor, [
                $lockedRequest->chef_reviewed_by,
                $lockedRequest->director_reviewed_by,
            ]);
            $this->lockChangeTargets($lockedRequest);

            $requestedChanges = is_array($lockedRequest->requested_changes) ? $lockedRequest->requested_changes : [];
            $approvedDeadline = array_key_exists(DeadlineExtensionChangeSet::FIELD_DEADLINE, $requestedChanges)
                ? (string) $requestedChanges[DeadlineExtensionChangeSet::FIELD_DEADLINE]
                : null;
            $finalValues = [
                'status' => $status,
                'final_decision' => $decision,
                'final_comment' => (string) ($payload['comment'] ?? ''),
                'final_decided_by' => $actor->id,
                'final_decided_at' => now(),
                'final_approver_role' => $actor->role,
                'approved_deadline' => $decision === DeadlineExtensionRequest::DECISION_APPROUVER ? $approvedDeadline : null,
                // Compatibilité des exports et historiques déjà fondés sur les colonnes DG.
                'dg_decision' => $decision,
                'dg_comment' => (string) ($payload['comment'] ?? ''),
                'dg_decided_by' => $actor->id,
                'dg_decided_at' => now(),
            ];

            if ($decision === DeadlineExtensionRequest::DECISION_APPROUVER) {
                $finalValues['applied_values'] = $this->changeSet->apply($lockedRequest);
                $finalValues['applied_by'] = $actor->id;
                $finalValues['applied_at'] = now();
            }

            $lockedRequest->forceFill($finalValues)->save();

            $this->logAction(
                $lockedRequest->action,
                $decision === DeadlineExtensionRequest::DECISION_APPROUVER
                    ? 'deadline_extension_dg_approved_and_applied'
                    : 'deadline_extension_final_decided',
                $decision === DeadlineExtensionRequest::DECISION_APPROUVER ? 'info' : 'warning',
                'Décision finale enregistrée sur une demande de report.',
                $actor,
                [
                    'deadline_extension_request_id' => $lockedRequest->id,
                    'decision' => $decision,
                    'status' => $status,
                    'approver_role' => $actor->role,
                    'approved_deadline' => $decision === DeadlineExtensionRequest::DECISION_APPROUVER ? $approvedDeadline : null,
                    'change_fields' => array_keys($requestedChanges),
                ],
                $decision === DeadlineExtensionRequest::DECISION_APPROUVER ? User::ROLE_SCIQ : null
            );

            return $lockedRequest;
        });

        if ($decision === DeadlineExtensionRequest::DECISION_APPROUVER) {
            DeadlineExtensionApproved::dispatch($reviewed, $actor);
        }

        $this->notificationService->notifyDeadlineExtensionFinalDecided($reviewed->fresh(['action']), $actor);

        return $reviewed->fresh(['action', 'sousAction', 'finalDecidedBy']);
    }

    public function applyApprovedDeadline(DeadlineExtensionRequest $request, User $actor): DeadlineExtensionRequest
    {
        $applied = DB::transaction(function () use ($request, $actor): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()
                ->with(['action', 'sousAction'])
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStatus($lockedRequest, [DeadlineExtensionRequest::STATUS_APPROUVEE]);
            $this->lockChangeTargets($lockedRequest);

            $appliedValues = $this->changeSet->apply($lockedRequest);

            $lockedRequest->forceFill([
                'status' => DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE,
                'applied_values' => $appliedValues,
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ])->save();

            $this->logAction(
                $lockedRequest->action,
                'deadline_extension_legacy_applied_by_dg',
                'info',
                'La modification approuvée a été appliquée par la DG.',
                $actor,
                [
                    'deadline_extension_request_id' => $lockedRequest->id,
                    'change_fields' => array_keys($appliedValues),
                ]
            );

            return $lockedRequest;
        });

        DeadlineExtensionApproved::dispatch($applied, $actor);

        $this->notificationService->notifyDeadlineExtensionApplied($applied->fresh(['action']), $actor);

        return $applied->fresh(['action', 'sousAction']);
    }

    private function targetSousAction(Action $action, mixed $sousActionId): ?SousAction
    {
        $id = (int) ($sousActionId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $sousAction = $action->sousActions()->whereKey($id)->first();
        if (! $sousAction instanceof SousAction) {
            throw ValidationException::withMessages([
                'sous_action_id' => 'La sous-action selectionnee ne correspond pas a cette action.',
            ]);
        }

        return $sousAction;
    }

    private function targetDeadline(Action $action, ?SousAction $sousAction): Carbon
    {
        $deadline = $sousAction?->date_fin
            ?? $action->date_fin
            ?? $action->date_echeance
            ?? $action->echeance_cible;

        if ($deadline === null) {
            throw ValidationException::withMessages([
                'requested_deadline' => 'Aucune echeance de reference n est definie pour cet element.',
            ]);
        }

        return Carbon::parse($deadline)->startOfDay();
    }

    private function resubmissionStatus(DeadlineExtensionRequest $request): string
    {
        if ($request->final_decision === DeadlineExtensionRequest::DECISION_COMPLEMENT
            || $request->dg_decision === DeadlineExtensionRequest::DECISION_COMPLEMENT) {
            return DeadlineExtensionRequest::STATUS_TRANSMISE_DG;
        }

        if ($request->director_decision === DeadlineExtensionRequest::AVIS_COMPLEMENT) {
            return DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION;
        }

        return DeadlineExtensionRequest::STATUS_SOUMISE;
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function ensureStatus(DeadlineExtensionRequest $request, array $allowedStatuses): void
    {
        if (in_array((string) $request->status, $allowedStatuses, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => 'Cette demande de report ne peut plus etre traitee a cette etape.',
        ]);
    }

    private function ensureNoActiveRequest(Action $action, ?SousAction $sousAction): void
    {
        $query = $action->deadlineExtensionRequests()
            ->whereIn('status', [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION,
                DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
                DeadlineExtensionRequest::STATUS_APPROUVEE,
            ]);

        if ($sousAction instanceof SousAction) {
            $query->where('sous_action_id', $sousAction->id);
        } else {
            $query->whereNull('sous_action_id');
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'requested_deadline' => 'Une demande de report est deja en cours pour cet element.',
        ]);
    }

    /** @param array<int, mixed> $previousReviewerIds */
    private function ensureDistinctReviewer(
        DeadlineExtensionRequest $request,
        User $actor,
        array $previousReviewerIds = []
    ): void {
        $forbiddenIds = collect($previousReviewerIds)
            ->push($request->requested_by)
            ->filter(fn (mixed $id): bool => (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if (! $forbiddenIds->contains((int) $actor->id)) {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => 'Chaque étape du circuit doit être traitée par un intervenant distinct du RMO et des valideurs précédents.',
        ]);
    }

    private function normalizedChangeSet(mixed $changes): string
    {
        $values = is_array($changes) ? $changes : [];
        foreach ($values as &$value) {
            if (is_array($value)) {
                sort($value);
            }
        }
        ksort($values);

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function lockChangeTargets(DeadlineExtensionRequest $request): void
    {
        $action = Action::query()->whereKey($request->action_id)->lockForUpdate()->firstOrFail();
        $request->setRelation('action', $action);

        if ($request->target_type !== 'sous_action') {
            $request->setRelation('sousAction', null);

            return;
        }

        $sousAction = SousAction::withTrashed()
            ->whereKey($request->sous_action_id)
            ->where('action_id', $request->action_id)
            ->lockForUpdate()
            ->first();
        if (! $sousAction instanceof SousAction || $sousAction->trashed()) {
            throw ValidationException::withMessages([
                'decision' => 'La sous-action ciblée est supprimée ou indisponible. Aucune modification n’a été appliquée.',
            ]);
        }

        $request->setRelation('sousAction', $sousAction);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function logAction(
        Action $action,
        string $eventType,
        string $level,
        string $message,
        User $actor,
        array $details,
        ?string $targetRole = null
    ): void {
        $action->actionLogs()->create([
            'niveau' => $level,
            'type_evenement' => $eventType,
            'message' => $message,
            'details' => $details,
            'cible_role' => $targetRole,
            'utilisateur_id' => $actor->id,
            'lu' => false,
        ]);
    }
}
