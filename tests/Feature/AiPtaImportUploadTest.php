<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
