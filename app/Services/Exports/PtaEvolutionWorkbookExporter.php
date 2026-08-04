<?php

namespace App\Services\Exports;

/**
 * Rapport d'evolution du PTA au format Excel.
 *
 * Reprend le modele institutionnel (support PAS/PAO/PTA de l'ANBG) : chaque
 * objectif operationnel forme un bloc precede de son axe strategique et de son
 * objectif strategique, suivi du tableau des actions detaillees a neuf
 * colonnes. La plomberie XLSX est heritee de {@see PtaSuiviWorkbookExporter} ;
 * seule la composition des lignes change.
 */
class PtaEvolutionWorkbookExporter extends PtaSuiviWorkbookExporter
{
    /** @var list<string> */
    public const COLUMNS = [
        'DESCRIPTION DES ACTIONS DÉTAILLÉES',
        'RMO',
        'CIBLE',
        'DÉBUT',
        'FIN',
        'ÉTAT DE RÉALISATION',
        'RESSOURCES REQUISES',
        'INDICATEURS DE PERFORMANCE',
        'RISQUES POTENTIELS',
    ];

    protected function tempFilePrefix(): string
    {
        return 'pta_evolution_xlsx_';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{cells:list<string>,style:int}>
     */
    protected function rows(array $payload): array
    {
        $rows = [];
        $rows[] = ['cells' => [(string) ($payload['title'] ?? "RAPPORT D'ÉVOLUTION DU PTA")], 'style' => 1];
        $rows[] = ['cells' => [(string) ($payload['scopeLabel'] ?? '')], 'style' => 2];
        $rows[] = ['cells' => [], 'style' => 0];

        $hasBlock = false;

        foreach (collect($payload['groups'] ?? []) as $pasGroup) {
            foreach (collect(((array) $pasGroup)['axes'] ?? []) as $axisGroup) {
                $axisGroup = (array) $axisGroup;

                foreach (collect($axisGroup['objectifs'] ?? []) as $strategicGroup) {
                    $strategicGroup = (array) $strategicGroup;

                    foreach (collect($strategicGroup['objectifs_operationnels'] ?? [])->values() as $index => $operationalGroup) {
                        $operationalGroup = (array) $operationalGroup;
                        $hasBlock = true;

                        $rows[] = ['cells' => ['AXE STRATÉGIQUE'], 'style' => 4];
                        $rows[] = ['cells' => [(string) ($axisGroup['label'] ?? '-')], 'style' => 6];
                        $rows[] = ['cells' => ['OBJECTIF STRATÉGIQUE'], 'style' => 5];
                        $rows[] = ['cells' => [(string) ($strategicGroup['label'] ?? '-')], 'style' => 6];
                        $rows[] = ['cells' => ['OBJECTIF OPÉRATIONNEL N° '.($index + 1)], 'style' => 5];
                        $rows[] = ['cells' => [(string) ($operationalGroup['label'] ?? '-')], 'style' => 6];
                        $rows[] = ['cells' => self::COLUMNS, 'style' => 7];

                        $actions = collect($operationalGroup['actions'] ?? []);

                        if ($actions->isEmpty()) {
                            $rows[] = ['cells' => ['Aucune action rattachée à cet objectif opérationnel.'], 'style' => 0];
                        }

                        foreach ($actions as $actionRow) {
                            $rows[] = ['cells' => $this->evolutionCells((array) $actionRow), 'style' => 0];
                        }

                        $rows[] = ['cells' => [], 'style' => 0];
                    }
                }
            }
        }

        if (! $hasBlock) {
            $rows[] = ['cells' => ['Aucune action disponible pour les filtres actifs.'], 'style' => 2];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function evolutionCells(array $row): array
    {
        return [
            (string) ($row['libelle'] ?? '-'),
            (string) ($row['responsable'] ?? '-'),
            (string) ($row['cible'] ?? '-'),
            (string) ($row['debut_label'] ?? '-'),
            (string) ($row['fin_label'] ?? '-'),
            (string) ($row['statut_action_label'] ?? '-'),
            (string) ($row['ressources_requises'] ?? '-'),
            (string) ($row['indicateur'] ?? '-'),
            (string) ($row['risques_potentiels'] ?? '-'),
        ];
    }
}
