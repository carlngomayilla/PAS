<?php

namespace Tests\Feature;

use App\Models\AiGeneratedReport;
use App\Services\Ai\ActionReportMetricsBuilder;
use App\Services\Ai\ReportTemplateConformityService;
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
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-test',
            'ai_draft' => $this->conformingContent(AiGeneratedReport::TYPE_PAS_GLOBAL),
            'validated_content' => $this->conformingContent(AiGeneratedReport::TYPE_PAS_GLOBAL),
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ]);
        app(ReportTemplateConformityService::class)->apply($report);

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
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-test',
            'ai_draft' => $this->conformingContent(AiGeneratedReport::TYPE_PTA_QUARTERLY),
            'validated_content' => $this->conformingContent(AiGeneratedReport::TYPE_PTA_QUARTERLY),
            'status' => AiGeneratedReport::STATUS_VALIDATED,
        ]);
        app(ReportTemplateConformityService::class)->apply($report);

        $this->actingAs($user)->get(route('workspace.ai-reports.export.word', $report))->assertOk();

        $report->refresh();
        Storage::disk('local')->assertExists($report->exported_docx_path);

        $text = $this->docxText(Storage::disk('local')->path($report->exported_docx_path));
        $this->assertStringContainsString('RAPPORT TRIMESTRIEL', $text);
        $this->assertStringContainsString('Sommaire', $text);
        $this->assertStringContainsString('TAUX DE REALISATION DES AXES GLOBAUX', $text);
        $this->assertStringContainsString('Il ressort de l analyse des donnees consolidees', $text);
        $this->assertStringContainsString('Les causes probables des ecarts', $text);
        $this->assertStringContainsString('6-Analyse des ecarts constates', $text);
        $this->assertStringContainsString('Le Gestionnaire Suivi-Evaluation Senior', $text);
        $this->assertStringNotContainsString('DONNEES ACTUALISEES GENEREES PAR L APPLICATION', $text);
        $this->assertSame([], array_values(array_filter(
            $this->docxMediaNames(Storage::disk('local')->path($report->exported_docx_path)),
            static fn (string $name): bool => str_contains($name, 'section_image')
        )));
        $this->assertGreaterThanOrEqual(1, $this->docxChartCount(Storage::disk('local')->path($report->exported_docx_path)));
    }

    public function test_draft_report_cannot_be_exported_before_human_validation(): void
    {
        Storage::fake('local');
        $user = $this->createAiUser();
        $report = AiGeneratedReport::query()->create([
            'user_id' => $user->id,
            'report_type' => AiGeneratedReport::TYPE_PAS_GLOBAL,
            'title' => 'Rapport brouillon',
            'metrics_snapshot' => ['totaux' => ['actions' => 1]],
            'ai_draft' => 'Brouillon avec contenu',
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.ai-reports.export.pdf', $report))
            ->assertStatus(422);

        $this->actingAs($user)
            ->get(route('workspace.ai-reports.show', $report))
            ->assertOk()
            ->assertDontSee(route('workspace.ai-reports.export.pdf', $report), false);
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

    private function conformingContent(string $reportType): string
    {
        $service = app(ReportTemplateConformityService::class);
        $sections = collect($service->template($reportType)['sections'])->map(fn (array $section): array => [
            'key' => $section['key'],
            'title' => $section['title'],
            'content' => str_repeat('Analyse institutionnelle detaillee et verifiee sur le snapshot Laravel. ', 3),
        ])->all();

        return $service->compose('Rapport conforme', $sections);
    }

    private function docxChartCount(string $path): int
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $count = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'word/charts/') && str_ends_with($name, '.xml')) {
                $count++;
            }
        }

        $zip->close();

        return $count;
    }

    /**
     * @return list<string>
     */
    private function docxMediaNames(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'word/media/')) {
                $names[] = basename($name);
            }
        }

        $zip->close();

        return $names;
    }
}
