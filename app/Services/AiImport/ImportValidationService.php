<?php

namespace App\Services\AiImport;

use App\Models\AiImportError;
use App\Models\AiImportRow;
use App\Models\AiImportSession;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Support\Str;

class ImportValidationService
{
    /**
     * @return array{total:int,ready:int,blocked:int,errors:int}
     */
    public function validateSession(AiImportSession $session): array
    {
        $session->errors()->delete();

        $stats = ['total' => 0, 'ready' => 0, 'blocked' => 0, 'errors' => 0];
        foreach ($session->rows()->get() as $row) {
            $stats['total']++;
            $issues = $this->issuesFor($row);
            $status = $this->statusFromIssues($row, $issues);
            $blocking = collect($issues)->contains(fn (array $issue): bool => $issue['gravity'] === AiImportError::GRAVITY_BLOCKING);

            foreach ($issues as $issue) {
                AiImportError::query()->create([
                    'ai_import_session_id' => $session->id,
                    'ai_import_row_id' => $row->id,
                    'gravity' => $issue['gravity'],
                    'field' => $issue['field'],
                    'message' => $issue['message'],
                    'suggestion' => $issue['suggestion'],
                ]);
            }

            $row->forceFill([
                'statut_import' => $status,
                'status' => $status === AiImportRow::IMPORT_READY ? AiImportRow::STATUS_VALID : AiImportRow::STATUS_INVALID,
                'errors_json' => $issues,
                'validation_errors' => [
                    'errors' => collect($issues)->where('gravity', AiImportError::GRAVITY_BLOCKING)->pluck('message')->values()->all(),
                    'warnings' => collect($issues)->where('gravity', '!=', AiImportError::GRAVITY_BLOCKING)->pluck('message')->values()->all(),
                ],
            ])->save();

            $stats['errors'] += count($issues);
            if ($status === AiImportRow::IMPORT_READY) {
                $stats['ready']++;
            }
            if ($blocking) {
                $stats['blocked']++;
            }
        }

        $session->forceFill([
            'status' => $stats['blocked'] > 0 ? AiImportSession::STATUS_REVIEW_REQUIRED : AiImportSession::STATUS_VALIDATED,
            'total_rows_detected' => $stats['total'],
            'total_rows_validated' => $stats['ready'],
            'total_errors' => $stats['errors'],
        ])->save();

        return $stats;
    }

    /**
     * @return list<array{gravity:string,field:string,message:string,suggestion:string}>
     */
    private function issuesFor(AiImportRow $row): array
    {
        $issues = [];

        if ($this->blank($row->action)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'action', 'Libelle action obligatoire.', 'Renseigner ou rejeter la ligne.');
        }

        if ($this->blank($row->date_fin)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'date_fin', 'Date de fin absente ou illisible.', 'Corriger la date avant import.');
        }

        if ($this->blank($row->direction) || ! $this->directionExists((string) $row->direction)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'direction', 'Direction introuvable dans le referentiel.', 'Rattacher une direction existante.');
        }

        if ($this->blank($row->service) || ! $this->serviceExists((string) $row->service, (string) $row->direction)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'service', 'Service introuvable ou incoherent avec la direction.', 'Rattacher un service existant.');
        }

        $type = (string) $row->type_indicateur;
        if (in_array($type, ['quantitatif', 'mixte'], true) && $row->quantite_a_realiser === null) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'quantite_a_realiser', 'Quantite obligatoire pour une action quantitative ou mixte.', 'Renseigner la quantite cible.');
        }

        if (in_array($type, ['non_quantitatif', 'mixte'], true) && $this->blank($row->livrable_attendu)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_BLOCKING, 'livrable_attendu', 'Livrable obligatoire pour une action non quantitative ou mixte.', 'Renseigner le livrable attendu.');
        }

        if ($this->blank($row->cible)) {
            $issues[] = $this->issue(AiImportError::GRAVITY_WARNING, 'cible', 'Cible non renseignee.', 'Completer la cible avant validation finale si elle est requise.');
        }

        return $issues;
    }

    /**
     * @param  list<array{gravity:string,field:string,message:string,suggestion:string}>  $issues
     */
    private function statusFromIssues(AiImportRow $row, array $issues): string
    {
        $blockingFields = collect($issues)
            ->where('gravity', AiImportError::GRAVITY_BLOCKING)
            ->pluck('field')
            ->all();

        if (in_array('action', $blockingFields, true)) {
            return AiImportRow::IMPORT_REJECTED;
        }

        if (in_array('date_fin', $blockingFields, true)) {
            return AiImportRow::IMPORT_DATE_ERROR;
        }

        if (in_array('direction', $blockingFields, true) || in_array('service', $blockingFields, true)) {
            return AiImportRow::IMPORT_ATTACHMENT_ERROR;
        }

        if (in_array('quantite_a_realiser', $blockingFields, true) || in_array('livrable_attendu', $blockingFields, true)) {
            return AiImportRow::IMPORT_PARAMETERIZE;
        }

        if ($issues !== [] || $row->statut_import === AiImportRow::IMPORT_VALIDATE) {
            return AiImportRow::IMPORT_VERIFY;
        }

        return AiImportRow::IMPORT_READY;
    }

    /**
     * @return array{gravity:string,field:string,message:string,suggestion:string}
     */
    private function issue(string $gravity, string $field, string $message, string $suggestion): array
    {
        return compact('gravity', 'field', 'message', 'suggestion');
    }

    private function directionExists(string $value): bool
    {
        $key = $this->key($value);
        if ($key === '') {
            return false;
        }

        return Direction::query()
            ->whereRaw('LOWER(code) = ?', [$key])
            ->orWhereRaw('LOWER(libelle) = ?', [$key])
            ->exists();
    }

    private function serviceExists(string $value, string $direction): bool
    {
        $key = $this->key($value);
        if ($key === '') {
            return false;
        }

        $query = Service::query()
            ->where(function ($builder) use ($key): void {
                $builder->whereRaw('LOWER(code) = ?', [$key])
                    ->orWhereRaw('LOWER(libelle) = ?', [$key]);
            });

        $directionKey = $this->key($direction);
        if ($directionKey !== '') {
            $query->whereHas('direction', function ($builder) use ($directionKey): void {
                $builder->whereRaw('LOWER(code) = ?', [$directionKey])
                    ->orWhereRaw('LOWER(libelle) = ?', [$directionKey]);
            });
        }

        return $query->exists();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }
}
