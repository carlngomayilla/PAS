<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\PlanningImport;
use App\Services\Imports\HistoricalExecutionImportService;
use App\Services\Imports\SimpleSpreadsheet;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalExecutionImportWebController extends Controller
{
    public function __construct(
        private readonly HistoricalExecutionImportService $importService,
        private readonly SimpleSpreadsheet $spreadsheet
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.historical', [
            'imports' => PlanningImport::query()
                ->where('module', HistoricalExecutionImportService::MODULE)
                ->with('user:id,name,email,role,custom_role_code')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function preview(Request $request)
    {
        $this->authorizeAccess($request);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:10240'],
        ]);

        try {
            $import = $this->importService->createPreview($validated['file'], $request->user(), (string) $request->ip());
        } catch (\Throwable $exception) {
            return back()->withErrors(['file' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('workspace.imports.historical.show', $import);
    }

    public function show(Request $request, PlanningImport $import): View
    {
        $this->authorizeAccess($request);
        abort_unless((string) $import->module === HistoricalExecutionImportService::MODULE, 404);

        return view('workspace.imports.historical-show', [
            'import' => $import,
            'preview' => $import->preview_payload ?? [],
        ]);
    }

    public function confirm(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);
        abort_unless((string) $import->module === HistoricalExecutionImportService::MODULE, 404);

        try {
            $this->importService->execute($import, $request->user(), (string) $request->ip());
        } catch (\Throwable $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.imports.historical.show', $import)
            ->with('success', 'Reprise d execution importee. Les justificatifs restent a deposer dans les fiches actions.');
    }

    public function template(Request $request)
    {
        $this->authorizeAccess($request);

        $headers = HistoricalExecutionImportService::COLUMNS;
        $rows = $request->boolean('prefill')
            ? Action::query()
                ->whereNotNull('code')
                ->orderBy('id')
                ->get(['code'])
                ->map(fn (Action $action): array => [
                    'code_action' => $action->code,
                    'statut_execution' => '',
                    'date_debut_reelle' => '',
                    'date_fin_reelle' => '',
                    'progression_reelle' => '',
                    'quantite_realisee' => '',
                    'commentaire_historique' => '',
                ])
                ->all()
            : [
                [
                    'code_action' => 'ACT-SERVICE-2026-001-001-AXE-01-OS-01',
                    'statut_execution' => 'achevee',
                    'date_debut_reelle' => '2026-01-05',
                    'date_fin_reelle' => '2026-03-28',
                    'progression_reelle' => 100,
                    'quantite_realisee' => '',
                    'commentaire_historique' => 'Realisation du premier trimestre. PV signe a joindre dans la fiche action.',
                ],
                [
                    'code_action' => 'ACT-SERVICE-2026-001-002-AXE-01-OS-01',
                    'statut_execution' => 'en_cours',
                    'date_debut_reelle' => '2026-02-02',
                    'date_fin_reelle' => '',
                    'progression_reelle' => 45,
                    'quantite_realisee' => '',
                    'commentaire_historique' => 'Action demarree au premier trimestre, poursuite prevue au trimestre suivant.',
                ],
                [
                    'code_action' => 'ACT-SERVICE-2026-001-003-AXE-01-OS-01',
                    'statut_execution' => 'non_executee',
                    'date_debut_reelle' => '',
                    'date_fin_reelle' => '',
                    'progression_reelle' => 0,
                    'quantite_realisee' => '',
                    'commentaire_historique' => 'Action non demarree au premier trimestre. Aucun justificatif attendu a ce stade.',
                ],
            ];

        return $this->spreadsheet->downloadXlsxWorkbook(
            'modele-import-reprise-execution.xlsx',
            [
                [
                    'name' => HistoricalExecutionImportService::SHEET_NAME,
                    'headers' => $headers,
                    'rows' => $rows,
                ],
                [
                    'name' => 'GUIDE',
                    'headers' => ['colonne', 'obligation', 'valeurs_acceptees', 'explication'],
                    'rows' => [
                        ['code_action', 'Oui', 'Code existant dans Actions', 'Ne pas utiliser le libelle pour identifier une action.'],
                        ['statut_execution', 'Oui', 'non_executee / en_cours / achevee', 'Le statut achevee exige une date de fin et 100 %.'],
                        ['date_debut_reelle', 'Selon statut', 'AAAA-MM-JJ', 'Date réelle de démarrage, jamais dans le futur.'],
                        ['date_fin_reelle', 'Pour achevee', 'AAAA-MM-JJ', 'Date réelle de fin, conservée pour le calcul du délai.'],
                        ['progression_reelle', 'Oui', '0 a 100', 'Pour une action quantitative, renseigner aussi quantite_realisee.'],
                        ['quantite_realisee', 'Quantitatif', 'Nombre positif', 'Quantité réellement produite à la date de reprise.'],
                        ['commentaire_historique', 'Oui', 'Texte >= 5 caractères', 'Source de vérification à compléter ensuite avec le justificatif.'],
                    ],
                ],
            ]
        );
    }

    public function destroy(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);
        abort_unless((string) $import->module === HistoricalExecutionImportService::MODULE, 404);
        $import->delete();

        return redirect()->route('workspace.imports.historical.index')
            ->with('success', 'Import de reprise supprime.');
    }

    private function authorizeAccess(Request $request): void
    {
        if (! $this->importService->canImport($request->user())) {
            abort(403, 'Cette reprise est reservee au SCIQ, a la planification et a leurs chefs.');
        }
    }
}
