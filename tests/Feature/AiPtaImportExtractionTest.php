<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportExtractionTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_simulated_ai_extraction_creates_normalized_rows(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->validPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch))->assertRedirect();

        $row = AiImportRow::query()->firstOrFail();
        $this->assertSame('valid', $row->status);
        $this->assertSame('Action PTA IA', $row->normalized_payload['libelle_action']);
        $this->assertNotNull($batch->refresh()->generated_excel_path);
    }
}
