<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportValidationTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_human_correction_makes_invalid_row_valid(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->invalidPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));
        $row = AiImportRow::query()->firstOrFail();
        $this->assertSame('invalid', $row->status);

        $payload = array_fill_keys(PtaNormalizationService::FIELDS, null);
        $payload = array_merge($payload, $row->normalized_payload, ['service' => 'Service Applications']);

        $this->actingAs($user)
            ->patch(route('workspace.ai-imports.pta.rows.update', [$batch, $row]), [
                'normalized' => $payload,
                'action' => 'save',
            ])
            ->assertRedirect();

        $this->assertSame('corrected', $row->refresh()->status);
    }
}
