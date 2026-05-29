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
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_SCIQ, User::ROLE_PLANIFICATION);
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
        if ((int) $sheet['sheet_count'] !== 1) {
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

            $this->detectOrderConflict($seenLabels['axes'], $startYear.'-'.$endYear.'|'.$normalized['ordre_axe'], $normalized['libelle_axe'], $errors, 'ordre_axe');
            $this->detectOrderConflict($seenLabels['strategic'], $startYear.'-'.$endYear.'|'.$normalized['ordre_axe'].'|'.$normalized['ordre_objectif_strategique'], $normalized['libelle_objectif_strategique'], $errors, 'ordre_objectif_strategique');
            $this->detectOrderConflict($seenLabels['operational'], $directionKey.'|'.$serviceKey.'|'.$startYear.'|'.$normalized['ordre_objectif_operationnel'], $normalized['libelle_objectif_operationnel'], $errors, 'ordre_objectif_operationnel');
            $this->detectOrderConflict($seenLabels['actions'], $serviceKey.'|'.$startYear.'|'.$normalized['ordre_objectif_operationnel'].'|'.$normalized['ordre_action'], $normalized['libelle_action'], $errors, 'ordre_action');

            if ($errors === [] && $service instanceof Service) {
                $existing = Action::query()
                    ->whereHas('pta', fn ($query) => $query->where('service_id', $service->id))
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
        if ((int) $sheet['sheet_count'] !== 1) {
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
        return array_values($headers) === self::REQUIRED_COLUMNS;
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
        DB::transaction(function () use ($rows, $mode, $user, $ipAddress, &$stats): void {
            foreach ($rows as $row) {
                $this->persistRow($row['data'], $mode, $user, $ipAddress, $stats);
            }
        });

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
     */
    private function persistRow(array $row, string $mode, User $user, ?string $ipAddress, array &$stats): void
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
                // STATUS_BROUILLON (regle metier ANBG 2026-05-29) : le PTA n'est PAS
                // considere comme "enregistre" tant que ses actions ne sont pas
                // parametrees une par une par le chef de service. Le passage a
                // STATUS_EN_COURS se fait automatiquement quand toutes les actions
                // ont statut_parametrage = 'parametre'.
                'statut' => Pta::STATUS_BROUILLON,
            ]
        );
        // PTA volontairement NON verrouille apres import.

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
        $payload = [
            'code' => $this->codes->action($service, $year, $operationalOrder, $actionOrder),
            'exercice_id' => $exercise->id,
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operational->id,
            'ordre_import' => $actionOrder,
            'nombre_sous_actions_prevu' => (int) $row['nombre_sous_actions'],
            'statut_parametrage' => 'a_parametrer',
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

        if ($action instanceof Action) {
            $before = $action->getAttributes();
            $action->fill($payload)->save();
            // Action volontairement NON verrouillee : le chef de service doit pouvoir
            // la parametrer (statut_parametrage = 'a_parametrer' tant qu'elle est incomplete).
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
            // Action volontairement NON verrouillee a la creation : le chef de service
            // la verrouillera apres parametrage complet, action par action.
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
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach (self::REQUIRED_COLUMNS as $column) {
            $normalized[$column] = is_string($row[$column] ?? null) ? trim((string) $row[$column]) : ($row[$column] ?? null);
        }

        return $normalized;
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
