<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Support\Arr;
use Throwable;

class PtaImportCompletionService
{
    public function __construct(
        private readonly PtaActionParameterizationService $parameterizer,
        private readonly PtaImportTemplateAnalyzerService $template
    ) {}

    /**
     * @return array{rows:int,fields:int}
     */
    public function complete(AiImportBatch $batch): array
    {
        $context = [];
        $stats = ['rows' => 0, 'fields' => 0];
        $columns = $this->officialColumns();

        foreach ($batch->rows()->reorder('row_number')->get() as $row) {
            $payload = $row->normalized_payload ?? [];
            if ($payload === []) {
                continue;
            }

            $changes = [];
            $payload = $this->completePayload($payload, $batch, $context, $columns, $changes);
            $this->rememberContext($payload, $context);

            if ($changes === []) {
                continue;
            }

            $row->forceFill([
                'normalized_payload' => $this->withCompletionNote($payload, $changes),
                'status' => $row->status === AiImportRow::STATUS_VALID ? AiImportRow::STATUS_CORRECTED : $row->status,
            ])->save();

            $stats['rows']++;
            $stats['fields'] += count(array_unique($changes));
        }

        return $stats;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @param  list<string>  $columns
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function completePayload(array $payload, AiImportBatch $batch, array $context, array $columns, array &$changes): array
    {
        $payload = $this->syncAliases($payload, $changes);
        $payload = $this->fillFromContext($payload, $context, $changes);
        $payload = $this->fillFromBatch($payload, $batch, $changes);
        $payload = $this->syncAliases($payload, $changes);
        $payload = $this->fillDatesAndYears($payload, $batch, $changes);
        $payload = $this->fillActionParameterization($payload, $changes);
        $payload = $this->fillFinancing($payload, $changes);
        $payload = $this->fillRequiredGuideDefaults($payload, $changes);
        $payload = $this->syncAliases($payload, $changes);

        foreach ($columns as $column) {
            if (! array_key_exists($column, $payload)) {
                $payload[$column] = null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function syncAliases(array $payload, array &$changes): array
    {
        foreach ([
            ['exercice', 'annee_debut_pas'],
            ['axe_strategique', 'libelle_axe'],
            ['objectif_strategique', 'libelle_objectif_strategique'],
            ['programme', 'libelle_objectif_operationnel'],
            ['code_action', 'ordre_action'],
            ['service', 'service_unite'],
            ['date_debut', 'date_debut_action'],
            ['date_fin', 'date_fin_action'],
            ['echeance', 'date_echeance_objectif_operationnel'],
            ['indicateur', 'justificatif_attendu'],
            ['cible', 'cible_minimum_execution'],
            ['ressources_requises', 'ressources_materielles'],
            ['risques_potentiels', 'risque'],
            ['budget_previsionnel', 'montant_financement'],
            ['source_financement', 'nature_financement'],
        ] as [$left, $right]) {
            $payload = $this->fillBothWays($payload, $left, $right, $changes);
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillFromContext(array $payload, array $context, array &$changes): array
    {
        foreach ([
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
        ] as $field) {
            if ($this->blank($payload[$field] ?? null) && ! $this->blank($context[$field] ?? null)) {
                $payload[$field] = $context[$field];
                $changes[] = $field;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillFromBatch(array $payload, AiImportBatch $batch, array &$changes): array
    {
        foreach ([
            'annee_debut_pas' => $batch->detected_year,
            'annee_fin_pas' => $batch->detected_year,
            'direction' => $batch->detected_direction,
            'service_unite' => $batch->detected_service,
        ] as $field => $value) {
            if ($this->blank($payload[$field] ?? null) && ! $this->blank($value)) {
                $payload[$field] = $value;
                $changes[] = $field;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillDatesAndYears(array $payload, AiImportBatch $batch, array &$changes): array
    {
        $year = $this->yearFrom($payload['annee_debut_pas'] ?? null)
            ?? $this->yearFrom($payload['exercice'] ?? null)
            ?? $batch->detected_year;

        if ($year !== null) {
            foreach (['annee_debut_pas', 'annee_fin_pas', 'exercice'] as $field) {
                if ($this->blank($payload[$field] ?? null)) {
                    $payload[$field] = $year;
                    $changes[] = $field;
                }
            }
        }

        if ($this->blank($payload['date_echeance_objectif_operationnel'] ?? null) && ! $this->blank($payload['date_fin_action'] ?? null)) {
            $payload['date_echeance_objectif_operationnel'] = $payload['date_fin_action'];
            $changes[] = 'date_echeance_objectif_operationnel';
        }

        if ($this->blank($payload['date_echeance_objectif_strategique'] ?? null)) {
            $fallback = $payload['date_echeance_objectif_operationnel'] ?? $payload['date_fin_action'] ?? null;
            if (! $this->blank($fallback)) {
                $payload['date_echeance_objectif_strategique'] = $fallback;
                $changes[] = 'date_echeance_objectif_strategique';
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillActionParameterization(array $payload, array &$changes): array
    {
        $parameterization = $this->parameterizer->parameterize($payload);
        foreach ([
            'type_action',
            'cible_minimum_execution',
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
            'commentaire_obligatoire',
            'champ_difficulte',
            'justification_type',
            'confidence_score',
        ] as $field) {
            $value = $parameterization[$field] ?? null;
            if ($field === 'sous_actions' && is_array($value)) {
                $value = $this->parameterizer->stringifySubActions($value);
            }

            if ($this->blank($payload[$field] ?? null) && ! $this->blank($value)) {
                $payload[$field] = $value;
                $changes[] = $field;
            }
        }

        $warnings = $parameterization['validation_warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $payload['validation_warnings'] = $this->appendNote($payload['validation_warnings'] ?? null, implode(' | ', $warnings));
            $changes[] = 'validation_warnings';
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillFinancing(array $payload, array &$changes): array
    {
        $hasFinancingDetail = ! $this->blank($payload['nature_financement'] ?? null)
            || ! $this->blank($payload['montant_financement'] ?? null)
            || ! $this->blank($payload['budget_previsionnel'] ?? null);

        if ($this->blank($payload['financement'] ?? null)) {
            $payload['financement'] = $hasFinancingDetail ? 1 : 0;
            $changes[] = 'financement';
        }

        if ((string) ($payload['financement'] ?? '') === '0') {
            foreach (['nature_financement', 'montant_financement'] as $field) {
                if (! array_key_exists($field, $payload)) {
                    $payload[$field] = null;
                }
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillRequiredGuideDefaults(array $payload, array &$changes): array
    {
        if ($this->blank($payload['justificatif_attendu'] ?? null) && ! $this->blank($payload['libelle_action'] ?? null)) {
            $payload['justificatif_attendu'] = 'Preuve de realisation de l action';
            $changes[] = 'justificatif_attendu';
        }

        foreach (['main_oeuvre', 'autres_ressources', 'mesures_preventives'] as $field) {
            if (! array_key_exists($field, $payload)) {
                $payload[$field] = null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    private function rememberContext(array $payload, array &$context): void
    {
        foreach ([
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
        ] as $field) {
            if (! $this->blank($payload[$field] ?? null)) {
                $context[$field] = $payload[$field];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function officialColumns(): array
    {
        try {
            $columns = $this->template->columns();

            return $columns === [] ? PlanningExcelImportService::IMPORT_COLUMNS : $columns;
        } catch (Throwable) {
            return PlanningExcelImportService::IMPORT_COLUMNS;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillBothWays(array $payload, string $left, string $right, array &$changes): array
    {
        if ($this->blank($payload[$left] ?? null) && ! $this->blank($payload[$right] ?? null)) {
            $payload[$left] = $payload[$right];
            $changes[] = $left;
        }

        if ($this->blank($payload[$right] ?? null) && ! $this->blank($payload[$left] ?? null)) {
            $payload[$right] = $payload[$left];
            $changes[] = $right;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function withCompletionNote(array $payload, array $changes): array
    {
        $changes = array_values(array_unique($changes));
        $note = 'Completion guide IMPORT_GLOBAL: '.implode(', ', $changes);
        $payload['validation_warnings'] = $this->appendNote($payload['validation_warnings'] ?? null, $note);

        return $payload;
    }

    private function appendNote(mixed $current, string $note): string
    {
        $current = trim((string) $current);

        return $current === '' ? $note : $current.' | '.$note;
    }

    private function yearFrom(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $year = (int) $value;

            return $year >= 2000 && $year <= 2100 ? $year : null;
        }

        if (preg_match('/(20[0-9]{2}|2100)/', (string) $value, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->every(fn (mixed $item): bool => $this->blank($item));
        }

        return trim((string) Arr::first(Arr::wrap($value), fn (mixed $item): bool => trim((string) $item) !== '', '')) === '';
    }
}
