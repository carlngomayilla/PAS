<?php

namespace App\Exports;

use App\Models\AiImportRow;
use App\Models\AiImportSession;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AiImportWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly AiImportSession $session
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $session = $this->session->loadMissing(['rows.importErrors', 'errors']);

        return [
            new ArraySheetExport('PAS', $this->pasRows($session)),
            new ArraySheetExport('AXES', $this->axisRows($session)),
            new ArraySheetExport('OBJECTIFS_STRATEGIQUES', $this->strategicRows($session)),
            new ArraySheetExport('OBJECTIFS_OPERATIONNELS', $this->operationalRows($session)),
            new ArraySheetExport('PTA_ACTIONS', $this->actionRows($session)),
            new ArraySheetExport('SOUS_ACTIONS', $this->subActionRows($session)),
            new ArraySheetExport('CONTROLES_EXTRACTION', $this->controlRows($session)),
            new ArraySheetExport('RAPPORT_IMPORT', $this->reportRows($session)),
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function pasRows(AiImportSession $session): array
    {
        $first = $session->rows->first();
        $year = $this->year($first);

        return [
            ['code_pas', 'libelle_pas', 'annee_debut', 'annee_fin', 'description'],
            [$first?->code_pas, $first?->raw_json['libelle_pas'] ?? ($year ? 'PAS '.$year : null), $year, $year, null],
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function axisRows(AiImportSession $session): array
    {
        $rows = [['code_pas', 'code_axe', 'ordre_axe', 'libelle_axe', 'description_axe']];
        $this->uniqueRows($session, 'axe')->each(function (AiImportRow $row, int $index) use (&$rows): void {
            $rows[] = [$row->code_pas, $this->axisCode($row, $index + 1), $index + 1, $row->axe, null];
        });

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function strategicRows(AiImportSession $session): array
    {
        $rows = [['code_axe', 'code_objectif_strategique', 'ordre_objectif_strategique', 'libelle_objectif_strategique', 'description']];
        $this->uniqueRows($session, 'objectif_strategique')->each(function (AiImportRow $row, int $index) use (&$rows): void {
            $order = $index + 1;
            $rows[] = [$this->axisCode($row, 1), $this->strategicCode($row, $order), $order, $row->objectif_strategique, null];
        });

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function operationalRows(AiImportSession $session): array
    {
        $rows = [['code_objectif_strategique', 'code_objectif_operationnel', 'ordre_objectif_operationnel', 'libelle_objectif_operationnel', 'description']];
        $this->uniqueRows($session, 'objectif_operationnel')->each(function (AiImportRow $row, int $index) use (&$rows): void {
            $order = $index + 1;
            $rows[] = [$this->strategicCode($row, 1), $this->operationalCode($row, $order), $order, $row->objectif_operationnel, null];
        });

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function actionRows(AiImportSession $session): array
    {
        $headers = [
            'code_pas', 'libelle_pas', 'annee', 'code_axe', 'ordre_axe', 'axe_strategique',
            'code_objectif_strategique', 'ordre_objectif_strategique', 'objectif_strategique',
            'code_objectif_operationnel', 'ordre_objectif_operationnel', 'objectif_operationnel',
            'code_direction', 'direction', 'code_service', 'service', 'code_action', 'ordre_action',
            'libelle_action', 'description_action', 'type_indicateur', 'cible', 'quantite_a_realiser',
            'livrable_attendu', 'unite_mesure', 'rmo', 'date_debut_prevue', 'date_fin_prevue',
            'etat_initial', 'ressources_requises', 'indicateurs_performance', 'risques_potentiels',
            'observations', 'source_page', 'statut_import',
        ];

        $rows = [$headers];
        foreach ($session->rows as $index => $row) {
            $raw = is_array($row->raw_json) ? $row->raw_json : [];
            $rows[] = [
                $row->code_pas,
                $raw['libelle_pas'] ?? null,
                $this->year($row),
                $this->axisCode($row, 1),
                $raw['ordre_axe'] ?? null,
                $row->axe,
                $this->strategicCode($row, 1),
                $raw['ordre_objectif_strategique'] ?? null,
                $row->objectif_strategique,
                $this->operationalCode($row, 1),
                $raw['ordre_objectif_operationnel'] ?? null,
                $row->objectif_operationnel,
                $raw['code_direction'] ?? null,
                $row->direction,
                $raw['code_service'] ?? null,
                $row->service,
                $raw['code_action'] ?? 'ACT'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                $raw['ordre_action'] ?? ($index + 1),
                $row->action,
                $raw['description_action'] ?? null,
                $row->type_indicateur,
                $row->cible,
                $row->quantite_a_realiser,
                $row->livrable_attendu,
                $row->unite_mesure,
                $row->rmo,
                $row->date_debut?->toDateString(),
                $row->date_fin?->toDateString(),
                $raw['etat_initial'] ?? null,
                $raw['ressources_requises'] ?? null,
                $raw['indicateurs_performance'] ?? null,
                $raw['risques_potentiels'] ?? null,
                $raw['observations'] ?? null,
                $row->source_page,
                $row->statut_import,
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function subActionRows(AiImportSession $session): array
    {
        $rows = [[
            'code_action_parent', 'code_sous_action', 'ordre_sous_action', 'libelle_sous_action',
            'type_indicateur', 'cible', 'quantite_a_realiser', 'livrable_attendu', 'unite_mesure',
            'rmo', 'date_debut_prevue', 'date_fin_prevue', 'observations', 'suggeree_par_ia', 'statut_import',
        ]];

        foreach ($session->rows as $rowIndex => $row) {
            $subActions = $row->raw_json['sous_actions'] ?? [];
            if (! is_array($subActions)) {
                continue;
            }
            foreach ($subActions as $index => $subAction) {
                if (! is_array($subAction)) {
                    continue;
                }
                $rows[] = [
                    $row->raw_json['code_action'] ?? 'ACT'.str_pad((string) ($rowIndex + 1), 3, '0', STR_PAD_LEFT),
                    $subAction['code_sous_action'] ?? 'SA'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    $subAction['ordre_sous_action'] ?? ($index + 1),
                    $subAction['libelle_sous_action'] ?? null,
                    $subAction['type_indicateur'] ?? null,
                    $subAction['cible'] ?? null,
                    $subAction['quantite_a_realiser'] ?? null,
                    $subAction['livrable_attendu'] ?? null,
                    $subAction['unite_mesure'] ?? null,
                    $subAction['rmo'] ?? null,
                    $subAction['date_debut_prevue'] ?? null,
                    $subAction['date_fin_prevue'] ?? null,
                    $subAction['observations'] ?? null,
                    $subAction['suggeree_par_ia'] ?? false,
                    $subAction['statut_import'] ?? $row->statut_import,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function controlRows(AiImportSession $session): array
    {
        $rows = [['source_page', 'element', 'champ', 'probleme', 'gravite', 'suggestion', 'statut']];
        foreach ($session->errors as $error) {
            $rows[] = [
                $error->row?->source_page,
                $error->row?->action,
                $error->field,
                $error->message,
                $error->gravity,
                $error->suggestion,
                $error->row?->statut_import,
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function reportRows(AiImportSession $session): array
    {
        $rows = $session->rows;

        return [
            [
                'total_axes_detectes',
                'total_objectifs_strategiques_detectes',
                'total_objectifs_operationnels_detectes',
                'total_actions_detectees',
                'total_sous_actions_detectees',
                'total_lignes_pretes',
                'total_lignes_a_verifier',
                'total_lignes_a_parametrer',
                'total_erreurs',
                'commentaire_global',
            ],
            [
                $rows->pluck('axe')->filter()->unique()->count(),
                $rows->pluck('objectif_strategique')->filter()->unique()->count(),
                $rows->pluck('objectif_operationnel')->filter()->unique()->count(),
                $rows->count(),
                $rows->filter(fn (AiImportRow $row): bool => trim((string) $row->sous_action) !== '')->count(),
                $rows->where('statut_import', AiImportRow::IMPORT_READY)->count(),
                $rows->where('statut_import', AiImportRow::IMPORT_VERIFY)->count(),
                $rows->where('statut_import', AiImportRow::IMPORT_PARAMETERIZE)->count(),
                $session->errors->count(),
                'Validation humaine obligatoire avant import definitif.',
            ],
        ];
    }

    private function uniqueRows(AiImportSession $session, string $field)
    {
        return $session->rows
            ->filter(fn (AiImportRow $row): bool => trim((string) $row->{$field}) !== '')
            ->unique(fn (AiImportRow $row): string => (string) $row->{$field})
            ->values();
    }

    private function year(?AiImportRow $row): ?int
    {
        $value = json_encode($row?->raw_json ?? []) ?: '';

        return preg_match('/20\d{2}/', $value, $matches) === 1 ? (int) $matches[0] : null;
    }

    private function axisCode(AiImportRow $row, int $order): string
    {
        return ($row->code_pas ?: 'PAS').'-AXE'.str_pad((string) $order, 2, '0', STR_PAD_LEFT);
    }

    private function strategicCode(AiImportRow $row, int $order): string
    {
        return $this->axisCode($row, 1).'-OS'.str_pad((string) $order, 2, '0', STR_PAD_LEFT);
    }

    private function operationalCode(AiImportRow $row, int $order): string
    {
        return $this->strategicCode($row, 1).'-OO'.str_pad((string) $order, 2, '0', STR_PAD_LEFT);
    }
}
