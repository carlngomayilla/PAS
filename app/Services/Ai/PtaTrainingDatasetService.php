<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use App\Models\AiImportRow;
use App\Models\AiTrainingExample;
use App\Models\User;
use Illuminate\Support\Facades\File;

class PtaTrainingDatasetService
{
    public function __construct(
        private readonly AiPromptService $prompts
    ) {}

    public function recordCorrection(AiImportRow $row, ?User $user = null): AiTrainingExample
    {
        return AiTrainingExample::query()->create([
            'task' => AiTrainingExample::TASK_CORRECTION,
            'input_text' => json_encode($row->raw_payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expected_json' => $row->normalized_payload ?? [],
            'source' => 'human_correction',
            'is_validated' => true,
            'validated_by' => $user?->id,
        ]);
    }

    public function recordValidatedReport(AiGeneratedReport $report, ?User $user = null): AiTrainingExample
    {
        return AiTrainingExample::query()->create([
            'task' => AiTrainingExample::TASK_REPORT_WRITING,
            'input_text' => json_encode($report->metrics_snapshot ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expected_text' => $report->validated_content ?? $report->ai_draft,
            'source' => 'validated_report',
            'is_validated' => true,
            'validated_by' => $user?->id,
        ]);
    }

    public function recordValidatedImportRow(AiImportRow $row, ?User $user = null): AiTrainingExample
    {
        return AiTrainingExample::query()->create([
            'task' => AiTrainingExample::TASK_PTA_EXTRACTION,
            'input_text' => json_encode($row->raw_payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expected_json' => $row->normalized_payload ?? [],
            'source' => 'validated_import',
            'is_validated' => true,
            'validated_by' => $user?->id,
        ]);
    }

    public function exportJsonl(?string $path = null): string
    {
        $path ??= rtrim((string) config('ai_training.training_root'), '\\/')
            .DIRECTORY_SEPARATOR.'pta-training-'.now()->format('Ymd-His').'.jsonl';

        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de creer le dataset IA.');
        }

        AiTrainingExample::query()
            ->where('is_validated', true)
            ->orderBy('id')
            ->chunk(200, function ($examples) use ($handle): void {
                foreach ($examples as $example) {
                    fwrite($handle, json_encode($this->toFineTuningLine($example), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                }
            });

        fclose($handle);

        return $path;
    }

    /**
     * @return array{messages:list<array{role:string,content:string}>}
     */
    private function toFineTuningLine(AiTrainingExample $example): array
    {
        $system = $example->task === AiTrainingExample::TASK_REPORT_WRITING
            ? $this->prompts->reportSystemPrompt()
            : $this->prompts->ptaExtractionSystemPrompt();

        $expected = $example->expected_text ?: json_encode($example->expected_json ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $example->input_text],
                ['role' => 'assistant', 'content' => (string) $expected],
            ],
        ];
    }
}
