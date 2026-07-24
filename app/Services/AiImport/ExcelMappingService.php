<?php

namespace App\Services\AiImport;

use App\Exports\AiImportWorkbookExport;
use App\Models\AiImportSession;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExcelMappingService
{
    public function generate(AiImportSession $session): string
    {
        $path = 'ai-imports/sessions/'.$session->id.'/import-pas-pao-pta-'.$session->id.'.xlsx';
        Storage::disk('local')->makeDirectory(dirname($path));

        Excel::store(new AiImportWorkbookExport($session), $path, 'local');

        $session->forceFill([
            'generated_excel_path' => $path,
        ])->save();

        return $path;
    }

    public function exists(AiImportSession $session): bool
    {
        return is_string($session->generated_excel_path)
            && $session->generated_excel_path !== ''
            && Storage::disk('local')->exists($session->generated_excel_path);
    }
}
