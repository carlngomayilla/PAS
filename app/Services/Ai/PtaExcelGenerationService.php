<?php

namespace App\Services\Ai;

use App\Exports\PtaNormalizedWorkbookExport;
use App\Models\AiImportBatch;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class PtaExcelGenerationService
{
    public function generate(AiImportBatch $batch): string
    {
        $path = 'ai-imports/pta/'.$batch->id.'/pta-normalise-'.$batch->id.'.xlsx';

        Excel::store(new PtaNormalizedWorkbookExport($batch), $path, 'local');

        $batch->forceFill(['generated_excel_path' => $path])->save();

        return $path;
    }

    public function exists(AiImportBatch $batch): bool
    {
        return is_string($batch->generated_excel_path)
            && $batch->generated_excel_path !== ''
            && Storage::disk('local')->exists($batch->generated_excel_path);
    }
}
