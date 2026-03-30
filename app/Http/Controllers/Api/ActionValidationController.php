<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\EnsuresPtaIsUnlocked;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
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
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
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
            'justificatif_final' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        if ($action->date_debut !== null && (string) $validated['date_fin_reelle'] < (string) $action->date_debut) {
            return response()->json([
                'message' => 'La date de fin reelle doit etre superieure ou egale a la date debut.',
            ], 422);
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
        $notificationService->notifyActionSubmittedToChef($action, $user);

        return response()->json([
            'message' => 'Action soumise au chef de service pour evaluation.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function review(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        $this->authorize('reviewByChef', $action);

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation chef de service.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'validation_sans_correction' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'justificatif_evaluation' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
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
        $notificationService->notifyActionReviewedByChef($action, $decision === 'valider', $user);

        return response()->json([
            'message' => $decision === 'valider'
                ? 'Action validee par le chef de service et transmise a la direction.'
                : 'Action rejetee. L agent peut mettre a jour et resoumettre.',
            'data' => $action->fresh(['actionKpi', 'weeks']),
        ]);
    }

    public function reviewDirection(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        $this->authorize('reviewByDirection', $action);

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_VALIDEE_CHEF) {
            return response()->json([
                'message' => 'Cette action n est pas en attente de validation direction.',
            ], 409);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'justificatif_evaluation_direction' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
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
