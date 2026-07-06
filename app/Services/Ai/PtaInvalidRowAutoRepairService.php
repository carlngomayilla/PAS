<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PtaInvalidRowAutoRepairService
{
    public function __construct(
        private readonly PtaImportValidationService $validation
    ) {}

    /**
     * @return array{rows:int,fields:int}
     */
    public function repair(AiImportBatch $batch): array
    {
        $rows = 0;
        $fields = 0;

        foreach ($batch->rows()->where('status', AiImportRow::STATUS_INVALID)->get() as $row) {
            $payload = $row->normalized_payload ?? [];
            if ($payload === []) {
                continue;
            }

            $changes = [];
            $repaired = $this->repairPayload($payload, $batch, $changes);
            if ($changes === []) {
                continue;
            }

            $row->forceFill([
                'normalized_payload' => $this->withRepairNote($repaired, $changes),
                'status' => AiImportRow::STATUS_CORRECTED,
            ])->save();

            $rows++;
            $fields += count($changes);
        }

        return ['rows' => $rows, 'fields' => $fields];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function repairPayload(array $payload, AiImportBatch $batch, array &$changes): array
    {
        $originalPayload = $payload;
        $year = $this->yearFrom($payload, $batch);

        foreach ($this->dateFields() as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $repaired = $this->repairDateField($payload, $field, $year);
            if ($repaired !== null && $repaired !== $payload[$field]) {
                $payload[$field] = $repaired;
                $changes[] = $field;
            }
        }

        $payload = $this->fillEndDateFromEmbeddedRange($payload, $originalPayload, $changes, $year);
        $payload = $this->syncDateAliases($payload, $changes, $year);
        $payload = $this->fillMissingEndDateFromStart($payload, $changes, $year);

        foreach (['budget_previsionnel', 'montant_financement'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $number = $this->repairNumber($payload[$field]);
            if ($number !== null && $number !== $payload[$field]) {
                $payload[$field] = $number;
                $changes[] = $field;
            }
        }

        $payload = $this->repairQuantitativeAction($payload, $changes);
        $payload = $this->repairStatus($payload, $changes);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function syncDateAliases(array $payload, array &$changes, ?int $year): array
    {
        foreach ([
            ['date_debut', 'date_debut_action'],
            ['date_fin', 'date_fin_action'],
            ['echeance', 'date_echeance_objectif_operationnel'],
        ] as [$generic, $official]) {
            $genericDate = $this->repairDateField($payload, $generic, $year);
            $officialDate = $this->repairDateField($payload, $official, $year);
            $date = $officialDate ?? $genericDate;

            if ($date === null) {
                continue;
            }

            foreach ([$generic, $official] as $field) {
                if (($payload[$field] ?? null) !== $date) {
                    $payload[$field] = $date;
                    $changes[] = $field;
                }
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $originalPayload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillEndDateFromEmbeddedRange(array $payload, array $originalPayload, array &$changes, ?int $year): array
    {
        $source = $originalPayload['date_debut_action'] ?? $originalPayload['date_debut'] ?? null;
        if ($this->blank($source)) {
            return $payload;
        }

        $dates = $this->repairDateCandidates($source, $year, null, 'date_debut_action');
        if (count($dates) < 2) {
            return $payload;
        }

        foreach (['date_fin_action', 'date_fin', 'date_echeance_objectif_operationnel', 'echeance'] as $field) {
            $current = $this->repairDateField($payload, $field, $year);
            if ($current !== null) {
                continue;
            }

            $payload[$field] = $dates[1];
            $changes[] = $field;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function fillMissingEndDateFromStart(array $payload, array &$changes, ?int $year): array
    {
        $endDate = $this->repairDateField($payload, 'date_fin_action', $year)
            ?? $this->repairDateField($payload, 'date_fin', $year)
            ?? $this->repairDateField($payload, 'date_echeance_objectif_operationnel', $year)
            ?? $this->repairDateField($payload, 'echeance', $year);

        if ($endDate !== null) {
            foreach (['date_fin_action', 'date_fin', 'date_echeance_objectif_operationnel', 'echeance'] as $field) {
                if ($this->repairDateField($payload, $field, $year) === null) {
                    $payload[$field] = $endDate;
                    $changes[] = $field;
                }
            }

            return $payload;
        }

        $startDate = $this->repairDateField($payload, 'date_debut_action', $year)
            ?? $this->repairDateField($payload, 'date_debut', $year);

        if ($startDate === null || ! $this->looksLikeSingleDayAction($payload)) {
            return $payload;
        }

        foreach (['date_fin_action', 'date_fin', 'date_echeance_objectif_operationnel', 'echeance'] as $field) {
            $payload[$field] = $startDate;
            $changes[] = $field;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function repairQuantitativeAction(array $payload, array &$changes): array
    {
        if ($this->typeCode($payload['type_action'] ?? null) !== 'Q') {
            return $payload;
        }

        $target = $this->repairNumber($payload['quantite_cible'] ?? null)
            ?? $this->repairNumber($payload['cible_minimum_execution'] ?? null)
            ?? $this->repairNumber($payload['cible'] ?? null);

        if ($target !== null && ! is_numeric($payload['quantite_cible'] ?? null)) {
            $payload['quantite_cible'] = $target;
            $changes[] = 'quantite_cible';
        }

        if ($this->blank($payload['unite_cible'] ?? null)) {
            $payload['unite_cible'] = $this->blank($payload['unite'] ?? null) ? '%' : $payload['unite'];
            $changes[] = 'unite_cible';
        }

        if ($this->blank($payload['unite'] ?? null) && ! $this->blank($payload['unite_cible'] ?? null)) {
            $payload['unite'] = $payload['unite_cible'];
            $changes[] = 'unite';
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function repairStatus(array $payload, array &$changes): array
    {
        if ($this->blank($payload['statut_initial'] ?? null)) {
            return $payload;
        }

        $status = match ($this->key((string) $payload['statut_initial'])) {
            'en cours', 'encours', 'demarre', 'demarree' => 'en_cours',
            'termine', 'terminee', 'cloture', 'cloturee', 'acheve' => 'termine',
            'suspendu', 'suspendue', 'bloque', 'bloquee' => 'suspendu',
            'annule', 'annulee' => 'annule',
            default => 'non_demarre',
        };

        if ($status !== $payload['statut_initial']) {
            $payload['statut_initial'] = $status;
            $changes[] = 'statut_initial';
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function repairDateField(array $payload, string $field, ?int $year): ?string
    {
        $value = $payload[$field] ?? null;
        $fallbackMonth = $this->fallbackMonthFor($payload, $field, $year);

        return $this->repairDate($value, $year, $fallbackMonth, $field);
    }

    private function repairDate(mixed $value, ?int $year, ?int $fallbackMonth = null, ?string $field = null): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        $parsed = $this->validation->parseDate($value);
        if ($parsed !== null) {
            return $parsed->toDateString();
        }

        if ($year !== null && $this->isEndDateField($field) && $this->containsFrequency((string) $value)) {
            return sprintf('%04d-12-31', $year);
        }

        $candidates = $this->repairDateCandidates($value, $year, $fallbackMonth, $field);

        return $candidates[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function repairDateCandidates(mixed $value, ?int $year, ?int $fallbackMonth = null, ?string $field = null): array
    {
        if ($this->blank($value)) {
            return [];
        }

        $normalized = $this->normalizeOcrDateText((string) $value);
        $dates = [];

        preg_match_all('/([0-9a-z]{1,4})\s*[\/.\-]\s*([0-9a-z]{1,4})(?:\s*[\/.\-]\s*([0-9a-z]{0,4}))?/i', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $date = $this->dateFromTokens(
                (string) ($match[1] ?? ''),
                (string) ($match[2] ?? ''),
                (string) ($match[3] ?? ''),
                $year,
                $fallbackMonth
            );

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        if ($dates !== []) {
            return array_values(array_unique($dates));
        }

        preg_match_all('/\d+/', $normalized, $numberMatches);
        $parts = $numberMatches[0] ?? [];
        if (count($parts) >= 3) {
            $date = $this->dateFromTokens($parts[0], $parts[1], $parts[2], $year, $fallbackMonth);

            return $date === null ? [] : [$date];
        }

        if (count($parts) === 2) {
            $date = $this->dateFromTokens($parts[0], $parts[1], '', $year, $fallbackMonth);

            return $date === null ? [] : [$date];
        }

        if (count($parts) === 1 && $year !== null && $this->isEndDateField($field)) {
            $month = (int) $parts[0];
            if ($month >= 1 && $month <= 12) {
                return [$this->lastDayOfMonth($year, $month)];
            }
        }

        return [];
    }

    private function normalizeOcrDateText(string $value): string
    {
        return strtr(Str::ascii($value), [
            'O' => '0',
            'o' => '0',
            'I' => '1',
            'l' => '1',
            '|' => '1',
            'S' => '5',
            's' => '5',
            'B' => '8',
            'Z' => '2',
            'z' => '2',
            'E' => '5',
            'e' => '5',
        ]);
    }

    private function dateFromTokens(string $dayToken, string $monthToken, string $yearToken, ?int $fallbackYear, ?int $fallbackMonth): ?string
    {
        $day = $this->ocrNumberToken($dayToken, 'day', null);
        $month = $this->ocrNumberToken($monthToken, 'month', $fallbackMonth);
        $detectedYear = $this->ocrNumberToken($yearToken, 'year', $fallbackYear);

        if ($day === null || $month === null || $detectedYear === null) {
            return null;
        }

        $dateYear = $this->normalizeYear($detectedYear, strlen(preg_replace('/\D+/', '', $yearToken) ?? ''), $fallbackYear);

        if ($dateYear === null || ! checkdate($month, $day, $dateYear)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $dateYear, $month, $day);
    }

    private function ocrNumberToken(string $token, string $position, ?int $fallback): ?int
    {
        $token = strtolower(trim($token));
        if ($token === '') {
            return $fallback;
        }

        $mapped = [
            'ao' => '20',
            'a0' => '20',
            'za' => '20',
            'z0' => '20',
            'oa' => '02',
            '0a' => '02',
            'ca' => $position === 'month' && $fallback !== null ? (string) $fallback : '02',
            '0e' => '05',
            'oe' => '05',
        ][$token] ?? null;

        $digits = $mapped ?? preg_replace('/\D+/', '', $token);
        if ($digits === '') {
            return $fallback;
        }

        return (int) $digits;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function fallbackMonthFor(array $payload, string $field, ?int $year): ?int
    {
        foreach ($this->siblingDateFields($field) as $candidateField) {
            $date = $this->repairDate($payload[$candidateField] ?? null, $year, null, $candidateField);
            if ($date === null) {
                continue;
            }

            return (int) substr($date, 5, 2);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function siblingDateFields(string $field): array
    {
        return match ($field) {
            'date_debut', 'date_debut_action' => ['date_fin_action', 'date_fin', 'echeance', 'date_echeance_objectif_operationnel'],
            'date_fin', 'date_fin_action', 'echeance', 'date_echeance_objectif_operationnel' => ['date_debut_action', 'date_debut'],
            default => ['date_fin_action', 'date_fin', 'date_debut_action', 'date_debut'],
        };
    }

    private function isEndDateField(?string $field): bool
    {
        return in_array($field, ['date_fin', 'date_fin_action', 'echeance', 'date_echeance_objectif_operationnel', 'date_echeance_objectif_strategique'], true);
    }

    private function containsFrequency(string $value): bool
    {
        $key = $this->key($value);

        return str_contains($key, 'trimestr')
            || str_contains($key, 'annuel')
            || str_contains($key, 'fil de l eau');
    }

    private function lastDayOfMonth(int $year, int $month): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function looksLikeSingleDayAction(array $payload): bool
    {
        $type = $this->typeCode($payload['type_action'] ?? null);
        $action = $this->key((string) ($payload['libelle_action'] ?? ''));

        return $type === 'NQ'
            || str_contains($action, 'presentation')
            || str_contains($action, 'participer')
            || str_contains($action, 'expression de besoin')
            || str_contains($action, 'fiche')
            || str_contains($action, 'rapport');
    }

    private function repairNumber(mixed $value): float|int|null
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return $this->normalizeNumber((float) $value);
        }

        $normalized = strtr(Str::ascii((string) $value), [
            'O' => '0',
            'o' => '0',
            'I' => '1',
            'l' => '1',
            '|' => '1',
            'S' => '5',
            's' => '5',
            'B' => '8',
        ]);
        $normalized = preg_replace('/[^0-9,.\-]+/', '', $normalized) ?? '';
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return $this->normalizeNumber((float) $normalized);
    }

    private function normalizeNumber(float $value): float|int
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    private function normalizeYear(int $value, int $length, ?int $fallback): ?int
    {
        if ($length === 4) {
            return $value >= 2000 && $value <= 2100 ? $value : null;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        if ($length === 2) {
            $year = 2000 + $value;

            return $year >= 2000 && $year <= 2100 ? $year : null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function withRepairNote(array $payload, array $changes): array
    {
        $changes = array_values(array_unique($changes));
        $note = 'Autocorrection OCR: '.implode(', ', $changes);
        $current = trim((string) ($payload['validation_warnings'] ?? ''));
        $payload['validation_warnings'] = $current === '' ? $note : $current.' | '.$note;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function yearFrom(array $payload, AiImportBatch $batch): ?int
    {
        foreach ([
            $payload['exercice'] ?? null,
            $payload['annee_debut_pas'] ?? null,
            $payload['annee_fin_pas'] ?? null,
            $batch->detected_year,
        ] as $value) {
            if (is_numeric($value)) {
                $year = (int) $value;
                if ($year >= 2000 && $year <= 2100) {
                    return $year;
                }
            }

            if (preg_match('/(20[0-9]{2}|2100)/', (string) $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function dateFields(): array
    {
        return [
            'date_debut',
            'date_fin',
            'echeance',
            'date_debut_action',
            'date_fin_action',
            'date_echeance_objectif_operationnel',
            'date_echeance_objectif_strategique',
        ];
    }

    private function typeCode(mixed $value): ?string
    {
        return match ($this->key((string) $value)) {
            'q', 'quantitative', 'quantitatif' => 'Q',
            'nq', 'non quantitative', 'non quantitatif', 'nonquantitative' => 'NQ',
            'm', 'mixte', 'composee', 'compose', 'composite', 'sous actions' => 'M',
            default => null,
        };
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function blank(mixed $value): bool
    {
        return trim((string) Arr::first(Arr::wrap($value), fn (mixed $item): bool => trim((string) $item) !== '', '')) === '';
    }
}
