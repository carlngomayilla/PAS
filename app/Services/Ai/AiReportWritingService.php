<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;

class AiReportWritingService
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function draft(string $title, string $reportType, array $metrics): string
    {
        if ($reportType === AiGeneratedReport::TYPE_PTA_QUARTERLY && is_array($metrics['pta_analyse'] ?? null)) {
            return $this->draftPtaQuarterly($title, $metrics);
        }

        $totals = $metrics['totaux'] ?? [];
        $actions = $totals['actions'] ?? 'Donnee non disponible';
        $running = $totals['actions_en_cours'] ?? 'Donnee non disponible';
        $closed = $totals['actions_cloturees'] ?? 'Donnee non disponible';
        $late = $totals['actions_hors_delai'] ?? 'Donnee non disponible';
        $progress = $totals['progression_moyenne'] ?? 'Donnee non disponible';
        $budget = $totals['budget_previsionnel'] ?? 'Donnee non disponible';

        return implode("\n\n", [
            '# '.$title,
            'Type de rapport : '.(AiGeneratedReport::reportTypes()[$reportType] ?? $reportType),
            'Resume executif : le portefeuille analyse contient '.$actions.' action(s), dont '.$running.' en cours, '.$closed.' cloturee(s) et '.$late.' hors delai.',
            'Methodologie : les chiffres proviennent exclusivement du snapshot Laravel joint au rapport. Aucune valeur externe n est ajoutee.',
            'Situation globale : la progression moyenne observee est de '.$progress.' %. Le budget previsionnel total renseigne est de '.$budget.'.',
            'Analyse par statut : '.$this->formatMap($metrics['par_statut'] ?? []),
            'Analyse par direction : '.$this->formatMap($metrics['par_direction'] ?? []),
            'Analyse par service : '.$this->formatMap($metrics['par_service'] ?? []),
            'Actions hors delai : '.$this->formatLines($metrics['actions_hors_delai'] ?? []),
            'Risques et alertes : '.$this->formatSimpleList($metrics['alertes'] ?? []),
            'Recommandations : prioriser les actions hors delai, documenter les difficultes et valider les corrections dans le circuit metier avant diffusion.',
            'Conclusion : ce brouillon doit etre relu, ajuste puis valide par un utilisateur habilite avant export officiel.',
            'Annexe chiffree : '.json_encode($metrics['totaux'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function formatMap(array $map): string
    {
        if ($map === []) {
            return 'Donnee non disponible.';
        }

        return collect($map)
            ->map(fn (mixed $value, string $key): string => $key.' : '.$value)
            ->implode(' ; ');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function formatLines(array $lines): string
    {
        if ($lines === []) {
            return 'Aucune action hors delai dans le snapshot.';
        }

        return collect($lines)
            ->map(fn (array $line): string => trim(($line['code'] ?? '').' '.($line['libelle'] ?? '').' - '.($line['date_fin'] ?? '')))
            ->implode(' ; ');
    }

    /**
     * @param  list<string>  $items
     */
    private function formatSimpleList(array $items): string
    {
        return $items === [] ? 'Aucune alerte dans le snapshot.' : implode(' ; ', $items);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function draftPtaQuarterly(string $title, array $metrics): string
    {
        $analysis = $metrics['pta_analyse'] ?? [];
        $summary = $analysis['synthese'] ?? [];
        $period = $analysis['periode']['libelle'] ?? 'Periode non renseignee';

        return implode("\n\n", array_filter([
            '# '.$title,
            'RAPPORT TRIMESTRIEL PTA - '.$period,
            'Source des donnees : snapshot Laravel calcule sur les actions PTA visibles dans le perimetre selectionne.',
            '1. Progression globale du PTA',
            'Le portefeuille PTA compte '.($summary['actions_prevues'] ?? 0).' action(s) prevue(s), dont '.($summary['actions_realisees'] ?? 0).' realisee(s), '.($summary['actions_en_retard_non_realisees'] ?? 0).' en retard ou non realisee(s), '.($summary['actions_non_demarrees'] ?? 0).' non demarree(s) et '.($summary['actions_echues'] ?? 0).' echue(s). Le taux global d avancement est de '.($summary['taux_global_avancement'] ?? 0).' %, pour un taux de realisation PTA de '.($summary['taux_realisation'] ?? 0).' %.',
            '2. Taux de realisation des axes strategiques',
            $this->formatQuarterRows($analysis['axes'] ?? [], 'libelle', 'taux_realisation'),
            '3. Evolution de la realisation PTA sur la periode',
            $this->formatQuarterRows($analysis['evolution_mensuelle'] ?? [], 'mois', 'taux_realisation'),
            '4. Taux de realisation par service',
            $this->formatQuarterRows($analysis['services'] ?? [], 'libelle', 'taux_realisation'),
            '5. Analyse des ecarts constates',
            $this->formatQuarterGaps($analysis['ecarts'] ?? []),
            '6. Mesures correctives proposees',
            $this->formatSimpleList($analysis['mesures_correctives'] ?? []),
            'Conclusion : ce brouillon reprend la structure du modele officiel de rapport trimestriel PTA et doit etre relu puis valide humainement avant diffusion.',
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function formatQuarterRows(array $rows, string $labelKey, string $rateKey): string
    {
        if ($rows === []) {
            return 'Donnee non disponible.';
        }

        return collect($rows)
            ->map(fn (array $row): string => ($row[$labelKey] ?? 'Non renseigne').' : '.($row[$rateKey] ?? 0).' %')
            ->implode(' ; ');
    }

    /**
     * @param  array<string, mixed>  $gaps
     */
    private function formatQuarterGaps(array $gaps): string
    {
        $parts = [];
        foreach ([
            'actions_non_realisees' => 'Actions non realisees',
            'actions_partielles' => 'Actions partiellement realisees',
            'actions_reportees' => 'Actions reportees',
        ] as $key => $label) {
            $rows = is_array($gaps[$key] ?? null) ? $gaps[$key] : [];
            $parts[] = $label.' : '.$this->formatLines($rows);
        }

        return implode(' ', $parts);
    }
}
