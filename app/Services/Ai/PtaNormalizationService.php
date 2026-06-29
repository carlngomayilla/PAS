<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\PtaImportMapping;
use Illuminate\Support\Str;

class PtaNormalizationService
{
    public function __construct(
        private readonly PtaActionParameterizationService $parameterizer
    ) {}

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
        'ressources_requises',
        'risques_potentiels',
        'type_action',
        'justification_type',
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
        'validation_warnings',
        'confidence_score',
        'observations',
        'pta_code',
        'pta_id',
        'annee_debut_pas',
        'annee_fin_pas',
        'ordre_axe',
        'libelle_axe',
        'ordre_objectif_strategique',
        'libelle_objectif_strategique',
        'date_echeance_objectif_strategique',
        'service_unite',
        'ordre_objectif_operationnel',
        'libelle_objectif_operationnel',
        'date_echeance_objectif_operationnel',
        'ordre_action',
        'date_debut_action',
        'date_fin_action',
        'codes_agents_rmo',
        'justificatif_attendu',
        'risque',
        'mesures_preventives',
        'ressources_materielles',
        'main_oeuvre',
        'autres_ressources',
        'financement',
        'nature_financement',
        'montant_financement',
        'rmo_raw',
        'etat_realisation_initial',
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
        'libelle_action' => ['libelle action', 'action', 'intitule action', 'libelle', 'activite', 'description des actions detaillees', 'actions detaillees'],
        'description_action' => ['description action', 'description', 'details', 'detail action'],
        'direction' => ['direction', 'departement', 'structure direction'],
        'service' => ['service', 'unite', 'cellule', 'structure service'],
        'responsable' => ['responsable', 'rmo', 'pilote', 'owner', 'codes agents rmo', 'code agent rmo'],
        'partenaires' => ['partenaires', 'partenaire', 'acteurs impliques'],
        'indicateur' => ['indicateur', 'kpi', 'indicateur de performance', 'indicateurs de performance'],
        'cible' => ['cible', 'valeur cible', 'quantite cible'],
        'unite' => ['unite', 'unite cible', 'unite de mesure'],
        'budget_previsionnel' => ['budget previsionnel', 'budget', 'cout', 'montant estime'],
        'source_financement' => ['source financement', 'source de financement', 'financement'],
        'date_debut' => ['date debut', 'debut', 'date de debut', 'date debut action'],
        'date_fin' => ['date fin', 'fin', 'date de fin', 'date fin action'],
        'echeance' => ['echeance', 'deadline', 'date echeance'],
        'priorite' => ['priorite', 'niveau priorite'],
        'statut_initial' => ['statut initial', 'statut', 'etat', 'etat de realisation'],
        'livrables_attendus' => ['livrables attendus', 'livrable', 'resultats attendus'],
        'ressources_requises' => ['ressources requises', 'ressources', 'moyens requis'],
        'risques_potentiels' => ['risques potentiels', 'risques', 'risque'],
        'type_action' => ['type action', 'type_action', 'type propose'],
        'justification_type' => ['justification ia', 'justification type', 'justification_type'],
        'cible_minimum_execution' => ['cible minimum execution', 'cible minimum', 'seuil minimum'],
        'quantite_cible' => ['quantite cible', 'quantite_cible'],
        'unite_cible' => ['unite cible', 'unite_cible'],
        'seuil_mode' => ['seuil mode', 'mode seuil', 'seuil propose'],
        'seuil_t1' => ['seuil t1', 'seuil_t1'],
        'seuil_t2' => ['seuil t2', 'seuil_t2'],
        'seuil_t3' => ['seuil t3', 'seuil_t3'],
        'seuil_t4' => ['seuil t4', 'seuil_t4'],
        'nombre_sous_actions' => ['nombre sous actions', 'nombre_sous_actions'],
        'sous_actions' => ['sous actions', 'sous_actions', 'sous-actions proposees'],
        'niveau_risque' => ['niveau risque', 'niveau_risque', 'risque propose'],
        'commentaire_obligatoire' => ['commentaire obligatoire', 'commentaire_obligatoire'],
        'champ_difficulte' => ['champ difficulte', 'champ_difficulte'],
        'validation_warnings' => ['alerte validation', 'alertes validation', 'validation warnings'],
        'confidence_score' => ['score confiance', 'score de confiance', 'confidence score'],
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

        $normalized = $this->syncOfficialAndGenericFields($normalized);

        $parameterization = $this->parameterizer->parameterize($normalized);
        foreach ($parameterization as $field => $value) {
            if (! array_key_exists($field, $normalized) || ! $this->blank($normalized[$field] ?? null)) {
                continue;
            }

            $normalized[$field] = $this->normalizeValue($field, $this->formatParameterValue($field, $value));
        }

