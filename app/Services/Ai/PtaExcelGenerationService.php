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

        $this->ensureWorkbookMemoryLimit();
        gc_collect_cycles();

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

    private function ensureWorkbookMemoryLimit(): void
    {
        $target = trim((string) config('ai_training.pta.excel_memory_limit', '512M'));
        $targetBytes = $this->memoryLimitToBytes($target);
        $currentBytes = $this->memoryLimitToBytes((string) ini_get('memory_limit'));

        if ($target === '' || $targetBytes <= 0 || $currentBytes === -1 || ($currentBytes > 0 && $currentBytes >= $targetBytes)) {
            return;
        }

        @ini_set('memory_limit', $target);
    }

    private function memoryLimitToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
