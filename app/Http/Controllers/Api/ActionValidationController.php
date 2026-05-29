<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\EnsuresPtaIsUnlocked;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\WorkflowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActionValidationController extends Controller
{
    use EnsuresPtaIsUnlocked;

    public function review(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService,
        WorkflowSettings $workflowSettings
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        $this->authorize('reviewByChef', $action);

        if (! $workflowSettings->serviceValidationEnabled()) {
            return response()->json([
                'message' => 'La validation chef de service est desactivee dans le workflow courant.',
            ], 409);
        }

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation chef de service.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $commentRules = ['nullable', 'string'];
        if ($workflowSettings->rejectionCommentRequired() && (string) $request->input('decision_validation') === 'rejeter') {
            array_unshift($commentRules, 'required');
        }

        // Spec v2 : le chef ne note plus. Seul le motif de rejet/correction est conserve
        // dans motif_validation_chef (ancien evaluation_commentaire).
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'motif_validation_chef' => $commentRules,
            'validation_sans_correction' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'justificatif_evaluation' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $validated['validation_sans_correction'] = $request->filled('validation_sans_correction')
            ? (bool) $request->boolean('validation_sans_correction')
            : null;

        $user = $request->user();
        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByChef($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation')) {
                $file = $request->file('justificatif_evaluation');
                $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));
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
        $action->refresh()->loadMissing('pta:id,direction_id,service_id');
        if ($decision === 'valider') {
            $notificationService->notifyActionFinalizedByChef($action, $user);
        } else {
            $notificationService->notifyActionReviewedByChef($action, $decision === 'valider', $user);
        }

        return response()->json([
            'message' => $decision === 'valider'
                ? 'Action validee par le chef de service. Le directeur et l agent sont notifies.'
                : 'Action rejetee. L agent peut mettre a jour et resoumettre.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    // reviewDirection : methode supprimee. L'etape de validation direction
    // a ete retiree du circuit metier. La route API correspondante renvoie
    // desormais 403 (cf. routes/api.php).
}
