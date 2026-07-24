<?php

namespace Tests\Feature;

use App\Models\AiImportRow;
use App\Models\AiImportSession;
use App\Models\AiUsageLog;
use App\Services\AiReporting\MonthlyReportService;
use App\Services\OpenAi\OpenAiUsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiInstitutionalModuleTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_generic_import_upload_analyzes_csv_and_generates_excel(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        config([
            'queue.default' => 'sync',
            'services.openai_responses.key' => null,
        ]);

        $response = $this->actingAs($this->createAiUser())
            ->post(route('workspace.ai-imports.upload'), [
                'file' => $this->validPtaCsv(),
                'document_type' => 'PTA',
            ]);

        $session = AiImportSession::query()->firstOrFail();
        $response->assertRedirect(route('workspace.ai-imports.review', $session));

        $row = AiImportRow::query()->whereBelongsTo($session, 'session')->firstOrFail();
        $this->assertSame(AiImportRow::IMPORT_READY, $row->statut_import);
        $this->assertSame('Action PTA IA', $row->action);
        $this->assertSame('Direction SI', $row->direction);
        $this->assertSame('Service Applications', $row->service);
        $this->assertNotNull($session->refresh()->generated_excel_path);
        Storage::disk('local')->assertExists($session->generated_excel_path);
    }

    public function test_generic_import_blocks_rows_with_missing_referential_data(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        config([
            'queue.default' => 'sync',
            'services.openai_responses.key' => null,
        ]);

        $this->actingAs($this->createAiUser())
            ->post(route('workspace.ai-imports.upload'), [
                'file' => $this->invalidPtaCsv(),
                'document_type' => 'PTA',
            ]);

        $session = AiImportSession::query()->firstOrFail();
        $row = AiImportRow::query()->whereBelongsTo($session, 'session')->firstOrFail();

        $this->assertSame(AiImportRow::IMPORT_ATTACHMENT_ERROR, $row->statut_import);
        $this->assertGreaterThan(0, $session->refresh()->total_errors);
        $this->assertTrue($session->errors()->exists());
    }

    public function test_openai_usage_billing_records_estimated_cost(): void
    {
        config([
            'services.openai_responses.input_cost_per_1m_tokens' => 1.00,
            'services.openai_responses.output_cost_per_1m_tokens' => 2.00,
            'services.openai_responses.monthly_budget_usd' => 20,
        ]);

        $user = $this->createAiUser();
        $billing = app(OpenAiUsageBillingService::class);
        $log = $billing->record($user, 'ai_import', 'pas_pao_pta_import', 'gpt-test', 1000, 2000, 'resp_test');

        $this->assertSame(3000, $log->total_tokens);
        $this->assertSame('0.005000', $log->total_cost_usd);
        $this->assertSame(1, AiUsageLog::query()->count());
    }

    public function test_monthly_ai_report_is_generated_from_database_metrics(): void
    {
        $fixture = $this->createReportFixture();
        config(['services.openai_responses.key' => null]);

        $report = app(MonthlyReportService::class)->generate(
            $this->createAiUser(),
            '2026-12-01',
            ['direction_id' => $fixture['direction']->id]
        );

        $this->assertSame('monthly', $report->request->report_type);
        $this->assertNotEmpty($report->summary);
        $this->assertGreaterThan(0, $report->sections()->count());
        $this->assertSame('laravel_database', $report->report_json['source']);
    }
}
