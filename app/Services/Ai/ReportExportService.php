<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
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
        $axes = is_array($analysis['axes'] ?? null) ? $analysis['axes'] : [];
        $services = is_array($analysis['services'] ?? null) ? $analysis['services'] : [];
        $monthly = is_array($analysis['evolution_mensuelle'] ?? null) ? $analysis['evolution_mensuelle'] : [];
        $gaps = is_array($analysis['ecarts'] ?? null) ? $analysis['ecarts'] : [];
        $measures = is_array($analysis['mesures_correctives'] ?? null) ? $analysis['mesures_correctives'] : [];
        $period = (string) ($analysis['periode']['libelle'] ?? 'Periode courante');
        $periodMonths = $this->ptaPeriodMonthsLabel($analysis);
        $periodEnd = $this->ptaPeriodEndLabel($analysis);
        $year = $this->ptaReportYear($analysis);
        $templatePath = (string) config('ai_training.reports.pta_quarterly_template_path', '');

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 22, 'color' => '4B5563'], ['alignment' => 'center', 'spaceAfter' => 160]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 13, 'color' => '17324A'], ['spaceBefore' => 220, 'spaceAfter' => 100]);
        $phpWord->addTableStyle('ptaReportGrid', [
            'borderSize' => 6,
            'borderColor' => '7A8797',
            'cellMargin' => 80,
            'width' => 100 * 50,
        ], [
            'bgColor' => '0EA5D7',
        ]);

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginTop' => 900,
            'marginBottom' => 900,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);

        $this->addPtaCover($section, $report->title, $year, $periodMonths, $templatePath);
        $section->addPageBreak();
        $this->addPtaSommaire($section, $period, $periodEnd);
        $section->addPageBreak();

        $this->addPtaSectionTitle($section, '1-Progression globale du PTA de la Direction Generale.');
        $this->addPtaIntro($section, $summary, $axes, $services);
        $this->addPtaSummaryTable($section, $summary, $axes);
        $this->addPtaAxisNarratives($section, $axes);

        $this->addPtaSectionTitle($section, '2-Taux de realisation des axes strategiques de la Direction Generale.');
        $this->addPtaAxisRatesTable($section, $axes);

        $this->addPtaSectionTitle($section, '3-Evolution des taux de realisation des axes strategiques de la DG');
        $this->addPtaAxisEvolution($section, $axes, $monthly);

        $this->addPtaSectionTitle($section, '4- Taux de realisation du PTA de la Direction Generale au '.$periodEnd);
        $this->addPtaServiceRateTable($section, $services);

        $this->addPtaSectionTitle($section, '5-Evolution du taux de realisation du PTA de la Direction Generale sur la periode '.$period);
        $this->addPtaMonthlyEvolutionTable($section, $monthly);

        $this->addPtaSectionTitle($section, '6-Analyse des ecarts constates.');
        $this->addPtaGapAnalysis($section, $gaps, $axes, $services);

        $this->addPtaSectionTitle($section, '7. Mesures correctives proposees :');
        $this->addPtaCorrectiveMeasures($section, $measures);

        return $phpWord;
    }

    private function addPtaCover($section, string $title, string $year, string $periodMonths, string $templatePath): void
    {
        $section->addTitle('RAPPORT TRIMESTRIEL '.$year, 1);
        $section->addText($periodMonths, ['bold' => true, 'size' => 16, 'color' => '17324A'], ['alignment' => 'center', 'spaceAfter' => 120]);
        $section->addText('SUIVI ET EVALUATION', ['bold' => true, 'size' => 13, 'color' => '0F5B66'], ['alignment' => 'center', 'spaceAfter' => 500]);
        $section->addText($title, ['bold' => true, 'size' => 12], ['alignment' => 'center', 'spaceAfter' => 220]);

        if ($templatePath !== '' && is_file($templatePath)) {
            $section->addText('Modele de reference : '.basename($templatePath), ['size' => 8, 'color' => '64748B'], ['alignment' => 'center']);
        }
    }

    private function addPtaSommaire($section, string $period, string $periodEnd): void
    {
        $section->addTitle('Sommaire', 2);
        foreach ([
            '1-PROGRESSION GLOBALE DU PTA DE LA DIRECTION GENERALE',
            '2- Taux de realisation des axes strategiques de la Direction Generale',
            '3- Evolution des taux de realisation des axes strategiques de la DG',
            '4- Taux de realisation du PTA de la Direction Generale au '.$periodEnd,
            '5- Evolution du taux de realisation du PTA de la Direction Generale sur la periode '.$period,
            '6-Analyse des ecarts constates',
            '7. Mesures correctives proposees',
        ] as $item) {
            $section->addText($item, ['size' => 11], ['spaceAfter' => 80]);
        }
    }

    private function addPtaSectionTitle($section, string $title): void
    {
        $section->addTitle($title, 2);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $services
     */
    private function addPtaIntro($section, array $summary, array $axes, array $services): void
    {
        $section->addText(
            'Le plan d actions strategique compte '.count($axes).' axe(s) strategique(s) et '.count($services).' PTA/service(s) suivis. '
            .'Le portefeuille consolide comprend '.($summary['actions_prevues'] ?? 0).' action(s) prevue(s), dont '.($summary['actions_realisees'] ?? 0).' realisee(s), '
            .($summary['actions_en_retard_non_realisees'] ?? 0).' en retard ou non realisee(s), '.($summary['actions_non_demarrees'] ?? 0).' non demarree(s) et '
            .($summary['actions_echues'] ?? 0).' echue(s). Le taux global d avancement est de '.$this->asPercent($summary['taux_global_avancement'] ?? 0)
            .' et le taux de realisation PTA est de '.$this->asPercent($summary['taux_realisation'] ?? 0).'.',
            ['size' => 10],
            ['spaceAfter' => 140]
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $axes
     */
    private function addPtaSummaryTable($section, array $summary, array $axes): void
    {
        $widths = [700, 3800, 1400, 1400, 1800, 1500, 1200, 1600];
        $table = $section->addTable('ptaReportGrid');
        $this->addPtaTableHeader($table, [
            'Axe',
            'AXES STRATEGIQUES DE LA DIRECTION GENERALE',
            'Nombre d actions prevues',
            'Actions realisees',
            'Actions en retard/non realisees',
            'Actions non demarrees',
            'Actions echues',
            'Taux global d avancement',
        ], $widths);

        foreach ($axes as $index => $axis) {
            $this->addPtaTableRow($table, [
                (string) ($index + 1),
                (string) ($axis['libelle'] ?? 'Non renseigne'),
                (string) ($axis['actions_prevues'] ?? 0),
                (string) ($axis['actions_realisees'] ?? 0),
                (string) ($axis['actions_en_retard_non_realisees'] ?? 0),
                (string) ($axis['actions_non_demarrees'] ?? 0),
                (string) ($axis['actions_echues'] ?? 0),
                $this->asPercent($axis['taux_global_avancement'] ?? $axis['taux_realisation'] ?? 0),
            ], $widths);
        }

        $this->addPtaTableRow($table, [
            'T',
            'TOTAL',
            (string) ($summary['actions_prevues'] ?? 0),
            (string) ($summary['actions_realisees'] ?? 0),
            (string) ($summary['actions_en_retard_non_realisees'] ?? 0),
            (string) ($summary['actions_non_demarrees'] ?? 0),
            (string) ($summary['actions_echues'] ?? 0),
            $this->asPercent($summary['taux_global_avancement'] ?? $summary['taux_realisation'] ?? 0),
        ], $widths, true);
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     */
    private function addPtaAxisNarratives($section, array $axes): void
    {
        if ($axes === []) {
            $section->addText('Aucun axe strategique n est disponible dans le snapshot.', ['size' => 10], ['spaceAfter' => 100]);

            return;
        }

        foreach ($axes as $index => $axis) {
            $section->addText(
                'L axe strategique N '.($index + 1).' "'.($axis['libelle'] ?? 'Non renseigne').'" compte '.($axis['actions_prevues'] ?? 0)
                .' action(s) prevue(s), '.($axis['actions_realisees'] ?? 0).' action(s) realisee(s), '.($axis['actions_echues'] ?? 0)
                .' action(s) echue(s) et affiche un taux de realisation de '.$this->asPercent($axis['taux_realisation'] ?? 0).'.',
                ['size' => 10],
                ['spaceAfter' => 80]
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     */
    private function addPtaAxisRatesTable($section, array $axes): void
    {
        $section->addText('TAUX DE REALISATION DES AXES GLOBAUX', ['bold' => true, 'size' => 10], ['spaceAfter' => 80]);
        $widths = [4400, 1600, 1600, 1800, 2000];
        $table = $section->addTable('ptaReportGrid');
        $this->addPtaTableHeader($table, ['Axe strategique', 'Actions prevues', 'Actions echues', 'Taux de realisation', 'Statut'], $widths);

        foreach ($axes as $axis) {
            $rate = $axis['taux_realisation'] ?? 0;
            $this->addPtaTableRow($table, [
                (string) ($axis['libelle'] ?? 'Non renseigne'),
                (string) ($axis['actions_prevues'] ?? 0),
                (string) ($axis['actions_echues'] ?? 0),
                $this->asPercent($rate),
                $this->statusFromRate($rate),
            ], $widths);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $monthly
     */
    private function addPtaAxisEvolution($section, array $axes, array $monthly): void
    {
        $axisRates = collect($axes)
            ->map(fn (array $axis): string => ($axis['libelle'] ?? 'Non renseigne').' : '.$this->asPercent($axis['taux_realisation'] ?? 0))
            ->implode(' ; ');
        $monthlyRates = collect($monthly)
            ->map(fn (array $row): string => ($row['mois'] ?? 'Mois non renseigne').' : '.$this->asPercent($row['taux_realisation'] ?? 0))
            ->implode(' ; ');

        $section->addText(
            'Les taux disponibles par axe sont les suivants : '.($axisRates !== '' ? $axisRates : 'donnee non disponible').'.',
            ['size' => 10],
            ['spaceAfter' => 80]
        );
        $section->addText(
            'L evolution mensuelle consolidee indique : '.($monthlyRates !== '' ? $monthlyRates : 'donnee non disponible').'.',
            ['size' => 10],
            ['spaceAfter' => 100]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $services
     */
    private function addPtaServiceRateTable($section, array $services): void
    {
        $widths = [4400, 2200, 2600, 2200];
        $table = $section->addTable('ptaReportGrid');
        $this->addPtaTableHeader($table, ['PTA', 'Taux de realisation', 'Nombre d actions echues (Poids)', 'Statut'], $widths);

        foreach ($services as $service) {
            $rate = $service['taux_realisation'] ?? 0;
            $this->addPtaTableRow($table, [
                (string) ($service['libelle'] ?? 'Non renseigne'),
                $this->asPercent($rate),
                (string) ($service['actions_echues'] ?? 0),
                $this->statusFromRate($rate),
            ], $widths);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $monthly
     */
    private function addPtaMonthlyEvolutionTable($section, array $monthly): void
    {
        $widths = [2800, 2200, 2200, 2200];
        $table = $section->addTable('ptaReportGrid');
        $this->addPtaTableHeader($table, ['Mois', 'Actions echues', 'Actions realisees', 'Taux de realisation'], $widths);

        foreach ($monthly as $row) {
            $this->addPtaTableRow($table, [
                (string) ($row['mois'] ?? 'Non renseigne'),
                (string) ($row['actions_echues'] ?? 0),
                (string) ($row['actions_realisees'] ?? 0),
                $this->asPercent($row['taux_realisation'] ?? 0),
            ], $widths);
        }
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $services
     */
    private function addPtaGapAnalysis($section, array $gaps, array $axes, array $services): void
    {
        $section->addText('1. Ecarts de realisation', ['bold' => true, 'size' => 10], ['spaceAfter' => 80]);
        foreach ([
            'actions_non_realisees' => 'Actions non realisees dans le trimestre:',
            'actions_partielles' => 'Actions partiellement realisees:',
            'actions_reportees' => 'Activites reportees a une periode ulterieure.',
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

        $section->addText('Taux de realisation inferieur a la cible prevue.', ['bold' => true]);
        $lowRates = collect(array_merge($axes, $services))
            ->filter(fn (array $row): bool => (float) ($row['taux_realisation'] ?? 0) < 100)
            ->take(8);
        if ($lowRates->isEmpty()) {
            $section->addText('Aucun taux inferieur a la cible n est signale dans le snapshot.', ['size' => 10]);
        } else {
            $lowRates->each(function (array $row) use ($section): void {
                $section->addListItem((string) (($row['libelle'] ?? 'Non renseigne').' : '.$this->asPercent($row['taux_realisation'] ?? 0)), 0, ['size' => 10]);
            });
        }

        $section->addText('2. Causes des ecarts', ['bold' => true, 'size' => 10], ['spaceBefore' => 120, 'spaceAfter' => 80]);
        $section->addText(
            'Les causes des ecarts doivent etre confirmees par les responsables concernes. Les alertes a prioriser portent sur les actions echues non realisees, les activites reportees, les ressources non disponibles et les retards de validation.',
            ['size' => 10],
            ['spaceAfter' => 100]
        );
    }

    /**
     * @param  list<mixed>  $measures
     */
    private function addPtaCorrectiveMeasures($section, array $measures): void
    {
        if ($measures === []) {
            $section->addText('Aucune mesure corrective n est disponible dans le snapshot.', ['size' => 10]);
        }

        foreach ($measures as $measure) {
            $section->addListItem((string) $measure, 0, ['size' => 10]);
        }

        $section->addTextBreak(2);
        $section->addText('Le Gestionnaire Suivi-Evaluation Senior', ['bold' => true, 'size' => 10], ['alignment' => 'right']);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<int>  $widths
     */
    private function addPtaTableHeader($table, array $headers, array $widths): void
    {
        $table->addRow();
        foreach ($headers as $index => $header) {
            $table->addCell($widths[$index] ?? 1600, ['bgColor' => '0EA5D7', 'valign' => 'center'])
                ->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8], ['alignment' => 'center', 'spaceAfter' => 0]);
        }
    }

    /**
     * @param  list<string>  $values
     * @param  list<int>  $widths
     */
    private function addPtaTableRow($table, array $values, array $widths, bool $bold = false): void
    {
        $table->addRow();
        foreach ($values as $index => $value) {
            $table->addCell($widths[$index] ?? 1600, ['valign' => 'center'])
                ->addText($value, ['bold' => $bold, 'size' => 8], ['spaceAfter' => 0]);
        }
    }

    private function asPercent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' %';
    }

    private function statusFromRate(mixed $rate): string
    {
        $rate = (float) $rate;

        return match (true) {
            $rate >= 100 => 'Realise',
            $rate >= 50 => 'En cours',
            $rate > 0 => 'Faible',
            default => 'Non demarre',
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function ptaPeriodMonthsLabel(array $analysis): string
    {
        $start = $analysis['periode']['debut'] ?? null;
        $end = $analysis['periode']['fin'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return 'PERIODE NON RENSEIGNEE';
        }

        try {
            $cursor = Carbon::parse($start)->startOfMonth();
            $last = Carbon::parse($end)->startOfMonth();
            $months = [];

            while ($cursor->lte($last)) {
                $months[] = $cursor->locale('fr')->translatedFormat('F');
                $cursor->addMonth();
            }

            return mb_strtoupper(implode('-', $months));
        } catch (\Throwable) {
            return 'PERIODE NON RENSEIGNEE';
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function ptaPeriodEndLabel(array $analysis): string
    {
        $end = $analysis['periode']['fin'] ?? null;

        if (! is_string($end)) {
            return 'la date de cloture';
        }

        try {
            return Carbon::parse($end)->format('d/m/Y');
        } catch (\Throwable) {
            return 'la date de cloture';
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function ptaReportYear(array $analysis): string
    {
        $end = $analysis['periode']['fin'] ?? null;

        if (! is_string($end)) {
            return now()->format('Y');
        }

        try {
            return Carbon::parse($end)->format('Y');
        } catch (\Throwable) {
            return now()->format('Y');
        }
    }
}
