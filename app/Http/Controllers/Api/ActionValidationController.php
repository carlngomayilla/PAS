<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewActionRequest;
use App\Http\Resources\ActionResource;
use App\Models\Action;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Workflow\ActionWorkflowService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ActionValidationController extends Controller
{
    use RecordsAuditTrail;

    public function review(
        ReviewActionRequest $request,
        Action $action,
        ActionWorkflowService $workflow,
        WorkspaceNotificationService $notificationService
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $approve = $validated['decision'] === 'valider';
        $before = $action->toArray();

        try {
            $reviewed = $workflow->reviewAction(
                $action,
                $approve,
                $validated['motif'] ?? null,
                $user,
                isset($validated['progress_percent']) ? (float) $validated['progress_percent'] : null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['action' => [$exception->getMessage()]],
            ], 422);
        }

        $this->recordAudit(
            $request,
            'action',
            $approve ? 'review_action_validate' : 'review_action_reject',
            $reviewed,
            $before,
            $reviewed->toArray()
        );

        $notificationService->notifyActionReviewedByChef($reviewed, $approve, $user);
        if ($approve) {
            $notificationService->notifyActionSubmittedToController($reviewed, $user);
        }

        return (new ActionResource($reviewed->loadMissing([
            'pta:id,pao_id,direction_id,service_id,titre,statut',
            'responsable:id,name,email',
        ])))->response();
    }
}
