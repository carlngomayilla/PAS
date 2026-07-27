<?php

namespace App\Services\AiImport;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\AiImportSession;
use App\Models\User;
use App\Services\OpenAi\OpenAiClientService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PasPaoPtaAnalysisService
{
    public function __construct(
        private readonly OpenAiClientService $openAi,
        private readonly DocumentExtractionService $documents
    ) {}

    /**
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     * @return array<string,mixed>
     */
    public function analyzeAndPersist(AiImportSession $session, array $extraction, ?User $user = null): array
    {
        $result = $this->analyze($session, $extraction, $user);
        $batch = $this->documents->legacyBatchFor($session);
        $rows = $this->persistRows($session, $batch, $result);
        $report = $result['rapport_import'] ?? [];

        $session->forceFill([
            'status' => AiImportSession::STATUS_REVIEW_REQUIRED,
            'total_rows_detected' => $rows,
            'total_rows_validated' => (int) ($report['total_lignes_pretes'] ?? 0),
            'total_errors' => (int) ($report['total_erreurs'] ?? 0),
            'completed_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     * @return array<string,mixed>
     */
    public function analyze(AiImportSession $session, array $extraction, ?User $user = null): array
    {
        if (! $this->openAi->available()) {
            throw new RuntimeException('L import IA requiert OpenAI. Configurez OPENAI_API_KEY; aucun autre fournisseur ni resultat de secours ne sera utilise.');
        }

        try {
            $response = $this->openAi->createStructuredResponse(
                'pas_pao_pta_import',
                $this->prompt($session, $extraction),
                $this->schema(),
                $user,
                'ai_import'
            );

            $session->forceFill([
                'model_used' => $response['model'],
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
                'total_cost_usd' => $response['total_cost_usd'],
            ])->save();

            return $this->normalizeAiResult($response['data'], $session, $extraction);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException('OpenAI n a pas pu analyser le document. L import IA est interrompu pour eviter un resultat non verifie.', previous: $exception);
        }
    }

    /**
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     */
    private function prompt(AiImportSession $session, array $extraction): string
    {
        return implode("\n\n", [
            'Tu es un agent metier e-Pilotage ANBG. Analyse uniquement les donnees sources fournies.',
            'Regles obligatoires: ne jamais inventer une action, une direction, un service, une date, un taux ou une cible. Signale tout manque dans controles. Une donnee incertaine doit recevoir le statut a_verifier, a_parametrer, a_valider, erreur_date, erreur_rattachement, doublon_possible ou rejetee.',
            'Hierarchie: PAS > axe strategique > objectif strategique > objectif operationnel > action PTA > sous-action.',
            'Document attendu: '.json_encode([
                'session_id' => $session->id,
                'file_name' => $session->file_name,
                'file_type' => $session->file_type,
                'document_type_hint' => $session->document_type,
                'metadata' => $extraction['metadata'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'SOURCE='.Str::limit($extraction['text'], 180000, "\n[CONTENU_TRONQUE]"),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
            'required' => ['document', 'pas', 'axes', 'controles', 'rapport_import'],
            'properties' => [
                'document' => ['type' => 'object'],
                'pas' => ['type' => 'object'],
                'axes' => ['type' => 'array', 'items' => ['type' => 'object']],
                'controles' => ['type' => 'array', 'items' => ['type' => 'object']],
                'rapport_import' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     * @return array<string,mixed>
     */
    private function normalizeAiResult(array $result, AiImportSession $session, array $extraction): array
    {
        return [
            'document' => is_array($result['document'] ?? null) ? $result['document'] : $this->documentPayload($session),
            'pas' => is_array($result['pas'] ?? null) ? $result['pas'] : $this->pasPayload($session, $extraction),
            'axes' => is_array($result['axes'] ?? null) ? array_values($result['axes']) : [],
            'controles' => is_array($result['controles'] ?? null) ? array_values($result['controles']) : [],
            'rapport_import' => is_array($result['rapport_import'] ?? null) ? $result['rapport_import'] : $this->rapportImport([]),
        ];
    }

    /**
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     * @return array<string,mixed>
     */
    private function fallbackResult(AiImportSession $session, array $extraction): array
    {
        $rows = $extraction['rows'];
        if ($rows === []) {
            $rows = $this->rowsFromText($extraction['text']);
        }

        $year = $this->yearFrom($session->file_name.' '.$extraction['text']);
        $codePas = $year !== null ? 'PAS'.$year : '';
        $actions = [];
        $controles = [];

        foreach (array_values($rows) as $index => $row) {
            $action = $this->actionFromSourceRow($row, $index + 1, $codePas, $year);
            $actions[] = $action;

            foreach ($this->controlsForAction($action) as $control) {
                $controles[] = $control;
            }
        }

        $axes = $this->buildHierarchy($actions, $codePas);

        return [
            'document' => $this->documentPayload($session) + ['annee' => $year],
            'pas' => $this->pasPayload($session, $extraction) + [
                'code_pas' => $codePas,
                'annee_debut' => $year,
                'annee_fin' => $year,
            ],
            'axes' => $axes,
            'controles' => $controles,
            'rapport_import' => $this->rapportImport($actions, $controles),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsFromText(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line, int $index): array => [
                '_row_number' => $index + 1,
                'libelle_action' => trim($line),
            ])
            ->filter(fn (array $row): bool => mb_strlen((string) $row['libelle_action']) > 12)
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function actionFromSourceRow(array $row, int $index, string $codePas, ?int $year): array
    {
        $libelle = $this->first($row, ['libelle_action', 'libelle action', 'action', 'actions', 'activite', 'activites', 'description']);
        $direction = $this->first($row, ['direction', 'directions']);
        $service = $this->first($row, ['service', 'service_unite', 'unite', 'unite_dg']);
        $dateDebut = $this->parseDate($this->first($row, ['date_debut_action', 'date debut action', 'date_debut', 'date debut', 'debut']));
        $dateFinRaw = $this->first($row, ['date_fin_action', 'date fin action', 'date_fin', 'date fin', 'echeance', 'date_echeance']);
        $dateFin = $this->parseDate($dateFinRaw);
        $quantity = $this->numberFrom($this->first($row, ['quantite_a_realiser', 'quantite_cible', 'quantite', 'volume']));
        $livrable = $this->first($row, ['livrable_attendu', 'livrables_attendus', 'justificatif_attendu', 'livrable', 'indicateur']);
        $type = $this->indicatorType((string) $libelle, $quantity, (string) $livrable);
        $status = $this->statusFor((string) $libelle, (string) $direction, (string) $service, $dateFinRaw, $dateFin, $quantity, (string) $livrable, $type);

        return [
            'code_pas' => $codePas,
            'libelle_pas' => $year !== null ? 'PAS '.$year : '',
            'annee' => $year,
            'code_axe' => '',
            'ordre_axe' => (int) ($this->first($row, ['ordre_axe']) ?: 1),
            'axe_strategique' => $this->first($row, ['libelle_axe', 'axe_strategique', 'axe']),
            'code_objectif_strategique' => '',
            'ordre_objectif_strategique' => (int) ($this->first($row, ['ordre_objectif_strategique']) ?: 1),
            'objectif_strategique' => $this->first($row, ['libelle_objectif_strategique', 'objectif_strategique']),
            'code_objectif_operationnel' => '',
            'ordre_objectif_operationnel' => (int) ($this->first($row, ['ordre_objectif_operationnel']) ?: 1),
            'objectif_operationnel' => $this->first($row, ['libelle_objectif_operationnel', 'objectif_operationnel', 'programme']),
            'code_direction' => '',
            'direction' => $direction,
            'code_service' => '',
            'service' => $service,
            'code_action' => $codePas !== '' ? $codePas.'-ACT'.str_pad((string) $index, 3, '0', STR_PAD_LEFT) : 'ACT'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'ordre_action' => (int) ($this->first($row, ['ordre_action']) ?: $index),
            'libelle_action' => $libelle,
            'description_action' => $this->first($row, ['description_action', 'description']),
            'type_indicateur' => $type,
            'cible' => $this->first($row, ['cible', 'cible_minimum_execution']),
            'quantite_a_realiser' => $quantity,
            'livrable_attendu' => $livrable,
            'unite_mesure' => $this->first($row, ['unite_mesure', 'unite_cible', 'unite']),
            'rmo' => $this->first($row, ['rmo', 'responsable', 'codes_agents_rmo']),
            'date_debut_prevue' => $dateDebut?->toDateString(),
            'date_fin_prevue' => $dateFin?->toDateString(),
            'etat_initial' => $this->first($row, ['etat_initial', 'statut_initial']),
            'ressources_requises' => $this->first($row, ['ressources_requises', 'ressources_materielles']),
            'indicateurs_performance' => $this->first($row, ['indicateurs_performance', 'indicateur']),
            'risques_potentiels' => $this->first($row, ['risques_potentiels', 'risque']),
            'observations' => $this->first($row, ['observations', 'note_normalisation']),
            'source_page' => $this->intOrNull($this->first($row, ['source_page', 'page_pdf'])),
            'source_line' => $this->intOrNull($row['_row_number'] ?? $index),
            'statut_import' => $status,
            'sous_actions' => [],
            '_raw' => $row,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $actions
     * @return list<array<string,mixed>>
     */
    private function buildHierarchy(array $actions, string $codePas): array
    {
        if ($actions === []) {
            return [];
        }

        $grouped = collect($actions)->groupBy(fn (array $action): string => (string) ($action['axe_strategique'] ?? ''));

        return $grouped->values()->map(function ($axisActions, int $axisIndex) use ($codePas): array {
            $axisCode = $codePas !== '' ? $codePas.'-AXE'.str_pad((string) ($axisIndex + 1), 2, '0', STR_PAD_LEFT) : 'AXE'.str_pad((string) ($axisIndex + 1), 2, '0', STR_PAD_LEFT);
            $first = $axisActions->first();

            return [
                'code_axe' => $axisCode,
                'ordre_axe' => $axisIndex + 1,
                'libelle_axe' => (string) ($first['axe_strategique'] ?? ''),
                'objectifs_strategiques' => [[
                    'code_objectif_strategique' => $axisCode.'-OS01',
                    'ordre_objectif_strategique' => 1,
                    'libelle_objectif_strategique' => (string) ($first['objectif_strategique'] ?? ''),
                    'objectifs_operationnels' => [[
                        'code_objectif_operationnel' => $axisCode.'-OS01-OO01',
                        'ordre_objectif_operationnel' => 1,
                        'libelle_objectif_operationnel' => (string) ($first['objectif_operationnel'] ?? ''),
                        'actions' => $axisActions->values()->all(),
                    ]],
                ]],
            ];
        })->all();
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function persistRows(AiImportSession $session, AiImportBatch $batch, array $result): int
    {
        $batch->rows()->delete();
        $session->rows()->delete();

        $actions = $this->flattenActions($result);
        foreach ($actions as $index => $action) {
            AiImportRow::query()->create([
                'ai_import_session_id' => $session->id,
                'batch_id' => $batch->id,
                'row_number' => $index + 1,
                'raw_payload' => $action['_raw'] ?? $action,
                'normalized_payload' => $this->normalizedPayload($action),
                'validation_errors' => null,
                'status' => in_array($action['statut_import'] ?? null, [AiImportRow::IMPORT_READY], true) ? AiImportRow::STATUS_VALID : AiImportRow::STATUS_PENDING,
                'source_page' => $this->intOrNull($action['source_page'] ?? null),
                'source_line' => $this->intOrNull($action['source_line'] ?? ($index + 1)),
                'code_pas' => $action['code_pas'] ?? null,
                'axe' => $action['axe_strategique'] ?? null,
                'objectif_strategique' => $action['objectif_strategique'] ?? null,
                'objectif_operationnel' => $action['objectif_operationnel'] ?? null,
                'direction' => $action['direction'] ?? null,
                'service' => $action['service'] ?? null,
                'action' => $action['libelle_action'] ?? null,
                'sous_action' => $this->stringifySousActions($action['sous_actions'] ?? []),
                'rmo' => $action['rmo'] ?? null,
                'cible' => $action['cible'] ?? null,
                'type_indicateur' => $action['type_indicateur'] ?? null,
                'quantite_a_realiser' => $action['quantite_a_realiser'] ?? null,
                'livrable_attendu' => $action['livrable_attendu'] ?? null,
                'unite_mesure' => $action['unite_mesure'] ?? null,
                'date_debut' => $action['date_debut_prevue'] ?? null,
                'date_fin' => $action['date_fin_prevue'] ?? null,
                'statut_import' => $action['statut_import'] ?? AiImportRow::IMPORT_VERIFY,
                'errors_json' => null,
                'raw_json' => $action,
            ]);
        }

        $batch->forceFill([
            'status' => AiImportBatch::STATUS_EXTRACTED,
            'detected_year' => $result['document']['annee'] ?? null,
            'generated_excel_path' => null,
            'confidence_score' => 70,
        ])->save();

        return count($actions);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return list<array<string,mixed>>
     */
    private function flattenActions(array $result): array
    {
        $actions = [];
        foreach ((array) ($result['axes'] ?? []) as $axis) {
            foreach ((array) ($axis['objectifs_strategiques'] ?? []) as $strategic) {
                foreach ((array) ($strategic['objectifs_operationnels'] ?? []) as $operational) {
                    foreach ((array) ($operational['actions'] ?? []) as $action) {
                        if (! is_array($action)) {
                            continue;
                        }
                        $actions[] = $action + [
                            'axe_strategique' => $axis['libelle_axe'] ?? '',
                            'objectif_strategique' => $strategic['libelle_objectif_strategique'] ?? '',
                            'objectif_operationnel' => $operational['libelle_objectif_operationnel'] ?? '',
                        ];
                    }
                }
            }
        }

        return $actions;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function normalizedPayload(array $action): array
    {
        $year = $action['annee'] ?? $this->yearFrom(json_encode($action) ?: '');
        $type = match ($action['type_indicateur'] ?? '') {
            'quantitatif', 'quantitative' => 'Q',
            'mixte' => 'M',
            default => 'NQ',
        };

        return [
            'exercice' => $year,
            'annee_debut_pas' => $year,
            'annee_fin_pas' => $year,
            'ordre_axe' => $action['ordre_axe'] ?? 1,
            'libelle_axe' => $action['axe_strategique'] ?? null,
            'ordre_objectif_strategique' => $action['ordre_objectif_strategique'] ?? 1,
            'libelle_objectif_strategique' => $action['objectif_strategique'] ?? null,
            'direction' => $action['direction'] ?? null,
            'service' => $action['service'] ?? null,
            'service_unite' => $action['service'] ?? null,
            'ordre_objectif_operationnel' => $action['ordre_objectif_operationnel'] ?? 1,
            'libelle_objectif_operationnel' => $action['objectif_operationnel'] ?? null,
            'ordre_action' => $action['ordre_action'] ?? null,
            'code_action' => $action['code_action'] ?? null,
            'libelle_action' => $action['libelle_action'] ?? null,
            'description_action' => $action['description_action'] ?? null,
            'date_debut' => $action['date_debut_prevue'] ?? null,
            'date_fin' => $action['date_fin_prevue'] ?? null,
            'echeance' => $action['date_fin_prevue'] ?? null,
            'responsable' => $action['rmo'] ?? null,
            'rmo_raw' => $action['rmo'] ?? null,
            'type_action' => $type,
            'quantite_cible' => $action['quantite_a_realiser'] ?? null,
            'unite_cible' => $action['unite_mesure'] ?? null,
            'cible' => $action['cible'] ?? null,
            'livrables_attendus' => $action['livrable_attendu'] ?? null,
            'ressources_requises' => $action['ressources_requises'] ?? null,
            'risques_potentiels' => $action['risques_potentiels'] ?? null,
            'observations' => $action['observations'] ?? null,
            'sous_actions' => $this->stringifySousActions($action['sous_actions'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $action
     * @return list<array<string,mixed>>
     */
    private function controlsForAction(array $action): array
    {
        $controls = [];
        foreach ([
            'libelle_action' => 'Action obligatoire.',
            'direction' => 'Direction a rattacher.',
            'service' => 'Service a rattacher.',
            'date_fin_prevue' => 'Date de fin a verifier.',
        ] as $field => $message) {
            if (trim((string) ($action[$field] ?? '')) === '') {
                $controls[] = [
                    'source_page' => $action['source_page'] ?? null,
                    'element' => $action['code_action'] ?? '',
                    'champ' => $field,
                    'probleme' => $message,
                    'gravite' => $field === 'libelle_action' ? 'bloquant' : 'avertissement',
                    'suggestion' => 'Completer ou corriger avant import final.',
                    'statut' => $action['statut_import'] ?? AiImportRow::IMPORT_VERIFY,
                ];
            }
        }

        return $controls;
    }

    /**
     * @param  list<array<string,mixed>>  $actions
     * @param  list<array<string,mixed>>  $controls
     * @return array<string,mixed>
     */
    private function rapportImport(array $actions, array $controls = []): array
    {
        $rows = collect($actions);

        return [
            'total_axes_detectes' => $rows->pluck('axe_strategique')->filter()->unique()->count(),
            'total_objectifs_strategiques_detectes' => $rows->pluck('objectif_strategique')->filter()->unique()->count(),
            'total_objectifs_operationnels_detectes' => $rows->pluck('objectif_operationnel')->filter()->unique()->count(),
            'total_actions_detectees' => $rows->count(),
            'total_sous_actions_detectees' => $rows->sum(fn (array $row): int => is_array($row['sous_actions'] ?? null) ? count($row['sous_actions']) : 0),
            'total_lignes_pretes' => $rows->where('statut_import', AiImportRow::IMPORT_READY)->count(),
            'total_lignes_a_verifier' => $rows->where('statut_import', AiImportRow::IMPORT_VERIFY)->count(),
            'total_lignes_a_parametrer' => $rows->where('statut_import', AiImportRow::IMPORT_PARAMETERIZE)->count(),
            'total_erreurs' => count($controls),
            'commentaire_global' => $rows->count().' ligne(s) detectee(s); validation humaine obligatoire avant import.',
        ];
    }

    private function documentPayload(AiImportSession $session): array
    {
        return [
            'type' => strtoupper((string) ($session->document_type ?: 'MIXTE')),
            'annee' => $this->yearFrom($session->file_name),
            'direction' => '',
            'service' => '',
            'source' => $session->file_name,
        ];
    }

    /**
     * @param  array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}  $extraction
     */
    private function pasPayload(AiImportSession $session, array $extraction): array
    {
        $year = $this->yearFrom($session->file_name.' '.$extraction['text']);

        return [
            'code_pas' => $year !== null ? 'PAS'.$year : '',
            'libelle_pas' => $year !== null ? 'PAS '.$year : '',
            'annee_debut' => $year,
            'annee_fin' => $year,
            'description' => '',
        ];
    }

    private function statusFor(string $action, string $direction, string $service, mixed $rawDate, ?Carbon $date, ?float $quantity, string $livrable, string $type): string
    {
        if (trim($action) === '') {
            return AiImportRow::IMPORT_REJECTED;
        }

        if (trim((string) $rawDate) === '' || $date === null) {
            return AiImportRow::IMPORT_DATE_ERROR;
        }

        if (trim($direction) === '' || trim($service) === '') {
            return AiImportRow::IMPORT_ATTACHMENT_ERROR;
        }

        if (($type === 'quantitatif' || $type === 'mixte') && $quantity === null) {
            return AiImportRow::IMPORT_PARAMETERIZE;
        }

        if (($type === 'non_quantitatif' || $type === 'mixte') && trim($livrable) === '') {
            return AiImportRow::IMPORT_PARAMETERIZE;
        }

        return AiImportRow::IMPORT_READY;
    }

    private function indicatorType(string $action, ?float $quantity, string $livrable): string
    {
        $hasQuantity = $quantity !== null || preg_match('/\d+(?:[,.]\d+)?\s*(%|pourcent|agents?|dossiers?|ateliers?|rapports?|documents?)?/i', $action) === 1;
        $hasDeliverable = trim($livrable) !== '' || preg_match('/rapport|note|manuel|procedure|plateforme|application|document/i', $action) === 1;

        return match (true) {
            $hasQuantity && $hasDeliverable => 'mixte',
            $hasQuantity => 'quantitatif',
            default => 'non_quantitatif',
        };
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<string>  $keys
     */
    private function first(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return $row[$key];
            }
        }

        return null;
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
        } catch (Throwable) {
            return null;
        }
    }

    private function numberFrom(mixed $value): ?float
    {
        $value = str_replace(["\u{00A0}", ' ', '%'], '', (string) $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function yearFrom(string $value): ?int
    {
        return preg_match('/20\d{2}/', $value, $matches) === 1 ? (int) $matches[0] : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringifySousActions(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return collect($value)
            ->map(fn (mixed $row): string => is_array($row) ? (string) ($row['libelle_sous_action'] ?? '') : (string) $row)
            ->filter()
            ->implode('; ');
    }
}
