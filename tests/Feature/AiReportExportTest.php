<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use App\Services\Ai\ActionReportMetricsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;
use ZipArchive;

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

    public function test_pta_quarterly_word_export_uses_template_structure(): void
    {
        Storage::fake('local');
        $this->createReportFixture();
        $user = $this->createAiUser();
        $metrics = app(ActionReportMetricsBuilder::class)->build('pta');

        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PTA_QUARTERLY,
            'title' => 'Rapport PTA trimestriel modele',
            'metrics_snapshot' => $metrics,
            'ai_draft' => 'Brouillon PTA',
            'validated_content' => 'Rapport valide PTA',
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ]);

        $this->actingAs($user)->get(route('workspace.ai-reports.export.word', $report))->assertOk();

        $report->refresh();
        Storage::disk('local')->assertExists($report->exported_docx_path);

        $text = $this->docxText(Storage::disk('local')->path($report->exported_docx_path));
        $this->assertStringContainsString('RAPPORT TRIMESTRIEL', $text);
        $this->assertStringContainsString('Sommaire', $text);
        $this->assertStringContainsString('TAUX DE REALISATION DES AXES GLOBAUX', $text);
        $this->assertStringContainsString('6-Analyse des ecarts constates', $text);
        $this->assertStringContainsString('Le Gestionnaire Suivi-Evaluation Senior', $text);
    }

    private function docxText(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($xml);

        return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
