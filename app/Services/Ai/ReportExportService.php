<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class ReportExportService
{
    public function pdf(AiGeneratedReport $report)
    {
        $this->ensureValidated($report);

        $path = $this->path($report, 'pdf');
        Pdf::loadView('workspace.ai-reports.export-pdf', ['report' => $report])
            ->save(Storage::disk('local')->path($path));

        $report->forceFill([
            'exported_pdf_path' => $path,
            'status' => AiGeneratedReport::STATUS_EXPORTED,
        ])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'pdf'));
    }

    public function word(AiGeneratedReport $report)
    {
        $this->ensureValidated($report);

        $path = $this->path($report, 'docx');
        $phpWord = $this->buildWordDocument($report);

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($path));

        $report->forceFill([
            'exported_docx_path' => $path,
            'status' => AiGeneratedReport::STATUS_EXPORTED,
        ])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'docx'));
    }

    public function excel(AiGeneratedReport $report)
    {
        $this->ensureValidated($report);

        $path = $this->path($report, 'xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rapport IA');
        $sheet->fromArray([
            ['Titre', $report->title],
            ['Type', $report->report_type],
            ['Statut', $report->status],
            ['Periode debut', $report->period_start?->toDateString()],
            ['Periode fin', $report->period_end?->toDateString()],
            ['Contenu', $report->contentForExport()],
            ['Snapshot JSON', json_encode($report->metrics_snapshot ?? [], JSON_UNESCAPED_SLASHES)],
        ]);

        (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));

        $report->forceFill([
            'exported_xlsx_path' => $path,
            'status' => AiGeneratedReport::STATUS_EXPORTED,
        ])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'xlsx'));
    }

    private function ensureValidated(AiGeneratedReport $report): void
    {
        abort_unless(trim($report->contentForExport()) !== '', 422, 'Rapport vide.');
    }

    private function path(AiGeneratedReport $report, string $extension): string
    {
        $directory = 'ai-reports/'.$report->id;
        Storage::disk('local')->makeDirectory($directory);

        return $directory.'/rapport-'.$report->id.'.'.$extension;
    }

    private function filename(AiGeneratedReport $report, string $extension): string
    {
        $slug = str($report->title)->ascii()->slug('-')->limit(80, '')->toString() ?: 'rapport-ia';

        return $slug.'.'.$extension;
    }

    private function buildWordDocument(AiGeneratedReport $report): PhpWord
    {
        if (
            $report->report_type === AiGeneratedReport::TYPE_PTA_QUARTERLY
            && is_array($report->metrics_snapshot['pta_analyse'] ?? null)
        ) {
            return $this->buildPtaQuarterlyWordDocument($report);
        }

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addTitle($report->title, 1);

        foreach (preg_split('/\R+/', $report->contentForExport()) ?: [] as $paragraph) {
            $text = trim($paragraph);
            if ($text !== '') {
                $section->addText($text);
            }
        }

        return $phpWord;
    }

    private function buildPtaQuarterlyWordDocument(AiGeneratedReport $report): PhpWord
    {
        $analysis = $report->metrics_snapshot['pta_analyse'] ?? [];
        $summary = $analysis['synthese'] ?? [];
        $period = (string) ($analysis['periode']['libelle'] ?? 'Periode courante');
        $templatePath = (string) config('ai_training.reports.pta_quarterly_template_path', '');

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Aptos');
        $phpWord->setDefaultFontSize(11);
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 18, 'color' => '17324A'], ['alignment' => 'center', 'spaceAfter' => 240]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 13, 'color' => '17324A'], ['spaceBefore' => 260, 'spaceAfter' => 120]);
        $phpWord->addTableStyle('ptaReportTable', [
            'borderSize' => 6,
            'borderColor' => '7A8797',
            'cellMargin' => 80,
        ], [
            'bgColor' => '0EA5D7',
        ]);

        $section = $phpWord->addSection([
            'marginTop' => 900,
            'marginBottom' => 900,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);
        $section->addTitle('RAPPORT TRIMESTRIEL PTA', 1);
        $section->addText($period, ['bold' => true, 'size' => 14, 'color' => '17324A'], ['alignment' => 'center']);
        $section->addText('SUIVI ET EVALUATION', ['bold' => true, 'color' => '0F5B66'], ['alignment' => 'center', 'spaceAfter' => 300]);
        if ($templatePath !== '' && is_file($templatePath)) {
            $section->addText('Modele de mise en forme reference : '.basename($templatePath), ['size' => 8, 'color' => '64748B'], ['alignment' => 'center']);
        }

        $section->addTitle('1. Progression globale du PTA', 2);
        $this->addKeyValueTable($section, [
            ['Actions prevues', $summary['actions_prevues'] ?? 0],
            ['Actions realisees', $summary['actions_realisees'] ?? 0],
            ['Actions en retard/non realisees', $summary['actions_en_retard_non_realisees'] ?? 0],
            ['Actions non demarrees', $summary['actions_non_demarrees'] ?? 0],
            ['Actions echues', $summary['actions_echues'] ?? 0],
            ['Taux global d avancement', ($summary['taux_global_avancement'] ?? 0).' %'],
            ['Taux de realisation PTA', ($summary['taux_realisation'] ?? 0).' %'],
        ]);

        $section->addTitle('2. Taux de realisation des axes strategiques', 2);
        $this->addAnalysisTable($section, ['Axe', 'Prevues', 'Realisees', 'Retard/non realisees', 'Non demarrees', 'Echues', 'Taux PTA'], $analysis['axes'] ?? [], 'libelle');

        $section->addTitle('3. Taux de realisation par service', 2);
        $this->addAnalysisTable($section, ['Service', 'Prevues', 'Realisees', 'Retard/non realisees', 'Non demarrees', 'Echues', 'Taux PTA'], $analysis['services'] ?? [], 'libelle');

        $section->addTitle('4. Evolution de la periode', 2);
        $this->addMonthlyTable($section, $analysis['evolution_mensuelle'] ?? []);

        $section->addTitle('5. Analyse des ecarts constates', 2);
        $this->addGapList($section, $analysis['ecarts'] ?? []);

        $section->addTitle('6. Mesures correctives proposees', 2);
        foreach (($analysis['mesures_correctives'] ?? []) as $measure) {
            $section->addListItem((string) $measure, 0, ['size' => 10]);
        }

        return $phpWord;
    }

    /**
     * @param  list<array{0:string,1:mixed}>  $rows
     */
    private function addKeyValueTable($section, array $rows): void
    {
        $table = $section->addTable('ptaReportTable');
        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(5200)->addText($label, ['bold' => true]);
            $table->addCell(2400)->addText((string) $value);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function addAnalysisTable($section, array $headers, array $rows, string $labelKey): void
    {
        $table = $section->addTable('ptaReportTable');
        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(1800)->addText($header, ['bold' => true, 'color' => 'FFFFFF']);
        }

        foreach ($rows as $row) {
            $table->addRow();
            foreach ([
                $row[$labelKey] ?? '-',
                $row['actions_prevues'] ?? 0,
                $row['actions_realisees'] ?? 0,
                $row['actions_en_retard_non_realisees'] ?? 0,
                $row['actions_non_demarrees'] ?? 0,
                $row['actions_echues'] ?? 0,
                ($row['taux_realisation'] ?? 0).' %',
            ] as $value) {
                $table->addCell(1800)->addText((string) $value);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function addMonthlyTable($section, array $rows): void
    {
        $table = $section->addTable('ptaReportTable');
        $table->addRow();
        foreach (['Mois', 'Actions echues', 'Actions realisees', 'Taux PTA'] as $header) {
            $table->addCell(2200)->addText($header, ['bold' => true, 'color' => 'FFFFFF']);
        }

        foreach ($rows as $row) {
            $table->addRow();
            foreach ([
                $row['mois'] ?? '-',
                $row['actions_echues'] ?? 0,
                $row['actions_realisees'] ?? 0,
                ($row['taux_realisation'] ?? 0).' %',
            ] as $value) {
                $table->addCell(2200)->addText((string) $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $gaps
     */
    private function addGapList($section, array $gaps): void
    {
        foreach ([
            'actions_non_realisees' => 'Actions non realisees',
            'actions_partielles' => 'Actions partiellement realisees',
            'actions_reportees' => 'Actions reportees',
        ] as $key => $title) {
            $section->addText($title, ['bold' => true]);
            $rows = is_array($gaps[$key] ?? null) ? $gaps[$key] : [];
            if ($rows === []) {
                $section->addText('Aucune donnee.', ['size' => 10]);

                continue;
            }
            foreach ($rows as $row) {
                $section->addListItem(trim((string) (($row['libelle'] ?? '-').' - RMO : '.($row['responsable'] ?? 'Non renseigne'))), 0, ['size' => 10]);
            }
        }
    }
}