        $normalized = $this->syncOfficialAndGenericFields($normalized);

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function normalizeManualPayload(array $payload): array
    {
        $normalized = array_fill_keys(self::FIELDS, null);

        foreach ($payload as $field => $value) {
            if (! in_array((string) $field, self::FIELDS, true)) {
                continue;
            }

            $normalized[(string) $field] = $this->normalizeValue((string) $field, $value);
        }

        $normalized = $this->syncOfficialAndGenericFields($normalized);

        $parameterization = $this->parameterizer->parameterize($normalized);
        foreach ($parameterization as $field => $value) {
            if (! array_key_exists($field, $normalized) || ! $this->blank($normalized[$field] ?? null)) {
                continue;
            }

            $normalized[$field] = $this->normalizeValue($field, $this->formatParameterValue($field, $value));
        }

        return $this->syncOfficialAndGenericFields($normalized);
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

        if (in_array($field, ['budget_previsionnel', 'montant_financement'], true)) {
            $number = str_replace([' ', "\u{00A0}"], '', (string) $value);
            $number = str_replace(',', '.', $number);

            return is_numeric($number) ? (float) $number : $value;
        }

        if ($field === 'statut_initial') {
            return $this->normalizeStatus((string) $value);
        }

        if (in_array($field, [
            'cible_minimum_execution',
            'quantite_cible',
            'seuil_t1',
            'seuil_t2',
            'seuil_t3',
            'seuil_t4',
            'confidence_score',
        ], true)) {
            $number = str_replace(['%', ' ', "\u{00A0}"], '', (string) $value);
            $number = str_replace(',', '.', $number);

            return is_numeric($number) ? (float) $number : $value;
        }

        if ($field === 'nombre_sous_actions') {
            return is_numeric($value) ? (int) $value : $value;
        }

        if (in_array($field, ['financement', 'commentaire_obligatoire', 'champ_difficulte'], true)) {
            return in_array((string) $value, ['1', 'oui', 'Oui', 'true', 'vrai'], true) ? 1 : 0;
        }

        if ($field === 'type_action') {
            return strtoupper((string) $value);
        }

        if ($field === 'niveau_risque') {
            return match ($this->key((string) $value)) {
                'modere' => 'modere',
                'eleve' => 'eleve',
                'critique' => 'critique',
                default => 'faible',
            };
        }

        return $value;
    }

    private function formatParameterValue(string $field, mixed $value): mixed
    {
        if ($field === 'sous_actions' && is_array($value)) {
            return $this->parameterizer->stringifySubActions($value);
        }

        if ($field === 'validation_warnings' && is_array($value)) {
            return implode(' | ', array_map(static fn (mixed $warning): string => trim((string) $warning), $value));
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

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function syncOfficialAndGenericFields(array $normalized): array
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
            ['statut_initial', 'etat_realisation_initial'],
        ] as [$generic, $official]) {
            $normalized = $this->fillBothWays($normalized, $generic, $official);
        }

        if ($this->blank($normalized['annee_fin_pas'] ?? null) && ! $this->blank($normalized['annee_debut_pas'] ?? null)) {
            $normalized['annee_fin_pas'] = $normalized['annee_debut_pas'];
        }

        if ($this->blank($normalized['responsable'] ?? null) && ! $this->blank($normalized['rmo_raw'] ?? null)) {
            $normalized['responsable'] = $normalized['rmo_raw'];
        }

        if ($this->blank($normalized['responsable'] ?? null) && ! $this->blank($normalized['codes_agents_rmo'] ?? null)) {
            $normalized['responsable'] = $normalized['codes_agents_rmo'];
        }

        if ($this->blank($normalized['rmo_raw'] ?? null) && ! $this->blank($normalized['codes_agents_rmo'] ?? null)) {
            $normalized['rmo_raw'] = $normalized['codes_agents_rmo'];
        }

        if ($this->blank($normalized['codes_agents_rmo'] ?? null) && ! $this->blank($normalized['responsable'] ?? null)) {
            $normalized['codes_agents_rmo'] = $normalized['responsable'];
        }

        if ($this->blank($normalized['date_fin_action'] ?? null) && ! $this->blank($normalized['date_fin'] ?? null)) {
            $normalized['date_fin_action'] = $normalized['date_fin'];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function fillBothWays(array $normalized, string $left, string $right): array
    {
        if ($this->blank($normalized[$left] ?? null) && ! $this->blank($normalized[$right] ?? null)) {
            $normalized[$left] = $normalized[$right];
        }

        if ($this->blank($normalized[$right] ?? null) && ! $this->blank($normalized[$left] ?? null)) {
            $normalized[$right] = $normalized[$left];
        }

        return $normalized;
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
