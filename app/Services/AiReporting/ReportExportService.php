<?php

namespace App\Services\AiReporting;

use App\Models\AiReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class ReportExportService
{
    public function pdf(AiReport $report)
    {
        $report->loadMissing('sections');
        $path = $this->path($report, 'pdf');

        Pdf::loadHTML($this->html($report))->save(Storage::disk('local')->path($path));
        $report->forceFill(['pdf_path' => $path])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'pdf'));
    }

    public function word(AiReport $report)
    {
        $report->loadMissing('sections');
        $path = $this->path($report, 'docx');
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTitle($report->title, 1);
        $section->addText((string) $report->summary);
        foreach ($report->sections as $reportSection) {
            $section->addTitle($reportSection->section_title, 2);
            foreach (preg_split('/\R+/', (string) $reportSection->content) ?: [] as $paragraph) {
                if (trim($paragraph) !== '') {
                    $section->addText(trim($paragraph));
                }
            }
        }

        IOFactory::createWriter($word, 'Word2007')->save(Storage::disk('local')->path($path));
        $report->forceFill(['word_path' => $path])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'docx'));
    }

    public function excel(AiReport $report)
    {
        $report->loadMissing('sections');
        $path = $this->path($report, 'xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rapport IA');
        $sheet->fromArray([
            ['Titre', $report->title],
            ['Resume', $report->summary],
            ['Genere le', $report->generated_at?->toDateTimeString()],
        ]);

        $line = 5;
        foreach ($report->sections as $section) {
            $sheet->setCellValue('A'.$line, $section->section_title);
            $sheet->setCellValue('B'.$line, $section->content);
            $sheet->setCellValue('C'.$line, json_encode($section->indicators_json ?? [], JSON_UNESCAPED_SLASHES));
            $line++;
        }

        (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));
        $report->forceFill(['excel_path' => $path])->save();

        return Storage::disk('local')->download($path, $this->filename($report, 'xlsx'));
    }

    private function path(AiReport $report, string $extension): string
    {
        $directory = 'ai-reports/institutional/'.$report->id;
        Storage::disk('local')->makeDirectory($directory);

        return $directory.'/rapport-'.$report->id.'.'.$extension;
    }

    private function filename(AiReport $report, string $extension): string
    {
        $slug = str($report->title)->ascii()->slug('-')->limit(90, '')->toString() ?: 'rapport-ia';

        return $slug.'.'.$extension;
    }

    private function html(AiReport $report): string
    {
        $sections = $report->sections
            ->map(fn ($section): string => '<h2>'.e($section->section_title).'</h2><p>'.nl2br(e((string) $section->content)).'</p>')
            ->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;line-height:1.5}h1{font-size:22px}h2{font-size:16px;margin-top:24px}</style></head><body><h1>'
            .e($report->title).'</h1><p>'.e((string) $report->summary).'</p>'.$sections.'</body></html>';
    }
}
