<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\AiTrainingExample;
use App\Models\Direction;
use App\Models\Service;
use App\Services\Ai\PtaImportCompletionService;
use App\Services\Ai\PtaImportHierarchyCoherenceService;
use App\Services\Ai\PtaImportValidationService;
use App\Services\Ai\PtaInvalidRowAutoRepairService;
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

    public function test_ocr_direction_and_service_aliases_are_accepted_by_validation(): void
    {
        $direction = Direction::query()->create([
            'code' => 'DG',
            'libelle' => 'Cabinet du DG',
            'actif' => true,
        ]);

        Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SCIQ',
            'libelle' => 'SCIQ',
            'actif' => true,
        ]);

        $payload = array_fill_keys(PtaNormalizationService::FIELDS, null);
        $payload = array_merge($payload, [
            'exercice' => '2026',
            'libelle_action' => 'Selectionner les documents perimes',
            'direction' => 'Direction Générale',
            'service' => 'Service Contrôle Interne et Qualité',
            'date_debut' => '2026-03-02',
            'date_fin' => '2026-03-13',
            'statut_initial' => 'non_demarre',
            'type_action' => 'NQ',
            'seuil_mode' => 'unique',
        ]);

        $validation = app(PtaImportValidationService::class)->validatePayload($payload);

        $this->assertNotContains('Direction introuvable dans le referentiel.', $validation['errors']);
        $this->assertNotContains('Service introuvable dans le referentiel.', $validation['errors']);
    }

    public function test_import_completion_fills_empty_cells_and_keeps_hierarchy_orders_coherent(): void
    {
        $referential = $this->createAiReferential();
        $batch = AiImportBatch::query()->create([
            'user_id' => $this->createAiUser()->id,
            'original_filename' => 'pta-scan.pdf',
            'file_path' => 'ai-imports/pta/pta-scan.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_MAPPED,
            'detected_year' => 2026,
            'detected_direction' => $referential['direction']->libelle,
            'detected_service' => $referential['service']->libelle,
        ]);

        AiImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_payload' => [],
            'normalized_payload' => [
                'libelle_axe' => 'Gouvernance institutionnelle',
                'libelle_objectif_strategique' => 'Ameliorer le pilotage',
                'libelle_objectif_operationnel' => 'Digitaliser le suivi PTA',
                'libelle_action' => 'Mettre en place le tableau de suivi PTA',
                'date_debut_action' => '2026-01-01',
                'date_fin_action' => '2026-03-31',
                'cible_minimum_execution' => '100%',
            ],
            'validation_errors' => null,
            'status' => AiImportRow::STATUS_PENDING,
        ]);

        AiImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_payload' => [],
            'normalized_payload' => [
                'libelle_action' => 'Rediger le rapport de mise en oeuvre',
                'date_debut_action' => '2026-04-01',
                'date_fin_action' => '2026-06-30',
                'cible_minimum_execution' => '100%',
            ],
            'validation_errors' => null,
            'status' => AiImportRow::STATUS_PENDING,
        ]);

        app(PtaImportCompletionService::class)->complete($batch->refresh());
        app(PtaImportHierarchyCoherenceService::class)->repairAndCheck($batch->refresh());
        $stats = app(PtaImportValidationService::class)->validateBatch($batch->refresh());

        $rows = $batch->refresh()->rows()->get();
        $first = $rows[0]->normalized_payload;
        $second = $rows[1]->normalized_payload;

        $this->assertSame(2, $stats['valid']);
        $this->assertSame(1, (int) $first['ordre_axe']);
        $this->assertSame(1, (int) $first['ordre_objectif_strategique']);
        $this->assertSame(1, (int) $first['ordre_objectif_operationnel']);
        $this->assertSame(1, (int) $first['ordre_action']);
        $this->assertSame('Gouvernance institutionnelle', $second['libelle_axe']);
        $this->assertSame('Ameliorer le pilotage', $second['libelle_objectif_strategique']);
        $this->assertSame('Digitaliser le suivi PTA', $second['libelle_objectif_operationnel']);
        $this->assertSame(2, (int) $second['ordre_action']);
        $this->assertSame('Direction SI', $second['direction']);
        $this->assertSame('Service Applications', $second['service_unite']);
        $this->assertSame('Preuve de realisation de l action', $second['justificatif_attendu']);
        $this->assertNotEmpty($second['type_action']);
        $this->assertNotEmpty($second['validation_warnings']);
    }

    public function test_successful_batch_validation_clears_stale_extraction_error_message(): void
    {
        $referential = $this->createAiReferential();
        $batch = AiImportBatch::query()->create([
            'user_id' => $this->createAiUser()->id,
            'original_filename' => 'pta-scan.pdf',
            'file_path' => 'ai-imports/pta/pta-scan.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_VALIDATING,
            'detected_year' => 2026,
            'detected_direction' => $referential['direction']->libelle,
            'detected_service' => $referential['service']->libelle,
            'error_message' => 'Le PDF semble etre un document scanne ou compose uniquement d images.',
        ]);

        AiImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_payload' => [],
            'normalized_payload' => $this->validImportPayload(),
            'validation_errors' => null,
            'status' => AiImportRow::STATUS_PENDING,
        ]);

        $stats = app(PtaImportValidationService::class)->validateBatch($batch->refresh());

        $this->assertSame(1, $stats['valid']);
        $this->assertSame(0, $stats['invalid']);
        $this->assertSame(AiImportBatch::STATUS_VALIDATED, $batch->refresh()->status);
        $this->assertNull($batch->error_message);
    }

    public function test_invalid_row_auto_repair_recovers_common_ocr_date_errors(): void
    {
        $referential = $this->createAiReferential();
        $batch = AiImportBatch::query()->create([
            'user_id' => $this->createAiUser()->id,
            'original_filename' => 'pta-scan.pdf',
            'file_path' => 'ai-imports/pta/pta-scan.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_VALIDATING,
            'detected_year' => 2026,
            'detected_direction' => $referential['direction']->libelle,
            'detected_service' => $referential['service']->libelle,
        ]);

        foreach ([
            [
                'libelle_action' => 'Organiser la session de cadrage',
                'date_debut' => '26/02',
                'date_fin' => '31/12',
                'echeance' => '31/12',
            ],
            [
                'libelle_action' => 'Traiter la demande ANBG',
                'date_debut_action' => 'ao/04/26 ao/0E/2 ANBG',
                'date_fin_action' => '',
            ],
            [
                'libelle_action' => 'Actualiser la liste trimestrielle',
                'date_debut' => '20/01',
                'date_fin' => 'trimestri Non demarre la liste des',
                'echeance' => '',
            ],
            [
                'libelle_action' => 'Transmettre la fiche de besoin',
                'date_debut' => 'Des 31/12/2 transmis e',
                'date_fin' => '',
                'echeance' => '',
            ],
        ] as $index => $payload) {
            AiImportRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $index + 1,
                'raw_payload' => $payload,
                'normalized_payload' => array_merge($this->validImportPayload(), $payload),
                'validation_errors' => ['errors' => ['Date invalide.'], 'warnings' => []],
                'status' => AiImportRow::STATUS_INVALID,
            ]);
        }

        $repair = app(PtaInvalidRowAutoRepairService::class)->repair($batch->refresh());
        $stats = app(PtaImportValidationService::class)->validateBatch($batch->refresh());
        $payloads = $batch->refresh()->rows()->get()->pluck('normalized_payload');

        $this->assertSame(4, $repair['rows']);
        $this->assertSame(4, $stats['valid']);
        $this->assertSame(0, $stats['invalid']);
        $this->assertSame('2026-02-26', $payloads[0]['date_debut']);
        $this->assertSame('2026-12-31', $payloads[0]['date_fin']);
        $this->assertSame('2026-04-20', $payloads[1]['date_debut']);
        $this->assertSame('2026-05-20', $payloads[1]['date_fin']);
        $this->assertSame('2026-12-31', $payloads[2]['date_fin']);
        $this->assertSame('2026-12-31', $payloads[3]['date_fin']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validImportPayload(): array
    {
        return [
            'exercice' => '2026',
            'direction' => 'Direction SI',
            'service' => 'Service Applications',
            'libelle_action' => 'Action PTA IA',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'echeance' => '2026-12-31',
            'statut_initial' => 'non_demarre',
            'type_action' => 'NQ',
            'seuil_mode' => 'unique',
        ];
    }
}
