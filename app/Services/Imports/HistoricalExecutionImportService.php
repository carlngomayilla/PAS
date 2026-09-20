<?php

namespace App\Services\Imports;

use App\Models\Action;
use App\Models\JournalAudit;
use App\Models\PlanningImport;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricalExecutionImportService
{
    public const MODULE = 'execution_historique';

    public const MODE = 'historical_execution';

    public const SHEET_NAME = 'IMPORT_EXECUTION';

    /**
     * @var list<string>
     */
    public const COLUMNS = [
        'code_action',
        'statut_execution',
        'date_debut_reelle',
        'date_fin_reelle',
        'progression_reelle',
        'quantite_realisee',
        'commentaire_historique',
    ];

    /**
     * @var list<string>
     */
    public const STATUSES = [
        'non_executee',
        'en_cours',
        'achevee',
    ];

    public function __construct(
        private readonly SimpleSpreadsheet $spreadsheet,
        private readonly ActionTrackingService $trackingService
    ) {}

    public function canImport(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasRole(
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
        );
    }

    public function createPreview(UploadedFile $file, User $user, string $ipAddress): PlanningImport
    {
        $this->authorize($user);
        $sheet = $this->spreadsheet->read($file);
        $preview = $this->validateSheet($sheet);

        return PlanningImport::query()->create([
            'user_id' => $user->id,
            'role' => $user->effectiveRoleCode(),
            'filename' => $file->getClientOriginalName(),
            'module' => self::MODULE,
            'mode' => self::MODE,
            'total_rows' => count($preview['rows']),
            'valid_rows' => collect($preview['rows'])->whereIn('status', ['Valide', 'Avertissement'])->count(),
            'error_rows' => collect($preview['rows'])->where('status', 'Erreur')->count(),
            'status' => $preview['has_errors'] ? 'preview_errors' : 'preview_ready',
            'preview_payload' => $preview,
            'error_report' => collect($preview['rows'])->where('status', 'Erreur')->values()->all(),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @param  array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>}  $sheet
     * @return array<string,mixed>
     */
    public function validateSheet(array $sheet): array
    {
        $globalErrors = [];
        if ((int) ($sheet['sheet_count'] ?? 0) < 1) {
            $globalErrors[] = 'Le fichier doit contenir au moins une feuille.';
        }
        $sheetName = (string) ($sheet['sheet_name'] ?? '');
        if ($sheetName !== self::SHEET_NAME && $sheetName !== 'IMPORT_GLOBAL') {
            $globalErrors[] = 'La feuille Excel doit etre nommee '.self::SHEET_NAME.'.';
        }
        if (array_values($sheet['headers'] ?? []) !== self::COLUMNS) {
            $globalErrors[] = 'Les colonnes attendues sont : '.implode(', ', self::COLUMNS).'.';
        }

        $seenCodes = [];
        $rows = [];
        foreach (($sheet['rows'] ?? []) as $rawRow) {
            $rowNumber = (int) ($rawRow['_row_number'] ?? 0);
            $data = $this->normalizeRow($rawRow);
            $errors = [];
            $warnings = [];

            $code = $data['code_action'];
            if ($code === '') {
                $errors[] = 'code_action est obligatoire.';
            } elseif (isset($seenCodes[$code])) {
                $errors[] = 'code_action present plusieurs fois dans le fichier.';
            } else {
                $seenCodes[$code] = true;
            }

            $action = $code !== ''
                ? Action::query()->with('pta.direction', 'pta.service')->where('code', $code)->first()
                : null;
            if (! $action instanceof Action) {
                $errors[] = 'Action introuvable pour le code '.$code.'.';
            } elseif ($action->historical_execution_recorded_at !== null) {
                $warnings[] = 'Cette action possede deja une reprise historique : la ligne la mettra a jour.';
            }

            if (! in_array($data['statut_execution'], self::STATUSES, true)) {
                $errors[] = 'statut_execution doit valoir non_executee, en_cours ou achevee.';
            }

            $rawStart = trim((string) ($rawRow['date_debut_reelle'] ?? ''));
            $rawEnd = trim((string) ($rawRow['date_fin_reelle'] ?? ''));
            $start = $this->parseDate($rawStart);
            $end = $this->parseDate($rawEnd);
            if ($rawStart !== '' && $start === null) {
                $errors[] = 'date_debut_reelle doit etre une date valide.';
            }
            if ($rawEnd !== '' && $end === null) {
                $errors[] = 'date_fin_reelle doit etre une date valide.';
            }
            if ($start?->isAfter(Carbon::today()) || $end?->isAfter(Carbon::today())) {
                $errors[] = 'Les dates reelles ne peuvent pas etre dans le futur.';
            }
            if ($start !== null && $end !== null && $start->isAfter($end)) {
                $errors[] = 'date_debut_reelle doit etre inferieure ou egale a date_fin_reelle.';
            }

            if ($data['statut_execution'] === 'achevee') {
                if ($start === null) {
                    $errors[] = 'date_debut_reelle est obligatoire pour une action achevee.';
                }
                if ($end === null) {
                    $errors[] = 'date_fin_reelle est obligatoire pour une action achevee.';
                }
            }
            if ($data['statut_execution'] === 'en_cours' && $start === null) {
                $errors[] = 'date_debut_reelle est obligatoire pour une action en cours.';
            }
            if ($data['statut_execution'] === 'en_cours' && $end !== null) {
                $errors[] = 'Une action en cours ne doit pas avoir de date_fin_reelle.';
            }
            if ($data['statut_execution'] === 'non_executee' && ($start !== null || $end !== null)) {
                $errors[] = 'Une action non executee ne doit pas avoir de date reelle.';
            }

            $progress = $data['progression_reelle'];
            if ($progress === null || ! is_numeric($progress) || (float) $progress < 0 || (float) $progress > 100) {
                $errors[] = 'progression_reelle doit etre comprise entre 0 et 100.';
            } elseif ($data['statut_execution'] === 'achevee' && (float) $progress < 100) {
                $errors[] = 'Une action achevee doit avoir une progression_reelle de 100.';
            } elseif ($data['statut_execution'] === 'non_executee' && (float) $progress !== 0.0) {
                $errors[] = 'Une action non executee doit avoir une progression_reelle de 0.';
            }

            if ($data['quantite_realisee'] !== null && (! is_numeric($data['quantite_realisee']) || (float) $data['quantite_realisee'] < 0)) {
                $errors[] = 'quantite_realisee doit etre numerique et positive ou nulle.';
            }
            if ($action instanceof Action
                && $action->usesQuantitativeProgress()
                && (float) ($action->quantite_cible ?? 0) > 0
                && $data['statut_execution'] !== 'non_executee'
                && $data['quantite_realisee'] === null) {
                $errors[] = 'quantite_realisee est obligatoire pour cette action quantitative.';
            }
            if (Str::length($data['commentaire_historique']) < 5) {
                $errors[] = 'commentaire_historique est obligatoire (5 caracteres minimum).';
            }

            $rows[] = [
                'line' => $rowNumber,
                'status' => $errors !== [] ? 'Erreur' : ($warnings !== [] ? 'Avertissement' : 'Valide'),
                'errors' => $errors,
                'warnings' => $warnings,
                'message' => implode(' ', array_merge($errors, $warnings)),
                'data' => $data + [
                    'action_id' => $action?->id,
                    'action_libelle' => $action?->libelle,
                    'direction' => $action?->pta?->direction?->libelle,
                    'service' => $action?->pta?->service?->libelle,
                ],
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
            'sheet_name' => (string) ($sheet['sheet_name'] ?? self::SHEET_NAME),
            'headers' => self::COLUMNS,
            'required_columns' => self::COLUMNS,
            'global_errors' => $globalErrors,
            'rows' => $rows,
            'has_errors' => collect($rows)->contains(fn (array $row): bool => $row['status'] === 'Erreur') || $globalErrors !== [],
        ];
    }

    public function execute(PlanningImport $import, User $user, ?string $ipAddress = null): PlanningImport
    {
        $this->authorize($user);
        if ((string) $import->module !== self::MODULE || (string) $import->mode !== self::MODE) {
            throw new RuntimeException('Cet import ne correspond pas a une reprise d execution.');
        }
        if ((string) $import->status !== 'preview_ready') {
            throw new RuntimeException('Corrigez les erreurs avant de confirmer la reprise.');
        }

        $rows = collect($import->preview_payload['rows'] ?? []);
        if ($rows->contains(fn (array $row): bool => ($row['status'] ?? '') === 'Erreur')) {
            throw new RuntimeException('Corrigez les erreurs avant de confirmer la reprise.');
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        DB::transaction(function () use ($rows, $user, $ipAddress, &$stats): void {
            foreach ($rows as $row) {
                $data = $row['data'] ?? [];
                $action = Action::query()->whereKey((int) ($data['action_id'] ?? 0))->lockForUpdate()->firstOrFail();
                $before = $action->getAttributes();
                $status = (string) ($data['statut_execution'] ?? '');
                $progress = (float) ($data['progression_reelle'] ?? 0);
                $endDate = $data['date_fin_reelle'] ?: null;
                $dynamicStatus = ActionTrackingService::STATUS_NON_DEMARRE;
                if ($status === 'en_cours') {
                    $dynamicStatus = ActionTrackingService::STATUS_EN_COURS;
                } elseif ($status === 'achevee') {
                    $deadline = $action->date_echeance ?? $action->date_fin ?? $action->echeance_cible;
                    $onTime = $deadline === null || Carbon::parse($endDate)->lte(Carbon::parse($deadline));
                    $dynamicStatus = $onTime
                        ? ActionTrackingService::STATUS_ACHEVE_DANS_DELAI
                        : ActionTrackingService::STATUS_ACHEVE_HORS_DELAI;
                    $progress = 100.0;
                }

                $action->forceFill([
                    'date_debut_reelle' => $data['date_debut_reelle'] ?: null,
                    'date_fin_reelle' => $endDate,
                    'quantite_realisee' => $data['quantite_realisee'] !== null
                        ? (float) $data['quantite_realisee']
                        : ($status === 'non_executee' ? 0 : $action->quantite_realisee),
                    'progression_reelle' => $progress,
                    'historical_execution_recorded_at' => now(),
                    'historical_execution_recorded_by' => $user->id,
                    'historical_execution_comment' => $data['commentaire_historique'],
                    'statut' => $dynamicStatus,
                    'statut_dynamique' => $dynamicStatus,
                ])->save();

                $referenceDate = $endDate !== null ? Carbon::parse($endDate) : Carbon::today();
                $this->trackingService->refreshActionMetrics($action->fresh(), $referenceDate);
                $after = $action->fresh()->getAttributes();
                JournalAudit::query()->create([
                    'user_id' => (int) $user->id,
                    'module' => self::MODULE,
                    'entite_type' => Action::class,
                    'entite_id' => (int) $action->id,
                    'action' => 'historical_execution_import',
                    'ancienne_valeur' => $before,
                    'nouvelle_valeur' => $after,
                    'adresse_ip' => $ipAddress,
                ]);
                $stats['updated']++;
            }
        });

        $import->forceFill([
            'created_count' => 0,
            'updated_count' => $stats['updated'],
            'skipped_count' => 0,
            'status' => 'imported',
        ])->save();

        return $import->fresh();
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{code_action:string,statut_execution:string,date_debut_reelle:string,date_fin_reelle:string,progression_reelle:?float,quantite_realisee:?float,commentaire_historique:string}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'code_action' => trim((string) ($row['code_action'] ?? '')),
            'statut_execution' => $this->normalizeStatus($row['statut_execution'] ?? ''),
            'date_debut_reelle' => $this->parseDate($row['date_debut_reelle'] ?? null)?->toDateString() ?? '',
            'date_fin_reelle' => $this->parseDate($row['date_fin_reelle'] ?? null)?->toDateString() ?? '',
            'progression_reelle' => $this->numericOrNull($row['progression_reelle'] ?? null),
            'quantite_realisee' => $this->numericOrNull($row['quantite_realisee'] ?? null),
            'commentaire_historique' => trim((string) ($row['commentaire_historique'] ?? '')),
        ];
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = Str::of((string) $value)->ascii()->lower()->replace([' ', '-'], '_')->toString();

        return match ($status) {
            'non_demarre', 'non_demarre_e', 'non_execute', 'non_executed' => 'non_executee',
            'realisee', 'realise', 'terminee', 'termine', 'acheve', 'achevee', 'acheve_dans_delai', 'acheve_hors_delai' => 'achevee',
            default => $status,
        };
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->startOfDay();
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorize(User $user): void
    {
        if (! $this->canImport($user)) {
            abort(403, 'Cette reprise est reservee au SCIQ, a la planification et a leurs chefs.');
        }
    }
}
