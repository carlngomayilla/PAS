<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use App\Services\Ai\ReportTemplateConformityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiReportGenerationTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_ai_report_is_generated_from_laravel_metrics_only(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();
        $this->fakeOpenAiReport(AiGeneratedReport::TYPE_PTA_ANNUAL);

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.generate'), [
                'report_type' => AiGeneratedReport::TYPE_PTA_ANNUAL,
                'title' => 'Rapport PTA test',
            ])
            ->assertRedirect();

        $report = AiGeneratedReport::query()->firstOrFail();
        $this->assertSame(1, $report->metrics_snapshot['totaux']['actions']);
        $this->assertSame('openai', $report->ai_provider);
        $this->assertSame(AiGeneratedReport::CONFORMITY_CONFORMING, $report->conformity_status);
        $this->assertSame(100, $report->conformity_score);
        $this->assertStringContainsString('[SECTION:resume_executif]', $report->ai_draft);
        $this->assertStringNotContainsString('999', $report->ai_draft);
    }

    public function test_pta_quarterly_report_uses_official_sections(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();
        $this->fakeOpenAiReport(AiGeneratedReport::TYPE_PTA_QUARTERLY);

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.generate'), [
                'report_type' => AiGeneratedReport::TYPE_PTA_QUARTERLY,
                'title' => 'Rapport PTA trimestriel test',
            ])
            ->assertRedirect();

        $report = AiGeneratedReport::query()->firstOrFail();
        $this->assertArrayHasKey('pta_analyse', $report->metrics_snapshot);
        $this->assertStringContainsString('[SECTION:progression_globale]', $report->ai_draft);
        $this->assertStringContainsString('[SECTION:analyse_ecarts]', $report->ai_draft);
        $this->assertStringContainsString('[SECTION:mesures_correctives]', $report->ai_draft);
        $this->assertSame('ANBG-PTA-TRIMESTRIEL', $report->template_code);
    }

    public function test_report_generation_fails_closed_without_openai(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();
        config(['services.openai_responses.key' => null]);

        $this->actingAs($user)
            ->from(route('workspace.ai-reports.create'))
            ->post(route('workspace.ai-reports.generate'), [
                'report_type' => AiGeneratedReport::TYPE_PTA_ANNUAL,
            ])
            ->assertRedirect(route('workspace.ai-reports.create'))
            ->assertSessionHasErrors('openai');

        $this->assertDatabaseCount('ai_generated_reports', 0);
    }

    private function fakeOpenAiReport(string $reportType): void
    {
        config([
            'services.openai_responses.key' => 'test-openai-key',
            'services.openai_responses.model_high' => 'gpt-test',
        ]);

        $template = app(ReportTemplateConformityService::class)->template($reportType);
        $sections = collect($template['sections'])->map(fn (array $section): array => [
            'key' => $section['key'],
            'title' => $section['title'],
            'content' => str_repeat('Analyse institutionnelle fondee exclusivement sur les donnees Laravel disponibles. ', 3),
        ])->all();

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test',
                'status' => 'completed',
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['sections' => $sections], JSON_THROW_ON_ERROR),
                    ]],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
            ], 200, ['x-request-id' => 'req_test']),
        ]);
    }
}
