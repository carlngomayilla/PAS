<?php

namespace App\Services\Imports;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\JournalAudit;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlanningImport;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\PlanningModificationLockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class PlanningExcelImportService
{
    public const REQUIRED_COLUMNS = [
        'annee_debut_pas',
        'annee_fin_pas',
        'ordre_axe',
        'libelle_axe',
        'ordre_objectif_strategique',
        'libelle_objectif_strategique',
        'date_echeance_objectif_strategique',
        'direction',
        'service_unite',
        'ordre_objectif_operationnel',
        'libelle_objectif_operationnel',
        'date_echeance_objectif_operationnel',
        'ordre_action',
        'libelle_action',
        'date_debut_action',
        'date_fin_action',
        'codes_agents_rmo',
        'cible_minimum_execution',
        'justificatif_attendu',
        'nombre_sous_actions',
        'financement',
        'nature_financement',
        'montant_financement',
        'risque',
        'ressources_materielles',
        'main_oeuvre',
        'autres_ressources',
    ];

    /**
     * Colonnes OPTIONNELLES de parametrage (workflow V2). Quand elles sont
     * presentes et que `type_action` est renseigne sur une ligne, l'action est
     * pre-remplie mais reste 'a_parametrer' tant que le chef de service ne
     * l'enregistre pas officiellement dans le PTA.
     *
     * Format de `sous_actions` (cellule unique) : sous-actions separees par ';',
     * champs par '|' dans l'ordre  libelle|type|poids|cible|unite.
     * Codes import : Q = quantitative, NQ = non quantitative, M = composee.
     *   Ex: "Former 20 agents|Q|50|20|agents ; Rediger guide|NQ|50||"
     */
    public const PARAMETRAGE_COLUMNS = [
        'type_action',
        'quantite_cible',
        'unite_cible',
        'seuil_mode',
        'seuil_t1',
        'seuil_t2',
        'seuil_t3',
        'seuil_t4',
        'niveau_risque',
        'mesures_preventives',
        'commentaire_obligatoire',
        'champ_difficulte',
        'sous_actions',
    ];

    public const IMPORT_COLUMNS = [
        'annee_debut_pas',
        'annee_fin_pas',
        'ordre_axe',
        'libelle_axe',
        'ordre_objectif_strategique',
        'libelle_objectif_strategique',
        'date_echeance_objectif_strategique',
        'direction',
        'service_unite',
        'ordre_objectif_operationnel',
        'libelle_objectif_operationnel',
        'date_echeance_objectif_operationnel',
        'ordre_action',
        'libelle_action',
        'date_debut_action',
        'date_fin_action',
        'codes_agents_rmo',
        'cible_minimum_execution',
        'justificatif_attendu',
        'type_action',
        'quantite_cible',
        'unite_cible',
        'seuil_mode',
        'seuil_t1',
        'seuil_t2',
        'seuil_t3',
        'seuil_t4',
        'nombre_sous_actions',
        'sous_actions',
        'niveau_risque',
        'risque',
        'mesures_preventives',
        'ressources_materielles',
        'main_oeuvre',
        'autres_ressources',
        'financement',
        'nature_financement',
        'montant_financement',
        'commentaire_obligatoire',
        'champ_difficulte',
    ];

    public const FORBIDDEN_COLUMNS = [
        'description_action',
        'rmo_prevu',
        'code_pas',
        'code_axe',
        'code_objectif_strategique',
        'code_pao',
        'code_objectif_operationnel',
        'code_pta',
        'code_action',
        'statut',
        'mode_execution',
        'avancement',
        'quantite_realisee',
        'validation',
        'commentaire_execution',
        'difficulte_execution',
        'piece_justificative_execution',
        'cloture',
    ];

    public function __construct(
        private readonly SimpleSpreadsheet $spreadsheet,
        private readonly PlanningImportCodeGenerator $codes
    ) {
    }

    public function canImport(User $user): bool
    {
        return $user->hasRole(
            User::ROLE_SUPER_ADMIN,
            User::ROLE_SCIQ,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ
        );
    }

