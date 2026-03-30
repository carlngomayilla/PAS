<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionCommentController extends Controller
{
    public function comment(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,responsable_id');
        $this->authorize('comment', $action);

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
            $request->user()
        );

        return response()->json([
            'message' => 'Commentaire enregistre.',
            'data' => $entry->load('utilisateur:id,name,email'),
        ], 201);
    }

    public function logs(Request $request, Action $action): JsonResponse
    {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $this->authorize('view', $action);

        return response()->json([
            'data' => $action->actionLogs()
                ->with(['week:id,action_id,numero_semaine', 'utilisateur:id,name,email'])
                ->latest()
                ->paginate(max(1, min(100, (int) $request->integer('per_page', 20))))
                ->withQueryString(),
        ]);
    }
}
