<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function test_image_only_pdf_requires_ocr_instead_of_creating_placeholder_row(): void
    {
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        Storage::fake('local');
        $path = 'ai-imports/pta/scan/scan.pdf';
        Storage::disk('local')->put($path, implode("\n", [
            '%PDF-1.3',
            '1 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            '2 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            'trailer <<>>',
            '%%EOF',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'scan.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        try {
            app(PtaExtractionService::class)->extract($batch);
            $this->fail('Le PDF image-only aurait du exiger un OCR.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('document scanne', $exception->getMessage());
        }

        $this->assertSame(AiImportBatch::STATUS_FAILED, $batch->refresh()->status);
        $this->assertSame(0, AiImportRow::query()->count());
    }

    public function test_image_only_pdf_uses_configured_ocr_command_to_extract_rows(): void
    {
        $this->createAiReferential();
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        config()->set('ai_training.pta.pdf_ocr_command', implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('tests/Fixtures/pta_ocr_command.php')),
            '{file}',
        ]));

        Storage::fake('local');
        $path = 'ai-imports/pta/scan/ocr-scan.pdf';
        Storage::disk('local')->put($path, implode("\n", [
            '%PDF-1.3',
            '1 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            '2 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            'trailer <<>>',
            '%%EOF',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'ocr-scan.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        $result = app(PtaExtractionService::class)->extract($batch);

        $row = AiImportRow::query()->firstOrFail();
        $this->assertSame(1, $result['created']);
        $this->assertSame(AiImportBatch::STATUS_EXTRACTED, $batch->refresh()->status);
        $this->assertSame('Selectionner les documents perimes', $row->raw_payload['libelle_action']);
        $this->assertSame('REDRESSEMENT DE LA SITUATION FINANCIERE', $row->raw_payload['libelle_axe']);
    }
}
