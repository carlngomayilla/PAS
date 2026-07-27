<?php

namespace Tests\Feature;

use App\Models\AiImportSession;
use App\Models\AiUsageLog;
use App\Services\AiReporting\MonthlyReportService;
use App\Services\OpenAi\OpenAiUsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiInstitutionalModuleTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_generic_ai_import_fails_closed_without_openai(): void
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

        $response->assertSessionHasErrors('openai');
        $this->assertSame(AiImportSession::STATUS_FAILED, AiImportSession::query()->firstOrFail()->status);
        $this->assertDatabaseCount('ai_import_rows', 0);
    }

    public function test_generic_ai_import_does_not_fallback_when_openai_fails(): void
    {
        $this->createAiReferential();
        Storage::fake('local');
        config([
            'queue.default' => 'sync',
            'services.openai_responses.key' => 'test-openai-key',
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'failure']], 500)]);

        $response = $this->actingAs($this->createAiUser())
            ->post(route('workspace.ai-imports.upload'), [
                'file' => $this->invalidPtaCsv(),
                'document_type' => 'PTA',
            ]);

        $session = AiImportSession::query()->firstOrFail();
        $response->assertSessionHasErrors('openai');
        $this->assertSame(AiImportSession::STATUS_FAILED, $session->status);
        $this->assertDatabaseCount('ai_import_rows', 0);
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
        config([
            'services.openai_responses.key' => 'test-openai-key',
            'services.openai_responses.model_high' => 'gpt-test',
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_monthly',
                'status' => 'completed',
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'Rapport mensuel OpenAI',
                            'summary' => 'Synthese fondee sur les metriques Laravel.',
                            'html' => '<section><h2>Resume</h2><p>Synthese.</p></section>',
                            'sections' => [[
                                'title' => 'Resume executif',
                                'content' => 'Synthese fondee sur les metriques Laravel.',
                                'indicators' => [],
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
            ]),
        ]);

        $report = app(MonthlyReportService::class)->generate(
            $this->createAiUser(),
            '2026-12-01',
            ['direction_id' => $fixture['direction']->id]
        );

        $this->assertSame('monthly', $report->request->report_type);
        $this->assertNotEmpty($report->summary);
        $this->assertGreaterThan(0, $report->sections()->count());
        $this->assertSame('laravel_database', $report->report_json['source']);
        $this->assertSame('gpt-test', $report->request->model_used);
    }
}
