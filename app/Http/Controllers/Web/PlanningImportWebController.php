<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlanningImport;
use App\Services\Imports\PlanningExcelImportService;
use App\Services\Imports\SimpleSpreadsheet;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanningImportWebController extends Controller
{
    public function __construct(
        private readonly PlanningExcelImportService $importService,
        private readonly SimpleSpreadsheet $spreadsheet
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.index', [
            'imports' => PlanningImport::query()->with('user:id,name,email,role,custom_role_code')->latest()->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.create', [
            'modes' => $this->modes(),
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

        return redirect()->route('workspace.imports.show', $import);
    }

    public function show(Request $request, PlanningImport $import): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.show', [
            'import' => $import,
            'preview' => $import->preview_payload ?? [],
            'modes' => $this->modes(),
        ]);
    }

    public function mapping(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        try {
            $this->importService->applyColumnMapping($import, $validated['mapping'], $request->user());
        } catch (\Throwable $exception) {
            return back()->withErrors(['mapping' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('workspace.imports.show', $import);
    }

    public function confirm(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);
        $validated = $request->validate([
            'mode' => ['required', 'in:create_only,skip_duplicates,update_existing'],
        ]);

        try {
            $this->importService->execute($import, (string) $validated['mode'], $request->user(), (string) $request->ip());
        } catch (\Throwable $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.imports.result', $import);
    }

    public function result(Request $request, PlanningImport $import): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.result', ['import' => $import]);
    }

    public function errors(Request $request, PlanningImport $import): View
    {
        $this->authorizeAccess($request);

        return view('workspace.imports.errors', ['import' => $import]);
    }

    public function template(Request $request)
    {
        $this->authorizeAccess($request);

        $example = array_combine(PlanningExcelImportService::REQUIRED_COLUMNS, [
            2026, 2028, 1, 'Axe institutionnel exemple', 1, 'Objectif strategique exemple', '2028-12-31',
            'DSIC', 'SIRS', 1, 'Objectif operationnel exemple', '2026-12-31', 1, 'Action planifiee exemple',
            '2026-01-15', '2026-06-30', 'AG001;AG002', 80, 'Rapport de realisation', 2, 0, '', '',
            'Risque a surveiller', 'Ordinateurs et fournitures', 'Equipe projet', 'Appui technique',
        ]);

        return $this->spreadsheet->downloadXlsx(
            'modele-import-global-pas-pao-pta-actions.xlsx',
            PlanningExcelImportService::REQUIRED_COLUMNS,
            [$example]
        );
    }

    public function errorReport(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);

        $headers = array_merge(PlanningExcelImportService::REQUIRED_COLUMNS, ['numero_ligne', 'statut', 'message_erreur', 'suggestion']);
        $rows = collect($import->error_report ?? [])
            ->map(function (array $row): array {
                $data = $row['data'] ?? [];
                $data['numero_ligne'] = $row['line'] ?? '';
                $data['statut'] = $row['status'] ?? 'Erreur';
                $data['message_erreur'] = $row['message'] ?? '';
                $data['suggestion'] = 'Corriger la ligne indiquee puis relancer la verification.';

                return $data;
            })
            ->values()
            ->all();

        return $this->spreadsheet->downloadXlsx('rapport-erreurs-import-'.$import->id.'.xlsx', $headers, $rows);
    }

    private function authorizeAccess(Request $request): void
    {
        if (! $this->importService->canImport($request->user())) {
            abort(403, "Vous n'avez pas acces aux imports Excel.");
        }
    }

    private function modes(): array
    {
        return [
            PlanningImport::MODE_CREATE_ONLY => 'Creer uniquement',
            PlanningImport::MODE_SKIP_DUPLICATES => 'Ignorer les doublons',
            PlanningImport::MODE_UPDATE_EXISTING => 'Mettre a jour si existe',
        ];
    }
}
