<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiReportExportTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_validated_report_exports_to_pdf_word_and_excel(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PAS_GLOBAL,
            'title' => 'Rapport export',
            'metrics_snapshot' => ['totaux' => ['actions' => 1]],
            'ai_draft' => 'Brouillon',
            'validated_content' => 'Rapport valide exportable',
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ]);

        $this->actingAs($user)->get(route('workspace.ai-reports.export.pdf', $report))->assertOk();
        $this->actingAs($user)->get(route('workspace.ai-reports.export.word', $report))->assertOk();
        $this->actingAs($user)->get(route('workspace.ai-reports.export.excel', $report))->assertOk();

        $report->refresh();
        Storage::disk('local')->assertExists($report->exported_pdf_path);
        Storage::disk('local')->assertExists($report->exported_docx_path);
        Storage::disk('local')->assertExists($report->exported_xlsx_path);
    }
}
