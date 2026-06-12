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

    public function destroy(Request $request, PlanningImport $import)
    {
        $this->authorizeAccess($request);

        $filename = $import->filename;
        $import->delete();

        return redirect()
            ->route('workspace.imports.index')
            ->with('success', "Import « {$filename} » supprimé.");
    }

    public function template(Request $request)
    {
        $this->authorizeAccess($request);

        $headers = PlanningExcelImportService::IMPORT_COLUMNS;

        // Un exemple par type d'action (deja parametre, importe directement en
        // 'parametre'). Pour ne PAS parametrer une ligne et la laisser
        // 'a_parametrer', il suffit de laisser la colonne type_action vide.
        // Codes type_action : Q = quantitative, NQ = non quantitative,
        // M = action composee. Dans sous_actions, utiliser Q ou NQ.

        // Ligne 1 : action simple QUANTITATIVE (cible chiffree + seuils trimestriels).
        $quantitative = $this->templateRow([
            'ordre_action' => 1,
            'libelle_action' => 'Action quantitative exemple',
            'date_debut_action' => '2026-02-01',
            'date_fin_action' => '2026-09-30',
            'codes_agents_rmo' => 'AG001',
            'cible_minimum_execution' => 75,
            'justificatif_attendu' => 'Tableau de bord',
            'type_action' => 'Q',
            'quantite_cible' => 120,
            'unite_cible' => 'dossiers',
            'seuil_mode' => 'trimestriel',
            'seuil_t1' => 25,
            'seuil_t2' => 50,
            'seuil_t3' => 75,
            'seuil_t4' => 100,
            'nombre_sous_actions' => 0,
            'ressources_materielles' => 'Licences logicielles',
            'financement' => 0,
            'commentaire_obligatoire' => 1,
            'champ_difficulte' => 1,
        ]);

        // Ligne 2 : action simple NON QUANTITATIVE (piece justificative, sans cible).
        $nonQuantitative = $this->templateRow([
            'ordre_action' => 2,
            'libelle_action' => 'Action non quantitative exemple',
            'date_debut_action' => '2026-03-01',
            'date_fin_action' => '2026-07-31',
            'codes_agents_rmo' => 'AG001',
            'cible_minimum_execution' => 100,
            'justificatif_attendu' => 'Note de service signee',
            'type_action' => 'NQ',
            'seuil_mode' => 'unique',
            'nombre_sous_actions' => 0,
            'niveau_risque' => 'faible',
            'main_oeuvre' => 'Equipe juridique',
            'financement' => 0,
            'commentaire_obligatoire' => 1,
            'champ_difficulte' => 1,
        ]);

        // Ligne 3 : action COMPOSEE (2 sous-actions, poids 60/40).
        $composee = $this->templateRow([
            'ordre_action' => 3,
            'libelle_action' => 'Action composee exemple',
            'date_debut_action' => '2026-01-15',
            'date_fin_action' => '2026-06-30',
            'codes_agents_rmo' => 'AG001;AG002',
            'cible_minimum_execution' => 80,
            'justificatif_attendu' => 'Rapport de realisation',
            'type_action' => 'M',
            'seuil_mode' => 'unique',
            'nombre_sous_actions' => 2,
            'sous_actions' => 'Former 20 agents|Q|60|20|agents ; Rediger le guide|NQ|40||',
            'niveau_risque' => 'modere',
            'risque' => 'Risque a surveiller',
            'mesures_preventives' => 'Plan de mitigation',
            'ressources_materielles' => 'Ordinateurs et fournitures',
            'main_oeuvre' => 'Equipe projet',
            'autres_ressources' => 'Appui technique',
            'financement' => 0,
            'commentaire_obligatoire' => 0,
            'champ_difficulte' => 1,
        ]);

        return $this->spreadsheet->downloadXlsxWorkbook(
            'modele-import-global-pas-pao-pta-actions.xlsx',
            [
                [
                    'name' => 'IMPORT_GLOBAL',
                    'headers' => $headers,
                    'rows' => [$quantitative, $nonQuantitative, $composee],
                ],
                [
                    'name' => 'GUIDE',
                    'headers' => ['bloc', 'colonne', 'principe', 'exemple'],
                    'rows' => $this->templateGuideRows(),
                ],
            ]
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

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function templateRow(array $overrides = []): array
    {
        return array_replace([
            'annee_debut_pas' => 2026,
            'annee_fin_pas' => 2028,
            'ordre_axe' => 1,
            'libelle_axe' => 'Axe institutionnel exemple',
            'ordre_objectif_strategique' => 1,
            'libelle_objectif_strategique' => 'Objectif strategique exemple',
            'date_echeance_objectif_strategique' => '2028-12-31',
            'direction' => 'DSIC',
            'service_unite' => 'SIRS',
            'ordre_objectif_operationnel' => 1,
            'libelle_objectif_operationnel' => 'Objectif operationnel exemple',
            'date_echeance_objectif_operationnel' => '2026-12-31',
            'ordre_action' => 1,
            'libelle_action' => 'Action exemple',
            'date_debut_action' => '2026-01-01',
            'date_fin_action' => '2026-12-31',
            'codes_agents_rmo' => 'AG001',
            'cible_minimum_execution' => 80,
            'justificatif_attendu' => 'Rapport',
            'type_action' => '',
            'quantite_cible' => '',
            'unite_cible' => '',
            'seuil_mode' => 'unique',
            'seuil_t1' => '',
            'seuil_t2' => '',
            'seuil_t3' => '',
            'seuil_t4' => '',
            'nombre_sous_actions' => 0,
            'sous_actions' => '',
            'niveau_risque' => '',
            'risque' => '',
            'mesures_preventives' => '',
            'ressources_materielles' => '',
            'main_oeuvre' => '',
            'autres_ressources' => '',
            'financement' => 0,
            'nature_financement' => '',
            'montant_financement' => '',
            'commentaire_obligatoire' => 1,
            'champ_difficulte' => 1,
        ], $overrides);
    }

    /**
     * @return list<array{bloc:string,colonne:string,principe:string,exemple:string}>
     */
    private function templateGuideRows(): array
    {
        return [
            ['bloc' => 'Cadre PAS', 'colonne' => 'annee_debut_pas / annee_fin_pas', 'principe' => 'Periode du PAS importee dans le fichier.', 'exemple' => '2026 / 2028'],
            ['bloc' => 'Cadre PAS', 'colonne' => 'ordre_axe, ordre_objectif_strategique', 'principe' => 'Numeros d ordre positifs utilises pour regrouper les lignes.', 'exemple' => '1'],
            ['bloc' => 'Perimetre', 'colonne' => 'direction', 'principe' => 'Code ou libelle de direction existant en referentiel.', 'exemple' => 'DSIC'],
            ['bloc' => 'Perimetre', 'colonne' => 'service_unite', 'principe' => 'Code ou libelle de service existant dans la direction.', 'exemple' => 'SIRS'],
            ['bloc' => 'Action', 'colonne' => 'codes_agents_rmo', 'principe' => 'Matricules des RMO separes par point-virgule.', 'exemple' => 'AG001;AG002'],
            ['bloc' => 'Action', 'colonne' => 'cible_minimum_execution', 'principe' => 'Pourcentage minimum attendu entre 0 et 100.', 'exemple' => '80'],
            ['bloc' => 'Type et suivi', 'colonne' => 'type_action', 'principe' => 'Vide = a parametrer plus tard, Q = quantitative, NQ = non quantitative, M = composee.', 'exemple' => 'Q'],
            ['bloc' => 'Type et suivi', 'colonne' => 'quantite_cible / unite_cible', 'principe' => 'Obligatoire uniquement pour une action Q.', 'exemple' => '120 / dossiers'],
            ['bloc' => 'Type et suivi', 'colonne' => 'seuil_mode', 'principe' => 'unique ou trimestriel. Si trimestriel, renseigner seuil_t1 a seuil_t4.', 'exemple' => 'trimestriel'],
            ['bloc' => 'Type et suivi', 'colonne' => 'nombre_sous_actions / sous_actions', 'principe' => 'A utiliser pour une action M. Les poids des sous-actions doivent totaliser 100.', 'exemple' => 'Former 20 agents|Q|60|20|agents ; Rediger guide|NQ|40||'],
            ['bloc' => 'Risques', 'colonne' => 'niveau_risque', 'principe' => 'Valeurs acceptees : faible, modere, eleve, critique.', 'exemple' => 'modere'],
            ['bloc' => 'Financement', 'colonne' => 'financement', 'principe' => '0 = pas de financement, 1 = financement requis.', 'exemple' => '1'],
            ['bloc' => 'Financement', 'colonne' => 'nature_financement / montant_financement', 'principe' => 'Obligatoires lorsque financement = 1.', 'exemple' => 'Budget / 2500000'],
            ['bloc' => 'Options', 'colonne' => 'commentaire_obligatoire / champ_difficulte', 'principe' => '0 = non, 1 = oui.', 'exemple' => '1 / 1'],
        ];
    }
}
