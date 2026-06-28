<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\AiImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportFinalImportTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_final_import_creates_pta_actions_after_validation(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->validPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));

        $this->actingAs($user)
            ->post(route('workspace.ai-imports.pta.import', $batch))
            ->assertRedirect();

        $this->assertDatabaseHas('actions', ['libelle' => 'Action PTA IA']);
        $action = Action::query()->where('libelle', 'Action PTA IA')->firstOrFail();
        $this->assertSame('parametre', $action->statut_parametrage);
        $this->assertSame(Action::TYPE_QUANTITATIVE, $action->type_action);
        $this->assertSame(Action::MODE_QUANTITATIF, $action->mode_evaluation);
        $this->assertSame('%', $action->unite_cible);
        $this->assertEquals(100.0, (float) $action->quantite_cible);
        $this->assertSame('imported', $batch->refresh()->status);
    }
}
