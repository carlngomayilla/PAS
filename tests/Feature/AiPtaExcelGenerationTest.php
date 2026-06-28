<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaExcelGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaExcelGenerationTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_normalized_excel_is_generated(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->validPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));

        $path = app(PtaExcelGenerationService::class)->generate($batch->refresh());
        Storage::disk('local')->assertExists($path);
    }
}
