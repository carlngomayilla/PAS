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

    public function close(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        ActionManagementSettings $actionManagementSettings,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService,
        WorkflowSettings $workflowSettings
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id');
        $this->authorize('submitClosure', $action);

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        $currentValidationStatus = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);
        if (in_array($currentValidationStatus, [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ], true)) {
            return response()->json([
                'message' => 'Action deja soumise. En attente de validation hierarchique.',
            ], 409);
        }

        if ($currentValidationStatus === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION) {
            return response()->json([
                'message' => 'Action deja validee par la direction.',
            ], 409);
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->where('categorie', 'hebdomadaire')
            ->exists();
        if (! $hasExecutionJustificatif) {
            return response()->json([
                'message' => 'Soumission impossible: aucun justificatif d execution trouve dans les periodes de suivi.',
            ], 422);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'date_fin_reelle' => ['required', 'date'],
            'rapport_final' => ['required', 'string'],
            'justificatif_final' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        if ($action->date_debut !== null && (string) $validated['date_fin_reelle'] < (string) $action->date_debut) {
            return response()->json([
                'message' => 'La date de fin reelle doit etre superieure ou egale a la date debut.',
            ], 422);
        }

        $minimumProgress = $actionManagementSettings->minProgressForClosure();
        if ((float) ($action->progression_reelle ?? 0) < $minimumProgress) {
            return response()->json([
                'message' => 'Soumission impossible: la progression minimale de cloture est fixee a '.$minimumProgress.'%.',
            ], 422);
        }

        if ($actionManagementSettings->finalJustificatifRequired()) {
            $hasExistingFinalJustificatif = $action->justificatifs()
                ->where('categorie', 'final')
                ->exists();

            if (! $request->hasFile('justificatif_final') && ! $hasExistingFinalJustificatif) {
                return response()->json([
                    'message' => 'Le justificatif final est obligatoire selon la politique metier des actions.',
                ], 422);
            }
        }

        $user = $request->user();
        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->submitClosureForReview($action, $validated, $user);

            if ($request->hasFile('justificatif_final')) {
                $file = $request->file('justificatif_final');
                $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'final',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif final de cloture transmis pour validation',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });
        $action->refresh()->loadMissing('pta:id,direction_id,service_id');
        $submissionTarget = $workflowSettings->actionSubmissionTarget();
        if ($submissionTarget === 'service') {
            $notificationService->notifyActionSubmittedToChef($action, $user);
        } elseif ($submissionTarget === 'direction') {
            $notificationService->notifyActionSubmittedToDirection($action, $user);
        } else {
            $notificationService->notifyActionFinalizedWithoutWorkflow($action, $user);
        }

        return response()->json([
            'message' => match ($submissionTarget) {
                'direction' => 'Action soumise directement a la direction pour evaluation.',
                'final' => 'Action cloturee sans validation supplementaire. Elle est maintenant prise en compte dans les statistiques.',
                default => 'Action soumise au chef de service pour evaluation.',
            },
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

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

        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => $commentRules,
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
        $directionEnabled = $workflowSettings->directionValidationEnabled();

        if ($decision === 'valider' && ! $directionEnabled) {
            $notificationService->notifyActionFinalizedByChef($action, $user);
        } else {
            $notificationService->notifyActionReviewedByChef($action, $decision === 'valider', $user);
        }

        return response()->json([
            'message' => $decision === 'valider'
                ? ($directionEnabled
                    ? 'Action validee par le chef de service et transmise a la direction.'
                    : 'Action validee par le chef de service. Elle est maintenant prise en compte dans les statistiques.')
                : 'Action rejetee. L agent peut mettre a jour et resoumettre.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function reviewDirection(
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
        $this->authorize('reviewByDirection', $action);

        if (! $workflowSettings->directionValidationEnabled()) {
            return response()->json([
                'message' => 'La validation direction est desactivee dans le workflow courant.',
            ], 409);
        }

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_VALIDEE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation direction.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $commentRules = ['nullable', 'string'];
        if ($workflowSettings->rejectionCommentRequired() && (string) $request->input('decision_validation') === 'rejeter') {
            array_unshift($commentRules, 'required');
        }

        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => $commentRules,
            'justificatif_evaluation_direction' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $user = $request->user();
        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByDirection($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation_direction')) {
                $file = $request->file('justificatif_evaluation_direction');
                $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'evaluation_direction',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de revue direction',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');
        $action->refresh()->loadMissing('pta:id,direction_id,service_id');
        $notificationService->notifyActionReviewedByDirection($action, $decision === 'valider', $user);

        return response()->json([
            'message' => $decision === 'valider'
                ? 'Action validee par la direction. Elle est maintenant prise en compte dans les statistiques.'
                : 'Action rejetee par la direction. Retour au chef de service.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }
}
