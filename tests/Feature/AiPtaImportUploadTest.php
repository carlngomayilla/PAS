<?php

namespace Tests\Feature;

use App\Jobs\AnalyzePtaImportBatch;
use App\Models\AiImportBatch;
use App\Services\Ai\PtaExcelGenerationService;
use App\Services\Ai\PtaExtractionService;
use App\Services\Ai\PtaImportValidationService;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportUploadTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_controller_can_upload_pta_file(): void
    {
        $this->createAiReferential();
        Storage::fake('local');

        $response = $this->actingAs($this->createAiUser())
            ->post(route('workspace.ai-imports.pta.upload'), [
                'file' => $this->validPtaCsv(),
                'detected_year' => 2026,
            ]);

        $batch = AiImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('workspace.ai-imports.pta.preview', $batch));
        Storage::disk('local')->assertExists($batch->file_path);
        $this->assertSame('uploaded', $batch->status);
    }

    public function test_index_displays_ollama_status_without_claiming_full_reliability(): void
    {
        config([
            'ai.default' => 'ollama',
            'ai.providers.ollama.url' => 'http://127.0.0.1:11434',
            'ai_training.pta.llm_enabled' => true,
            'ai_training.pta.llm_provider' => 'ollama',
            'ai_training.pta.llm_text_model' => 'qwen3:1.7b',
            'ai_training.pta.llm_reasoning_model' => 'deepseek-r1:1.5b',
            'ai_training.pta.llm_vision_model' => 'qwen2.5vl:3b',
        ]);

        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'qwen3:1.7b'],
                    ['name' => 'deepseek-r1:1.5b'],
                    ['name' => 'qwen2.5vl:3b'],
                ],
            ]),
        ]);

        $this->actingAs($this->createAiUser())
            ->get(route('workspace.ai-imports.pta.index'))
            ->assertOk()
            ->assertSee('Ollama connecte, fiabilite a valider', false)
            ->assertSee('ne garantit pas une extraction PTA a 100/100', false)
            ->assertSee('qwen3:1.7b', false);
    }

    public function test_analyze_extends_php_runtime_before_slow_ocr_services_run(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $probe = new class
        {
            public ?int $maxExecutionTime = null;
        };

        config([
            'ai_training.pta.llm_timeout' => 20,
            'ai_training.pta.llm_vision_timeout' => 20,
            'ai_training.pta.pdf_ocr_timeout' => 240,
            'ai_training.pta.windows_ocr_timeout' => 300,
            'ai_training.pta.linux_ocr_timeout' => 240,
        ]);

        $this->app->bind(PtaExtractionService::class, fn (): PtaExtractionService => new class($probe) extends PtaExtractionService
        {
            public function __construct(private readonly object $probe) {}

            /**
             * @return array{created:int,confidence:float,warning:?string}
             */
            public function extract(AiImportBatch $batch): array
            {
                $this->probe->maxExecutionTime = (int) ini_get('max_execution_time');
                $batch->forceFill([
                    'status' => AiImportBatch::STATUS_EXTRACTED,
                    'confidence_score' => 70.0,
                    'error_message' => null,
                ])->save();

                return ['created' => 0, 'confidence' => 70.0, 'warning' => null];
            }
        });
        $this->app->bind(PtaNormalizationService::class, fn (): PtaNormalizationService => new class extends PtaNormalizationService
        {
            public function __construct() {}

            /**
             * @return array{rows:int,confidence:float}
             */
            public function normalize(AiImportBatch $batch): array
            {
                $batch->forceFill(['status' => AiImportBatch::STATUS_MAPPED])->save();

                return ['rows' => 0, 'confidence' => 70.0];
            }
        });
        $this->app->bind(PtaImportValidationService::class, fn (): PtaImportValidationService => new class extends PtaImportValidationService
        {
            public function __construct() {}

            /**
             * @return array{total:int,valid:int,invalid:int,ignored:int}
             */
            public function validateBatch(AiImportBatch $batch): array
            {
                $batch->forceFill(['status' => AiImportBatch::STATUS_VALIDATING])->save();

                return ['total' => 0, 'valid' => 0, 'invalid' => 0, 'ignored' => 0];
            }
        });
        $this->app->bind(PtaExcelGenerationService::class, fn (): PtaExcelGenerationService => new class extends PtaExcelGenerationService
        {
            public function generate(AiImportBatch $batch): string
            {
                $path = 'ai-imports/pta/'.$batch->id.'/pta-normalise-'.$batch->id.'.xlsx';
                $batch->forceFill(['generated_excel_path' => $path])->save();

                return $path;
            }
        });

        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'images_pta_pas_pao_anbg.pdf',
            'file_path' => 'ai-imports/pta/images_pta_pas_pao_anbg.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        $originalLimit = ini_get('max_execution_time');
        @ini_set('max_execution_time', '30');

        try {
            $this->actingAs($user)
                ->post(route('workspace.ai-imports.pta.analyze', $batch))
                ->assertRedirect(route('workspace.ai-imports.pta.preview', $batch));

            $this->assertGreaterThanOrEqual(360, $probe->maxExecutionTime);
        } finally {
            @ini_set('max_execution_time', $originalLimit === false ? '0' : (string) $originalLimit);
        }
    }

    public function test_analyze_dispatches_background_job_when_queue_is_not_sync(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['queue.default' => 'database']);
        $user = $this->createAiUser();

        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'images_pta_pas_pao_anbg.pdf',
            'file_path' => 'ai-imports/pta/images_pta_pas_pao_anbg.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        $this->actingAs($user)
            ->post(route('workspace.ai-imports.pta.analyze', $batch))
            ->assertRedirect(route('workspace.ai-imports.pta.preview', $batch))
            ->assertSessionHas('status', 'Analyse IA/OCR lancee en arriere-plan. La previsualisation se mettra a jour apres traitement.');

        $this->assertSame(AiImportBatch::STATUS_EXTRACTING, $batch->refresh()->status);
        Queue::assertPushed(AnalyzePtaImportBatch::class);
    }
}
