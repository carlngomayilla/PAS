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
}
