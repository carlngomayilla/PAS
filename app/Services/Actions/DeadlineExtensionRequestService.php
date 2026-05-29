<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\DeadlineExtensionRequest;
use App\Models\JournalAudit;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeadlineExtensionRequestService
{
    public function __construct(
        private readonly SecureJustificatifStorage $storage,
        private readonly WorkspaceNotificationService $notificationService
    ) {
    }

    /**
     * @param array{requested_deadline:string,motif:string,justification:string} $payload
     */
    public function create(Action $action, ?SousAction $sousAction, array $payload, UploadedFile $file, User $actor, ?string $ipAddress = null): DeadlineExtensionRequest
    {
        $action->loadMissing('objectifOperationnel:id,echeance');

        if ($this->isDefinitivelyValidated($action, $sousAction)) {
            throw new RuntimeException('Une action ou sous-action validee definitivement ne peut plus faire l objet d un report.');
        }

        $oldDeadline = $this->currentDeadline($action, $sousAction);
        $requestedDeadline = Carbon::parse($payload['requested_deadline'])->startOfDay();
        if ($requestedDeadline->lte($oldDeadline)) {
            throw new RuntimeException('La nouvelle echeance doit etre superieure a l echeance actuelle.');
        }

        $storedFile = $this->storage->store($file, 'reports-echeance/'.date('Y/m'));
        $objectiveDeadline = $action->objectifOperationnel?->echeance
            ? Carbon::parse($action->objectifOperationnel->echeance)->startOfDay()
            : null;

        return DB::transaction(function () use ($action, $sousAction, $payload, $actor, $ipAddress, $oldDeadline, $requestedDeadline, $objectiveDeadline, $storedFile): DeadlineExtensionRequest {
            $request = DeadlineExtensionRequest::query()->create([
                'action_id' => $action->id,
                'sous_action_id' => $sousAction?->id,
                'target_type' => $sousAction instanceof SousAction ? 'sous_action' : 'action',
                'old_deadline' => $oldDeadline->toDateString(),
                'requested_deadline' => $requestedDeadline->toDateString(),
                'requested_by' => $actor->id,
                'motif' => $payload['motif'],
                'justification' => $payload['justification'],
                'attachment_path' => $storedFile['path'],
                'attachment_name' => $storedFile['nom_original'],
                'attachment_mime' => $storedFile['mime_type'],
                'attachment_size' => $storedFile['taille_octets'],
                'is_critical' => $objectiveDeadline !== null && $requestedDeadline->gt($objectiveDeadline),
                'status' => DeadlineExtensionRequest::STATUS_SOUMISE,
                'metadata' => [
                    'objective_deadline' => $objectiveDeadline?->toDateString(),
                    'target_label' => $sousAction?->libelle ?: $action->libelle,
                ],
            ]);

            $this->log($action, 'report_echeance_demande', 'Demande de report d echeance soumise.', [
                'deadline_extension_request_id' => (int) $request->id,
                'sous_action_id' => $sousAction?->id,
                'old_deadline' => $oldDeadline->toDateString(),
                'requested_deadline' => $requestedDeadline->toDateString(),
                'is_critical' => $request->is_critical,
            ], $actor);

            $this->audit($actor, 'create', $request, null, $request->toArray(), $ipAddress);
            $this->notificationService->notifyDeadlineExtensionRequested($request, $actor);

            return $request;
        });
    }

    /**
     * @param array{sciq_avis:string,sciq_comment?:string|null} $payload
     */
    public function reviewBySciq(DeadlineExtensionRequest $request, array $payload, User $actor, ?string $ipAddress = null): DeadlineExtensionRequest
    {
        return DB::transaction(function () use ($request, $payload, $actor, $ipAddress): DeadlineExtensionRequest {
            $before = $request->toArray();
            $avis = (string) $payload['sciq_avis'];

            $request->forceFill([
                'sciq_avis' => $avis,
                'sciq_comment' => trim((string) ($payload['sciq_comment'] ?? '')) ?: null,
                'sciq_reviewed_by' => $actor->id,
                'sciq_reviewed_at' => now(),
                'status' => match ($avis) {
                    DeadlineExtensionRequest::AVIS_FAVORABLE => DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
                    DeadlineExtensionRequest::AVIS_COMPLEMENT => DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
                    default => DeadlineExtensionRequest::STATUS_REJETEE,
                },
            ])->save();

            $request->loadMissing('action');
            $this->log($request->action, 'report_echeance_avis_sciq', 'Avis SCIQ / Planification enregistre.', [
                'deadline_extension_request_id' => (int) $request->id,
                'avis' => $avis,
                'status' => $request->status,
            ], $actor);

            $this->audit($actor, 'review_sciq', $request, $before, $request->toArray(), $ipAddress);
            $this->notificationService->notifyDeadlineExtensionSciqReviewed($request, $actor);

            return $request->fresh(['action']) ?? $request;
        });
    }

    /**
     * @param array{dg_decision:string,approved_deadline?:string|null,dg_comment?:string|null} $payload
     */
    public function decideByDg(DeadlineExtensionRequest $request, array $payload, User $actor, ?string $ipAddress = null): DeadlineExtensionRequest
    {
        return DB::transaction(function () use ($request, $payload, $actor, $ipAddress): DeadlineExtensionRequest {
            $before = $request->toArray();
            $decision = (string) $payload['dg_decision'];
            $approvedDeadline = ! empty($payload['approved_deadline'])
                ? Carbon::parse($payload['approved_deadline'])->startOfDay()
                : Carbon::parse($request->requested_deadline)->startOfDay();

            if ($decision === DeadlineExtensionRequest::DECISION_APPROUVER && $approvedDeadline->lte(Carbon::parse($request->old_deadline)->startOfDay())) {
                throw new RuntimeException('La date approuvee doit etre superieure a l ancienne echeance.');
            }

            $request->forceFill([
                'dg_decision' => $decision,
                'dg_comment' => trim((string) ($payload['dg_comment'] ?? '')) ?: null,
                'dg_decided_by' => $actor->id,
                'dg_decided_at' => now(),
                'approved_deadline' => $decision === DeadlineExtensionRequest::DECISION_APPROUVER ? $approvedDeadline->toDateString() : null,
                'status' => match ($decision) {
                    DeadlineExtensionRequest::DECISION_APPROUVER => DeadlineExtensionRequest::STATUS_APPROUVEE,
                    DeadlineExtensionRequest::DECISION_COMPLEMENT => DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
                    default => DeadlineExtensionRequest::STATUS_REJETEE,
                },
            ])->save();

            if ($decision === DeadlineExtensionRequest::DECISION_APPROUVER) {
                $this->applyApprovedDeadline($request, $approvedDeadline, $actor);
            }

            $request->loadMissing('action');
            $this->log($request->action, 'report_echeance_decision_dg', 'Decision DG sur report d echeance enregistree.', [
                'deadline_extension_request_id' => (int) $request->id,
                'decision' => $decision,
                'approved_deadline' => $request->approved_deadline?->toDateString(),
                'status' => $request->status,
            ], $actor);

            $this->audit($actor, 'decision_dg', $request, $before, $request->toArray(), $ipAddress);
            $this->notificationService->notifyDeadlineExtensionDgDecided($request, $actor);

            return $request->fresh(['action', 'sousAction']) ?? $request;
        });
    }

    private function applyApprovedDeadline(DeadlineExtensionRequest $request, Carbon $approvedDeadline, User $actor): void
    {
        $request->loadMissing('action', 'sousAction');

        if ($request->sousAction instanceof SousAction) {
            $request->sousAction->forceFill([
                'date_fin' => $approvedDeadline->toDateString(),
            ])->save();
        } else {
            $request->action->forceFill([
                'date_fin' => $approvedDeadline->toDateString(),
                'date_echeance' => $approvedDeadline->toDateString(),
            ])->save();
        }

        $request->forceFill([
            'status' => DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE,
            'applied_by' => $actor->id,
            'applied_at' => now(),
        ])->save();
    }

    private function currentDeadline(Action $action, ?SousAction $sousAction): Carbon
    {
        $deadline = $sousAction?->date_fin ?: $action->date_fin ?: $action->date_echeance;
        if ($deadline === null) {
            throw new RuntimeException('Aucune echeance actuelle n est definie.');
        }

        return Carbon::parse($deadline)->startOfDay();
    }

    private function isDefinitivelyValidated(Action $action, ?SousAction $sousAction): bool
    {
        if ($sousAction instanceof SousAction && in_array((string) $sousAction->statut, ['validee_chef', 'cloturee'], true)) {
            return true;
        }

        return in_array((string) $action->statut_validation, [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function log(Action $action, string $event, string $message, array $details, User $actor): void
    {
        ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => 'info',
            'type_evenement' => $event,
            'message' => $message,
            'details' => $details,
            'cible_role' => 'planification',
            'utilisateur_id' => $actor->id,
        ]);
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed> $new
     */
    private function audit(User $actor, string $event, DeadlineExtensionRequest $request, ?array $old, array $new, ?string $ipAddress): void
    {
        JournalAudit::query()->create([
            'user_id' => $actor->id,
            'module' => 'reports_echeance',
            'entite_type' => DeadlineExtensionRequest::class,
            'entite_id' => $request->id,
            'action' => $event,
            'ancienne_valeur' => $old,
            'nouvelle_valeur' => $new,
            'adresse_ip' => $ipAddress,
        ]);
    }
}
