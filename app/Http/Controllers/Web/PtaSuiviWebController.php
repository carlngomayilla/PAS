<?php

namespace App\Http\Controllers\Web;

use App\Enums\TypeIndicateur;
use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeletePtaSuiviActionRequest;
use App\Http\Requests\UpdatePtaSuiviActionRequest;
use App\Models\Action;
use App\Models\Pta;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\DeletionRequestService;
use App\Services\Exports\PtaEvolutionWorkbookExporter;
use App\Services\Exports\PtaSuiviWorkbookExporter;
use App\Services\PlanningModificationLockService;
use App\Services\PtaSuiviService;
use App\Support\SchemaIntrospectionCache;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PtaSuiviWebController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PtaSuiviService $ptaSuiviService,
        private readonly PtaSuiviWorkbookExporter $workbookExporter,
        private readonly PtaEvolutionWorkbookExporter $evolutionWorkbookExporter
    ) {}

    public function index(Request $request)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        return view('workspace.pta-suivi.index', $this->ptaSuiviService->buildPagePayload($request, $user));
    }

    public function details(Request $request, Action $action)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->ptaSuiviService->buildActionDetails($action, $user);

        if ($request->ajax()) {
            return view('workspace.pta-suivi.partials.details', $payload);
        }

        return view('workspace.pta-suivi.details', $payload);
    }

    public function update(
        UpdatePtaSuiviActionRequest $request,
        Action $action,
        PlanningModificationLockService $lockService,
        ActionTrackingService $trackingService
    ): RedirectResponse|JsonResponse {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $action->loadMissing('pta:id,direction_id,service_id,statut,modification_locked_at,modification_unlocked_at,modification_unlock_expires_at');
        $pta = $action->pta;
        if (! $pta instanceof Pta) {
            abort(404);
        }

        if ((string) $pta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors(['pta_suivi_inline' => 'Impossible de modifier un PTA archive.']);
        }

        if (! $this->ptaSuiviService->canInlineEditAction($action, $user)) {
            return back()->withErrors(['pta_suivi_inline' => 'Vous ne pouvez pas modifier cette ligne du Suivi PTA.']);
        }

        $validated = $request->validated();
        if (! $this->rmoIsAssignable($validated['rmo_id'] ?? null, $user)) {
            return back()->withErrors(['pta_suivi_inline' => 'RMO hors perimetre.']);
        }

        if ((string) $validated['row_type'] === 'sous_action') {
            $subAction = $action->sousActions()
                ->whereKey((int) ($validated['sous_action_id'] ?? 0))
                ->firstOrFail();
            $before = $subAction->toArray();
            $subAction->forceFill($this->subActionInlinePayload($validated))->save();

            $action->refresh();
            $trackingService->refreshActionMetrics($action);
            $lockService->lockAfterSave($action, $user);
            $this->recordAudit($request, 'pta_suivi', 'inline_update_sous_action', $subAction, $before, $subAction->toArray());
        } else {
            $before = $action->toArray();
            $action->forceFill($this->actionInlinePayload($validated))->save();
            $action->refresh();
            if (array_key_exists('rmo_id', $validated)) {
                $this->syncPrimaryRmo($action, $this->nullableRmoId($validated['rmo_id'] ?? null));
                $action->load('responsables:id,name,email');
            }

            $trackingService->refreshActionMetrics($action);
            $lockService->lockAfterSave($action, $user);
            $this->recordAudit($request, 'pta_suivi', 'inline_update_action', $action, $before, $action->toArray());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Paramétrage enregistré sans rechargement de la page.',
                'action_id' => (int) $action->id,
                'row_type' => (string) $validated['row_type'],
                'sous_action_id' => isset($validated['sous_action_id']) ? (int) $validated['sous_action_id'] : null,
            ]);
        }

        return redirect()
            ->route('pta.suivi.index', $request->query())
            ->with('success', 'Ligne du Suivi PTA mise a jour.');
    }

    public function destroy(
        DeletePtaSuiviActionRequest $request,
        Action $action,
        PlanningModificationLockService $lockService,
        ActionTrackingService $trackingService
    ): RedirectResponse {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $action->loadMissing('pta:id,direction_id,service_id,statut,modification_locked_at,modification_unlocked_at,modification_unlock_expires_at');
        $pta = $action->pta;
        if (! $pta instanceof Pta) {
            abort(404);
        }

        if ((string) $pta->statut === Pta::STATUS_ARCHIVE) {
            return back()->withErrors(['pta_suivi_inline' => 'Impossible de supprimer une ligne d\'un PTA archive.']);
        }

        if (! $this->ptaSuiviService->canInlineEditAction($action, $user)) {
            return back()->withErrors(['pta_suivi_inline' => 'Vous ne pouvez pas supprimer cette ligne du Suivi PTA.']);
        }

        $validated = $request->validated();

        if ((string) $validated['row_type'] === 'sous_action') {
            $subAction = $action->sousActions()
                ->whereKey((int) ($validated['sous_action_id'] ?? 0))
                ->firstOrFail();
            $before = $subAction->toArray();
            $subAction->delete();

            $trackingService->refreshActionMetrics($action->refresh());
            $lockService->lockAfterSave($action->refresh(), $user);
            $this->recordAudit($request, 'pta_suivi', 'inline_delete_sous_action', $subAction, $before, null);
        } else {
            $deletionRequest = app(DeletionRequestService::class)->requestBusinessDeletion(
                $action,
                $user,
                'Suppression demandée depuis le tableau de suivi du PTA.',
                'action'
            );
            $this->recordAudit($request, 'pta_suivi', 'deletion_request_create', $deletionRequest, null, $deletionRequest->toArray());

            return redirect()
                ->route('pta.suivi.index', $request->query())
                ->with('success', 'Demande transmise au Chef Planification. La ligne reste visible jusqu’à l’exécution administrative.');
        }

        return redirect()
            ->route('pta.suivi.index', $request->query())
            ->with('success', 'Ligne du Suivi PTA supprimee.');
    }

    public function exportPdf(Request $request)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->ptaSuiviService->buildPagePayload($request, $user);
        $filename = $this->filename($payload, 'pdf');

        return Pdf::loadView('workspace.pta-suivi.pdf', $payload)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->ptaSuiviService->buildPagePayload($request, $user);
        $filename = $this->filename($payload, 'xlsx');
        $tempPath = $this->workbookExporter->create($payload);

        return response()->streamDownload(function () use ($tempPath): void {
            $stream = fopen($tempPath, 'rb');
            if (! is_resource($stream)) {
                @unlink($tempPath);

                return;
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
                @unlink($tempPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Rapport d'evolution du PTA au format PDF, suivant le modele
     * institutionnel : un bloc par objectif operationnel (axe strategique,
     * objectif strategique, objectif operationnel) puis le tableau des actions
     * detaillees a neuf colonnes.
     */
    public function exportEvolutionPdf(Request $request)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->evolutionPayload($request, $user);

        return Pdf::loadView('workspace.pta-suivi.evolution-pdf', $payload)
            ->setPaper('a4', 'landscape')
            ->download($this->filename($payload, 'pdf'));
    }

    public function exportEvolutionExcel(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->evolutionPayload($request, $user);

        return $this->streamWorkbook(
            $this->evolutionWorkbookExporter->create($payload),
            $this->filename($payload, 'xlsx')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evolutionPayload(Request $request, User $user): array
    {
        $payload = $this->ptaSuiviService->buildPagePayload($request, $user);
        $payload['title'] = "RAPPORT D'EVOLUTION DU PTA ".$this->ptaSuiviService->titleScopeLabel($payload['filters']);
        $payload['directions'] = $this->ptaSuiviService->buildEvolutionReportGroups($payload['rows']);

        return $payload;
    }

    private function streamWorkbook(string $tempPath, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($tempPath): void {
            $stream = fopen($tempPath, 'rb');
            if (! is_resource($stream)) {
                @unlink($tempPath);

                return;
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
                @unlink($tempPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function filename(array $payload, string $extension): string
    {
        $title = Str::slug((string) ($payload['title'] ?? 'suivi-pta'), '_');
        $date = now()->format('Ymd_His');

        return ($title !== '' ? $title : 'suivi_pta').'_'.$date.'.'.$extension;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function actionInlinePayload(array $validated): array
    {
        $type = TypeIndicateur::fromLegacy((string) $validated['type_indicateur']);
        $quantity = $this->nullableQuantity($validated['quantite_a_realiser'] ?? null);
        $deliverableText = $type->tracksDeliverable()
            ? $this->nullableText($validated['livrable_attendu'] ?? null)
            : null;

        $payload = [
            'libelle' => trim((string) $validated['libelle']),
            'type_indicateur' => $type->value,
            'type_action' => $this->actionTypeFor($type),
            'mode_evaluation' => $this->modeFor($type),
            'type_cible' => match ($type) {
                TypeIndicateur::Quantitatif => 'quantitative',
                TypeIndicateur::Mixte => 'mixte',
                TypeIndicateur::NonQuantitatif => 'qualitative',
            },
            'methode_calcul' => match ($type) {
                TypeIndicateur::Quantitatif => 'cumulative_quantity',
                TypeIndicateur::Mixte => 'sum_sous_actions',
                TypeIndicateur::NonQuantitatif => 'binary_completion',
            },
            'indicateurs_attendus' => $this->nullableText($validated['indicateur'] ?? null),
            'cible' => $deliverableText,
            'livrable_attendu' => $deliverableText,
            'resultat_attendu' => $deliverableText,
            'quantite_a_realiser' => $type->tracksQuantity() ? $quantity : null,
            'quantite_cible' => $type->tracksQuantity() ? $quantity : null,
            'unite_cible' => $type->tracksQuantity() ? $this->nullableText($validated['unite'] ?? null) : null,
            'observations' => $this->nullableText($validated['observations'] ?? null),
            'statut_parametrage' => 'parametre',
        ];

        if (array_key_exists('seuil_minimum', $validated)) {
            $payload['seuil_minimum'] = $this->percentageOrDefault($validated['seuil_minimum'] ?? null);
        }

        if (array_key_exists('rmo_id', $validated)) {
            $payload['responsable_id'] = $this->nullableRmoId($validated['rmo_id'] ?? null);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function subActionInlinePayload(array $validated): array
    {
        $type = TypeIndicateur::fromLegacy((string) $validated['type_indicateur']);
        $quantity = $this->nullableQuantity($validated['quantite_a_realiser'] ?? null);
        $deliverableText = $type->tracksDeliverable()
            ? $this->nullableText($validated['livrable_attendu'] ?? null)
            : null;

        $payload = [
            'libelle' => trim((string) $validated['libelle']),
            'type_indicateur' => $type->value,
            'sub_action_type' => match ($type) {
                TypeIndicateur::Quantitatif => SousAction::TYPE_QUANTITATIVE,
                TypeIndicateur::Mixte => SousAction::TYPE_MIXTE,
                TypeIndicateur::NonQuantitatif => SousAction::TYPE_NON_QUANTITATIVE,
            },
            'resultat_attendu' => $this->nullableText($validated['indicateur'] ?? null),
            'cible' => $deliverableText,
            'livrable_attendu' => $deliverableText,
            'quantite_a_realiser' => $type->tracksQuantity() ? $quantity : null,
            'cible_prevue' => $type->tracksQuantity() ? $quantity : null,
            'unite' => $type->tracksQuantity() ? $this->nullableText($validated['unite'] ?? null) : null,
            'commentaire' => $this->nullableText($validated['observations'] ?? null),
        ];

        if (array_key_exists('seuil_minimum', $validated)) {
            $payload['seuil_minimum'] = $this->percentageOrDefault($validated['seuil_minimum'] ?? null);
        }

        if (array_key_exists('rmo_id', $validated)) {
            $payload['agent_id'] = $this->nullableRmoId($validated['rmo_id'] ?? null);
        }

        return $payload;
    }

    private function actionTypeFor(TypeIndicateur $type): string
    {
        return match ($type) {
            TypeIndicateur::Quantitatif => Action::TYPE_QUANTITATIVE,
            TypeIndicateur::Mixte => Action::TYPE_COMPOSEE,
            TypeIndicateur::NonQuantitatif => Action::TYPE_NON_QUANTITATIVE,
        };
    }

    private function modeFor(TypeIndicateur $type): string
    {
        return match ($type) {
            TypeIndicateur::Quantitatif => Action::MODE_QUANTITATIF,
            TypeIndicateur::Mixte => Action::MODE_SOUS_ACTIONS,
            TypeIndicateur::NonQuantitatif => Action::MODE_SANS_QUANTITE,
        };
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(max(0.0, (float) $value), 4);
    }

    private function percentageOrDefault(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 80.0;
        }

        return round(min(100.0, max(0.0, (float) $value)), 2);
    }

    private function nullableRmoId(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function rmoIsAssignable(mixed $value, User $user): bool
    {
        $rmoId = $this->nullableRmoId($value);
        if ($rmoId === null) {
            return true;
        }

        $query = User::query()
            ->whereKey($rmoId)
            ->where('is_active', true);

        if (! $this->ptaSuiviService->hasInlineControlProfile($user) && ! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if (! $this->ptaSuiviService->hasInlineControlProfile($user) && $this->hasOwnServicePlanningScope($user)) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->exists();
    }

    private function syncPrimaryRmo(Action $action, ?int $rmoId): void
    {
        if (! SchemaIntrospectionCache::hasTable('action_responsables')) {
            return;
        }

        if ($rmoId === null) {
            $action->responsables()->detach();

            return;
        }

        $existingIds = $action->responsables()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->push($rmoId)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $action->responsables()->sync(
            $existingIds->mapWithKeys(fn (int $id): array => [
                $id => ['is_primary' => $id === $rmoId],
            ])->all()
        );
    }
}
