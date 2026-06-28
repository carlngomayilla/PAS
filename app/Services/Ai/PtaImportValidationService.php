<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PtaImportValidationService
{
    /**
     * @var list<string>
     */
    private const ALLOWED_STATUSES = ['non_demarre', 'en_cours', 'suspendu', 'termine', 'annule'];

    public function __construct(
        private readonly PtaReferenceResolver $references
    ) {}

    /**
     * @return array{total:int,valid:int,invalid:int,ignored:int}
     */
    public function validateBatch(AiImportBatch $batch): array
    {
        $batch->forceFill(['status' => AiImportBatch::STATUS_VALIDATING])->save();

        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'ignored' => 0];

        foreach ($batch->rows()->get() as $row) {
            $stats['total']++;
            if ($row->status === AiImportRow::STATUS_IGNORED) {
                $stats['ignored']++;

                continue;
            }

            $this->validateRow($row, $row->status === AiImportRow::STATUS_CORRECTED);
            $row->refresh();
            if ($row->status === AiImportRow::STATUS_INVALID) {
                $stats['invalid']++;
            } else {
                $stats['valid']++;
            }
        }

        $batch->forceFill([
            'status' => $stats['invalid'] > 0 ? AiImportBatch::STATUS_VALIDATING : AiImportBatch::STATUS_VALIDATED,
        ])->save();

        return $stats;
    }

    /**
     * @return array{errors:list<string>,warnings:list<string>}
     */
    public function validateRow(AiImportRow $row, bool $corrected = false): array
    {
        $validation = $this->validatePayload($row->normalized_payload ?? []);
        $row->forceFill([
            'validation_errors' => $validation,
            'status' => $validation['errors'] === []
                ? ($corrected ? AiImportRow::STATUS_CORRECTED : AiImportRow::STATUS_VALID)
                : AiImportRow::STATUS_INVALID,
        ])->save();

        return $validation;
    }

    public function hasBlockingErrors(AiImportBatch $batch): bool
    {
        return $batch->rows()
            ->where('status', AiImportRow::STATUS_INVALID)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{errors:list<string>,warnings:list<string>}
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];
        $warnings = [];

        if ($this->blank($payload['exercice'] ?? null) || $this->references->yearFrom($payload['exercice'] ?? null) === null) {
            $errors[] = 'Exercice obligatoire ou invalide.';
        }

        if ($this->blank($payload['libelle_action'] ?? null)) {
            $errors[] = 'Libelle action obligatoire.';
        }

        $direction = null;
        if ($this->blank($payload['direction'] ?? null)) {
            $errors[] = 'Direction obligatoire.';
        } else {
            $direction = $this->references->findDirection((string) $payload['direction']);
            if (! $direction instanceof Direction) {
                $errors[] = 'Direction introuvable dans le referentiel.';
            }
        }

        if ($this->blank($payload['service'] ?? null)) {
            $errors[] = 'Service obligatoire pour importer une action PTA.';
        } else {
            $service = $this->references->findService((string) $payload['service'], $direction);
            if (! $service instanceof Service) {
                $errors[] = 'Service introuvable dans le referentiel.';
            }
        }

        if ($this->blank($payload['date_fin'] ?? null) && $this->blank($payload['echeance'] ?? null)) {
            $errors[] = 'Date de fin ou echeance obligatoire.';
        }

        foreach (['date_debut', 'date_fin', 'echeance'] as $field) {
            if (! $this->blank($payload[$field] ?? null) && $this->parseDate($payload[$field]) === null) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)).' invalide.';
            }
        }

        if (! $this->blank($payload['budget_previsionnel'] ?? null) && ! is_numeric($payload['budget_previsionnel'])) {
            $errors[] = 'Budget previsionnel numerique attendu.';
        }

        $status = trim((string) ($payload['statut_initial'] ?? 'non_demarre'));
        if ($status !== '' && ! in_array($status, self::ALLOWED_STATUSES, true)) {
            $errors[] = 'Statut initial non autorise.';
        }

        if ($this->blank($payload['indicateur'] ?? null)) {
            $warnings[] = 'Indicateur recommande.';
        }

        if ($this->blank($payload['cible'] ?? null)) {
            $warnings[] = 'Cible recommandee.';
        }

        $this->validateParameterization($payload, $errors, $warnings);

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateParameterization(array $payload, array &$errors, array &$warnings): void
    {
        $type = $this->typeCode($payload['type_action'] ?? null);
        if ($type === null) {
            $warnings[] = 'Type action IA recommande avant import final.';

            return;
        }

        if ($type === 'Q') {
            if (! is_numeric($payload['quantite_cible'] ?? null) || (float) $payload['quantite_cible'] <= 0) {
                $errors[] = 'Quantite cible numerique obligatoire lorsque type_action = Q.';
            }

            if ($this->blank($payload['unite_cible'] ?? $payload['unite'] ?? null)) {
                $errors[] = 'Unite cible obligatoire lorsque type_action = Q.';
            }
        }

        if ($type === 'M' && $this->parseSubActions((string) ($payload['sous_actions'] ?? '')) === []) {
            $errors[] = 'Sous-actions obligatoires lorsque type_action = M.';
        }

        $mode = $this->key((string) ($payload['seuil_mode'] ?? ''));
        if ($mode !== '' && ! in_array($mode, ['unique', 'trimestriel'], true)) {
            $errors[] = 'Seuil mode non autorise.';
        }

        if ($mode === 'trimestriel') {
            foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $field) {
                if (! is_numeric($payload[$field] ?? null) || (float) $payload[$field] < 0 || (float) $payload[$field] > 100) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)).' doit etre compris entre 0 et 100.';
                }
            }
        }

        $risk = $this->key((string) ($payload['niveau_risque'] ?? ''));
        if ($risk !== '' && ! in_array($risk, ['faible', 'modere', 'eleve', 'critique'], true)) {
            $errors[] = 'Niveau risque non autorise.';
        }
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

    /**
     * @return list<string>
     */
    private function parseSubActions(string $value): array
    {
        return collect(explode(';', $value))
            ->map(fn (string $chunk): string => trim(explode('|', $chunk)[0] ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
