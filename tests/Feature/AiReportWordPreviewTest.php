<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use App\Services\Ai\ActionReportMetricsBuilder;
use App\Services\Ai\PtaQuarterlyReportPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiReportWordPreviewTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_pta_quarterly_preview_service_builds_word_tables_and_charts(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();
        $metrics = app(ActionReportMetricsBuilder::class)->build('pta');

        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PTA_QUARTERLY,
            'title' => 'Rapport PTA trimestriel preview',
            'metrics_snapshot' => $metrics,
            'ai_draft' => 'Brouillon PTA',
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        $preview = app(PtaQuarterlyReportPreviewService::class)->build($report);

        $this->assertIsArray($preview);
        $this->assertSame('Apercu du modele Word trimestriel', $preview['title']);
        $this->assertCount(6, $preview['tables']);
        $this->assertSame('Comparaison entre avancement global et realisation des actions echues', $preview['tables'][1]['title']);
        $this->assertSame('TAUX DE REALISATION DES AXES GLOBAUX', $preview['tables'][2]['title']);
        $this->assertSame('Service Applications', $preview['tables'][3]['rows'][0][0]);
        $this->assertSame('Progression des axes du PTA sur la periode', $preview['charts'][0]['title']);
        $this->assertNotEmpty($preview['charts'][0]['points']);
    }

    public function test_pta_quarterly_report_show_renders_word_preview(): void
    {
        $this->createReportFixture();
        $user = $this->createAiUser();
        $metrics = app(ActionReportMetricsBuilder::class)->build('pta');

        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PTA_QUARTERLY,
            'title' => 'Rapport PTA trimestriel preview',
            'metrics_snapshot' => $metrics,
            'ai_draft' => 'Brouillon PTA',
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.ai-reports.show', $report))
            ->assertOk()
            ->assertViewHas('wordPreview', fn (?array $preview): bool => is_array($preview)
                && $preview['title'] === 'Apercu du modele Word trimestriel')
            ->assertSee('Apercu du modele Word trimestriel')
            ->assertSee('Tableaux du document Word')
            ->assertSee('Graphiques du document Word')
            ->assertSee('TAUX DE REALISATION DES AXES GLOBAUX')
            ->assertSee('Service Applications');
    }
}
