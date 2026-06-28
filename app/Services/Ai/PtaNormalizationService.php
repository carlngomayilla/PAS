<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\PtaImportMapping;
use Illuminate\Support\Str;

class PtaNormalizationService
{
    /**
     * @var list<string>
     */
    public const FIELDS = [
        'exercice',
        'axe_strategique',
        'objectif_strategique',
        'programme',
        'code_action',
        'libelle_action',
        'description_action',
        'direction',
        'service',
        'responsable',
        'partenaires',
        'indicateur',
        'cible',
        'unite',
        'budget_previsionnel',
        'source_financement',
        'date_debut',
        'date_fin',
        'echeance',
        'priorite',
        'statut_initial',
        'livrables_attendus',
        'observations',
        'pta_code',
        'pta_id',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'exercice' => ['exercice', 'annee', 'annee exercice', 'year'],
        'axe_strategique' => ['axe strategique', 'axe', 'axe pas'],
        'objectif_strategique' => ['objectif strategique', 'objectif pas', 'os'],
        'programme' => ['programme', 'projet', 'composante'],
        'code_action' => ['code action', 'code', 'reference', 'ref action'],
        'libelle_action' => ['libelle action', 'action', 'intitule action', 'libelle', 'activite'],
        'description_action' => ['description action', 'description', 'details', 'detail action'],
        'direction' => ['direction', 'departement', 'structure direction'],
        'service' => ['service', 'unite', 'cellule', 'structure service'],
        'responsable' => ['responsable', 'rmo', 'pilote', 'owner'],
        'partenaires' => ['partenaires', 'partenaire', 'acteurs impliques'],
        'indicateur' => ['indicateur', 'kpi', 'indicateur de performance'],
        'cible' => ['cible', 'valeur cible', 'quantite cible'],
        'unite' => ['unite', 'unite cible', 'unite de mesure'],
        'budget_previsionnel' => ['budget previsionnel', 'budget', 'cout', 'montant estime'],
        'source_financement' => ['source financement', 'source de financement', 'financement'],
        'date_debut' => ['date debut', 'debut', 'date de debut'],
        'date_fin' => ['date fin', 'fin', 'date de fin'],
        'echeance' => ['echeance', 'deadline', 'date echeance'],
        'priorite' => ['priorite', 'niveau priorite'],
        'statut_initial' => ['statut initial', 'statut', 'etat'],
        'livrables_attendus' => ['livrables attendus', 'livrable', 'resultats attendus'],
        'observations' => ['observations', 'commentaires', 'commentaire', 'notes'],
        'pta_code' => ['pta code', 'code pta', 'pta'],
        'pta_id' => ['pta id', 'id pta'],
    ];

    /**
     * @return array{rows:int,confidence:float}
     */
    public function normalize(AiImportBatch $batch): array
    {
        $batch->mappings()->delete();
        $rows = $batch->rows()->get();
        $mappedColumns = [];

        foreach ($rows as $row) {
            $normalized = $this->normalizePayload($row->raw_payload ?? [], $mappedColumns);
            $row->forceFill([
                'normalized_payload' => $normalized,
                'status' => AiImportRow::STATUS_PENDING,
            ])->save();
        }

        foreach ($mappedColumns as $source => $target) {
            PtaImportMapping::query()->firstOrCreate([
                'batch_id' => $batch->id,
                'source_column' => $source,
                'target_field' => $target,
            ], [
                'confidence_score' => 80,
                'is_confirmed' => false,
            ]);
        }

        $confidence = $this->confidenceScore($rows->count(), count($mappedColumns));
        $batch->forceFill([
            'status' => AiImportBatch::STATUS_MAPPED,
            'confidence_score' => max((float) ($batch->confidence_score ?? 0), $confidence),
        ])->save();

        return ['rows' => $rows->count(), 'confidence' => $confidence];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $mappedColumns
     * @return array<string, mixed>
     */
    public function normalizePayload(array $raw, array &$mappedColumns = []): array
    {
        $normalized = array_fill_keys(self::FIELDS, null);

        foreach ($raw as $source => $value) {
            $target = $this->targetForHeader((string) $source);
            if ($target === null) {
                continue;
            }

            $mappedColumns[(string) $source] = $target;
            $normalized[$target] = $this->normalizeValue($target, $value);
        }

        return $normalized;
    }

    public function targetForHeader(string $header): ?string
    {
        $normalizedHeader = $this->key($header);

        foreach (self::ALIASES as $target => $aliases) {
            if ($normalizedHeader === $this->key($target)) {
                return $target;
            }

            foreach ($aliases as $alias) {
                if ($normalizedHeader === $this->key($alias)) {
                    return $target;
                }
            }
        }

        return null;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === '') {
            return null;
        }

        if ($field === 'budget_previsionnel') {
            $number = str_replace([' ', "\u{00A0}"], '', (string) $value);
            $number = str_replace(',', '.', $number);

            return is_numeric($number) ? (float) $number : $value;
        }

        if ($field === 'statut_initial') {
            return $this->normalizeStatus((string) $value);
        }

        return $value;
    }

    private function normalizeStatus(string $status): string
    {
        $key = $this->key($status);

        return match ($key) {
            'en cours', 'encours', 'demarre', 'demarree' => 'en_cours',
            'termine', 'terminee', 'cloture', 'cloturee', 'acheve' => 'termine',
            'suspendu', 'suspendue', 'bloque', 'bloquee' => 'suspendu',
            'annule', 'annulee' => 'annule',
            default => 'non_demarre',
        };
    }

    private function confidenceScore(int $rowCount, int $mappedColumns): float
    {
        if ($rowCount < 1) {
            return 0.0;
        }

        return round(min(95, max(35, ($mappedColumns / max(1, count(self::FIELDS) - 2)) * 100)), 2);
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
