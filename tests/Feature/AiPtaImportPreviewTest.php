<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaImportPreviewTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_uploaded_batch_preview_displays_lightweight_pending_state(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'images_pta_pas_pao_anbg.pdf',
            'file_path' => 'ai-imports/pta/table/images_pta_pas_pao_anbg.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', $batch))
            ->assertOk()
            ->assertSee('Fichier charge, analyse IA a lancer.')
            ->assertSee('Analyser avec IA')
            ->assertSee("Aucune ligne extraite pour le moment. Lancez l'analyse IA pour demarrer le traitement.");
    }

    public function test_extracting_batch_preview_displays_running_state(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'images_pta_pas_pao_anbg.pdf',
            'file_path' => 'ai-imports/pta/table/images_pta_pas_pao_anbg.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_EXTRACTING,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', $batch))
            ->assertOk()
            ->assertSee('Analyse IA/OCR en cours.')
            ->assertSee('Analyse en cours')
            ->assertSee('disabled', false);
    }

    public function test_preview_rows_are_paginated_to_keep_large_batches_responsive(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'source.csv',
            'file_path' => 'ai-imports/pta/table/source.csv',
            'file_type' => 'csv',
            'status' => AiImportBatch::STATUS_VALIDATED,
        ]);

        foreach (range(1, 55) as $rowNumber) {
            AiImportRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'raw_payload' => ['libelle_action' => 'Action PTA page '.$rowNumber],
                'normalized_payload' => ['libelle_action' => 'Action PTA page '.$rowNumber],
                'validation_errors' => null,
                'status' => AiImportRow::STATUS_VALID,
            ]);
        }

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', $batch))
            ->assertOk()
            ->assertSee('Affichage pagine pour eviter les lenteurs : lignes 1-50 sur 55.')
            ->assertSee('Action PTA page 50')
            ->assertDontSee('Action PTA page 51');

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', ['batch' => $batch, 'page' => 2]))
            ->assertOk()
            ->assertSee('Affichage pagine pour eviter les lenteurs : lignes 51-55 sur 55.')
            ->assertSee('Action PTA page 51');
    }

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

    public function test_preview_displays_ai_provider_warning(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $batch = AiImportBatch::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'source.csv',
            'file_path' => 'ai-imports/pta/table/source.csv',
            'file_type' => 'csv',
            'status' => AiImportBatch::STATUS_VALIDATING,
            'error_message' => 'L appel IA Openai a ete limite temporairement.',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.preview', $batch))
            ->assertOk()
            ->assertSee('L appel IA Openai a ete limite temporairement.');
    }
}
