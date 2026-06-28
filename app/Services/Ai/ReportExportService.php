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
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addTitle($report->title, 1);

        foreach (preg_split('/\R+/', $report->contentForExport()) ?: [] as $paragraph) {
            $section->addText(trim($paragraph));
        }

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
}
