<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;

class PtaImportAnalysisService
{
    public function __construct(
        private readonly PtaExtractionService $extraction,
        private readonly PtaNormalizationService $normalization,
        private readonly PtaImportCompletionService $completion,
        private readonly PtaImportHierarchyCoherenceService $hierarchy,
        private readonly PtaInvalidRowAutoRepairService $autoRepair,
        private readonly PtaImportValidationService $validation,
        private readonly PtaExcelGenerationService $excel
    ) {}

    /**
     * @return array{
     *     created:int,
     *     confidence:float,
     *     warning:?string,
     *     stats:array{total:int,valid:int,invalid:int,ignored:int},
     *     completion:array{rows:int,fields:int},
     *     hierarchy:array{rows:int,fields:int,warnings:int},
     *     repair:array{rows:int,fields:int},
     *     excel:string
     * }
     */
    public function analyze(AiImportBatch $batch): array
    {
        $this->extendRuntime();

        $result = $this->extraction->extract($batch);
        $this->normalization->normalize($batch->refresh());

        $completion = $this->completion->complete($batch->refresh());
        $hierarchy = $this->hierarchy->repairAndCheck($batch->refresh());

        $stats = $this->validation->validateBatch($batch->refresh());
        $repair = $this->autoRepair->repair($batch->refresh());

        if ($repair['rows'] > 0) {
            $completion = $this->mergeCompletionStats($completion, $this->completion->complete($batch->refresh()));
            $hierarchy = $this->mergeHierarchyStats($hierarchy, $this->hierarchy->repairAndCheck($batch->refresh()));
            $stats = $this->validation->validateBatch($batch->refresh());
        }

        $excel = $this->excel->generate($batch->refresh());

        return [
            'created' => $result['created'],
            'confidence' => $result['confidence'],
            'warning' => $result['warning'],
            'stats' => $stats,
            'completion' => $completion,
            'hierarchy' => $hierarchy,
            'repair' => $repair,
            'excel' => $excel,
        ];
    }

    public function extendRuntime(): void
    {
        $seconds = $this->timeoutSeconds();

        $currentLimit = (int) ini_get('max_execution_time');
        if ($currentLimit > 0 && $currentLimit < $seconds) {
            @ini_set('max_execution_time', (string) $seconds);
        }

        $memoryLimit = trim((string) config('ai_training.pta.import_memory_limit', config('ai_training.pta.excel_memory_limit', '512M')));
        if ($memoryLimit !== '') {
            @ini_set('memory_limit', $memoryLimit);
        }

        @set_time_limit($seconds);
    }

    public function timeoutSeconds(): int
    {
        return max(
            120,
            (int) config('ai_training.pta.import_job_timeout', 1200),
            (int) config('ai_training.pta.llm_timeout', 120),
            (int) config('ai_training.pta.llm_vision_timeout', 45),
            (int) config('ai_training.pta.pdf_ocr_timeout', 900),
            (int) config('ai_training.pta.windows_ocr_timeout', 300),
            (int) config('ai_training.pta.linux_ocr_timeout', 900)
        ) + 60;
    }

    /**
     * @param  array{rows:int,fields:int}  $left
     * @param  array{rows:int,fields:int}  $right
     * @return array{rows:int,fields:int}
     */
    private function mergeCompletionStats(array $left, array $right): array
    {
        return [
            'rows' => $left['rows'] + $right['rows'],
            'fields' => $left['fields'] + $right['fields'],
        ];
    }

    /**
     * @param  array{rows:int,fields:int,warnings:int}  $left
     * @param  array{rows:int,fields:int,warnings:int}  $right
     * @return array{rows:int,fields:int,warnings:int}
     */
    private function mergeHierarchyStats(array $left, array $right): array
    {
        return [
            'rows' => $left['rows'] + $right['rows'],
            'fields' => $left['fields'] + $right['fields'],
            'warnings' => $left['warnings'] + $right['warnings'],
        ];
    }
}
