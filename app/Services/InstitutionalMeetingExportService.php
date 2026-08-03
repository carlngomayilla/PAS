<?php

namespace App\Services;

use App\Models\InstitutionalReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstitutionalMeetingExportService
{
    /**
     * @param  Collection<int, InstitutionalReport>  $meetings
     * @param  array<string, int|float>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function pdf(Collection $meetings, array $summary, array $filters): BinaryFileResponse
    {
        $path = $this->path('pdf');
        Pdf::loadView('workspace.reports.export-pdf', compact('meetings', 'summary', 'filters'))
            ->setPaper('a4', 'landscape')
            ->save(Storage::disk('local')->path($path));

        return $this->download($path, 'rapport-reunions.pdf');
    }

    /**
     * @param  Collection<int, InstitutionalReport>  $meetings
     * @param  array<string, int|float>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function word(Collection $meetings, array $summary, array $filters): BinaryFileResponse
    {
        $path = $this->path('docx');
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['orientation' => 'landscape']);
        $section->addTitle('Rapport de suivi des réunions', 1);
        $section->addText($this->filterLabel($filters));
        $section->addText('Généré le '.now()->format('d/m/Y à H:i'));
        $section->addTextBreak();
        $section->addText(sprintf(
            'Réunions prévues : %d | Tenues : %d | Non tenues : %d | PV diffusés : %d',
            $summary['meetings_scheduled'] ?? 0,
            $summary['meetings_held'] ?? 0,
            $summary['meetings_overdue'] ?? 0,
            $summary['minutes_distributed'] ?? 0,
        ));
        $section->addTextBreak();
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'CBD5E1', 'cellMargin' => 80]);
        foreach ([['Réunion', 'Périmètre', 'Programmation', 'État', 'Responsable', 'PV']] as $row) {
            $cells = $table->addRow();
            foreach ($row as $cell) {
                $cells->addCell(1800)->addText($cell, ['bold' => true]);
            }
        }
        foreach ($meetings as $meeting) {
            $cells = $table->addRow();
            foreach ($this->row($meeting) as $cell) {
                $cells->addCell(1800)->addText($cell);
            }
        }
        IOFactory::createWriter($word, 'Word2007')->save(Storage::disk('local')->path($path));

        return $this->download($path, 'rapport-reunions.docx');
    }

    /**
     * @param  Collection<int, InstitutionalReport>  $meetings
     * @param  array<string, int|float>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function excel(Collection $meetings, array $summary, array $filters): BinaryFileResponse
    {
        $path = $this->path('xlsx');
        $spreadsheet = new Spreadsheet;
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Synthèse');
        $summarySheet->fromArray([
            ['Rapport de suivi des réunions'],
            ['Périmètre', $this->filterLabel($filters)],
            ['Généré le', now()->format('d/m/Y H:i')],
            [],
            ['Indicateur', 'Valeur'],
            ['Réunions prévues', $summary['meetings_scheduled'] ?? 0],
            ['Réunions tenues', $summary['meetings_held'] ?? 0],
            ['Non tenues à échéance', $summary['meetings_overdue'] ?? 0],
            ['Reportées', $summary['meetings_postponed'] ?? 0],
            ['Annulées', $summary['meetings_cancelled'] ?? 0],
            ['PV diffusés', $summary['minutes_distributed'] ?? 0],
            ['Décisions à suivre', $summary['meeting_decisions_open'] ?? 0],
            ['Taux de tenue', ($summary['meeting_completion_rate'] ?? 0).'%'],
        ]);
        $meetingSheet = $spreadsheet->createSheet();
        $meetingSheet->setTitle('Réunions');
        $meetingSheet->fromArray([['Réunion', 'Périmètre', 'Programmation', 'État', 'Responsable', 'PV']]);
        foreach ($meetings as $meeting) {
            $meetingSheet->fromArray([$this->row($meeting)], null, 'A'.($meetingSheet->getHighestRow() + 1));
        }
        foreach ([$summarySheet, $meetingSheet] as $sheet) {
            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
        (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));

        return $this->download($path, 'rapport-reunions.xlsx');
    }

    private function path(string $extension): string
    {
        $directory = 'institutional-reports/exports';
        Storage::disk('local')->makeDirectory($directory);

        return $directory.'/reunions-'.now()->format('Ymd-His').'.'.$extension;
    }

    private function download(string $path, string $filename): BinaryFileResponse
    {
        return response()->download(Storage::disk('local')->path($path), $filename)->deleteFileAfterSend(true);
    }

    /**
     * @return list<string>
     */
    private function row(InstitutionalReport $meeting): array
    {
        $state = $meeting->cancelled_at !== null
            ? 'Annulée'
            : ($meeting->held_at !== null
                ? ($meeting->scheduled_at !== null && $meeting->held_at->lte($meeting->scheduled_at) ? 'Tenue dans les délais' : 'Tenue hors délai')
                : ($meeting->scheduled_at !== null && $meeting->scheduled_at->isPast() ? 'Non tenue à échéance' : 'Programmée'));

        return [
            (string) $meeting->title,
            trim(($meeting->direction?->code ?? 'Agence').($meeting->service ? ' · '.$meeting->service->code : '')),
            $meeting->scheduled_at?->format('d/m/Y H:i') ?? '-',
            $state,
            $meeting->responsible?->name ?? $meeting->submittedBy?->name ?? '-',
            $meeting->minutes_published_at?->format('d/m/Y H:i') ?? 'En attente',
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filterLabel(array $filters): string
    {
        return collect([
            ! empty($filters['year']) ? 'Exercice '.$filters['year'] : null,
            ! empty($filters['quarter']) ? 'Trimestre '.$filters['quarter'] : null,
            ! empty($filters['month']) ? 'Mois '.$filters['month'] : null,
        ])->filter()->join(' | ') ?: 'Tous les périmètres accessibles';
    }
}
