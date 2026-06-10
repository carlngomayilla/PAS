<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Action;
use App\Models\KpiMesure;
use App\Models\Kpi;
use App\Models\ObjectifOperationnel;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

        User::query()
            ->whereNull('password_changed_at')
            ->update(['password_changed_at' => now()]);
    }

    public function test_admin_can_access_workspace_pages(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Espace de travail');

        $this->actingAs($admin)
            ->get('/workspace/pilotage')
            ->assertRedirect('/dashboard');

        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee('Reporting consolidé');

        $this->actingAs($admin)
            ->get('/workspace/alertes')
            ->assertOk()
            ->assertSee('Alertes opérationnelles')
            ->assertSee("Centre d'alertes", false);

        $this->actingAs($admin)
            ->get('/workspace/audit')
            ->assertOk()
            ->assertSee("Journal d'audit", false);

        $this->actingAs($admin)
            ->get('/workspace/referentiel/directions')
            ->assertOk()
            ->assertSee('Référentiel - Directions');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/services')
            ->assertOk()
            ->assertSee('Référentiel - Services');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertOk()
            ->assertSee('Référentiel - Utilisateurs');

        $this->actingAs($admin)
            ->get('/workspace/pas')
            ->assertOk()
            ->assertSee('PAS');

        $this->actingAs($admin)
            ->get('/workspace/pao')
            ->assertOk()
            ->assertSee('PAO');

        // Routes legacy /workspace/pas-axes et /workspace/pas-objectifs SUPPRIMEES
        // (2026-05-29) : tout est gere dans le wizard PAS unique.
        $this->actingAs($admin)
            ->get('/workspace/pas-axes')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/workspace/pas-objectifs')
            ->assertNotFound();

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
            ->get('/workspace/actions/create')
            ->assertRedirect(route('workspace.pta.index'))
            ->assertSessionHas('info', 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');

        $this->actingAs($admin)
            ->get('/workspace/kpi')
            ->assertRedirect(route('workspace.actions.index'));

        $this->actingAs($admin)
            ->get('/workspace/kpi-mesures')
            ->assertRedirect(route('workspace.actions.index'));

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

    public function test_legacy_workspace_modules_redirect_to_functional_pages(): void
    {
        $direction = Direction::query()->create([
            'code' => 'DMOD',
            'libelle' => 'Direction modules',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SMOD',
            'libelle' => 'Service modules',
            'actif' => true,
        ]);
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
        ]);

        $this->actingAs($agent)
            ->get('/workspace/corrections')
            ->assertRedirect(route('workspace.actions.index', ['vue' => 'mes_actions', 'statut' => 'a_corriger']));

        $this->actingAs($chef)
            ->get('/workspace/agents')
            ->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $this->actingAs($dg)
            ->get('/workspace/financements-critiques')
            ->assertRedirect(route('workspace.daf.financements.index'));

        $this->actingAs($dg)
            ->get('/workspace/synthese-agence')
            ->assertRedirect(route('workspace.reporting'));
    }

    public function test_referentiel_directions_hides_inactive_entries_by_default(): void
    {
        $admin = $this->createAdminUser();

        Direction::query()->create([
            'code' => 'LEGACY',
            'libelle' => 'Direction Legacy',
            'actif' => false,
        ]);

        $this->actingAs($admin)
            ->get('/workspace/referentiel/directions')
            ->assertOk()
            ->assertSee('Référentiel - Directions')
            ->assertSee('DAF')
            ->assertSee('DG')
            ->assertSee('DS')
            ->assertSee('DSIC')
            ->assertDontSee('Direction Legacy');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/directions?actif=0')
            ->assertOk()
            ->assertSee('Direction Legacy');
    }

    public function test_dashboard_embeds_advanced_reporting_visuals_while_reporting_page_becomes_export_hub(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/dashboard?dashboardTab=charts')
            ->assertOk()
            ->assertSee('Analytique avancée')
            ->assertSee('Statuts empiles par')
            ->assertSee('dashboard-report-status-unit-chart', false)
            ->assertSee('analytics-explorer-title', false);

        // Le bloc « Analytique disponible » (qui contenait le bouton « Tableau de bord analytique »)
        // a été retiré du module Reporting pour s'aligner sur la nouvelle logique métier.
        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee("Centre d'export et de diffusion")
            ->assertDontSee('Analytique disponible')
            ->assertDontSee('dashboard-report-status-unit-chart', false);
    }

    public function test_dashboard_stat_cards_expose_drilldown_links(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('workspace.actions.index'), false)
            ->assertSee(route('workspace.actions.index', ['statut' => 'en_retard']), false)
            ->assertSee('statut_validation_min=validee_chef', false);
    }

    public function test_admin_layout_disables_global_content_auto_refresh(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/dashboard?dashboardTab=charts')
            ->assertOk()
            ->assertSee('data-auto-refresh="0"', false)
            ->assertSee('data-auto-refresh-region', false);
    }

    public function test_pilotage_stat_cards_expose_drilldown_links(): void
    {
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $this->actingAs($dg)
            ->get('/workspace/pilotage')
            ->assertRedirect('/dashboard');

        $this->assertStringContainsString('without_pao=1', route('workspace.pas.index', ['without_pao' => 1]));
        $this->assertStringContainsString('without_kpi=1', route('workspace.actions.index', ['without_kpi' => 1]));
    }

    public function test_workspace_list_pages_use_simple_statistics_labels(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/workspace/actions')
            ->assertOk()
            ->assertDontSee('Provisoire');

        $this->actingAs($admin)
            ->get('/workspace/actions?statut_validation_min=validee_chef')
            ->assertOk()
            ->assertDontSee('Officiel');

        $this->actingAs($admin)
            ->get('/workspace/pas?statut=actif')
            ->assertOk()
            ->assertSee('Liste des PAS');

        $this->actingAs($admin)
            ->get('/workspace/pao?statut=en_cours')
            ->assertOk()
            ->assertSee('Liste des PAO');

        $this->actingAs($admin)
            ->get('/workspace/pta?statut=en_cours')
            ->assertOk()
            ->assertSee('Liste des PTA');

        $this->actingAs($admin)
            ->get('/workspace/alertes?limit=100')
            ->assertOk()
            ->assertDontSee('Provisoire')
            ->assertDontSee('Officiel');
    }

    public function test_service_user_has_no_audit_access_and_removed_modules_are_unavailable(): void
    {
        $serviceUser = User::query()->where('email', 'r.ekomi.anbg@gmail.com')->firstOrFail();

        $this->actingAs($serviceUser)
            ->get('/workspace/pilotage')
            ->assertRedirect('/dashboard');

        $this->actingAs($serviceUser)
            ->get('/workspace/justificatifs')
            ->assertNotFound();

        $this->actingAs($serviceUser)
            ->get('/workspace/justificatifs/create')
            ->assertNotFound();

        $this->actingAs($serviceUser)
            ->get('/workspace/referentiel/directions')
            ->assertOk();

        $this->actingAs($serviceUser)
            ->get('/workspace/kpi-mesures')
            ->assertRedirect(route('workspace.actions.index'));

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
                    'global',
                    'progression',
                ],
                'items',
                'center_url',
            ])
            ->assertJsonPath('kpi_summary.conformite', fn ($value) => is_numeric($value));

        $response->assertJsonPath('summary.total', (int) ($expectedSummary['total'] ?? 0));
        $response->assertJsonPath('summary.unread', (int) ($expectedSummary['unread'] ?? 0));
        $response->assertJsonPath('center_url', route('workspace.notifications.index', ['tab' => 'alertes']));
    }

    public function test_notifications_page_exposes_alert_center_tab(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.notifications.index', ['tab' => 'alertes']))
            ->assertOk()
            ->assertSee('Alertes')
            ->assertSee('Notifications');
    }

    public function test_sidebar_alert_badge_is_only_on_alertes_entry_not_pilotage(): void
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

        // Le menu Pilotage est présent dans la sidebar...
        $this->assertStringContainsString('data-sidebar-module="pilotage"', $content);

        // ...mais ne doit JAMAIS porter de badge d'alertes (qui faisait double emploi avec le menu Alertes).
        $this->assertDoesNotMatchRegularExpression(
            '/data-sidebar-module="pilotage"[\s\S]*?data-sidebar-badge-for="pilotage">/',
            $content
        );

        // Le badge d'alertes reste sur l'entrée Alertes uniquement, basé sur le compteur réel d'alertes.
        if ($expectedAlertUnreadCount > 0) {
            $expectedBadge = $expectedAlertUnreadCount > 99 ? '99+' : (string) $expectedAlertUnreadCount;
            $this->assertMatchesRegularExpression(
                '/data-sidebar-module="alertes"[\s\S]*?data-sidebar-badge-for="alertes">' . preg_quote($expectedBadge, '/') . '<\/span>/',
                $content
            );
        }
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

    public function test_user_role_management_access_follows_role_permissions(): void
    {
        // Note (2026-05-29) : la liste utilisateurs est en lecture pour tout role
        // disposant de referentiel.read (DG, Cabinet, Direction, etc.). Seules les
        // ecritures (create/update/delete) sont restreintes a users.manage.
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $planification = User::query()->where('role', User::ROLE_PLANIFICATION)->firstOrFail();
        $cabinet = User::query()->where('email', 'l.adan.anbg@gmail.com')->firstOrFail();

        // DG : peut LIRE la liste mais pas CREER (pas users.manage).
        $this->actingAs($dg)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertOk();
        $this->actingAs($dg)
            ->get('/workspace/referentiel/utilisateurs/create')
            ->assertForbidden();

        // Planification : peut LIRE et CREER (a users.manage).
        $this->actingAs($planification)
            ->get('/workspace/referentiel/utilisateurs/create')
            ->assertOk();

        // Cabinet : peut LIRE mais pas CREER.
        $this->actingAs($cabinet)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertOk();
        $this->actingAs($cabinet)
            ->get('/workspace/referentiel/utilisateurs/create')
            ->assertForbidden();
    }

    public function test_cabinet_cannot_access_removed_justificatifs_module(): void
    {
        $cabinet = User::query()->where('email', 'l.adan.anbg@gmail.com')->firstOrFail();

        $response = $this->actingAs($cabinet)
            ->get('/workspace/justificatifs');

        $response->assertNotFound();

        $this->actingAs($cabinet)
            ->get('/workspace/justificatifs/create')
            ->assertNotFound();
    }

    public function test_cabinet_cannot_open_or_submit_pas_wizard(): void
    {
        // A06 — Cabinet a perdu planning.strategic.manage : il consulte le PAS
        // mais ne peut plus le creer / modifier. La gestion du PAS revient a
        // PLANIFICATION, SCIQ, SUPER_ADMIN, ADMIN.
        $cabinet = User::query()->where('email', 'l.adan.anbg@gmail.com')->firstOrFail();

        $this->actingAs($cabinet)
            ->get(route('workspace.pas.create'))
            ->assertForbidden();

        // Le payload inclut date_echeance (champ obligatoire sur chaque objectif
        // stratégique selon la nouvelle logique métier). Sans cela la validation
        // échoue avant le check d'autorisation et le test reçoit 302 au lieu de 403.
        $this->actingAs($cabinet)
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
                                'date_echeance' => '2030-12-31',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('pas', [
            'titre' => 'PAS 2029-2031',
        ]);
    }

    public function test_direction_user_cannot_open_pta_create_form(): void
    {
        $directionUser = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();

        $this->actingAs($directionUser)
            ->get(route('workspace.pta.create'))
            ->assertForbidden();
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
            $sheetThreeXml = $zip->getFromName('xl/worksheets/sheet3.xml');
            $sheetFourXml = $zip->getFromName('xl/worksheets/sheet4.xml');
            $sheetFiveXml = $zip->getFromName('xl/worksheets/sheet5.xml');
            $sheetSixXml = $zip->getFromName('xl/worksheets/sheet6.xml');
            $sheetSevenXml = $zip->getFromName('xl/worksheets/sheet7.xml');
            $sheetEightXml = $zip->getFromName('xl/worksheets/sheet8.xml');
            $sheetNineXml = $zip->getFromName('xl/worksheets/sheet9.xml');
            $sheetTenXml = $zip->getFromName('xl/worksheets/sheet10.xml');
            $stylesXml = $zip->getFromName('xl/styles.xml');
            $zip->close();
        } else {
            $entries = app(SimpleZipReader::class)->read($tempFile);
            $workbookXml = $entries['xl/workbook.xml'] ?? false;
            $sheetOneXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
            $sheetTwoXml = $entries['xl/worksheets/sheet2.xml'] ?? false;
            $sheetThreeXml = $entries['xl/worksheets/sheet3.xml'] ?? false;
            $sheetFourXml = $entries['xl/worksheets/sheet4.xml'] ?? false;
            $sheetFiveXml = $entries['xl/worksheets/sheet5.xml'] ?? false;
            $sheetSixXml = $entries['xl/worksheets/sheet6.xml'] ?? false;
            $sheetSevenXml = $entries['xl/worksheets/sheet7.xml'] ?? false;
            $sheetEightXml = $entries['xl/worksheets/sheet8.xml'] ?? false;
            $sheetNineXml = $entries['xl/worksheets/sheet9.xml'] ?? false;
            $sheetTenXml = $entries['xl/worksheets/sheet10.xml'] ?? false;
            $stylesXml = $entries['xl/styles.xml'] ?? false;
        }
        @unlink($tempFile);

        $this->assertNotFalse($workbookXml);
        $this->assertNotFalse($sheetOneXml);
        $this->assertNotFalse($sheetTwoXml);
        $this->assertNotFalse($sheetThreeXml);
        $this->assertNotFalse($sheetFourXml);
        $this->assertNotFalse($sheetFiveXml);
        $this->assertNotFalse($sheetSixXml);
        $this->assertNotFalse($sheetSevenXml);
        $this->assertNotFalse($sheetEightXml);
        $this->assertNotFalse($sheetNineXml);
        $this->assertNotFalse($sheetTenXml);
        $this->assertNotFalse($stylesXml);
        $this->assertStringContainsString('STRATEGIE', (string) $workbookXml);
        $this->assertStringContainsString('PAO', (string) $workbookXml);
        $this->assertStringContainsString('ACTIONS', (string) $workbookXml);
        $this->assertStringContainsString('Indicateurs', (string) $workbookXml);
        $this->assertStringContainsString('SYNTH', (string) $workbookXml);
        $this->assertStringContainsString('ALERTES', (string) $workbookXml);
        $this->assertStringNotContainsString('RISQUES', (string) $workbookXml);
        $this->assertStringContainsString('RMO_PERFORMANCE', (string) $workbookXml);
        $this->assertStringContainsString('JUSTIFICATIFS', (string) $workbookXml);
        $this->assertStringNotContainsString('Synthèse graphique', (string) $workbookXml);
        $this->assertStringContainsString('Rapport Consolidé DG - PAS ANBG', (string) $sheetOneXml);
        $this->assertStringContainsString('Base statistique : Validation chef de service', (string) $sheetOneXml);
        $this->assertStringContainsString('Plan d', (string) $sheetOneXml);
        $this->assertStringContainsString('Axes strat', (string) $sheetOneXml);
        $this->assertStringContainsString('Tableau 1 : Axes &amp; Objectifs stratégiques', (string) $sheetTwoXml);
        $this->assertStringContainsString('Axe stratégique', (string) $sheetTwoXml);
        $this->assertStringContainsString('Tableau 2 : Objectifs opérationnels &amp; Actions', (string) $sheetThreeXml);
        $this->assertStringContainsString('Direction', (string) $sheetThreeXml);
        $this->assertStringContainsString('Service', (string) $sheetThreeXml);
        $this->assertStringContainsString('Objectif opérationnel', (string) $sheetThreeXml);
        $this->assertStringContainsString('Tableau 3 : Actions détaillées', (string) $sheetFourXml);
        $this->assertStringContainsString('Description action', (string) $sheetFourXml);
        $this->assertStringContainsString('Tableau 4 : Indicateurs par action', (string) $sheetFiveXml);
        $this->assertStringContainsString('Performance d execution (%)', (string) $sheetFiveXml);
        $this->assertStringContainsString('Tableau 5 : Reporting synthétique', (string) $sheetSixXml);
        $this->assertStringContainsString('Performance (%)', (string) $sheetSixXml);
        $this->assertStringContainsString('Tableau 6 : Alertes indicateurs sous seuil', (string) $sheetSevenXml);
        $this->assertStringContainsString('Action corrective', (string) $sheetSevenXml);
        $this->assertStringContainsString('Tableau 7 : Performance par RMO', (string) $sheetEightXml);
        $this->assertStringContainsString('RMO', (string) $sheetEightXml);
        $this->assertStringContainsString('Tableau 8 : Suivi des justificatifs', (string) $sheetNineXml);
        $this->assertStringContainsString('Statut validation', (string) $sheetNineXml);
        $this->assertStringContainsString('ANBG - RAPPORT PAS PAR DIRECTION ET SERVICE', (string) $sheetTenXml);
        $this->assertStringContainsString('Service :', (string) $sheetTenXml);
        $this->assertStringContainsString('FF7FB8E6', (string) $stylesXml);
        $this->assertStringContainsString('FF3996D3', (string) $stylesXml);
        $this->assertStringContainsString('FF1C203D', (string) $stylesXml);

        $pdfResponse = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.pdf'));

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_reporting_pdf_template_includes_execution_quality_and_progress_kpis(): void
    {
        $admin = $this->createAdminUser();
        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);

        $html = view('workspace.monitoring.reporting-pdf', $payload)->render();

        $this->assertStringContainsString("Performance d'exécution (%)", $html);
        // KPI Conformite retire (2026-05-28) — assertion inversee.
        $this->assertStringNotContainsString('Conformité (%)', $html);
        $this->assertStringContainsString('Avancement réel (%)', $html);
        $this->assertStringNotContainsString('Indicateur risque (%)', $html);
        $this->assertStringNotContainsString('Indicateur global (%)', $html);
        $this->assertStringContainsString('section-kicker">Direction', $html);
        $this->assertStringContainsString('section-kicker">Service', $html);
        $this->assertStringNotContainsString('<th>Direction</th><th>Service</th><th>Axe stratégique</th>', $html);
        $this->assertStringNotContainsString('Provisoire', $html);
        $this->assertStringNotContainsString('Officiel', $html);
        $this->assertStringContainsString('Base statistique : Validation chef de service', $html);
        $this->assertStringNotContainsString('level-badge level-officiel', $html);
    }

    public function test_glass_theme_keeps_admin_table_headers_blue(): void
    {
        $css = file_get_contents(resource_path('css/anbg-glass.css'));
        $this->assertIsString($css);
        $normalizedCss = str_replace("\r\n", "\n", $css);

        $this->assertStringContainsString('.data-table thead th', $normalizedCss);
        $this->assertStringContainsString('background: #3996d3 !important;', $normalizedCss);
        $this->assertStringNotContainsString('table thead th
    ) {
        background: #f1f5f9 !important;', $normalizedCss);
        $this->assertStringNotContainsString('table thead th
    ) {
        background: #1e293b !important;', $normalizedCss);
    }

    public function test_reporting_exports_display_super_admin_official_basis(): void
    {
        $admin = $this->createAdminUser();
        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);
        $html = view('workspace.monitoring.reporting-pdf', $payload)->render();

        $this->assertStringContainsString('Base statistique : Validation chef de service', $html);
        $this->assertStringContainsString('Statut validation', $html);

        $xlsxResponse = $this->actingAs($admin)
            ->get(route('workspace.reporting.export.excel'));

        $xlsxResponse->assertOk();
        $xlsxBinary = $xlsxResponse->streamedContent();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_policy_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, $xlsxBinary);

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($tempFile) === true);
            $sheetOneXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sheetTwoXml = $zip->getFromName('xl/worksheets/sheet2.xml');
            $sheetSixXml = $zip->getFromName('xl/worksheets/sheet6.xml');
            $sheetSevenXml = $zip->getFromName('xl/worksheets/sheet7.xml');
            $zip->close();
        } else {
            $entries = app(SimpleZipReader::class)->read($tempFile);
            $sheetOneXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
            $sheetTwoXml = $entries['xl/worksheets/sheet2.xml'] ?? false;
            $sheetSixXml = $entries['xl/worksheets/sheet6.xml'] ?? false;
            $sheetSevenXml = $entries['xl/worksheets/sheet7.xml'] ?? false;
        }
        @unlink($tempFile);

        $this->assertNotFalse($sheetOneXml);
        $this->assertNotFalse($sheetTwoXml);
        $this->assertNotFalse($sheetSixXml);
        $this->assertNotFalse($sheetSevenXml);
        $this->assertStringContainsString('Base statistique : Validation chef de service', (string) $sheetOneXml);
        $this->assertStringContainsString('Tableau 1 : Axes &amp; Objectifs stratégiques', (string) $sheetTwoXml);
        $this->assertStringContainsString('Tableau 5 : Reporting synthétique', (string) $sheetSixXml);
        $this->assertStringContainsString('Tableau 6 : Alertes indicateurs sous seuil', (string) $sheetSevenXml);
        $this->assertStringContainsString('Action corrective', (string) $sheetSevenXml);
    }

    public function test_reporting_hub_displays_super_admin_official_basis(): void
    {
        $admin = $this->createAdminUser();
        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        // Les cartes synthèse PAS / PAO / Actions suivies / Alertes ont été retirées
        // du module Reporting (alignement nouvelle logique métier).
        // Les assertions sur statut_validation_min / Moyenne validée direction portaient
        // sur les liens de ces cartes — désormais non rendus dans la page.
        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee('Base statistique : Validation chef de service')
            ->assertDontSee('Lecture DG : opérationnel vs consolidé');
    }

    public function test_pilotage_displays_super_admin_official_basis(): void
    {
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($dg, true, true);
        $html = view('partials.dashboard-reporting-analytics', [
            'reportingAnalytics' => $payload,
        ])->render();

        $this->assertStringContainsString('Base statistique : Validation chef de service', $html);
        $this->assertStringContainsString('Actions', $html);
        $this->assertStringNotContainsString('Moyenne validee direction', $html);
        $this->assertStringNotContainsString('socle officiel valide direction', $html);
        $this->assertStringNotContainsString('Lecture DG : opérationnel vs consolidé', $html);
    }

    public function test_dashboard_reporting_analytics_partial_displays_super_admin_official_basis(): void
    {
        $admin = $this->createAdminUser();
        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);
        $html = view('partials.dashboard-reporting-analytics', [
            'reportingAnalytics' => $payload,
        ])->render();

        $this->assertStringContainsString('Base statistique : Validation chef de service', $html);
        $this->assertStringContainsString('Validation chef de service', $html);
        $this->assertStringContainsString('Validées', $html);
        $this->assertStringNotContainsString('Moyenne validee direction', $html);
        $this->assertStringNotContainsString('socle officiel valide direction', $html);
    }

    public function test_dashboard_status_distribution_exposes_all_status_filters(): void
    {
        $admin = $this->createAdminUser();

        $content = $this->actingAs($admin)
            ->get('/dashboard?dashboardTab=charts')
            ->assertOk()
            ->getContent();

        foreach ([
            'statut=a_parametrer',
            'statut=en_avance',
            'statut=en_cours',
            'statut=a_risque',
            'statut=en_retard',
            'statut=suspendu',
            'statut=annule',
            'statut=non_demarre',
            'statut=achevees',
        ] as $filter) {
            $this->assertStringContainsString($filter, $content);
        }
    }

    /**
     * @return array{0: \App\Models\Pta, 1: \App\Models\User}
     */
    private function firstUnlockedPtaAndAgent(): array
    {
        $pta = Pta::query()
            ->where(function ($query): void {
                $query->whereNull('statut')
                    ->orWhere('statut', '!=', 'verrouille');
            })
            ->orderBy('id')
            ->first();

        if (! $pta instanceof Pta) {
            $pta = Pta::query()->orderBy('id')->firstOrFail();

            if ((string) ($pta->statut ?? '') === 'verrouille') {
                $pta->update(['statut' => 'brouillon']);
            }
        }

        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('direction_id', (int) $pta->direction_id)
            ->where('service_id', (int) $pta->service_id)
            ->orderBy('id')
            ->first();

        if (! $agent instanceof User) {
            $agent = User::factory()->create([
                'name' => 'Agent test PTA',
                'email' => 'agent.pta.test@anbg.test',
                'password' => Hash::make('Pass@12345'),
                'password_changed_at' => now(),
                'role' => User::ROLE_AGENT,
                'is_agent' => true,
                'is_active' => true,
                'direction_id' => (int) $pta->direction_id,
                'service_id' => (int) $pta->service_id,
            ]);
        }

        return [$pta, $agent];
    }
}
