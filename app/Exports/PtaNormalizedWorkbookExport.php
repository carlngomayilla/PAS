<?php

namespace App\Exports;

use App\Models\AiImportBatch;
use App\Services\Ai\AiPromptService;
use App\Services\Ai\PtaAgentResolverService;
use App\Services\Ai\PtaImportQualityControlService;
use App\Services\Ai\PtaImportTemplateAnalyzerService;
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
        try {
            return app(PtaImportTemplateAnalyzerService::class)->columns();
        } catch (Throwable) {
            return app(AiPromptService::class)->importGlobalColumns();
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function payloadToImportGlobal(array $payload): array
    {
        $year = $this->yearFrom($payload['exercice'] ?? $this->batch->detected_year);
        $dates = app(PtaImportQualityControlService::class);
        $dateDebut = $dates->normalizeDate($payload['date_debut'] ?? null)?->format('Y-m-d') ?? $payload['date_debut'] ?? null;
        $dateFin = $dates->normalizeDate($payload['date_fin'] ?? $payload['echeance'] ?? null)?->format('Y-m-d') ?? $payload['date_fin'] ?? $payload['echeance'] ?? null;

        return [
            'annee_debut_pas' => $year,
            'annee_fin_pas' => $year,
            'ordre_axe' => null,
            'libelle_axe' => $payload['axe_strategique'] ?? null,
            'ordre_objectif_strategique' => null,
            'libelle_objectif_strategique' => $payload['objectif_strategique'] ?? null,
            'date_echeance_objectif_strategique' => null,
            'direction' => $payload['direction'] ?? $this->batch->detected_direction,
            'service_unite' => $payload['service'] ?? $this->batch->detected_service,
            'ordre_objectif_operationnel' => null,
            'libelle_objectif_operationnel' => $payload['programme'] ?? null,
            'date_echeance_objectif_operationnel' => null,
            'ordre_action' => $payload['code_action'] ?? null,
            'libelle_action' => $payload['libelle_action'] ?? $payload['description_action'] ?? null,
            'date_debut_action' => $dateDebut,
            'date_fin_action' => $dateFin,
            'codes_agents_rmo' => $this->resolveAgentCodes($payload),
            'cible_minimum_execution' => $this->percent($payload['cible'] ?? null),
            'justificatif_attendu' => $payload['livrables_attendus'] ?? $payload['indicateur'] ?? null,
            'type_action' => $this->blank($payload['unite'] ?? null) ? null : 'Q',
            'quantite_cible' => null,
            'unite_cible' => $payload['unite'] ?? null,
            'seuil_mode' => null,
            'seuil_t1' => null,
            'seuil_t2' => null,
            'seuil_t3' => null,
            'seuil_t4' => null,
            'nombre_sous_actions' => null,
            'sous_actions' => null,
            'niveau_risque' => null,
            'risque' => null,
            'mesures_preventives' => null,
            'ressources_materielles' => null,
            'main_oeuvre' => null,
            'autres_ressources' => $payload['partenaires'] ?? null,
            'financement' => $this->blank($payload['budget_previsionnel'] ?? null) ? null : 1,
            'nature_financement' => $payload['source_financement'] ?? null,
            'montant_financement' => $payload['budget_previsionnel'] ?? null,
            'commentaire_obligatoire' => null,
            'champ_difficulte' => null,
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
                $payload['codes_agents_rmo'] ?? $payload['responsable'] ?? null,
                is_string($payload['direction'] ?? null) ? $payload['direction'] : null,
                is_string($payload['service'] ?? null) ? $payload['service'] : null
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

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }
}
