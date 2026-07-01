<?php

namespace App\Services\Ai;

use Illuminate\Support\Arr;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class PtaImportTemplateAnalyzerService
{
    /**
     * @return array{
     *     path:string,
     *     columns:list<string>,
     *     guide:list<array<string,mixed>>,
     *     examples:list<array<string,mixed>>,
     *     training:array{
     *         log_extraction:list<array<string,mixed>>,
     *         prompt_ia:string,
     *         pipeline_outils:list<array<string,mixed>>,
     *         synthese_import:list<array<string,mixed>>
     *     },
     *     constraints:array<string,mixed>
     * }
     */
    public function analyze(?string $path = null): array
    {
        $path = $this->templatePath($path);
        $spreadsheet = IOFactory::load($path);
        $importSheet = $spreadsheet->getSheetByName('IMPORT_GLOBAL');
        $guideSheet = $spreadsheet->getSheetByName('GUIDE');

        if (! $importSheet instanceof Worksheet) {
            throw new RuntimeException('La feuille IMPORT_GLOBAL est introuvable dans le modele officiel.');
        }

        return [
            'path' => $path,
            'columns' => $this->columnsFromSheet($importSheet),
            'guide' => $guideSheet instanceof Worksheet ? $this->rowsFromSheet($guideSheet) : [],
            'examples' => array_slice($this->rowsFromSheet($importSheet), 0, 5),
            'training' => $this->trainingSheets($spreadsheet),
            'constraints' => $this->constraints(),
        ];
    }

    /**
     * @return list<string>
     */
    public function columns(?string $path = null): array
    {
        return $this->analyze($path)['columns'];
    }

    /**
     * @return array<string,mixed>
     */
    public function constraints(): array
    {
        return [
            'required' => [
                'libelle_action',
                'direction',
                'date_debut_action',
                'date_fin_action',
            ],
            'agent_codes' => [
                'column' => 'codes_agents_rmo',
                'separator' => ';',
                'required_when_rmo_detected' => true,
            ],
            'ranges' => [
                'cible_minimum_execution' => ['min' => 0, 'max' => 100],
            ],
            'enums' => [
                'type_action' => ['', 'Q', 'NQ', 'M'],
                'seuil_mode' => ['', 'unique', 'trimestriel'],
                'niveau_risque' => ['', 'faible', 'modere', 'modéré', 'eleve', 'élevé', 'critique'],
                'financement' => ['', 0, 1, '0', '1'],
                'commentaire_obligatoire' => ['', 0, 1, '0', '1'],
                'champ_difficulte' => ['', 0, 1, '0', '1'],
            ],
            'conditional' => [
                'type_action_q' => ['when' => ['type_action' => 'Q'], 'required' => ['quantite_cible', 'unite_cible']],
                'seuil_trimestriel' => ['when' => ['seuil_mode' => 'trimestriel'], 'required' => ['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4']],
                'financement' => ['when' => ['financement' => 1], 'required' => ['nature_financement', 'montant_financement']],
            ],
        ];
    }

    public function templatePath(?string $path = null): string
    {
        $path ??= (string) config('ai_training.pta.import_template_path');

        if (! is_file($path)) {
            throw new RuntimeException('Modele IMPORT_GLOBAL introuvable : '.$path);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function columnsFromSheet(Worksheet $sheet): array
    {
        $highestColumn = $sheet->getHighestDataColumn();
        $values = Arr::flatten($sheet->rangeToArray('A1:'.$highestColumn.'1', null, true, false));

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        )));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsFromSheet(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headers = array_map(
            static fn (mixed $value): string => trim((string) $value),
            Arr::flatten($sheet->rangeToArray('A1:'.$highestColumn.'1', null, true, false))
        );

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = Arr::flatten($sheet->rangeToArray('A'.$rowNumber.':'.$highestColumn.$rowNumber, null, true, false));
            if (collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '')) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $values[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{
     *     log_extraction:list<array<string,mixed>>,
     *     prompt_ia:string,
     *     pipeline_outils:list<array<string,mixed>>,
     *     synthese_import:list<array<string,mixed>>
     * }
     */
    private function trainingSheets(Spreadsheet $spreadsheet): array
    {
        return [
            'log_extraction' => $this->rowsFromOptionalSheet($spreadsheet, 'LOG_EXTRACTION'),
            'prompt_ia' => $this->textFromOptionalSheet($spreadsheet, 'PROMPT_IA'),
            'pipeline_outils' => $this->rowsFromOptionalSheet($spreadsheet, 'PIPELINE_OUTILS'),
            'synthese_import' => $this->rowsFromOptionalSheet($spreadsheet, 'SYNTHESE_IMPORT'),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsFromOptionalSheet(Spreadsheet $spreadsheet, string $sheetName): array
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);

        return $sheet instanceof Worksheet ? $this->rowsFromSheet($sheet) : [];
    }

    private function textFromOptionalSheet(Spreadsheet $spreadsheet, string $sheetName): string
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (! $sheet instanceof Worksheet) {
            return '';
        }

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $lines = [];

        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
            $values = Arr::flatten($sheet->rangeToArray('A'.$rowNumber.':'.$highestColumn.$rowNumber, null, true, false));
            $line = implode(' | ', array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $values
            ), static fn (string $value): bool => $value !== ''));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
