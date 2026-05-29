<?php

namespace Tests\Feature;

use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\Support\SimpleZipReader;
use Tests\TestCase;
use ZipArchive;

class ReportingReportCatalogTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_reporting_hub_displays_canonical_report_catalog(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.reporting', ['report_type' => 'financement']))
            ->assertOk()
            ->assertSee('Rapport PAS')
            ->assertSee('Rapport PAO')
            ->assertSee('Rapport PTA')
            ->assertSee('Rapport Actions')
            ->assertSee('Rapport KPI')
            ->assertSee('Rapport Anomalies')
            ->assertSee('Rapport Financement')
            ->assertSee('Rapport Consolidé DG');
    }

    public function test_excel_export_can_target_financing_report_only(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel', ['report_type' => 'financement']));

        $response->assertOk();
        $binary = $response->streamedContent();
        $entries = $this->xlsxEntries($binary);
        $workbookXml = $entries['xl/workbook.xml'] ?? false;

        $this->assertNotFalse($workbookXml);
        $this->assertStringContainsString('FINANCEMENT', (string) $workbookXml);
        $this->assertStringNotContainsString('STRATEGIE', (string) $workbookXml);
        $this->assertDatabaseHas('journal_audit', [
            'user_id' => $admin->id,
            'module' => 'reporting_export',
            'action' => 'export_excel',
        ]);
    }

    public function test_actions_excel_export_contains_action_fields_without_followup_or_validation_sheets(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel', ['report_type' => 'actions']));

        $response->assertOk();
        $entries = $this->xlsxEntries($response->streamedContent());
        $workbookXml = $entries['xl/workbook.xml'] ?? false;
        $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? false;

        $this->assertNotFalse($workbookXml);
        $this->assertNotFalse($sheetXml);
        $this->assertStringContainsString('ACTIONS', (string) $workbookXml);
        $this->assertStringNotContainsString('JUSTIFICATIFS', (string) $workbookXml);
        $this->assertStringNotContainsString('ANOMALIES', (string) $workbookXml);
        $this->assertStringNotContainsString('FINANCEMENT', (string) $workbookXml);
        $this->assertStringContainsString('Mode execution', (string) $sheetXml);
        $this->assertStringContainsString('Financement', (string) $sheetXml);
        $this->assertStringContainsString('Risque', (string) $sheetXml);
        $this->assertStringContainsString('KPI global (%)', (string) $sheetXml);
        $this->assertStringNotContainsString('Statut validation', (string) $sheetXml);
        $this->assertStringNotContainsString('Justificatif', (string) $sheetXml);
    }

    public function test_pta_excel_export_does_not_fallback_to_full_workbook(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel', ['report_type' => 'pta']));

        $response->assertOk();
        $entries = $this->xlsxEntries($response->streamedContent());
        $workbookXml = (string) ($entries['xl/workbook.xml'] ?? '');

        $this->assertStringContainsString('SYNTH', $workbookXml);
        $this->assertStringContainsString('PAO', $workbookXml);
        $this->assertStringContainsString('ACTIONS', $workbookXml);
        $this->assertStringNotContainsString('STRATEGIE', $workbookXml);
        $this->assertStringNotContainsString('JUSTIFICATIFS', $workbookXml);
        $this->assertStringNotContainsString('FINANCEMENT', $workbookXml);
    }

    public function test_anomalies_excel_export_is_limited_to_anomaly_and_alert_sheets(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel', ['report_type' => 'anomalies']));

        $response->assertOk();
        $entries = $this->xlsxEntries($response->streamedContent());
        $workbookXml = (string) ($entries['xl/workbook.xml'] ?? '');

        $this->assertStringContainsString('ANOMALIES', $workbookXml);
        $this->assertStringContainsString('ALERTES', $workbookXml);
        $this->assertStringNotContainsString('STRATEGIE', $workbookXml);
        $this->assertStringNotContainsString('FINANCEMENT', $workbookXml);
        $this->assertStringNotContainsString('JUSTIFICATIFS', $workbookXml);
    }

    public function test_actions_pdf_report_is_limited_to_action_fields(): void
    {
        $html = $this->renderPdfReport('actions');

        $this->assertStringContainsString('Rapport Actions - PAS ANBG', $html);
        $this->assertStringContainsString('Actions detaillees', $html);
        $this->assertStringContainsString('Mode execution', $html);
        $this->assertStringContainsString('Financement', $html);
        $this->assertStringContainsString('Risque', $html);
        $this->assertStringContainsString('KPI global (%)', $html);
        $this->assertStringNotContainsString('Suivi des justificatifs', $html);
        $this->assertStringNotContainsString('Statut validation', $html);
        $this->assertStringNotContainsString('Financements DAF / DG', $html);
    }

    public function test_financing_pdf_report_is_limited_to_financing_section(): void
    {
        $html = $this->renderPdfReport('financement');

        $this->assertStringContainsString('Rapport Financement - PAS ANBG', $html);
        $this->assertStringContainsString('Financements DAF / DG', $html);
        $this->assertStringContainsString('Statut DAF / DG', $html);
        $this->assertStringContainsString('Montant estime', $html);
        $this->assertStringNotContainsString('Actions detaillees</h2>', $html);
        $this->assertStringNotContainsString('Suivi des justificatifs', $html);
    }

    public function test_anomalies_pdf_report_is_limited_to_anomalies_and_alerts(): void
    {
        $html = $this->renderPdfReport('anomalies');

        $this->assertStringContainsString('Rapport Anomalies - PAS ANBG', $html);
        $this->assertStringContainsString('Anomalies et blocages', $html);
        $this->assertStringContainsString('Correction attendue', $html);
        $this->assertStringContainsString('Alertes sous seuil', $html);
        $this->assertStringNotContainsString('Suivi des justificatifs', $html);
        $this->assertStringNotContainsString('Financements DAF / DG', $html);
    }

    /**
     * @return array<string, string>
     */
    private function xlsxEntries(string $binary): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_report_catalog_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, $binary);

        try {
            if (class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                $this->assertTrue($zip->open($tempFile) === true);
                $entries = [];
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);
                    if ($name === false) {
                        continue;
                    }
                    $contents = $zip->getFromName($name);
                    $entries[$name] = $contents === false ? '' : $contents;
                }
                $zip->close();

                return $entries;
            }

            return app(SimpleZipReader::class)->read($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    private function renderPdfReport(string $reportType): string
    {
        $admin = $this->createAdminUser();
        $labels = [
            'actions' => 'Rapport Actions',
            'anomalies' => 'Rapport Anomalies',
            'financement' => 'Rapport Financement',
        ];
        $label = $labels[$reportType] ?? 'Rapport';
        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);
        $payload['report_context'] = [
            'type' => $reportType,
            'label' => $label,
            'title' => $label.' - PAS ANBG',
            'description' => 'Contexte de test du rapport.',
            'filters' => [],
        ];

        return view('workspace.monitoring.reporting-pdf', $payload)->render();
    }
}
