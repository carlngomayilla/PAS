<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportPreviewTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_preview_displays_extracted_rows(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->validPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', $batch))
            ->assertOk()
            ->assertSee('Action PTA IA')
            ->assertSee('annee_debut_pas')
            ->assertSee('service_unite')
            ->assertSee('libelle_action')
            ->assertSee('codes_agents_rmo')
            ->assertSee('montant_financement')
            ->assertDontSee('Type propose');

        $this->assertContains('champ_difficulte', PlanningExcelImportService::IMPORT_COLUMNS);
    }
}
