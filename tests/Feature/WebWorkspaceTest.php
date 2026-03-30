<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\Pao;
use App\Models\Action;
use App\Models\KpiMesure;
use App\Models\Pta;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAdminUser;
use Tests\Support\SimpleZipReader;
use Tests\TestCase;
use ZipArchive;

class WebWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_workspace_pages(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Espace de travail');

        $this->actingAs($admin)
            ->get('/workspace/messagerie')
            ->assertOk()
            ->assertSee('Messagerie interne et annuaire organisationnel');

        $this->actingAs($admin)
            ->get('/workspace/pilotage')
            ->assertOk()
            ->assertSee('Pilotage global')
            ->assertSee('analytics-explorer-title', false);

        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee('Reporting consolide');

        $this->actingAs($admin)
            ->get('/workspace/alertes')
            ->assertOk()
            ->assertSee('Alertes operationnelles')
            ->assertSee("Centre d'alertes", false);

        $this->actingAs($admin)
            ->get('/workspace/audit')
            ->assertOk()
            ->assertSee("Journal d'audit", false);

        $this->actingAs($admin)
            ->get('/workspace/referentiel/directions')
            ->assertOk()
            ->assertSee('Referentiel - Directions');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/services')
            ->assertOk()
            ->assertSee('Referentiel - Services');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertOk()
            ->assertSee('Referentiel - Utilisateurs');

        $this->actingAs($admin)
            ->get('/workspace/pas')
            ->assertOk()
            ->assertSee('PAS');

        $this->actingAs($admin)
            ->get('/workspace/pao')
            ->assertOk()
            ->assertSee('PAO');

        $this->actingAs($admin)
            ->get('/workspace/pas-axes')
            ->assertRedirect('/workspace/pas');

        $this->actingAs($admin)
            ->get('/workspace/pas-objectifs')
            ->assertRedirect('/workspace/pas');

        $this->actingAs($admin)
            ->get('/workspace/pao-axes')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/workspace/pao-objectifs-strategiques')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/workspace/pao-objectifs-operationnels')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/workspace/pta')
            ->assertOk()
            ->assertSee('PTA');

        $this->actingAs($admin)
            ->get('/workspace/actions')
            ->assertOk()
            ->assertSee('Actions')
            ->assertSee('analytics-explorer-title', false);

        $this->actingAs($admin)
            ->get('/workspace/kpi')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/workspace/kpi-mesures')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/candidats')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/bourses')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/quotas')
            ->assertNotFound();
    }

    public function test_dashboard_embeds_advanced_reporting_visuals_while_reporting_page_becomes_export_hub(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Analytique avancee')
            ->assertSee('Entonnoir PAS - PAO - PTA - Actions')
            ->assertSee('analytics-explorer-title', false);

        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee('Centre d export et de diffusion')
            ->assertSee('Dashboard analytique')
            ->assertDontSee('Entonnoir PAS - PAO - PTA - Actions');
    }

    public function test_service_user_has_no_audit_access_and_removed_modules_are_unavailable(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $this->actingAs($serviceUser)
            ->get('/workspace/pilotage')
            ->assertOk()
            ->assertSee('Pilotage global');

        $this->actingAs($serviceUser)
            ->get('/workspace/justificatifs')
            ->assertNotFound();

        $this->actingAs($serviceUser)
            ->get('/workspace/justificatifs/create')
            ->assertNotFound();

        $this->actingAs($serviceUser)
            ->get('/workspace/referentiel/directions')
            ->assertForbidden();

        $this->actingAs($serviceUser)
            ->get('/workspace/kpi-mesures')
            ->assertNotFound();

        $this->actingAs($serviceUser)
            ->get('/workspace/audit')
            ->assertForbidden();

        $this->actingAs($serviceUser)
            ->get('/workspace/pao/create')
            ->assertForbidden();

        $this->actingAs($serviceUser)
            ->get('/workspace/pao-objectifs-operationnels/create')
            ->assertNotFound();
    }

    public function test_alerts_page_exposes_direct_links_to_alert_causes(): void
    {
        $admin = $this->createAdminUser();
        $visibleAlert = app(AlertCenterService::class)
            ->buildForUser($admin, 20)
            ->first();

        $this->assertIsArray($visibleAlert);

        $response = $this->actingAs($admin)->get('/workspace/alertes');

        $response->assertOk();

        $expectedReadUrl = route('workspace.alertes.read', [
            'type' => (string) $visibleAlert['source_type'],
            'id' => (int) $visibleAlert['source_id'],
            'limit' => 20,
        ]);

        $response->assertSee($expectedReadUrl, false);
    }

    public function test_navbar_alert_dropdown_endpoint_exposes_kpi_summary_and_items(): void
    {
        $admin = $this->createAdminUser();
        $expectedSummary = app(AlertCenterService::class)->summaryForUser(
            $admin,
            app(AlertReadService::class)->readFingerprintsForUser($admin)
        );

        $response = $this->actingAs($admin)
            ->getJson(route('workspace.alertes.dropdown'))
            ->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'summary' => ['total', 'unread', 'urgence', 'critical', 'warning', 'info'],
                'kpi_summary' => [
                    'delai',
                    'performance',
                    'conformite',
                    'qualite',
                    'risque',
                    'global',
                    'progression',
                ],
                'items',
                'center_url',
            ])
            ->assertJsonPath('kpi_summary.qualite', fn ($value) => is_numeric($value))
            ->assertJsonPath('kpi_summary.risque', fn ($value) => is_numeric($value));

        $response->assertJsonPath('summary.total', (int) ($expectedSummary['total'] ?? 0));
        $response->assertJsonPath('summary.unread', (int) ($expectedSummary['unread'] ?? 0));
    }

    public function test_sidebar_alert_badge_uses_real_alert_unread_count_not_notification_module_count(): void
    {
        $admin = $this->createAdminUser();
        $expectedAlertUnreadCount = (int) (app(AlertCenterService::class)->summaryForUser(
            $admin,
            app(AlertReadService::class)->readFingerprintsForUser($admin)
        )['unread'] ?? 0);

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $admin->getKey())
            ->delete();

        $fakeNotificationCount = $expectedAlertUnreadCount + 5;
        $now = now();

        $rows = [];
        for ($index = 0; $index < $fakeNotificationCount; $index++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => 'Tests\\Notifications\\SidebarAlertBadgeNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $admin->getKey(),
                'data' => json_encode([
                    'module' => 'alertes',
                    'title' => 'Notification de test',
                    'message' => 'Ne doit pas piloter le badge sidebar alertes.',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($rows);

        $response = $this->actingAs($admin)->get('/dashboard')->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('data-sidebar-module="alertes"', $content);

        if ($expectedAlertUnreadCount > 0) {
            $expectedBadge = $expectedAlertUnreadCount > 99 ? '99+' : (string) $expectedAlertUnreadCount;
            $fakeBadge = $fakeNotificationCount > 99 ? '99+' : (string) $fakeNotificationCount;

            $this->assertMatchesRegularExpression(
                '/data-sidebar-module="alertes"[\s\S]*?data-sidebar-badge-for="alertes">' . preg_quote($expectedBadge, '/') . '<\/span>/',
                $content
            );

            if ($fakeBadge !== $expectedBadge) {
                $this->assertDoesNotMatchRegularExpression(
                    '/data-sidebar-module="alertes"[\s\S]*?data-sidebar-badge-for="alertes">' . preg_quote($fakeBadge, '/') . '<\/span>/',
                    $content
                );
            }

            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/data-sidebar-module="alertes"[\s\S]*?data-sidebar-badge-for="alertes">/',
            $content
        );
    }

    public function test_alerts_page_exposes_quality_risk_and_escalation_context(): void
    {
        $admin = $this->createAdminUser();
        $action = Action::query()
            ->whereHas('actionKpi')
            ->with('actionKpi')
            ->firstOrFail();

        ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => 'critical',
            'type_evenement' => 'retard_kpi_critique',
            'message' => 'Escalade DG pour action critique.',
            'details' => ['source' => 'test'],
            'cible_role' => 'dg',
            'utilisateur_id' => $admin->id,
            'lu' => false,
        ]);

        $this->actingAs($admin)
            ->get('/workspace/alertes')
            ->assertOk()
            ->assertSee('Escalade DG')
            ->assertSee('KPI global')
            ->assertSee('Qualite')
            ->assertSee('Risque');
    }

    public function test_alert_read_route_redirects_to_the_cause_and_marks_it_as_read(): void
    {
        $admin = $this->createAdminUser();

        $actionLog = ActionLog::query()
            ->whereIn('niveau', ['warning', 'critical', 'urgence'])
            ->with(['action', 'week'])
            ->firstOrFail();

        $targetUrl = $actionLog->week !== null
            ? route('workspace.actions.suivi', $actionLog->action) . '#action-week-' . $actionLog->week->id
            : route('workspace.actions.suivi', $actionLog->action) . '#action-logs';

        $response = $this->actingAs($admin)->get(route('workspace.alertes.read', [
            'type' => 'action_log',
            'id' => $actionLog->id,
            'limit' => 20,
        ]));

        $response->assertRedirect($targetUrl);
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $admin->id,
            'fingerprint' => 'action_log:' . $actionLog->id . ':' . (optional($actionLog->created_at)->timestamp ?? 0),
            'source_type' => 'action_log',
            'source_id' => $actionLog->id,
        ]);
    }

    public function test_overdue_action_alert_redirects_to_action_status(): void
    {
        $admin = $this->createAdminUser();
        $action = Action::query()
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()->toDateString())
            ->whereNotIn('statut_dynamique', [
                \App\Services\Actions\ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                \App\Services\Actions\ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
                \App\Services\Actions\ActionTrackingService::STATUS_SUSPENDU,
                \App\Services\Actions\ActionTrackingService::STATUS_ANNULE,
            ])
            ->first();

        if (! $action instanceof Action) {
            $this->markTestSkipped('Aucune action en retard dans le jeu de donnees de test.');
        }

        $response = $this->actingAs($admin)->get(route('workspace.alertes.read', [
            'type' => 'action_overdue',
            'id' => $action->id,
            'limit' => 20,
        ]));

        $response->assertRedirect(route('workspace.actions.suivi', $action) . '#action-status');
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $admin->id,
            'fingerprint' => 'action_overdue:' . $action->id . ':' . \Illuminate\Support\Carbon::parse($action->date_echeance)->format('Ymd') . ':' . ((float) ($action->progression_reelle ?? 0) <= 0.0 ? 'action_non_demarre' : 'retard'),
            'source_type' => 'action_overdue',
            'source_id' => $action->id,
        ]);
    }

    public function test_kpi_alert_redirects_to_action_status(): void
    {
        $admin = $this->createAdminUser();

        $mesureId = KpiMesure::query()
            ->select('kpi_mesures.id')
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte')
            ->value('kpi_mesures.id');

        $this->assertNotNull($mesureId);

        $mesure = KpiMesure::query()->with('kpi.action')->findOrFail((int) $mesureId);
        $action = $mesure->kpi?->action;
        $this->assertNotNull($action);

        $response = $this->actingAs($admin)->get(route('workspace.alertes.read', [
            'type' => 'kpi_breach',
            'id' => $mesure->id,
            'limit' => 20,
        ]));

        $response->assertRedirect(route('workspace.actions.suivi', $action) . '#action-status');
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $admin->id,
            'fingerprint' => 'kpi_breach:' . $mesure->id . ':' . number_format((float) $mesure->valeur, 4, '.', ''),
            'source_type' => 'kpi_breach',
            'source_id' => $mesure->id,
        ]);
    }

    public function test_non_admin_global_profiles_cannot_access_user_role_management(): void
    {
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $planification = User::query()->where('email', 'hilaire.nguebet@anbg.ga')->firstOrFail();
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $this->actingAs($dg)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertForbidden();

        $this->actingAs($planification)
            ->get('/workspace/referentiel/utilisateurs/create')
            ->assertForbidden();

        $this->actingAs($cabinet)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertForbidden();
    }

    public function test_cabinet_cannot_access_removed_justificatifs_module(): void
    {
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $response = $this->actingAs($cabinet)
            ->get('/workspace/justificatifs');

        $response->assertNotFound();

        $this->actingAs($cabinet)
            ->get('/workspace/justificatifs/create')
            ->assertNotFound();
    }

    public function test_cabinet_can_open_and_submit_pas_wizard(): void
    {
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $this->actingAs($cabinet)
            ->get(route('workspace.pas.create'))
            ->assertOk()
            ->assertSee('Enregistrer un nouveau PAS');

        $response = $this->actingAs($cabinet)
            ->post(route('workspace.pas.store'), [
                'titre' => 'PAS cabinet test',
                'periode_debut' => 2029,
                'periode_fin' => 2031,
                'statut' => 'brouillon',
                'axes' => [
                    [
                        'code' => 'AXE-CAB-1',
                        'libelle' => 'Axe cabinet',
                        'ordre' => 1,
                        'objectifs' => [
                            [
                                'code' => 'OS-CAB-1',
                                'libelle' => 'Objectif cabinet',
                                'ordre' => 1,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertRedirect(route('workspace.pas.index'));
        $this->assertDatabaseHas('pas', [
            'titre' => 'PAS cabinet test',
            'statut' => 'brouillon',
        ]);
    }

    public function test_dg_can_approve_and_lock_submitted_pao(): void
    {
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $pao = Pao::query()->where('statut', 'soumis')->firstOrFail();

        $this->actingAs($dg)
            ->post(route('workspace.pao.approve', $pao))
            ->assertRedirect(route('workspace.pao.index'));

        $pao->refresh();
        $this->assertSame('valide', $pao->statut);
        $this->assertNotNull($pao->valide_le);
        $this->assertSame($dg->id, $pao->valide_par);

        $this->actingAs($dg)
            ->post(route('workspace.pao.lock', $pao))
            ->assertRedirect(route('workspace.pao.index'));

        $pao->refresh();
        $this->assertSame('verrouille', $pao->statut);

        $this->actingAs($dg)
            ->get(route('workspace.pao.edit', $pao))
            ->assertOk()
            ->assertSee('Timeline validation')
            ->assertSee('Verrouillage');
    }

    public function test_direction_user_cannot_approve_pao(): void
    {
        $directionUser = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();
        $pao = Pao::query()
            ->where('direction_id', (int) $directionUser->direction_id)
            ->where('statut', 'soumis')
            ->firstOrFail();

        $this->actingAs($directionUser)
            ->post(route('workspace.pao.approve', $pao))
            ->assertForbidden();

        $pao->refresh();
        $this->assertSame('soumis', $pao->statut);
    }

    public function test_service_user_cannot_edit_his_service_pao(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $pao = Pao::query()
            ->where('service_id', (int) $serviceUser->service_id)
            ->firstOrFail();

        $this->actingAs($serviceUser)
            ->get(route('workspace.pao.edit', $pao))
            ->assertForbidden();
    }

    public function test_direction_user_cannot_open_pta_create_form(): void
    {
        $directionUser = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();

        $this->actingAs($directionUser)
            ->get(route('workspace.pta.create'))
            ->assertForbidden();
    }

    public function test_service_user_can_reopen_then_submit_his_own_pta(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $pta = Pta::query()
            ->where('service_id', (int) $serviceUser->service_id)
            ->where('statut', 'soumis')
            ->firstOrFail();

        $this->actingAs($serviceUser)
            ->post(route('workspace.pta.reopen', $pta), [
                'motif_retour' => 'Ajustement du contenu a completer.',
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $pta->refresh();
        $this->assertSame('brouillon', $pta->statut);
        $this->assertNull($pta->valide_le);
        $this->assertNull($pta->valide_par);

        $this->actingAs($serviceUser)
            ->post(route('workspace.pta.submit', $pta))
            ->assertRedirect(route('workspace.pta.index'));

        $pta->refresh();
        $this->assertSame('soumis', $pta->statut);
    }

    public function test_reopen_requires_reason_for_pta(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $pta = Pta::query()
            ->where('service_id', (int) $serviceUser->service_id)
            ->where('statut', 'soumis')
            ->firstOrFail();

        $this->actingAs($serviceUser)
            ->from(route('workspace.pta.index'))
            ->post(route('workspace.pta.reopen', $pta), [
                'motif_retour' => '',
            ])
            ->assertSessionHasErrors('motif_retour');

        $pta->refresh();
        $this->assertSame('soumis', $pta->statut);
    }

    public function test_admin_can_export_reporting_in_xlsx_and_pdf(): void
    {
        $admin = $this->createAdminUser();

        $xlsxResponse = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel'));

        $xlsxResponse->assertOk();
        $xlsxResponse->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', (string) $xlsxResponse->headers->get('content-disposition'));
        $xlsxBinary = $xlsxResponse->streamedContent();
        $this->assertStringStartsWith('PK', $xlsxBinary);

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, $xlsxBinary);

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($tempFile) === true);
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $sheetOneXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sheetTwoXml = $zip->getFromName('xl/worksheets/sheet2.xml');
            $chartOneXml = $zip->getFromName('xl/charts/chart1.xml');
            $drawingXml = $zip->getFromName('xl/drawings/drawing1.xml');
            $zip->close();
        } else {
            $entries = app(SimpleZipReader::class)->read($tempFile);
            $workbookXml = $entries['xl/workbook.xml'] ?? false;
            $sheetOneXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
            $sheetTwoXml = $entries['xl/worksheets/sheet2.xml'] ?? false;
            $chartOneXml = $entries['xl/charts/chart1.xml'] ?? false;
            $drawingXml = $entries['xl/drawings/drawing1.xml'] ?? false;
        }
        @unlink($tempFile);

        $this->assertNotFalse($workbookXml);
        $this->assertNotFalse($sheetOneXml);
        $this->assertNotFalse($sheetTwoXml);
        $this->assertNotFalse($chartOneXml);
        $this->assertNotFalse($drawingXml);
        $this->assertStringContainsString('Synthese graphique', (string) $workbookXml);
        $this->assertStringContainsString('Synthese KPI validee direction', (string) $sheetOneXml);
        $this->assertStringContainsString('KPI qualite', (string) $sheetOneXml);
        $this->assertStringContainsString('KPI risque', (string) $sheetOneXml);
        $this->assertStringContainsString('Funnel de pilotage', (string) $sheetTwoXml);
        $this->assertStringContainsString('<c:barChart>', (string) $chartOneXml);
        $this->assertStringContainsString('rId1', (string) $drawingXml);

        $pdfResponse = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.pdf'));

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_reporting_pdf_template_includes_quality_and_risk_kpis(): void
    {
        $admin = $this->createAdminUser();
        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);

        $html = view('workspace.monitoring.reporting-pdf', $payload)->render();

        $this->assertStringContainsString('KPI qualite', $html);
        $this->assertStringContainsString('KPI risque', $html);
        $this->assertStringContainsString('KPI global', $html);
    }

    public function test_agent_cannot_manage_actions_but_can_submit_weekly_tracking(): void
    {
        $agent = User::query()->where('email', 'melissa.abogo@anbg.ga')->firstOrFail();
        $action = Action::query()
            ->where('responsable_id', (int) $agent->id)
            ->firstOrFail();
        $week = $action->weeks()->orderBy('numero_semaine')->firstOrFail();

        $this->actingAs($agent)
            ->get(route('workspace.actions.create'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('workspace.actions.edit', $action))
            ->assertForbidden();

        $this->actingAs($agent)
            ->delete(route('workspace.actions.destroy', $action))
            ->assertForbidden();

        $response = $this->actingAs($agent)
            ->post(route('workspace.actions.weeks.submit', [$action, $week]), [
                'quantite_realisee' => 12,
                'commentaire' => 'Saisie hebdomadaire agent',
                'difficultes' => 'RAS',
                'mesures_correctives' => 'Suivi continue',
                'justificatif' => UploadedFile::fake()->create('suivi-semaine.pdf', 120, 'application/pdf'),
            ]);

        $response
            ->assertRedirect(route('workspace.actions.suivi', $action))
            ->assertSessionHas('success');

        $week->refresh();
        $this->assertTrue((bool) $week->est_renseignee);
        $this->assertSame((int) $agent->id, (int) $week->saisi_par);
    }
}