    public function createPreview(UploadedFile $file, User $user, string $ipAddress): PlanningImport
    {
        if (! $this->canImport($user)) {
            abort(403, "Vous n'avez pas acces aux imports Excel.");
        }

        $sheet = $this->spreadsheet->read($file);
        $strictErrors = $this->strictWorkbookErrors($sheet);

        if ($strictErrors === [] && $this->hasExactRequiredHeaders($sheet['headers'])) {
            $preview = $this->validateSheet($sheet);

            return PlanningImport::query()->create([
                'user_id' => $user->id,
                'role' => $user->effectiveRoleCode(),
                'filename' => $file->getClientOriginalName(),
                'mode' => PlanningImport::MODE_CREATE_ONLY,
                'total_rows' => count($preview['rows']),
                'valid_rows' => collect($preview['rows'])->where('status', 'Valide')->count(),
                'error_rows' => collect($preview['rows'])->where('status', 'Erreur')->count(),
                'status' => $preview['has_errors'] ? 'preview_errors' : 'preview_ready',
                'preview_payload' => $preview,
                'error_report' => collect($preview['rows'])->where('status', 'Erreur')->values()->all(),
                'ip_address' => $ipAddress,
            ]);
        }

        $payload = [
            'sheet_name' => $sheet['sheet_name'],
            'headers' => $sheet['headers'],
            'required_columns' => self::REQUIRED_COLUMNS,
            'forbidden_columns' => self::FORBIDDEN_COLUMNS,
            'suggested_mapping' => $this->suggestedMapping($sheet['headers']),
            'raw_sheet' => $sheet,
            'global_errors' => $strictErrors,
            'sample_rows' => array_slice($sheet['rows'], 0, 5),
        ];

        if ($strictErrors !== []) {
            $preview = $this->errorPreview($sheet, $strictErrors);

            return PlanningImport::query()->create([
                'user_id' => $user->id,
                'role' => $user->effectiveRoleCode(),
                'filename' => $file->getClientOriginalName(),
                'mode' => PlanningImport::MODE_CREATE_ONLY,
                'total_rows' => count($sheet['rows']),
                'valid_rows' => 0,
                'error_rows' => max(1, count($sheet['rows'])),
                'status' => 'preview_errors',
                'preview_payload' => $preview + ['mapping_payload' => $payload],
                'error_report' => $preview['rows'],
                'ip_address' => $ipAddress,
            ]);
        }

        return PlanningImport::query()->create([
            'user_id' => $user->id,
            'role' => $user->effectiveRoleCode(),
            'filename' => $file->getClientOriginalName(),
            'mode' => PlanningImport::MODE_CREATE_ONLY,
            'total_rows' => count($sheet['rows']),
            'valid_rows' => 0,
            'error_rows' => 0,
            'status' => 'mapping_required',
            'preview_payload' => $payload,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @param array<string,string|null> $mapping target column => source header
     */
    public function applyColumnMapping(PlanningImport $import, array $mapping, User $user): PlanningImport
    {
        if (! $this->canImport($user)) {
            abort(403, "Vous n'avez pas acces aux imports Excel.");
        }

        $payload = $import->preview_payload ?? [];
        $rawSheet = $payload['raw_sheet'] ?? null;
        if (! is_array($rawSheet)) {
            throw new RuntimeException('Les donnees brutes du fichier sont introuvables. Rechargez le fichier.');
        }

        $headers = array_map(fn ($header): string => (string) $header, $rawSheet['headers'] ?? []);
        $errors = [];
        $normalizedMapping = [];
        foreach (self::REQUIRED_COLUMNS as $targetColumn) {
            $source = trim((string) ($mapping[$targetColumn] ?? ''));
            if ($source === '') {
                $errors[] = 'La colonne '.$targetColumn.' doit etre associee a une colonne du fichier.';
                continue;
            }
            if (! in_array($source, $headers, true)) {
                $errors[] = 'La colonne source '.$source.' est introuvable dans le fichier.';
                continue;
            }
            $normalizedMapping[$targetColumn] = $source;
        }

        $usedSources = collect($normalizedMapping)->values();
        foreach ($usedSources->duplicates()->unique()->values() as $duplicateSource) {
            $errors[] = 'La colonne source '.$duplicateSource.' est associee plusieurs fois.';
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $mappedRows = [];
        foreach (($rawSheet['rows'] ?? []) as $rawRow) {
            if (! is_array($rawRow)) {
                continue;
            }

            $row = [];
            foreach ($normalizedMapping as $targetColumn => $sourceColumn) {
                $row[$targetColumn] = $rawRow[$sourceColumn] ?? null;
            }
            $row['_row_number'] = $rawRow['_row_number'] ?? null;
            $mappedRows[] = $row;
        }

        $preview = $this->validateSheet([
            'sheet_count' => (int) ($rawSheet['sheet_count'] ?? 1),
            'sheet_name' => (string) ($rawSheet['sheet_name'] ?? 'IMPORT_GLOBAL'),
            'headers' => self::REQUIRED_COLUMNS,
            'rows' => $mappedRows,
        ]);
        $preview['column_mapping'] = $normalizedMapping;

        $import->forceFill([
            'total_rows' => count($preview['rows']),
            'valid_rows' => collect($preview['rows'])->where('status', 'Valide')->count(),
            'error_rows' => collect($preview['rows'])->where('status', 'Erreur')->count(),
            'status' => $preview['has_errors'] ? 'preview_errors' : 'preview_ready',
            'preview_payload' => $preview,
            'error_report' => collect($preview['rows'])->where('status', 'Erreur')->values()->all(),
        ])->save();

        return $import->fresh();
    }

    /**
     * @param array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>} $sheet
     * @return array<string,mixed>
     */
    public function validateSheet(array $sheet): array
    {
        $globalErrors = [];
        if ((int) $sheet['sheet_count'] !== 1 && ! $this->hasOptionalGuideSheet($sheet)) {
            $globalErrors[] = 'Le fichier doit contenir une seule feuille.';
        }

        if ((string) ($sheet['sheet_name'] ?? '') !== 'IMPORT_GLOBAL') {
            $globalErrors[] = 'La feuille Excel doit etre nommee IMPORT_GLOBAL.';
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $sheet['headers']));
        if ($missing !== []) {
            $globalErrors[] = 'Colonnes manquantes: '.implode(', ', $missing).'.';
        }

        $forbidden = collect($sheet['headers'])
            ->filter(function (string $header): bool {
                $normalized = $this->normalizeColumnName($header);

                return in_array($normalized, self::FORBIDDEN_COLUMNS, true)
                    || (str_starts_with($normalized, 'code_') && $normalized !== 'codes_agents_rmo');
            })
            ->values()
            ->all();
        if ($forbidden !== []) {
            $globalErrors[] = 'Colonnes interdites detectees: '.implode(', ', $forbidden).'.';
        }

        $periods = collect($sheet['rows'])
            ->map(fn (array $row): string => trim((string) ($row['annee_debut_pas'] ?? '')).'-'.trim((string) ($row['annee_fin_pas'] ?? '')))
            ->filter(fn (string $period): bool => $period !== '-')
            ->unique()
            ->values();
        if ($periods->count() > 1) {
            $globalErrors[] = 'Le fichier contient plusieurs periodes PAS. Veuillez importer un seul PAS par fichier.';
        }

        $directionCache = [];
        $serviceCache = [];
        $rows = [];
        $seenLabels = [
            'axes' => [],
            'strategic' => [],
            'operational' => [],
            'actions' => [],
        ];

        foreach ($sheet['rows'] as $row) {
            $errors = [];
            $warnings = [];
            $normalized = $this->normalizeRow($row);
            $line = (int) ($row['_row_number'] ?? 0);

            foreach (['annee_debut_pas', 'annee_fin_pas', 'ordre_axe', 'ordre_objectif_strategique', 'ordre_objectif_operationnel', 'ordre_action'] as $column) {
                if (! $this->positiveInt($normalized[$column] ?? null)) {
                    $errors[] = "{$column} doit etre un entier positif.";
                }
            }

            foreach (['libelle_axe', 'libelle_objectif_strategique', 'libelle_objectif_operationnel', 'libelle_action', 'cible_minimum_execution'] as $column) {
                if (trim((string) ($normalized[$column] ?? '')) === '') {
                    $errors[] = "{$column} est obligatoire.";
                }
            }

            $startYear = (int) ($normalized['annee_debut_pas'] ?? 0);
            $endYear = (int) ($normalized['annee_fin_pas'] ?? 0);
            if ($startYear > 0 && $endYear > 0 && $startYear > $endYear) {
                $errors[] = 'annee_debut_pas doit etre inferieure ou egale a annee_fin_pas.';
            }

            $dates = [];
            foreach ([
                'date_echeance_objectif_strategique',
                'date_echeance_objectif_operationnel',
                'date_debut_action',
                'date_fin_action',
            ] as $column) {
                $dates[$column] = $this->parseDate($normalized[$column] ?? null);
                if ($dates[$column] === null) {
                    $errors[] = "{$column} doit etre une date valide.";
                }
            }
            if ($dates['date_debut_action'] && $dates['date_fin_action'] && $dates['date_debut_action']->gt($dates['date_fin_action'])) {
                $errors[] = 'date_debut_action doit etre inferieure ou egale a date_fin_action.';
            }
            if ($dates['date_fin_action'] && $dates['date_echeance_objectif_operationnel'] && $dates['date_fin_action']->gt($dates['date_echeance_objectif_operationnel'])) {
                $errors[] = 'date_fin_action doit etre inferieure ou egale a date_echeance_objectif_operationnel.';
            }
            if ($dates['date_echeance_objectif_operationnel'] && $dates['date_echeance_objectif_strategique'] && $dates['date_echeance_objectif_operationnel']->gt($dates['date_echeance_objectif_strategique'])) {
                $errors[] = 'date_echeance_objectif_operationnel doit etre inferieure ou egale a date_echeance_objectif_strategique.';
            }

            $directionKey = $this->lookupKey((string) ($normalized['direction'] ?? ''));
            $direction = $directionCache[$directionKey] ??= $this->findDirection((string) ($normalized['direction'] ?? ''));
            if (! $direction instanceof Direction) {
                $errors[] = 'direction inexistante: '.(string) ($normalized['direction'] ?? '');
            }

            $serviceKey = $directionKey.'|'.$this->lookupKey((string) ($normalized['service_unite'] ?? ''));
            $service = $serviceCache[$serviceKey] ??= $this->findService((string) ($normalized['service_unite'] ?? ''));
            if (! $service instanceof Service) {
                $errors[] = 'service_unite inexistant: '.(string) ($normalized['service_unite'] ?? '');
            } elseif ($direction instanceof Direction && (int) $service->direction_id !== (int) $direction->id) {
                $errors[] = 'service_unite '.$service->code.' n appartient pas a la direction '.$direction->code.'.';
            }

            $financing = trim((string) ($normalized['financement'] ?? ''));
            if (! in_array($financing, ['0', '1'], true)) {
                $errors[] = 'financement doit valoir 0 ou 1.';
            }
            if ($financing === '1') {
                if (trim((string) ($normalized['nature_financement'] ?? '')) === '') {
                    $errors[] = 'nature_financement est obligatoire lorsque financement = 1.';
                }
                if (! is_numeric($normalized['montant_financement'] ?? null) || (float) $normalized['montant_financement'] <= 0) {
                    $errors[] = 'montant_financement doit etre numerique et positif lorsque financement = 1.';
                }
            }

            if (! is_numeric($normalized['nombre_sous_actions'] ?? 0) || (int) $normalized['nombre_sous_actions'] < 0) {
                $errors[] = 'nombre_sous_actions doit etre superieur ou egal a 0.';
            }
            if (! is_numeric($normalized['cible_minimum_execution'] ?? null) || (float) $normalized['cible_minimum_execution'] < 0 || (float) $normalized['cible_minimum_execution'] > 100) {
                $errors[] = 'cible_minimum_execution doit etre comprise entre 0 et 100.';
            }

            $agentCodes = $this->splitAgentCodes((string) ($normalized['codes_agents_rmo'] ?? ''), $warnings);
            foreach ($agentCodes as $agentCode) {
                $agent = $this->findAgentByCode($agentCode);
                if (! $agent instanceof User) {
                    $errors[] = 'Code agent '.$agentCode.' introuvable.';
                    continue;
                }

                if (! (bool) ($agent->is_active ?? true)) {
                    $errors[] = 'Code agent '.$agentCode.' correspond a un utilisateur desactive.';
                }

                if ($service instanceof Service && (int) ($agent->service_id ?? 0) !== (int) $service->id) {
                    $warnings[] = 'Code agent '.$agentCode.' rattache a un autre service.';
                }
            }

            $this->validateParametrage($normalized, $errors, $warnings);

            $this->detectOrderConflict($seenLabels['axes'], $startYear.'-'.$endYear.'|'.$normalized['ordre_axe'], $normalized['libelle_axe'], $errors, 'ordre_axe');
            $this->detectOrderConflict($seenLabels['strategic'], $startYear.'-'.$endYear.'|'.$normalized['ordre_axe'].'|'.$normalized['ordre_objectif_strategique'], $normalized['libelle_objectif_strategique'], $errors, 'ordre_objectif_strategique');
            $this->detectOrderConflict($seenLabels['operational'], $directionKey.'|'.$serviceKey.'|'.$startYear.'|'.$normalized['ordre_objectif_operationnel'], $normalized['libelle_objectif_operationnel'], $errors, 'ordre_objectif_operationnel');
            $this->detectOrderConflict($seenLabels['actions'], $serviceKey.'|'.$startYear.'|'.$normalized['ordre_objectif_operationnel'].'|'.$normalized['ordre_action'], $normalized['libelle_action'], $errors, 'ordre_action');

            if ($errors === [] && $service instanceof Service) {
                $existing = Action::query()
                    ->whereHas('pta', fn ($query) => $query->where('service_id', $service->id))
                    ->whereHas('objectifOperationnel', fn ($query) => $query
                        ->where('service_id', $service->id)
                        ->where('import_ordre', (int) $normalized['ordre_objectif_operationnel']))
                    ->where('ordre_import', (int) $normalized['ordre_action'])
                    ->whereYear('date_debut', $startYear)
                    ->exists();
                if ($existing) {
                    $warnings[] = 'Action deja existante: elle dependra du mode d import choisi.';
                }
            }

            $rows[] = [
                'line' => $line,
                'status' => $errors !== [] ? 'Erreur' : ($warnings !== [] ? 'Avertissement' : 'Valide'),
                'errors' => $errors,
                'warnings' => $warnings,
                'message' => implode(' ', array_merge($errors, $warnings)),
                'data' => $normalized,
            ];
        }

        if ($globalErrors !== []) {
            foreach ($rows as &$row) {
                $row['status'] = 'Erreur';
                $row['errors'] = array_values(array_merge($row['errors'], $globalErrors));
                $row['message'] = implode(' ', $row['errors']);
            }
            unset($row);
        }

        return [
            'sheet_name' => $sheet['sheet_name'],
            'headers' => self::REQUIRED_COLUMNS,
            'global_errors' => $globalErrors,
            'rows' => $rows,
            'has_errors' => collect($rows)->contains(fn (array $row): bool => $row['status'] === 'Erreur'),
        ];
    }

    /**
     * @param array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>} $sheet
     * @return list<string>
     */
    private function strictWorkbookErrors(array $sheet): array
    {
        $errors = [];
        if ((int) $sheet['sheet_count'] !== 1 && ! $this->hasOptionalGuideSheet($sheet)) {
            $errors[] = 'Le fichier doit contenir une seule feuille.';
        }

        if ((string) ($sheet['sheet_name'] ?? '') !== 'IMPORT_GLOBAL') {
            $errors[] = 'La feuille Excel doit etre nommee IMPORT_GLOBAL.';
        }

        $forbidden = collect($sheet['headers'])
            ->filter(function (string $header): bool {
                $normalized = $this->normalizeColumnName($header);

                return in_array($normalized, self::FORBIDDEN_COLUMNS, true)
                    || (str_starts_with($normalized, 'code_') && $normalized !== 'codes_agents_rmo');
            })
            ->values()
            ->all();

        if ($forbidden !== []) {
            $errors[] = 'Colonnes interdites detectees: '.implode(', ', $forbidden).'. Utilisez uniquement les numeros d ordre et codes_agents_rmo.';
        }

        return $errors;
    }

    /**
     * @param list<string> $headers
     */
    private function hasExactRequiredHeaders(array $headers): bool
    {
        $headers = array_values($headers);

        return $headers === self::REQUIRED_COLUMNS
            || $headers === array_merge(self::REQUIRED_COLUMNS, self::PARAMETRAGE_COLUMNS)
            || $headers === self::IMPORT_COLUMNS;
    }

    /**
     * @param array{sheet_count:int,sheet_name:string,sheet_names?:list<string>} $sheet
     */
    private function hasOptionalGuideSheet(array $sheet): bool
    {
        $sheetNames = array_values(array_map('strval', $sheet['sheet_names'] ?? [$sheet['sheet_name'] ?? '']));

        return (int) $sheet['sheet_count'] === 2
            && ($sheetNames[0] ?? '') === 'IMPORT_GLOBAL'
            && in_array('GUIDE', $sheetNames, true);
    }

    /**
     * Valide les colonnes de parametrage d'une ligne UNIQUEMENT si `type_action`
     * est renseigne. Une cellule `type_action` vide signifie "je parametrerai
     * dans l'appli" : la ligne reste valide et l'action sera 'a_parametrer'.
     *
     * @param array<string,mixed> $normalized
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateParametrage(array $normalized, array &$errors, array &$warnings): void
    {
        $type = $this->normalizeImportActionType($normalized['type_action'] ?? '');
        if ($type === '') {
            return;
        }

        $validTypes = [Action::TYPE_QUANTITATIVE, Action::TYPE_NON_QUANTITATIVE, Action::TYPE_COMPOSEE];
        if (! in_array($type, $validTypes, true)) {
            $errors[] = 'type_action doit valoir Q, NQ ou M (Q=quantitative, NQ=non quantitative, M=composee).';

            return;
        }

        if ($type === Action::TYPE_QUANTITATIVE) {
            if (! is_numeric($normalized['quantite_cible'] ?? null) || (float) $normalized['quantite_cible'] <= 0) {
                $errors[] = 'quantite_cible doit etre numerique et positive lorsque type_action = Q.';
            }
            if (trim((string) ($normalized['unite_cible'] ?? '')) === '') {
                $errors[] = 'unite_cible est obligatoire lorsque type_action = Q.';
            }
        }

        if ($type === Action::TYPE_COMPOSEE) {
            $subErrors = [];
            $subWarnings = [];
            $subItems = $this->parseSousActions((string) ($normalized['sous_actions'] ?? ''), $subWarnings, $subErrors);
            if ($subItems === []) {
                $errors[] = 'sous_actions doit contenir au moins une sous-action lorsque type_action = M.';
            }
            $errors = array_merge($errors, $subErrors);
            $warnings = array_merge($warnings, $subWarnings);

            $declared = (int) ($normalized['nombre_sous_actions'] ?? 0);
            if ($subItems !== [] && $declared > 0 && $declared !== count($subItems)) {
                $warnings[] = 'nombre_sous_actions ('.$declared.') differe du nombre de sous-actions fournies ('.count($subItems).').';
            }
        }

        $thresholdMode = strtolower(trim((string) ($normalized['seuil_mode'] ?? '')));
        if ($thresholdMode !== '' && ! in_array($thresholdMode, ['unique', 'trimestriel'], true)) {
            $errors[] = 'seuil_mode doit valoir unique ou trimestriel.';
        }
        if ($thresholdMode === 'trimestriel') {
            foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $column) {
                $value = $normalized[$column] ?? null;
                if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                    $errors[] = $column.' doit etre compris entre 0 et 100 lorsque seuil_mode = trimestriel.';
                }
            }
        }

        $riskLevel = strtolower(trim((string) ($normalized['niveau_risque'] ?? '')));
        if ($riskLevel !== '' && ! in_array($riskLevel, ['faible', 'modere', 'eleve', 'critique'], true)) {
            $errors[] = 'niveau_risque doit valoir faible, modere, eleve ou critique.';
        }
    }

    /**
     * Parse la cellule `sous_actions`. Format : sous-actions separees par ';',
     * champs par '|' dans l'ordre  libelle|type|poids|cible|unite.
     * Le type de sous-action attend les codes Q ou NQ (anciens libelles acceptes).
     *
     * @param list<string> $warnings
     * @param list<string> $errors
     * @return list<array{libelle:string,type:string,weight:?float,cible:?float,unite:string}>
     */
    private function parseSousActions(string $value, array &$warnings = [], array &$errors = []): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $items = [];
        $weightSum = 0.0;
        $hasWeight = false;
        foreach (explode(';', $value) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $chunk));
            $libelle = $parts[0] ?? '';
            if ($libelle === '') {
                $errors[] = 'Chaque sous-action doit avoir un libelle (format attendu: libelle|type|poids|cible|unite).';
                continue;
            }

            $typeRaw = $parts[1] ?? '';
            $cibleRaw = $parts[3] ?? '';
            $type = $this->normalizeImportSubActionType($typeRaw);
            if (! in_array($type, [SousAction::TYPE_QUANTITATIVE, SousAction::TYPE_NON_QUANTITATIVE], true)) {
                if (trim((string) $typeRaw) !== '') {
                    $errors[] = 'Le type de la sous-action "'.$libelle.'" doit valoir Q ou NQ.';
                }
                $type = ($cibleRaw !== '' && is_numeric($cibleRaw))
                    ? SousAction::TYPE_QUANTITATIVE
                    : SousAction::TYPE_NON_QUANTITATIVE;
            }

            $weight = null;
            $weightRaw = $parts[2] ?? '';
            if ($weightRaw !== '') {
                if (! is_numeric($weightRaw) || (float) $weightRaw < 0 || (float) $weightRaw > 100) {
                    $errors[] = 'Le poids de la sous-action "'.$libelle.'" doit etre compris entre 0 et 100.';
                } else {
                    $weight = (float) $weightRaw;
                    $weightSum += $weight;
                    $hasWeight = true;
                }
            }

            $cible = ($cibleRaw !== '' && is_numeric($cibleRaw)) ? (float) $cibleRaw : null;
            if ($type === SousAction::TYPE_QUANTITATIVE && $cible === null) {
                $warnings[] = 'La sous-action "'.$libelle.'" est quantitative sans cible numerique.';
            }

            $items[] = [
                'libelle' => $libelle,
                'type' => $type,
                'weight' => $weight,
                'cible' => $cible,
                'unite' => $parts[4] ?? '',
            ];
        }

        if ($hasWeight && abs($weightSum - 100.0) > 0.01) {
            $errors[] = 'La somme des poids des sous-actions doit etre egale a 100 (actuel: '.number_format($weightSum, 0, '.', '').').';
        }

        return $items;
    }

