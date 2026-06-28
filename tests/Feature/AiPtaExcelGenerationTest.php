<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaExcelGenerationService;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        $workbook = IOFactory::load(Storage::disk('local')->path($path));

        $this->assertSame(1, $workbook->getSheetCount());
        $this->assertSame('IMPORT_GLOBAL', $workbook->getSheet(0)->getTitle());

        $sheet = $workbook->getSheet(0);
        $rows = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'2', null, true, false);
        $headers = $rows[0] ?? [];
        $values = $rows[1] ?? [];
        $typeIndex = array_search('type_action', $headers, true);
        $quantityIndex = array_search('quantite_cible', $headers, true);
        $unitIndex = array_search('unite_cible', $headers, true);

        $this->assertSame(PlanningExcelImportService::IMPORT_COLUMNS, $headers);
        $this->assertNotFalse($typeIndex);
        $this->assertNotFalse($quantityIndex);
        $this->assertNotFalse($unitIndex);
        $this->assertSame('Q', $values[$typeIndex]);
        $this->assertSame(100.0, (float) $values[$quantityIndex]);
        $this->assertSame('%', $values[$unitIndex]);
    }

    public function test_download_regenerates_existing_stale_excel_with_official_import_columns(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        $user = $this->createAiUser();

        $this->actingAs($user)->post(route('workspace.ai-imports.pta.upload'), ['file' => $this->validPtaCsv()]);
        $batch = AiImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('workspace.ai-imports.pta.analyze', $batch));

        Storage::disk('local')->put((string) $batch->refresh()->generated_excel_path, 'ancien-format-obsolete');

        $this->actingAs($user)
            ->get(route('workspace.ai-imports.pta.excel', $batch))
            ->assertOk();

        $workbook = IOFactory::load(Storage::disk('local')->path((string) $batch->refresh()->generated_excel_path));
        $sheet = $workbook->getSheet(0);
        $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, false)[0] ?? [];

        $this->assertSame(1, $workbook->getSheetCount());
        $this->assertSame('IMPORT_GLOBAL', $sheet->getTitle());
        $this->assertSame(PlanningExcelImportService::IMPORT_COLUMNS, $headers);
    }

    public function test_excel_export_preserves_official_hierarchy_columns_from_ai_payload(): void
    {
        Storage::fake('local');

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'pta-texte.pdf',
            'file_path' => 'ai-imports/pta/1/pta-texte.pdf',
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_VALIDATED,
            'detected_year' => 2026,
            'detected_direction' => 'Direction SI',
            'detected_service' => 'Service Applications',
        ]);

        AiImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_payload' => [],
            'normalized_payload' => [
                'exercice' => 2026,
                'annee_debut_pas' => 2026,
                'annee_fin_pas' => 2028,
                'ordre_axe' => 2,
                'libelle_axe' => 'REDRESSEMENT DE LA SITUATION FINANCIERE',
                'ordre_objectif_strategique' => 1,
                'libelle_objectif_strategique' => 'Rationaliser la depense de bourse',
                'direction' => 'Direction SI',
                'service' => 'Service Applications',
                'service_unite' => 'Service Applications',
                'ordre_objectif_operationnel' => 1,
                'libelle_objectif_operationnel' => 'Detruire les archives perimees',
                'ordre_action' => 3,
                'libelle_action' => 'Rediger un rapport',
                'date_debut_action' => '16/11/26',
                'date_fin_action' => '30/12/26',
                'codes_agents_rmo' => 'DG-006',
                'cible_minimum_execution' => 100,
                'justificatif_attendu' => 'Rapport redige',
                'type_action' => 'NQ',
                'seuil_mode' => 'unique',
                'niveau_risque' => 'faible',
            ],
            'validation_errors' => null,
            'status' => AiImportRow::STATUS_VALID,
        ]);

        $path = app(PtaExcelGenerationService::class)->generate($batch->refresh());
        $workbook = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $workbook->getSheet(0);
        $rows = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'2', null, true, false);
        $headers = $rows[0] ?? [];
        $values = array_combine($headers, $rows[1] ?? []);

        $this->assertSame(2026, (int) $values['annee_debut_pas']);
        $this->assertSame(2028, (int) $values['annee_fin_pas']);
        $this->assertSame(2, (int) $values['ordre_axe']);
        $this->assertSame('REDRESSEMENT DE LA SITUATION FINANCIERE', $values['libelle_axe']);
        $this->assertSame(1, (int) $values['ordre_objectif_operationnel']);
        $this->assertSame('Detruire les archives perimees', $values['libelle_objectif_operationnel']);
        $this->assertSame(3, (int) $values['ordre_action']);
        $this->assertSame('2026-11-16', $values['date_debut_action']);
    }
}
