<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use App\Models\AiTrainingExample;
use App\Services\Ai\ReportTemplateConformityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiReportValidationTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_report_can_be_human_validated(): void
    {
        $user = $this->createAiUser();
        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PAS_GLOBAL,
            'title' => 'Rapport a valider',
            'metrics_snapshot' => ['totaux' => ['actions' => 0]],
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-test',
            'ai_draft' => $this->conformingContent(),
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);
        app(ReportTemplateConformityService::class)->apply($report);

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.validate', $report), ['content' => $this->conformingContent()])
            ->assertRedirect();

        $this->assertSame(AiGeneratedReport::STATUS_VALIDATED, $report->refresh()->status);
        $this->assertSame($this->conformingContent(), $report->validated_content);
        $this->assertDatabaseHas('ai_training_examples', [
            'task' => AiTrainingExample::TASK_REPORT_WRITING,
            'source' => 'validated_report',
            'is_validated' => true,
        ]);
    }

    public function test_report_with_missing_template_sections_cannot_be_validated(): void
    {
        $user = $this->createAiUser();
        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PAS_GLOBAL,
            'title' => 'Rapport incomplet',
            'metrics_snapshot' => ['totaux' => ['actions' => 0]],
            'ai_provider' => 'openai',
            'ai_draft' => 'Contenu incomplet',
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.validate', $report), ['content' => 'Contenu incomplet'])
            ->assertSessionHasErrors('content');

        $this->assertSame(AiGeneratedReport::STATUS_DRAFT, $report->refresh()->status);
        $this->assertSame(AiGeneratedReport::CONFORMITY_NON_CONFORMING, $report->conformity_status);
    }

    private function conformingContent(): string
    {
        $template = app(ReportTemplateConformityService::class)->template(AiGeneratedReport::TYPE_PAS_GLOBAL);
        $sections = collect($template['sections'])->map(fn (array $section): array => [
            'key' => $section['key'],
            'title' => $section['title'],
            'content' => str_repeat('Contenu institutionnel detaille et verifie sur le snapshot Laravel. ', 3),
        ])->all();

        return app(ReportTemplateConformityService::class)->compose('Rapport conforme', $sections);
    }
}