    /**
     * @param list<string> $headers
     * @return array<string,string>
     */
    private function suggestedMapping(array $headers): array
    {
        $byNormalized = collect($headers)
            ->mapWithKeys(fn (string $header): array => [$this->normalizeColumnName($header) => $header])
            ->all();

        $mapping = [];
        foreach (self::REQUIRED_COLUMNS as $targetColumn) {
            $mapping[$targetColumn] = (string) ($byNormalized[$targetColumn] ?? '');
        }

        return $mapping;
    }

    /**
     * @param array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>} $sheet
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function errorPreview(array $sheet, array $errors): array
    {
        $rows = collect($sheet['rows'])
            ->map(fn (array $row): array => [
                'line' => (int) ($row['_row_number'] ?? 0),
                'status' => 'Erreur',
                'errors' => $errors,
                'warnings' => [],
                'message' => implode(' ', $errors),
                'data' => $row,
            ])
            ->values()
            ->all();

        if ($rows === []) {
            $rows[] = [
                'line' => 1,
                'status' => 'Erreur',
                'errors' => $errors,
                'warnings' => [],
                'message' => implode(' ', $errors),
                'data' => [],
            ];
        }

        return [
            'sheet_name' => $sheet['sheet_name'],
            'headers' => $sheet['headers'],
            'global_errors' => $errors,
            'rows' => $rows,
            'has_errors' => true,
        ];
    }

    private function normalizeColumnName(string $header): string
    {
        return Str::of($header)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    public function execute(PlanningImport $import, string $mode, User $user, ?string $ipAddress = null): PlanningImport
    {
        if (! $this->canImport($user)) {
            abort(403, "Vous n'avez pas acces aux imports Excel.");
        }
        if (! in_array($mode, [PlanningImport::MODE_CREATE_ONLY, PlanningImport::MODE_SKIP_DUPLICATES, PlanningImport::MODE_UPDATE_EXISTING], true)) {
            throw new RuntimeException('Mode d import invalide.');
        }
        if (! in_array((string) $import->status, ['preview_ready', 'preview_errors'], true)) {
            throw new RuntimeException('Associez les colonnes et lancez la previsualisation avant de confirmer l import.');
        }

        $preview = $import->preview_payload ?? [];
        $rows = collect($preview['rows'] ?? []);
        if ($rows->contains(fn (array $row): bool => ($row['status'] ?? '') === 'Erreur')) {
            throw new RuntimeException('Corrigez les erreurs avant de confirmer l import.');
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $touchedPtaIds = [];
        DB::transaction(function () use ($rows, $mode, $user, $ipAddress, &$stats, &$touchedPtaIds): void {
            foreach ($rows as $row) {
                $this->persistRow($row['data'], $mode, $user, $ipAddress, $stats, $touchedPtaIds);
            }
        });

        // Hors transaction : seuls les PTA dont toutes les actions ont ete
        // officiellement enregistrees peuvent entrer dans le suivi.
        $this->promoteFullyParametrePtas($touchedPtaIds);

        $import->forceFill([
            'mode' => $mode,
            'created_count' => $stats['created'],
            'updated_count' => $stats['updated'],
            'skipped_count' => $stats['skipped'],
            'status' => 'imported',
        ])->save();

        return $import->fresh();
    }

    /**
     * @param array<string,mixed> $stats
     * @param list<int> $touchedPtaIds
     */
    private function persistRow(array $row, string $mode, User $user, ?string $ipAddress, array &$stats, array &$touchedPtaIds = []): void
    {
        $year = (int) $row['annee_debut_pas'];
        $endYear = (int) $row['annee_fin_pas'];
        $direction = $this->findDirection((string) $row['direction']);
        $service = $this->findService((string) $row['service_unite']);
        if (! $direction || ! $service) {
            throw new RuntimeException('Referentiel direction/service invalide pendant l insertion.');
        }

        $exercise = Exercice::query()->firstOrCreate(
            ['annee' => $year],
            [
                'libelle' => 'Exercice '.$year,
                'date_debut' => $year.'-01-01',
                'date_fin' => $year.'-12-31',
                'statut' => Exercice::STATUT_OUVERT,
                'is_active' => false,
            ]
        );

        $pasCode = $this->codes->pas($year, $endYear);
        $pas = Pas::query()->firstOrCreate(
            ['periode_debut' => $year, 'periode_fin' => $endYear],
            ['exercice_id' => $exercise->id, 'titre' => $pasCode, 'created_by' => $user->id, 'statut' => Pas::STATUS_ACTIF]
        );
        $lockService = app(PlanningModificationLockService::class);
        if ($pas->wasRecentlyCreated) {
            $lockService->lockAfterSave($pas, $user);
        }

        $axisOrder = (int) $row['ordre_axe'];
        $axisCode = $this->codes->axe($pasCode, $axisOrder);
        $axis = PasAxe::query()->firstOrCreate(
            ['pas_id' => $pas->id, 'import_ordre' => $axisOrder],
            ['code' => $axisCode, 'libelle' => $row['libelle_axe'], 'ordre' => $axisOrder, 'created_by' => $user->id]
        );

        $strategicOrder = (int) $row['ordre_objectif_strategique'];
        $strategicCode = $this->codes->strategicObjective($axisCode, $strategicOrder);
        $strategic = PasObjectif::query()->firstOrCreate(
            ['pas_axe_id' => $axis->id, 'import_ordre' => $strategicOrder],
            [
                'code' => $strategicCode,
                'libelle' => $row['libelle_objectif_strategique'],
                'date_echeance' => $this->parseDate($row['date_echeance_objectif_strategique'])?->toDateString(),
                'ordre' => $strategicOrder,
                'created_by' => $user->id,
            ]
        );

        $pao = Pao::query()->firstOrCreate(
            ['direction_id' => $direction->id, 'annee' => $year, 'pas_id' => $pas->id],
            [
                'code' => $this->codes->pao($direction, $year),
                'exercice_id' => $exercise->id,
                'pas_objectif_id' => $strategic->id,
                'titre' => 'PAO - '.($direction->code ?: $direction->libelle).' - '.$year,
                'echeance' => $year.'-12-31',
                'statut' => Pao::STATUS_EN_COURS,
            ]
        );

        $operationalOrder = (int) $row['ordre_objectif_operationnel'];
        $operational = ObjectifOperationnel::query()->firstOrCreate(
            [
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'import_ordre' => $operationalOrder,
                'pao_id' => $pao->id,
            ],
            [
                'code' => $this->codes->operationalObjective($direction, $year, $service, $operationalOrder),
                'pas_id' => $pas->id,
                'pas_axe_id' => $axis->id,
                'pas_objectif_id' => $strategic->id,
                'libelle' => $row['libelle_objectif_operationnel'],
                'echeance' => $this->parseDate($row['date_echeance_objectif_operationnel'])?->toDateString(),
                'statut' => 'en_cours',
            ]
        );

        $pta = Pta::query()->firstOrCreate(
            ['pao_id' => $pao->id, 'service_id' => $service->id],
            [
                'code' => $this->codes->pta($service, $year),
                'exercice_id' => $exercise->id,
                'objectif_operationnel_id' => $operational->id,
                'direction_id' => $direction->id,
                'titre' => 'PTA - '.($service->code ?: $service->libelle),
                // STATUS_BROUILLON : le PTA passe en EN_COURS seulement quand
                // toutes ses actions ont ete enregistrees dans le formulaire PTA.
                'statut' => Pta::STATUS_BROUILLON,
            ]
        );
        // PTA volontairement NON verrouille apres import.
        $touchedPtaIds[] = (int) $pta->id;

        $actionOrder = (int) $row['ordre_action'];
        $action = Action::query()
            ->where('pta_id', $pta->id)
            ->where('objectif_operationnel_id', $operational->id)
            ->where('ordre_import', $actionOrder)
            ->first();

        if ($action instanceof Action && $mode === PlanningImport::MODE_CREATE_ONLY) {
            throw new RuntimeException('Doublon detecte en mode Creer uniquement: '.$row['libelle_action']);
        }
        if ($action instanceof Action && $mode === PlanningImport::MODE_SKIP_DUPLICATES) {
            $stats['skipped']++;
            return;
        }
        if ($action instanceof Action) {
            if ($message = $lockService->ensureUnlocked($action, $user)) {
                throw new RuntimeException($message);
            }
        }

        $warnings = [];
        $agentCodes = $this->splitAgentCodes((string) ($row['codes_agents_rmo'] ?? ''), $warnings);
        $rmos = collect($agentCodes)
            ->map(fn (string $agentCode): ?User => $this->findAgentByCode($agentCode))
            ->filter(fn (?User $agent): bool => $agent instanceof User)
            ->values();
        $primaryRmo = $rmos->first();
        $financingRequired = (string) $row['financement'] === '1';
        $hasParametrage = $this->normalizeImportActionType($row['type_action'] ?? '') !== '';
        $payload = [
            'code' => $this->codes->action($service, $year, $operationalOrder, $actionOrder),
            'exercice_id' => $exercise->id,
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operational->id,
            'ordre_import' => $actionOrder,
            'nombre_sous_actions_prevu' => (int) $row['nombre_sous_actions'],
            'statut_parametrage' => $action instanceof Action
                ? (string) ($action->statut_parametrage ?: 'a_parametrer')
                : 'a_parametrer',
            'libelle' => $row['libelle_action'],
            'date_debut' => $this->parseDate($row['date_debut_action'])?->toDateString(),
            'date_fin' => $this->parseDate($row['date_fin_action'])?->toDateString(),
            'date_echeance' => $this->parseDate($row['date_fin_action'])?->toDateString(),
            'responsable_id' => $primaryRmo?->id,
            'seuil_minimum' => (float) $row['cible_minimum_execution'],
            'justificatif_obligatoire' => trim((string) ($row['justificatif_attendu'] ?? '')) !== '',
            'livrable_attendu' => $row['justificatif_attendu'] ?: null,
            'mode_evaluation' => null,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'origine_action' => Action::ORIGIN_PTA,
            'financement_requis' => $financingRequired,
            'nature_financement' => $financingRequired ? $row['nature_financement'] : null,
            'montant_estime' => $financingRequired ? (float) $row['montant_financement'] : null,
            'risque_lie' => trim((string) ($row['risque'] ?? '')) !== '' ? 'oui' : null,
            'risque_potentiel' => $row['risque'] ?: null,
            'ressources_materielles' => $row['ressources_materielles'] ?: null,
            'ressource_main_oeuvre' => trim((string) ($row['main_oeuvre'] ?? '')) !== '',
            'ressource_equipement' => trim((string) ($row['ressources_materielles'] ?? '')) !== '',
            'ressource_autres' => trim((string) ($row['autres_ressources'] ?? '')) !== '',
            'ressource_autres_details' => $row['autres_ressources'] ?: null,
            'ressources_details' => collect([$row['ressources_materielles'] ?? null, $row['main_oeuvre'] ?? null, $row['autres_ressources'] ?? null])->filter()->implode("\n"),
        ];

        // Workflow V2 : si `type_action` est renseigne, les champs de parametrage
        // sont pre-remplis, mais le statut reste 'a_parametrer' jusqu'a
        // l'enregistrement officiel par le chef dans le PTA.
        if ($hasParametrage) {
            $payload = array_merge($payload, $this->parametragePayload($row));
        }

        if ($action instanceof Action) {
            $before = $action->getAttributes();
            $action->fill($payload)->save();
            $stats['updated']++;
            $this->audit($user, 'update', $action, $before, $action->getAttributes(), $ipAddress);
        } else {
            $action = Action::query()->create($payload);
            $action->forceFill([
                'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
                'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
                'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
                'financement_statut' => $financingRequired ? Action::FINANCEMENT_PRE_SIGNALE_DAF : Action::FINANCEMENT_NON_REQUIS,
            ])->save();
            $stats['created']++;
            $this->audit($user, 'create', $action, null, $action->getAttributes(), $ipAddress);
        }

        if ($rmos->isNotEmpty() && Schema::hasTable('action_responsables')) {
            $sync = [];
            foreach ($rmos as $index => $rmo) {
                $sync[(int) $rmo->id] = ['is_primary' => $index === 0];
            }
            $action->responsables()->sync($sync);
        }

        // Sous-actions planifiees pour pre-remplir le formulaire PTA.
        if ($hasParametrage && $this->normalizeImportActionType($row['type_action'] ?? '') === Action::TYPE_COMPOSEE) {
            $this->persistImportedSubActions($action, $row, $primaryRmo);
        }
    }

