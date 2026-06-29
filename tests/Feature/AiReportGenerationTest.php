<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.generate'), [
                'report_type' => AiGeneratedReport::TYPE_PTA_ANNUAL,
                'title' => 'Rapport PTA test',
            ])
            ->assertRedirect();

        $report = AiGeneratedReport::query()->firstOrFail();
        $this->assertSame(1, $report->metrics_snapshot['totaux']['actions']);
        $this->assertStringContainsString('1 action', $report->ai_draft);
        $this->assertStringNotContainsString('999', $report->ai_draft);
    }

    public function test_pta_quarterly_report_uses_official_sections(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.generate'), [
                'report_type' => AiGeneratedReport::TYPE_PTA_QUARTERLY,
                'title' => 'Rapport PTA trimestriel test',
            ])
            ->assertRedirect();

        $report = AiGeneratedReport::query()->firstOrFail();
        $this->assertArrayHasKey('pta_analyse', $report->metrics_snapshot);
        $this->assertStringContainsString('Progression globale du PTA', $report->ai_draft);
        $this->assertStringContainsString('Mesures correctives proposees', $report->ai_draft);
    }
}
