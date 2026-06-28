<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Support\Carbon;

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
}