    /**
     * Construit les surcharges de paramatrage (workflow V2) a fusionner dans le
     * payload de l'action quand `type_action` est renseigne. Mappe type ↔ mode ↔
     * methode_calcul comme PtaWebController::normalizePtaActionPayload.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function parametragePayload(array $row): array
    {
        $type = $this->normalizeImportActionType($row['type_action'] ?? '');
        $mode = match ($type) {
            Action::TYPE_QUANTITATIVE => Action::MODE_QUANTITATIF,
            Action::TYPE_COMPOSEE => Action::MODE_SOUS_ACTIONS,
            default => Action::MODE_SANS_QUANTITE,
        };
        $isQuantitative = $mode === Action::MODE_QUANTITATIF;
        $thresholdMode = strtolower(trim((string) ($row['seuil_mode'] ?? '')));
        $thresholdMode = in_array($thresholdMode, ['unique', 'trimestriel'], true) ? $thresholdMode : 'unique';
        $riskLevel = strtolower(trim((string) ($row['niveau_risque'] ?? '')));

        return [
            'mode_evaluation' => $mode,
            'type_action' => $type,
            'type_cible' => $isQuantitative ? 'quantitative' : 'qualitative',
            'methode_calcul' => match ($mode) {
                Action::MODE_QUANTITATIF => 'cumulative_quantity',
                Action::MODE_SANS_QUANTITE => 'binary_completion',
                default => 'sum_sous_actions',
            },
            'quantite_cible' => $isQuantitative ? (float) $row['quantite_cible'] : null,
            'unite_cible' => $isQuantitative ? (trim((string) ($row['unite_cible'] ?? '')) ?: null) : null,
            'seuil_mode' => $thresholdMode,
            'seuil_t1' => $thresholdMode === 'trimestriel' ? $this->nullableNumber($row['seuil_t1'] ?? null) : null,
            'seuil_t2' => $thresholdMode === 'trimestriel' ? $this->nullableNumber($row['seuil_t2'] ?? null) : null,
            'seuil_t3' => $thresholdMode === 'trimestriel' ? $this->nullableNumber($row['seuil_t3'] ?? null) : null,
            'seuil_t4' => $thresholdMode === 'trimestriel' ? $this->nullableNumber($row['seuil_t4'] ?? null) : null,
            'requires_comment' => $this->boolFromCell($row['commentaire_obligatoire'] ?? null, false),
            'allows_difficulty' => $this->boolFromCell($row['champ_difficulte'] ?? null, true),
            'niveau_risque' => $riskLevel !== '' ? $riskLevel : null,
            'mesures_preventives' => (trim((string) ($row['mesures_preventives'] ?? '')) ?: null),
        ];
    }

    /**
     * Cree les sous-actions planifiees d'une action composee a partir de la
     * cellule `sous_actions`. Reprend (en version allegee) la logique de
     * PtaWebController::syncPlannedSubActions.
     *
     * @param array<string,mixed> $row
     */
    private function persistImportedSubActions(Action $action, array $row, ?User $primaryRmo): void
    {
        $warnings = [];
        $errors = [];
        $items = $this->parseSousActions((string) ($row['sous_actions'] ?? ''), $warnings, $errors);

        // Re-import : on repart d'une base propre pour eviter les doublons.
        SousAction::query()->where('action_id', (int) $action->id)->delete();

        $defaultAgentId = (int) ($primaryRmo?->id ?? $action->responsable_id ?? 0);
        if ($defaultAgentId <= 0 || $items === []) {
            return;
        }

        $startDate = optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString();
        $endDate = optional($action->date_fin)->format('Y-m-d') ?? $startDate;
        foreach ($items as $item) {
            $isQuantitative = $item['type'] === SousAction::TYPE_QUANTITATIVE;
            $action->sousActions()->create([
                'agent_id' => $defaultAgentId,
                'libelle' => $item['libelle'],
                'sub_action_type' => $item['type'],
                'weight' => $item['weight'],
                'requires_proof' => true,
                'requires_comment' => false,
                'allows_difficulty' => true,
                'cible_prevue' => $isQuantitative ? $item['cible'] : null,
                'unite' => $item['unite'] !== '' ? $item['unite'] : $action->unite_cible,
                'date_debut' => $startDate,
                'date_fin' => $endDate,
                'quantite_realisee' => 0,
                'resultat_obtenu' => null,
                'taux_realisation' => 0,
                'statut' => 'non_demarre',
                'est_effectuee' => false,
                'taux_execution' => 0,
            ]);
        }

        $action->refresh()->recalculateRealization();
    }

