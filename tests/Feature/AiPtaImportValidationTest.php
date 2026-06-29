<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\AiTrainingExample;
use App\Services\Ai\PtaNormalizationService;
use App\Services\Imports\PlanningExcelImportService;
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
        $this->assertDatabaseHas('ai_training_examples', [
            'task' => AiTrainingExample::TASK_CORRECTION,
            'source' => 'human_correction',
            'is_validated' => true,
        ]);
    }

    public function test_human_correction_accepts_official_import_global_columns(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->invalidPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));
        $row = AiImportRow::query()->firstOrFail();

        $officialPayload = array_fill_keys(PlanningExcelImportService::IMPORT_COLUMNS, null);
        $officialPayload = array_merge($officialPayload, [
            'annee_debut_pas' => '2026',
            'annee_fin_pas' => '2026',
            'ordre_axe' => '1',
            'libelle_axe' => 'Axe test',
            'ordre_objectif_strategique' => '1',
            'libelle_objectif_strategique' => 'Objectif strategique test',
            'date_echeance_objectif_strategique' => '2026-12-31',
            'direction' => 'Direction SI',
            'service_unite' => 'Service Applications',
            'ordre_objectif_operationnel' => '1',
            'libelle_objectif_operationnel' => 'Objectif operationnel test',
            'date_echeance_objectif_operationnel' => '2026-12-31',
            'ordre_action' => '1',
            'libelle_action' => 'Action a corriger',
            'date_debut_action' => '2026-01-01',
            'date_fin_action' => '2026-12-31',
            'codes_agents_rmo' => '',
            'cible_minimum_execution' => '100',
            'justificatif_attendu' => 'Rapport',
            'type_action' => 'NQ',
            'seuil_mode' => 'unique',
            'nombre_sous_actions' => '0',
            'niveau_risque' => 'faible',
            'financement' => '0',
            'commentaire_obligatoire' => '0',
            'champ_difficulte' => '1',
        ]);

        $this->actingAs($user)
            ->patch(route('workspace.ai-imports.pta.rows.update', [$batch, $row]), [
                'normalized' => $officialPayload,
                'action' => 'save',
            ])
            ->assertRedirect();

        $payload = $row->refresh()->normalized_payload;

        $this->assertSame('corrected', $row->status);
        $this->assertSame('2026', (string) $payload['exercice']);
        $this->assertSame('Service Applications', $payload['service']);
        $this->assertSame('2026-12-31', $payload['date_fin']);
        $this->assertSame('Service Applications', $payload['service_unite']);
    }
}
