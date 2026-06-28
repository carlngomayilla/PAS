<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaExcelGenerationService;
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

        $this->assertNotFalse($typeIndex);
        $this->assertNotFalse($quantityIndex);
        $this->assertNotFalse($unitIndex);
        $this->assertSame('Q', $values[$typeIndex]);
        $this->assertSame(100.0, (float) $values[$quantityIndex]);
        $this->assertSame('%', $values[$unitIndex]);
    }
}
