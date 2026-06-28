<?php

namespace App\Exports;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaActionParameterizationService;
use App\Services\Ai\PtaAgentResolverService;
use App\Services\Ai\PtaImportQualityControlService;
use App\Services\Imports\PlanningExcelImportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Throwable;

class PtaNormalizedWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly AiImportBatch $batch
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new ArraySheetExport('IMPORT_GLOBAL', $this->importGlobalRows()),
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function importGlobalRows(): array
    {
        $headers = $this->importGlobalColumns();

        $rows = [$headers];
        foreach ($this->batch->rows()->get() as $row) {
            $payload = $row->normalized_payload ?? [];
            $importGlobal = $this->payloadToImportGlobal($payload);
            $rows[] = array_map(static fn (string $column): mixed => $importGlobal[$column] ?? null, $headers);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function importGlobalColumns(): array
    {
        return PlanningExcelImportService::IMPORT_COLUMNS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function payloadToImportGlobal(array $payload): array
    {
        $yearStart = $this->yearFrom($this->first($payload, ['annee_debut_pas', 'exercice'], $this->batch->detected_year));
        $yearEnd = $this->yearFrom($this->first($payload, ['annee_fin_pas'], $yearStart)) ?? $yearStart;
        $dates = app(PtaImportQualityControlService::class);
        $dateDebutRaw = $this->first($payload, ['date_debut_action', 'date_debut']);
        $dateFinRaw = $this->first($payload, ['date_fin_action', 'date_fin', 'echeance']);
        $dateDebut = $dates->normalizeDate($dateDebutRaw)?->format('Y-m-d') ?? $dateDebutRaw;
        $dateFin = $dates->normalizeDate($dateFinRaw)?->format('Y-m-d') ?? $dateFinRaw;
        $dateEcheanceStrategique = $this->normalizedDate($this->first($payload, ['date_echeance_objectif_strategique']));
        $dateEcheanceOperationnel = $this->normalizedDate($this->first($payload, ['date_echeance_objectif_operationnel', 'echeance']));
        $parameterizer = app(PtaActionParameterizationService::class);
        $parameterization = $parameterizer->parameterize(array_merge($payload, [
            'date_debut_action' => $dateDebut,
            'date_fin_action' => $dateFin,
            'indicateur' => $this->first($payload, ['livrables_attendus', 'justificatif_attendu', 'indicateur']),
            'risque' => $this->first($payload, ['risque', 'risques_potentiels']),
            'ressources_requises' => $this->first($payload, ['ressources_materielles', 'ressources_requises']),
        ]));

        return [
            'annee_debut_pas' => $yearStart,
            'annee_fin_pas' => $yearEnd,
            'ordre_axe' => $this->first($payload, ['ordre_axe']),
            'libelle_axe' => $this->first($payload, ['libelle_axe', 'axe_strategique']),
            'ordre_objectif_strategique' => $this->first($payload, ['ordre_objectif_strategique']),
            'libelle_objectif_strategique' => $this->first($payload, ['libelle_objectif_strategique', 'objectif_strategique']),
            'date_echeance_objectif_strategique' => $dateEcheanceStrategique,
            'direction' => $this->first($payload, ['direction'], $this->batch->detected_direction),
            'service_unite' => $this->first($payload, ['service_unite', 'service'], $this->batch->detected_service),
            'ordre_objectif_operationnel' => $this->first($payload, ['ordre_objectif_operationnel']),
            'libelle_objectif_operationnel' => $this->first($payload, ['libelle_objectif_operationnel', 'programme']),
            'date_echeance_objectif_operationnel' => $dateEcheanceOperationnel,
            'ordre_action' => $this->first($payload, ['ordre_action', 'code_action']),
            'libelle_action' => $this->first($payload, ['libelle_action', 'description_action']),
            'date_debut_action' => $dateDebut,
            'date_fin_action' => $dateFin,
            'codes_agents_rmo' => $this->resolveAgentCodes($payload),
            'cible_minimum_execution' => $this->first($payload, ['cible_minimum_execution']) ?? $parameterization['cible_minimum_execution'] ?? $this->percent($payload['cible'] ?? null),
            'justificatif_attendu' => $this->first($payload, ['justificatif_attendu', 'livrables_attendus', 'indicateur']),
            'type_action' => $payload['type_action'] ?? $parameterization['type_action'],
            'quantite_cible' => $payload['quantite_cible'] ?? $parameterization['quantite_cible'],
            'unite_cible' => $payload['unite_cible'] ?? $payload['unite'] ?? $parameterization['unite_cible'],
            'seuil_mode' => $payload['seuil_mode'] ?? $parameterization['seuil_mode'],
            'seuil_t1' => $payload['seuil_t1'] ?? $parameterization['seuil_t1'],
            'seuil_t2' => $payload['seuil_t2'] ?? $parameterization['seuil_t2'],
            'seuil_t3' => $payload['seuil_t3'] ?? $parameterization['seuil_t3'],
            'seuil_t4' => $payload['seuil_t4'] ?? $parameterization['seuil_t4'],
            'nombre_sous_actions' => $payload['nombre_sous_actions'] ?? $parameterization['nombre_sous_actions'],
            'sous_actions' => $payload['sous_actions'] ?? $parameterizer->stringifySubActions($parameterization['sous_actions']),
            'niveau_risque' => $payload['niveau_risque'] ?? $parameterization['niveau_risque'],
            'risque' => $this->first($payload, ['risque', 'risques_potentiels']),
            'mesures_preventives' => $this->first($payload, ['mesures_preventives']),
            'ressources_materielles' => $this->first($payload, ['ressources_materielles', 'ressources_requises']),
            'main_oeuvre' => $this->first($payload, ['main_oeuvre']),
            'autres_ressources' => $this->first($payload, ['autres_ressources', 'partenaires']),
            'financement' => $this->first($payload, ['financement'], $this->blank($payload['budget_previsionnel'] ?? null) ? null : 1),
            'nature_financement' => $this->first($payload, ['nature_financement', 'source_financement']),
            'montant_financement' => $this->first($payload, ['montant_financement', 'budget_previsionnel']),
            'commentaire_obligatoire' => $payload['commentaire_obligatoire'] ?? $parameterization['commentaire_obligatoire'],
            'champ_difficulte' => $payload['champ_difficulte'] ?? $parameterization['champ_difficulte'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveAgentCodes(array $payload): ?string
    {
        try {
            $resolver = app(PtaAgentResolverService::class);
            $result = $resolver->resolve(
                $this->first($payload, ['codes_agents_rmo', 'rmo_raw', 'responsable']),
                is_string($payload['direction'] ?? null) ? $payload['direction'] : null,
                is_string(($payload['service_unite'] ?? $payload['service'] ?? null)) ? ($payload['service_unite'] ?? $payload['service']) : null
            );

            return $result['codes'] === [] ? null : $resolver->codesToString($result['codes']);
        } catch (Throwable) {
            return null;
        }
    }

    private function yearFrom(mixed $value): ?int
    {
        if (preg_match('/20\d{2}/', (string) $value, $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    private function percent(mixed $value): mixed
    {
        if ($this->blank($value)) {
            return null;
        }

        $number = str_replace(['%', ' ', "\u{00A0}"], '', (string) $value);
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function first(array $payload, array $keys, mixed $fallback = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && ! $this->blank($payload[$key])) {
                return $payload[$key];
            }
        }

        return $fallback;
    }

    private function normalizedDate(mixed $value): mixed
    {
        if ($this->blank($value)) {
            return null;
        }

        $dates = app(PtaImportQualityControlService::class);

        return $dates->normalizeDate($value)?->format('Y-m-d') ?? $value;
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
