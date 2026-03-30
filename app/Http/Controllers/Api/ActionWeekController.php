<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\EnsuresPtaIsUnlocked;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActionWeekController extends Controller
{
    use EnsuresPtaIsUnlocked;

    public function weeks(Request $request, Action $action, ActionTrackingService $trackingService): JsonResponse
    {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $this->authorize('view', $action);

        $trackingService->refreshActionMetrics($action);

        return response()->json([
            'data' => $action->weeks()
                ->with('saisiPar:id,name,email')
                ->orderBy('numero_semaine')
                ->get(),
        ]);
    }

    public function submitWeek(
        Request $request,
        Action $action,
        ActionWeek $actionWeek,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        if (! $request->user() instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');
        $this->authorize('submitWeek', $action);

        if ((int) $actionWeek->action_id !== (int) $action->id) {
            abort(404);
        }

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return response()->json([
                'message' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ], 409);
        }

        $rules = [
            'commentaire' => ['nullable', 'string'],
            'difficultes' => ['required', 'string'],
            'mesures_correctives' => ['required', 'string'],
            'justificatif' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ];

        if ($action->type_cible === 'quantitative') {
            $rules['quantite_realisee'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['taches_realisees'] = ['required', 'string'];
            $rules['avancement_estime'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);

        $user = $request->user();
        DB::transaction(function () use ($trackingService, $actionWeek, $validated, $request, $action, $user, $secureStorage): void {
            $trackingService->submitWeek($actionWeek, $validated, $user);

            $file = $request->file('justificatif');
            $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));
            $trackingService->addActionJustificatif(
                $action,
                $actionWeek,
                'hebdomadaire',
                $storedFile['path'],
                $storedFile['nom_original'],
                $storedFile['mime_type'],
                $storedFile['taille_octets'],
                'Justificatif hebdomadaire',
                $user,
                $storedFile['est_chiffre']
            );
        });

        return response()->json([
            'message' => 'Semaine renseignee avec succes.',
            'data' => $actionWeek->fresh(),
        ]);
    }

    private function isExecutionEditableByAgent(Action $action): bool
    {
        $status = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);

        return in_array($status, [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ], true);
    }
}