    /**
     * Bascule en EN_COURS les PTA en brouillon dont plus aucune action n'est
     * 'a_parametrer'. Mirroir de PtaWebController::maybePromoteBrouillonToEnCours.
     *
     * @param list<int> $ptaIds
     */
    private function promoteFullyParametrePtas(array $ptaIds): void
    {
        foreach (array_unique($ptaIds) as $ptaId) {
            $pta = Pta::query()->find($ptaId);
            if (! $pta instanceof Pta || $pta->statut !== Pta::STATUS_BROUILLON) {
                continue;
            }

            $pending = Action::query()
                ->where('pta_id', $pta->id)
                ->where('statut_parametrage', 'a_parametrer')
                ->count();
            if ($pending === 0) {
                $pta->forceFill(['statut' => Pta::STATUS_EN_COURS])->save();
            }
        }
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach (array_merge(self::REQUIRED_COLUMNS, self::PARAMETRAGE_COLUMNS) as $column) {
            $normalized[$column] = is_string($row[$column] ?? null) ? trim((string) $row[$column]) : ($row[$column] ?? null);
        }
        $normalized['type_action'] = $this->normalizeImportActionType($normalized['type_action'] ?? '');

        return $normalized;
    }

    private function normalizeImportActionType(mixed $value): string
    {
        return match ($this->normalizeImportTypeToken($value)) {
            'q', 'quantitative' => Action::TYPE_QUANTITATIVE,
            'nq', 'non_quantitative', 'nonquantitative' => Action::TYPE_NON_QUANTITATIVE,
            'm', 'composee', 'compose', 'composite', 'sous_actions' => Action::TYPE_COMPOSEE,
            default => $this->normalizeImportTypeToken($value),
        };
    }

