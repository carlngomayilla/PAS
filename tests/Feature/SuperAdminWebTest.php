<?php

namespace Tests\Feature;

use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\Support\SimpleZipReader;
use Tests\TestCase;
use ZipArchive;

class SuperAdminWebTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_access_super_admin_workspace_and_admin_cannot(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->actingAs($superAdmin)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Super Administration');

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('Templates d export');

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_publish_and_override_default_export_template(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $previousTemplate = ExportTemplate::query()->where('code', 'reporting-pdf-officiel-default')->firstOrFail();
        $previousAssignment = ExportTemplateAssignment::query()
            ->where('export_template_id', $previousTemplate->id)
            ->where('module', 'reporting')
            ->where('report_type', 'consolidated_reporting')
            ->where('format', 'pdf')
            ->whereNull('target_profile')
            ->where('reading_level', 'officiel')
            ->whereNull('direction_id')
            ->whereNull('service_id')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.store'), [
                'name' => 'Reporting PDF DG officiel',
                'code' => 'reporting-pdf-dg-officiel',
                'description' => 'Template officiel de diffusion DG.',
                'format' => 'pdf',
                'module' => 'reporting',
                'report_type' => 'consolidated_reporting',
                'reading_level' => 'officiel',
                'is_default' => '1',
                'is_active' => '1',
                'document_title' => 'Reporting DG officiel',
                'document_subtitle' => 'Diffusion institutionnelle',
                'filename_prefix' => 'dg_reporting_officiel',
                'paper_size' => 'a3',
                'orientation' => 'landscape',
                'header_text' => 'ANBG | DG',
                'footer_text' => 'Document officiel DG',
                'watermark_text' => 'DG',
                'color_primary' => '#1E3A8A',
                'color_secondary' => '#3B82F6',
                'font_family' => 'Inter',
                'include_cover' => '1',
                'include_summary' => '1',
                'include_detail_table' => '1',
                'include_charts' => '1',
                'include_alerts' => '1',
                'visible_columns' => 'libelle, statut, validation',
                'dynamic_variables' => "{app_name}\n{generated_at}",
                'create_default_assignment' => '1',
            ])
            ->assertRedirect();

        $template = ExportTemplate::query()->where('code', 'reporting-pdf-dg-officiel')->firstOrFail();
        $this->assertSame(ExportTemplate::STATUS_DRAFT, $template->status);
        $this->assertDatabaseHas('export_template_assignments', [
            'export_template_id' => $template->id,
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'format' => 'pdf',
            'reading_level' => 'officiel',
            'is_default' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.publish', $template), [
                'mark_as_default' => '1',
                'note' => 'Publication DG',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $template->refresh();
        $previousTemplate->refresh();
        $previousAssignment->refresh();

        $this->assertSame(ExportTemplate::STATUS_PUBLISHED, $template->status);
        $this->assertTrue((bool) $template->is_default);
        $this->assertNotNull($template->published_at);
        $this->assertSame(1, $template->versions()->count());
        $this->assertFalse((bool) $previousTemplate->is_default);
        $this->assertFalse((bool) $previousAssignment->is_default);

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'export_template',
            'entite_id' => $template->id,
            'action' => 'create',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'export_template',
            'entite_id' => $template->id,
            'action' => 'publish',
        ]);
    }

    public function test_reporting_excel_export_uses_super_admin_published_template_metadata(): void
    {
        $admin = $this->createAdminUser();

        ExportTemplate::query()
            ->where('module', 'reporting')
            ->where('report_type', 'consolidated_reporting')
            ->where('format', 'excel')
            ->update(['is_default' => false]);
        ExportTemplateAssignment::query()
            ->where('module', 'reporting')
            ->where('report_type', 'consolidated_reporting')
            ->where('format', 'excel')
            ->update(['is_default' => false, 'is_active' => false]);

        $template = ExportTemplate::query()->create([
            'code' => 'reporting-excel-custom-officiel',
            'name' => 'Reporting Excel DG',
            'description' => 'Version light sans feuille graphique.',
            'format' => 'excel',
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'reading_level' => 'officiel',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_default' => true,
            'is_active' => true,
            'blocks_config' => [
                'include_cover' => true,
                'include_summary' => true,
                'include_detail_table' => true,
                'include_charts' => false,
                'include_alerts' => true,
                'include_signatures' => false,
            ],
            'layout_config' => [
                'paper_size' => 'a4',
                'orientation' => 'landscape',
                'header_text' => 'ANBG',
                'footer_text' => 'DG',
                'watermark_text' => '',
            ],
            'content_config' => [
                'visible_columns' => ['libelle', 'statut'],
                'dynamic_variables' => ['{app_name}', '{report_title}'],
            ],
            'style_config' => [
                'color_primary' => '#1E3A8A',
                'color_secondary' => '#3B82F6',
                'font_family' => 'Inter',
            ],
            'meta_config' => [
                'document_title' => 'Reporting DG Excel',
                'document_subtitle' => 'Classeur synthese sans graphiques',
                'filename_prefix' => 'dg_excel_template',
            ],
        ]);

        ExportTemplateAssignment::query()->create([
            'export_template_id' => $template->id,
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'format' => 'excel',
            'target_profile' => null,
            'reading_level' => 'officiel',
            'direction_id' => null,
            'service_id' => null,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('workspace.reporting.export.excel'));

        $response->assertOk();
        $this->assertStringContainsString('dg_excel_template_', (string) $response->headers->get('content-disposition'));

        $xlsxBinary = $response->streamedContent();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_super_admin_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, $xlsxBinary);

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($tempFile) === true);
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $sheetOneXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sheetTwoXml = $zip->getFromName('xl/worksheets/sheet2.xml');
            $sheetThreeXml = $zip->getFromName('xl/worksheets/sheet3.xml');
            $sheetSixXml = $zip->getFromName('xl/worksheets/sheet6.xml');
            $coreXml = $zip->getFromName('docProps/core.xml');
            $zip->close();
        } else {
            $entries = app(SimpleZipReader::class)->read($tempFile);
            $workbookXml = $entries['xl/workbook.xml'] ?? false;
            $sheetOneXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
            $sheetTwoXml = $entries['xl/worksheets/sheet2.xml'] ?? false;
            $sheetThreeXml = $entries['xl/worksheets/sheet3.xml'] ?? false;
            $sheetSixXml = $entries['xl/worksheets/sheet6.xml'] ?? false;
            $coreXml = $entries['docProps/core.xml'] ?? false;
        }
        @unlink($tempFile);

        $this->assertNotFalse($workbookXml);
        $this->assertNotFalse($sheetOneXml);
        $this->assertNotFalse($sheetTwoXml);
        $this->assertNotFalse($sheetThreeXml);
        $this->assertNotFalse($sheetSixXml);
        $this->assertNotFalse($coreXml);
        $this->assertStringContainsString('Reporting DG Excel', (string) $sheetOneXml);
        $this->assertStringContainsString('Classeur synthese sans graphiques', (string) $sheetOneXml);
        $this->assertStringContainsString('STRATEGIE', (string) $workbookXml);
        $this->assertStringContainsString('PAO', (string) $workbookXml);
        $this->assertStringContainsString('RMO_PERFORMANCE', (string) $workbookXml);
        $this->assertStringContainsString('Tableau 2 : Objectifs operationnels &amp; Actions', (string) $sheetTwoXml);
        $this->assertStringContainsString('Direction', (string) $sheetTwoXml);
        $this->assertStringContainsString('Service', (string) $sheetTwoXml);
        $this->assertStringContainsString('Objectif operationnel', (string) $sheetTwoXml);
        $this->assertStringContainsString('Tableau 3 : Actions detaillees', (string) $sheetThreeXml);
        $this->assertStringContainsString('Description action', (string) $sheetThreeXml);
        $this->assertStringContainsString('Tableau 6 : Alertes KPI sous seuil', (string) $sheetSixXml);
        $this->assertStringContainsString('Action corrective', (string) $sheetSixXml);
        $this->assertStringContainsString('Reporting DG Excel', (string) $coreXml);
        $this->assertStringNotContainsString('Synthese graphique', (string) $workbookXml);
    }

    public function test_reporting_pdf_view_honors_super_admin_template_metadata_and_blocks(): void
    {
        $admin = $this->createAdminUser();
        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);

        $payload['exportTemplate'] = ExportTemplate::query()->create([
            'code' => 'reporting-pdf-custom-blocks',
            'name' => 'Reporting PDF personnalise',
            'description' => 'Controle des blocs PDF.',
            'format' => 'pdf',
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'reading_level' => 'officiel',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_default' => true,
            'is_active' => true,
            'blocks_config' => [
                'include_cover' => true,
                'include_summary' => true,
                'include_detail_table' => false,
                'include_charts' => false,
                'include_alerts' => false,
                'include_signatures' => true,
            ],
            'layout_config' => [
                'paper_size' => 'a4',
                'orientation' => 'portrait',
                'header_text' => 'ANBG | PDF officiel',
                'footer_text' => 'Diffusion PDF DG',
                'watermark_text' => 'OFFICIEL DG',
            ],
            'content_config' => [
                'visible_columns' => ['libelle'],
                'dynamic_variables' => ['{report_title}'],
            ],
            'style_config' => [
                'color_primary' => '#1E3A8A',
                'color_secondary' => '#3B82F6',
                'font_family' => 'Inter',
            ],
            'meta_config' => [
                'document_title' => 'Reporting PDF DG',
                'document_subtitle' => 'Version controlee',
                'filename_prefix' => 'dg_pdf_template',
            ],
        ]);

        $html = view('workspace.monitoring.reporting-pdf', $payload)->render();

        $this->assertStringContainsString('Reporting PDF DG', $html);
        $this->assertStringContainsString('Version controlee', $html);
        $this->assertStringContainsString('ANBG | PDF officiel', $html);
        $this->assertStringContainsString('Diffusion PDF DG', $html);
        $this->assertStringContainsString('OFFICIEL DG', $html);
        $this->assertStringContainsString('Visa et signatures', $html);
        $this->assertStringNotContainsString('Synthese graphique', $html);
        $this->assertStringNotContainsString('Alertes de synthese', $html);
        $this->assertStringNotContainsString('Details actions en retard', $html);
    }

    public function test_reporting_word_export_uses_super_admin_template_metadata(): void
    {
        $admin = $this->createAdminUser();

        ExportTemplate::query()
            ->where('module', 'reporting')
            ->where('report_type', 'consolidated_reporting')
            ->where('format', 'word')
            ->update(['is_default' => false]);
        ExportTemplateAssignment::query()
            ->where('module', 'reporting')
            ->where('report_type', 'consolidated_reporting')
            ->where('format', 'word')
            ->update(['is_default' => false, 'is_active' => false]);

        $template = ExportTemplate::query()->create([
            'code' => 'reporting-word-officiel',
            'name' => 'Reporting Word officiel',
            'description' => 'Template Word DG.',
            'format' => 'word',
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'reading_level' => 'officiel',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_default' => true,
            'is_active' => true,
            'blocks_config' => [
                'include_cover' => true,
                'include_summary' => true,
                'include_detail_table' => true,
                'include_charts' => false,
                'include_alerts' => true,
                'include_signatures' => true,
            ],
            'layout_config' => [
                'paper_size' => 'a4',
                'orientation' => 'portrait',
                'header_text' => 'ANBG | WORD',
                'footer_text' => 'Diffusion Word',
                'watermark_text' => 'WORD DG',
            ],
            'content_config' => [
                'visible_columns' => ['libelle', 'statut'],
                'dynamic_variables' => ['{report_title}'],
            ],
            'style_config' => [
                'color_primary' => '#1E3A8A',
                'color_secondary' => '#3B82F6',
                'font_family' => 'Times New Roman',
            ],
            'meta_config' => [
                'document_title' => 'Reporting Word DG',
                'document_subtitle' => 'Version diffusable',
                'filename_prefix' => 'dg_word_template',
            ],
        ]);

        ExportTemplateAssignment::query()->create([
            'export_template_id' => $template->id,
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'format' => 'word',
            'target_profile' => null,
            'reading_level' => 'officiel',
            'direction_id' => null,
            'service_id' => null,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('workspace.reporting.export.word'));

        $response->assertOk();
        $this->assertStringContainsString('dg_word_template_', (string) $response->headers->get('content-disposition'));
        $response->assertSee('Reporting Word DG');
        $response->assertSee('Version diffusable');
        $response->assertSee('ANBG | WORD');
        $response->assertSee('WORD DG');
    }

    public function test_super_admin_can_preview_and_restore_export_template_version(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = ExportTemplate::query()->where('code', 'reporting-pdf-officiel-default')->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.publish', $template), [
                'mark_as_default' => '1',
                'note' => 'Version initiale',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.templates.update', $template), [
                'name' => 'Template modifie',
                'code' => $template->code,
                'description' => 'Template mis a jour',
                'format' => $template->format,
                'module' => $template->module,
                'report_type' => $template->report_type,
                'target_profile' => $template->target_profile,
                'reading_level' => $template->reading_level,
                'document_title' => 'Titre modifie',
                'document_subtitle' => 'Sous-titre modifie',
                'filename_prefix' => 'prefix_modifie',
                'paper_size' => 'a4',
                'orientation' => 'portrait',
                'header_text' => 'Header modifie',
                'footer_text' => 'Footer modifie',
                'watermark_text' => 'MODIF',
                'color_primary' => '#1E3A8A',
                'color_secondary' => '#3B82F6',
                'font_family' => 'Inter',
                'include_cover' => '1',
                'include_summary' => '1',
                'include_detail_table' => '1',
                'include_charts' => '1',
                'include_alerts' => '1',
                'visible_columns' => 'libelle,statut',
                'dynamic_variables' => "{report_title}\n{generated_at}",
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $version = $template->versions()->latest('id')->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.templates.preview', $template))
            ->assertOk()
            ->assertSee('Apercu')
            ->assertSee('Template modifie');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.versions.restore', [$template, $version]))
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $template->refresh();
        $this->assertSame(ExportTemplate::STATUS_DRAFT, $template->status);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'export_template',
            'entite_id' => $template->id,
            'action' => 'restore_version',
        ]);
    }

    public function test_admin_cannot_manage_super_admin_users(): void
    {
        $admin = $this->createAdminUser();
        $superAdmin = $this->createSuperAdminUser(['email' => 'locked.superadmin@anbg.test']);

        $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertDontSee($superAdmin->email);

        $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.edit', $superAdmin))
            ->assertForbidden();
    }

    public function test_super_admin_organization_screen_displays_recent_login_history(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $managedUser = User::factory()->create([
            'name' => 'Agent test',
            'email' => 'agent.test@anbg.test',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        JournalAudit::query()->create([
            'user_id' => $managedUser->id,
            'module' => 'auth',
            'entite_type' => User::class,
            'entite_id' => $managedUser->id,
            'action' => 'login_success',
            'nouvelle_valeur' => ['remember' => false],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.index'))
            ->assertOk()
            ->assertSee('Historique recent des connexions')
            ->assertSee('Agent test')
            ->assertSee('Connexion');
    }

    public function test_template_form_lists_supported_dynamic_variables(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.templates.create'))
            ->assertOk()
            ->assertSee('{app_name}')
            ->assertSee('{generated_at}')
            ->assertSee('Variables supportees');
    }

    public function test_super_admin_roles_screen_displays_role_comparison_details(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.roles.edit', [
                'compare_left_role' => User::ROLE_ADMIN,
                'compare_right_role' => User::ROLE_AGENT,
            ]))
            ->assertOk()
            ->assertSee('Comparaison de roles')
            ->assertSee('Ecarts de permissions')
            ->assertSee('Administrateur')
            ->assertSee('Agent');
    }

    public function test_super_admin_can_export_and_import_template_json(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = ExportTemplate::query()->where('code', 'reporting-pdf-officiel-default')->firstOrFail();

        $response = $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.templates.export-json', $template));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');
        $json = $response->streamedContent();
        $this->assertStringContainsString($template->code, $json);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.import-json'), [
                'template_json' => $json,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'export_template',
            'action' => 'import_json',
        ]);
    }
}
