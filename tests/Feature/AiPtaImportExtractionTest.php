<?php

namespace Tests\Feature;

use App\Ai\Agents\PtaImportExtractionAgent;
use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaDocumentTextExtractionService;
use App\Services\Ai\PtaExternalAiExtractionService;
use App\Services\Ai\PtaExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Smalot\PdfParser\Parser;
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

    public function test_pdf_extraction_uses_laravel_ai_agent_when_available(): void
    {
        $this->createAiReferential();
        config()->set('ai_training.pta.llm_provider', 'ollama');
        config()->set('ai_training.pta.llm_text_model', 'qwen3:8b');
        config()->set('ai_training.pta.llm_reasoning_model', 'deepseek-r1:14b');
        config()->set('ai_training.pta.pdf_text_command', 'fake-text {file}');

        Process::fake([
            '*fake-text*' => Process::result(output: $this->ptaOcrText()),
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

        $usedProvider = null;
        $usedModel = null;

        PtaImportExtractionAgent::fake(function ($prompt, $attachments, $provider, $model) use (&$usedProvider, &$usedModel): array {
            $usedProvider = $provider->name();
            $usedModel = $model;

            return [
                'rows' => [[
                    'annee_debut_pas' => 2026,
                    'annee_fin_pas' => 2028,
                    'ordre_axe' => 2,
                    'libelle_axe' => 'REDRESSEMENT DE LA SITUATION FINANCIERE',
                    'ordre_objectif_strategique' => 1,
                    'libelle_objectif_strategique' => 'Rationaliser la depense de bourse',
                    'direction' => 'Cabinet du DG',
                    'service_unite' => 'Collaborateurs',
                    'ordre_objectif_operationnel' => 1,
                    'libelle_objectif_operationnel' => 'Detruire les archives perimees',
                    'ordre_action' => 1,
                    'libelle_action' => 'Action structuree par OpenAI',
                    'date_debut_action' => '2026-03-02',
                    'date_fin_action' => '2026-03-13',
                    'codes_agents_rmo' => 'DG-006',
                    'cible_minimum_execution' => '100',
                    'justificatif_attendu' => 'Objectifs definis',
                    'type_action' => 'NQ',
                    'seuil_mode' => 'unique',
                    'page_pdf' => null,
                    'score_confiance_ia' => null,
                    'note_normalisation' => null,
                ]],
                'log' => [[
                    'ligne_import' => 1,
                    'page_pdf' => 8,
                    'score_confiance_ia' => 0.81,
                    'note_normalisation' => 'Extraction IA depuis OCR',
                ]],
            ];
        })->preventStrayPrompts();

        Storage::fake('local');
        $path = 'ai-imports/pta/scan/ai-scan.pdf';
        Storage::disk('local')->put($path, '%PDF-1.3');

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'ai-scan.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
            'detected_year' => 2026,
        ]);

        $result = app(PtaExtractionService::class)->extract($batch);

        $row = AiImportRow::query()->firstOrFail();
        $this->assertSame(1, $result['created']);
        $this->assertSame('Action structuree par OpenAI', $row->raw_payload['libelle_action']);
        $this->assertSame(8, $row->raw_payload['page_pdf']);
        $this->assertSame(0.81, $row->raw_payload['score_confiance_ia']);
        $this->assertSame('ollama', $usedProvider);
        $this->assertSame('qwen3:8b', $usedModel);
        PtaImportExtractionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'SOURCE_TYPE=pdf'));
        PtaImportExtractionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'MODELES_LOCAUX_CONFIGURES='));
        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'fake-text')
            && $process->timeout === 120);
    }

    public function test_spreadsheet_extraction_uses_laravel_ai_agent_when_available(): void
    {
        $this->createAiReferential();
        config()->set('ai_training.pta.llm_provider', 'ollama');
        config()->set('ai_training.pta.llm_text_model', 'qwen3:8b');
        config()->set('ai_training.pta.llm_reasoning_model', 'deepseek-r1:14b');

        $usedModel = null;

        PtaImportExtractionAgent::fake(function ($prompt, $attachments, $provider, $model) use (&$usedModel): array {
            $usedModel = $model;

            return [
                'rows' => [[
                    'annee_debut_pas' => 2026,
                    'annee_fin_pas' => 2028,
                    'ordre_axe' => 1,
                    'libelle_axe' => 'GOUVERNANCE INSTITUTIONNELLE',
                    'direction' => 'Cabinet du DG',
                    'service_unite' => 'Collaborateurs',
                    'ordre_action' => 1,
                    'libelle_action' => 'Action structuree depuis Excel par IA',
                    'date_debut_action' => '2026-01-01',
                    'date_fin_action' => '2026-02-01',
                    'codes_agents_rmo' => 'DG-006',
                    'cible_minimum_execution' => '100',
                    'justificatif_attendu' => 'Fichier normalise',
                    'type_action' => 'NQ',
                    'seuil_mode' => 'unique',
                ]],
                'log' => [],
            ];
        })->preventStrayPrompts();

        Storage::fake('local');
        $path = 'ai-imports/pta/table/source.csv';
        Storage::disk('local')->put($path, implode("\n", [
            'Axe;Action;Debut;Fin;RMO',
            'Gouvernance;Action brute Excel;2026-01-01;2026-02-01;DG-006',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'source.csv',
            'file_path' => $path,
            'file_type' => 'csv',
            'status' => AiImportBatch::STATUS_UPLOADED,
            'detected_year' => 2026,
        ]);

        app(PtaExtractionService::class)->extract($batch);

        $row = AiImportRow::query()->firstOrFail();
        $this->assertSame('Action structuree depuis Excel par IA', $row->raw_payload['libelle_action']);
        $this->assertSame('deepseek-r1:14b', $usedModel);
        PtaImportExtractionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'SOURCE_TYPE=spreadsheet'));
    }

    public function test_ollama_provider_is_available_when_configured_and_health_check_passes(): void
    {
        config()->set('ai_training.pta.llm_provider', 'ollama');
        config()->set('ai_training.pta.llm_allow_in_tests', true);
        config()->set('ai.providers.ollama.url', 'http://127.0.0.1:11434');

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response(['models' => []]),
        ]);

        $this->assertTrue(app(PtaExternalAiExtractionService::class)->available());

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:11434/api/tags');
    }

    public function test_unavailable_ollama_backend_falls_back_to_source_rows_with_warning(): void
    {
        config()->set('ai_training.pta.llm_provider', 'ollama');
        config()->set('ai_training.pta.llm_allow_in_tests', true);
        config()->set('ai.providers.ollama.url', 'http://127.0.0.1:11434');

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::failedConnection(),
        ]);

        Storage::fake('local');
        $path = 'ai-imports/pta/table/source.csv';
        Storage::disk('local')->put($path, implode("\n", [
            'Axe;Action;Debut;Fin;RMO',
            'Gouvernance;Action brute Excel;2026-01-01;2026-02-01;DG-006',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'source.csv',
            'file_path' => $path,
            'file_type' => 'csv',
            'status' => AiImportBatch::STATUS_UPLOADED,
            'detected_year' => 2026,
        ]);

        $result = app(PtaExtractionService::class)->extract($batch);

        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('Ollama est indisponible', (string) $result['warning']);
        $this->assertStringContainsString('extraction locale', (string) $batch->refresh()->error_message);
        $this->assertSame('Action brute Excel', AiImportRow::query()->firstOrFail()->raw_payload['action']);
    }

    public function test_ai_provider_rate_limit_is_kept_as_batch_warning_when_source_rows_are_used(): void
    {
        config()->set('ai.providers.openai.key', 'test-key');

        PtaImportExtractionAgent::fake(function ($prompt, $attachments, $provider, $model): never {
            throw RateLimitedException::forProvider('openai');
        })->preventStrayPrompts();

        Storage::fake('local');
        $path = 'ai-imports/pta/table/source.csv';
        Storage::disk('local')->put($path, implode("\n", [
            'Axe;Action;Debut;Fin;RMO',
            'Gouvernance;Action brute Excel;2026-01-01;2026-02-01;DG-006',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'source.csv',
            'file_path' => $path,
            'file_type' => 'csv',
            'status' => AiImportBatch::STATUS_UPLOADED,
            'detected_year' => 2026,
        ]);

        $result = app(PtaExtractionService::class)->extract($batch);

        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('limite', (string) $result['warning']);
        $this->assertStringContainsString('limite', (string) $batch->refresh()->error_message);
        $this->assertSame('Action brute Excel', AiImportRow::query()->firstOrFail()->raw_payload['action']);
    }

    public function test_image_only_pdf_requires_ocr_instead_of_creating_placeholder_row(): void
    {
        config()->set('ai_training.pta.windows_ocr_enabled', false);

        Process::fake([
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

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

    public function test_ocr_engine_none_skips_bundled_ocr_commands(): void
    {
        config()->set('ai_training.pta.ocr_engine', 'none');
        config()->set('ai_training.pta.windows_ocr_enabled', true);
        config()->set('ai_training.pta.linux_ocr_enabled', true);

        Process::fake([
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

        Storage::fake('local');
        $path = 'ai-imports/pta/scan/no-ocr.pdf';
        Storage::disk('local')->put($path, implode("\n", [
            '%PDF-1.3',
            '1 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            '2 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            'trailer <<>>',
            '%%EOF',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'no-ocr.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        try {
            app(PtaExtractionService::class)->extract($batch);
            $this->fail('Le PDF image-only aurait du echouer sans OCR actif.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('OCR local none', $exception->getMessage());
        }

        Process::assertNotRan(fn ($process): bool => is_array($process->command)
            && collect($process->command)->contains(fn (string $part): bool => str_contains($part, 'windows_pdf_ocr.ps1') || str_contains($part, 'linux_pdf_ocr.sh')));
    }

    public function test_image_only_pdf_uses_configured_ocr_command_to_extract_rows(): void
    {
        $this->createAiReferential();
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        config()->set('ai_training.pta.pdf_ocr_command', 'fake-ocr {file}');
        $this->failIfSmalotParserIsStarted();

        Process::fake([
            '*fake-ocr*' => Process::result(output: $this->ptaOcrText()),
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

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
        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'fake-ocr')
            && $process->timeout === 900);
    }

    public function test_image_dominant_pdf_with_text_operators_skips_smalot_parser(): void
    {
        config()->set('ai_training.pta.ocr_engine', 'none');
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        config()->set('ai_training.pta.linux_ocr_enabled', false);
        $this->failIfSmalotParserIsStarted();

        Process::fake([
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

        Storage::fake('local');
        $path = 'ai-imports/pta/scan/image-dominant-with-text-ops.pdf';
        Storage::disk('local')->put($path, implode("\n", [
            '%PDF-1.4',
            ...array_map(
                static fn (int $index): string => $index.' 0 obj << /Subtype /Image /Width 100 /Height 100 >> stream image endstream endobj',
                range(1, 23)
            ),
            str_repeat('BT /F1 12 Tf 10 10 Td Tj ET'.PHP_EOL, 46),
            'trailer <<>>',
            '%%EOF',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'image-dominant-with-text-ops.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        try {
            app(PtaExtractionService::class)->extract($batch);
            $this->fail('Le PDF image-dominant aurait du eviter Smalot et demander un OCR.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('document scanne', $exception->getMessage());
            $this->assertStringContainsString('OCR local none', $exception->getMessage());
        }

        $this->assertSame(AiImportBatch::STATUS_FAILED, $batch->refresh()->status);
        $this->assertSame(0, AiImportRow::query()->count());
    }

    public function test_large_pdf_skips_smalot_parser_and_reports_actionable_error(): void
    {
        config()->set('ai_training.pta.pdf_parser_max_bytes', 32);
        config()->set('ai_training.pta.ocr_engine', 'none');
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        config()->set('ai_training.pta.linux_ocr_enabled', false);
        $this->failIfSmalotParserIsStarted();

        Process::fake([
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

        Storage::fake('local');
        $path = 'ai-imports/pta/heavy/heavy.pdf';
        Storage::disk('local')->put($path, str_repeat('%PDF-1.4 no text stream '.PHP_EOL, 4));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'heavy.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        try {
            app(PtaExtractionService::class)->extract($batch);
            $this->fail('Le PDF lourd aurait du eviter Smalot et retourner une erreur exploitable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Smalot', $exception->getMessage());
            $this->assertStringContainsString('AI_PTA_PDF_PARSER_MAX_BYTES', $exception->getMessage());
            $this->assertStringContainsString('AI_PTA_PDF_TEXT_COMMAND', $exception->getMessage());
        }

        $this->assertSame(AiImportBatch::STATUS_FAILED, $batch->refresh()->status);
        $this->assertSame(0, AiImportRow::query()->count());
    }

    public function test_configured_ocr_engine_failure_reports_actionable_message(): void
    {
        config()->set('ai_training.pta.windows_ocr_enabled', false);
        config()->set('ai_training.pta.linux_ocr_enabled', false);
        config()->set('ai_training.pta.ocr_engine', 'paddleocr');
        config()->set('ai_training.pta.paddleocr_command', 'paddleocr {file}');

        Process::fake([
            '*paddleocr*' => Process::result(errorOutput: 'paddleocr not installed', exitCode: 127),
            '*' => Process::result(exitCode: 1),
        ])->preventStrayProcesses();

        Storage::fake('local');
        $path = 'ai-imports/pta/scan/paddle-fail.pdf';
        Storage::disk('local')->put($path, implode("\n", [
            '%PDF-1.3',
            '1 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            '2 0 obj << /Subtype /Image /Width 100 /Height 100 >> endobj',
            'trailer <<>>',
            '%%EOF',
        ]));

        $batch = AiImportBatch::query()->create([
            'original_filename' => 'paddle-fail.pdf',
            'file_path' => $path,
            'file_type' => 'pdf',
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        try {
            app(PtaExtractionService::class)->extract($batch);
            $this->fail('Le PDF image-only aurait du signaler l echec du moteur OCR configure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('OCR local paddleocr', $exception->getMessage());
            $this->assertStringContainsString('AI_PTA_PADDLEOCR_COMMAND', $exception->getMessage());
        }

        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'paddleocr'));
    }

    private function ptaOcrText(): string
    {
        return <<<'TEXT'
AXE STRATEGIQUE
REDRESSEMENT DE LA SITUATION FINANCIERE
OBJECTIF STRATEGIQUE
RATIONALISER LA DEPENSE DE BOURSE
OBJECTIF OPERATIONNEL N 1
DETRUIRE LES ARCHIVES PERIMEES
DESCRIPTION DES ACTIONS DETAILLEES RMO CIBLE DEBUT FIN ETAT DE REALISATION RESSOURCES REQUISES INDICATEURS DE PERFORMANCE RISQUES POTENTIELS
Selectionner les documents perimes    Clovis    100%    02/03/26    13/03/26    Non demarre    Personnel archives    Objectifs definis    Documents non perimes
TEXT;
    }

    private function failIfSmalotParserIsStarted(): void
    {
        $this->app->bind(PtaDocumentTextExtractionService::class, fn (): PtaDocumentTextExtractionService => new class extends PtaDocumentTextExtractionService
        {
            protected function makePdfParser(): Parser
            {
                throw new RuntimeException('Smalot PDF Parser should not be started for this PDF.');
            }
        });
    }
}