    private function normalizeImportSubActionType(mixed $value): string
    {
        return match ($this->normalizeImportTypeToken($value)) {
            'q', 'quantitative' => SousAction::TYPE_QUANTITATIVE,
            'nq', 'non_quantitative', 'nonquantitative' => SousAction::TYPE_NON_QUANTITATIVE,
            default => $this->normalizeImportTypeToken($value),
        };
    }

    private function normalizeImportTypeToken(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function boolFromCell(mixed $value, bool $default): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function findDirection(string $value): ?Direction
    {
        $key = $this->lookupKey($value);
        if ($key === '') {
            return null;
        }

        return Direction::query()
            ->whereRaw('LOWER(code) = ?', [$key])
            ->orWhereRaw('LOWER(libelle) = ?', [$key])
            ->first();
    }

    private function findService(string $value): ?Service
    {
        $key = $this->lookupKey($value);
        if ($key === '') {
            return null;
        }

        return Service::query()
            ->whereRaw('LOWER(code) = ?', [$key])
            ->orWhereRaw('LOWER(libelle) = ?', [$key])
            ->first();
    }

    private function lookupKey(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }

    private function positiveInt(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === trim((string) $value);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function splitAgentCodes(string $value, array &$warnings = []): array
    {
        $codes = collect(explode(';', $value))
            ->map(fn (string $code): string => Str::of($code)->ascii()->upper()->replace(' ', '')->trim()->toString())
            ->filter()
            ->values();

        $duplicates = $codes->duplicates()->unique()->values()->all();
        foreach ($duplicates as $duplicate) {
            $warnings[] = 'Code agent '.$duplicate.' present plusieurs fois dans la meme action; doublon ignore.';
        }

        return $codes->unique()->values()->all();
    }

    private function findAgentByCode(string $agentCode): ?User
    {
        $normalized = Str::of($agentCode)->ascii()->upper()->replace(' ', '')->trim()->toString();
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw("UPPER(REPLACE(agent_matricule, ' ', '')) = ?", [$normalized])
            ->first();
    }

    private function audit(
        User $user,
        string $action,
        mixed $entity,
        ?array $oldValue,
        ?array $newValue,
        ?string $ipAddress
    ): void {
        JournalAudit::query()->create([
            'user_id' => (int) $user->id,
            'module' => 'imports_excel',
            'entite_type' => is_object($entity) ? $entity::class : null,
            'entite_id' => is_object($entity) && isset($entity->id) ? (int) $entity->id : null,
            'action' => $action,
            'ancienne_valeur' => $oldValue,
            'nouvelle_valeur' => $newValue,
            'adresse_ip' => $ipAddress,
            'user_agent' => null,
        ]);
    }

    private function detectOrderConflict(array &$seen, string $key, mixed $label, array &$errors, string $field): void
    {
        $normalizedLabel = $this->lookupKey((string) $label);
        if (isset($seen[$key]) && $seen[$key] !== $normalizedLabel) {
            $errors[] = "Doublon incoherent sur {$field}: deux libelles differents utilisent le meme ordre.";

            return;
        }

        $seen[$key] = $normalizedLabel;
    }
}
