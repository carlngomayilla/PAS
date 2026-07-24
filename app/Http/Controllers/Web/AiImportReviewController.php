<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiImportRowRequest;
use App\Models\AiImportRow;
use App\Models\AiImportSession;
use App\Services\AiImport\ExcelMappingService;
use App\Services\AiImport\ImportExecutionService;
use App\Services\AiImport\ImportValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiImportReviewController extends Controller
{
    public function __construct(
        private readonly ImportValidationService $validation,
        private readonly ImportExecutionService $execution,
        private readonly ExcelMappingService $excel
    ) {}

    public function show(Request $request, AiImportSession $session): View
    {
        $this->authorizePermission($request, 'ai_pta_import.view');

        $session->load(['rows.importErrors', 'errors', 'user:id,name,email,role,custom_role_code']);

        return view('workspace.ai-imports.review', [
            'session' => $session,
            'rows' => $session->rows()->paginate(25),
        ]);
    }

    public function updateRow(UpdateAiImportRowRequest $request, AiImportSession $session, AiImportRow $row): RedirectResponse
    {
        abort_unless((int) $row->ai_import_session_id === (int) $session->id, 404);
        $validated = $request->validated();

        if (($validated['action'] ?? null) === 'reject') {
            $row->forceFill([
                'statut_import' => AiImportRow::IMPORT_REJECTED,
                'status' => AiImportRow::STATUS_IGNORED,
            ])->save();
        } else {
            $payload = array_replace($row->normalized_payload ?? [], [
                'libelle_axe' => $validated['axe'] ?? $row->axe,
                'libelle_objectif_strategique' => $validated['objectif_strategique'] ?? $row->objectif_strategique,
                'libelle_objectif_operationnel' => $validated['objectif_operationnel'] ?? $row->objectif_operationnel,
                'direction' => $validated['direction'] ?? $row->direction,
                'service' => $validated['service'] ?? $row->service,
                'service_unite' => $validated['service'] ?? $row->service,
                'libelle_action' => $validated['libelle_action'] ?? $row->action,
                'responsable' => $validated['rmo'] ?? $row->rmo,
                'cible' => $validated['cible'] ?? $row->cible,
                'quantite_cible' => $validated['quantite_a_realiser'] ?? $row->quantite_a_realiser,
                'livrables_attendus' => $validated['livrable_attendu'] ?? $row->livrable_attendu,
                'unite_cible' => $validated['unite_mesure'] ?? $row->unite_mesure,
                'date_debut' => $validated['date_debut'] ?? $row->date_debut?->toDateString(),
                'date_fin' => $validated['date_fin'] ?? $row->date_fin?->toDateString(),
                'echeance' => $validated['date_fin'] ?? $row->date_fin?->toDateString(),
            ]);

            $row->forceFill([
                'axe' => $validated['axe'] ?? $row->axe,
                'objectif_strategique' => $validated['objectif_strategique'] ?? $row->objectif_strategique,
                'objectif_operationnel' => $validated['objectif_operationnel'] ?? $row->objectif_operationnel,
                'direction' => $validated['direction'] ?? $row->direction,
                'service' => $validated['service'] ?? $row->service,
                'action' => $validated['libelle_action'] ?? $row->action,
                'sous_action' => $validated['sous_action'] ?? $row->sous_action,
                'rmo' => $validated['rmo'] ?? $row->rmo,
                'cible' => $validated['cible'] ?? $row->cible,
                'type_indicateur' => $validated['type_indicateur'] ?? $row->type_indicateur,
                'quantite_a_realiser' => $validated['quantite_a_realiser'] ?? $row->quantite_a_realiser,
                'livrable_attendu' => $validated['livrable_attendu'] ?? $row->livrable_attendu,
                'unite_mesure' => $validated['unite_mesure'] ?? $row->unite_mesure,
                'date_debut' => $validated['date_debut'] ?? $row->date_debut,
                'date_fin' => $validated['date_fin'] ?? $row->date_fin,
                'normalized_payload' => $payload,
            ])->save();
        }

        $this->validation->validateSession($session->refresh());
        $this->excel->generate($session->refresh());

        return redirect()
            ->route('workspace.ai-imports.review', $session)
            ->with('status', 'Ligne mise a jour.');
    }

    public function validateSession(Request $request, AiImportSession $session): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.validate');

        $stats = $this->validation->validateSession($session);
        $this->excel->generate($session->refresh());

        return back()->with('status', $stats['blocked'] > 0 ? 'Validation terminee avec lignes bloquees.' : 'Toutes les lignes sont pretes.');
    }

    public function executeImport(Request $request, AiImportSession $session): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.import');

        try {
            $stats = $this->execution->execute($session, $request->user());
        } catch (\Throwable $exception) {
            return back()->withErrors(['import' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.ai-imports.review', $session)
            ->with('status', 'Import final termine : '.$stats['imported'].' action(s) importee(s).');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
