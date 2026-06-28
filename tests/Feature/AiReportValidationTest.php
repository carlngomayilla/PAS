<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
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
            'ai_draft' => 'Brouillon',
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        $this->actingAs($user)
            ->post(route('workspace.ai-reports.validate', $report), ['content' => 'Rapport valide'])
            ->assertRedirect();

        $this->assertSame(AiGeneratedReport::STATUS_VALIDATED, $report->refresh()->status);
        $this->assertSame('Rapport valide', $report->validated_content);
    }
}
