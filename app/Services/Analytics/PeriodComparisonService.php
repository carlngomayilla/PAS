<?php

namespace App\Services\Analytics;

class PeriodComparisonService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRows(array $rows): array
    {
        return array_values(array_map(static function (array $row): array {
            $row['annee'] = (int) ($row['annee'] ?? 0);
            $row['actions_total'] = (int) ($row['actions_total'] ?? 0);
            $row['actions_terminees'] = (int) ($row['actions_terminees'] ?? 0);
            $row['taux_realisation'] = (float) ($row['taux_realisation'] ?? 0);

            return $row;
        }, $rows));
    }
}