<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewActionRequest;
use App\Http\Resources\ActionResource;
use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionFollowupDocumentService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Workflow\ActionWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ActionValidationController extends Controller
{
    use RecordsAuditTrail;

    public function review(
        ReviewActionRequest $request,
        Action $action,
        ActionWorkflowService $workflow,
        WorkspaceNotificationService $notificationService,
        ActionFollowupDocumentService $followupDocuments
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $approve = $validated['decision'] === 'valider';
        $before = $action->toArray();

        try {
            $reviewed = DB::transaction(function () use ($workflow, $followupDocuments, $action, $approve, $validated, $user): Action {
                if ($approve) {
                    $followupDocuments->recordSignature($action, $user, ActionFollowupDocumentService::STAGE_CHEF);
                }

                $reviewed = $workflow->reviewAction(
                    $action,
                    $approve,
                    $validated['motif'] ?? null,
                    $user,
                    isset($validated['progress_percent']) ? (float) $validated['progress_percent'] : null
                );

                if ($approve) {
                    $followupDocuments->generate($reviewed, ActionFollowupDocumentService::STAGE_CHEF, $user);
                }

                return $reviewed;
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['action' => [$exception->getMessage()]],
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'La fiche signée n’a pas pu être générée. La validation n’a pas été enregistrée.',
                'errors' => ['action' => ['La fiche signée n’a pas pu être générée. La validation n’a pas été enregistrée.']],
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
            $notificationService->notifyActionSubmittedToPlanification($reviewed, $user);
        }

        return (new ActionResource($reviewed->loadMissing([
            'pta:id,pao_id,direction_id,service_id,titre,statut',
            'responsable:id,name,email',
        ])))->response();
    }
}
