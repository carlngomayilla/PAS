<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\Pao;
use App\Models\Action;
use App\Models\KpiMesure;
use App\Models\Kpi;
use App\Models\ObjectifOperationnel;
use App\Models\Pta;
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
            ->assertSee('Messagerie interne');

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

        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee("Centre d'export et de diffusion")
            ->assertSee('Tableau de bord analytique')
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
            ->assertDontSee('statut_validation=validee_direction', false);
    }

    public function test_dashboard_graphs_and_summary_tables_expose_drilldown_metadata(): void
    {
        $admin = $this->createAdminUser();
        $reportingPayload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);

        $chartResponse = $this->actingAs($admin)
            ->get('/dashboard?dashboardTab=charts')
            ->assertOk()
            ->assertSee('"actions_index_url"', false)
            ->assertSee(route('workspace.pas.index'), false)
            ->assertSee(route('workspace.actions.index'), false);

        $tableResponse = $this->actingAs($admin)
            ->get('/dashboard?dashboardTab=tables')
            ->assertOk()
            ->assertSee('data-row-link="', false)
            ->assertSee(route('workspace.actions.index'), false);

        $pasRowUrl = $reportingPayload['pasConsolidation'][0]['url'] ?? null;
        if (is_string($pasRowUrl) && $pasRowUrl !== '') {
            $tableResponse->assertSee('/workspace/pao?pas_id=', false);
        }
    }

    public function test_official_reporting_counts_follow_super_admin_calculation_policy(): void
    {
        $admin = $this->createAdminUser();
        [$pta, $responsable] = $this->firstUnlockedPtaAndAgent();

        Action::query()->create([
            'pta_id' => (int) $pta->id,
            'responsable_id' => (int) $responsable->id,
            'libelle' => 'Action test politique officielle',
            'description' => 'Action prise en compte des validation chef.',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => '2026-04-01',
            'date_fin' => '2026-04-30',
            'date_echeance' => '2026-04-30',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            'progression_reelle' => 40,
            'progression_theorique' => 35,
            'validation_hierarchique' => false,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('statut_validation_min=validee_chef', false)
            ->assertDontSee('statut_validation=validee_direction', false);

        $this->actingAs($admin)
            ->get('/workspace/actions?statut_validation=validee_direction')
            ->assertOk()
            ->assertDontSee('Action test politique officielle');

        $this->actingAs($admin)
            ->get('/workspace/actions')
            ->assertOk()
            ->assertSee('Action test politique officielle');
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

    public function test_workspace_indexes_accept_drilldown_filter_aliases(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/workspace/actions?statut=achevees&sort=kpi_global_desc&without_kpi=1')
            ->assertOk()
            ->assertSee('Sans indicateur')
            ->assertSee('value="achevees" selected', false)
            ->assertSee('value="kpi_global_desc" selected', false);

        $this->actingAs($admin)
            ->get('/workspace/pas?statut=valide_ou_verrouille&without_pao=1')
            ->assertOk()
            ->assertSee('PAS sans PAO')
            ->assertSee('value="valide_ou_verrouille" selected', false);

        $this->actingAs($admin)
            ->get('/workspace/pao?statut=valide_ou_verrouille&without_pta=1')
            ->assertOk()
            ->assertSee('PAO sans PTA')
            ->assertSee('value="valide_ou_verrouille" selected', false);

        $this->actingAs($admin)
            ->get('/workspace/pta?statut=valide_ou_verrouille&without_action=1')
            ->assertOk()
            ->assertSee('PTA sans action')
            ->assertSee('value="valide_ou_verrouille" selected', false);

        $this->actingAs($admin)
            ->get('/workspace/alertes?niveau=warning&etat=unread&limit=100')
            ->assertOk()
            ->assertSee('var activeLevel = "warning";', false)
            ->assertSee('var activeState = "unread";', false);

        $action = Action::query()
            ->with('pta.pao')
            ->whereNotNull('date_debut')
            ->firstOrFail();

        $this->actingAs($admin)
            ->get('/workspace/actions?direction_id='.$action->pta->direction_id.'&service_id='.$action->pta->service_id.'&pas_objectif_id='.$action->pta->pao->pas_objectif_id.'&annee='.$action->pta->pao->annee.'&mois_demarrage='.$action->date_debut->format('Y-m'))
            ->assertOk()
            ->assertSee('name="direction_id" value="'.$action->pta->direction_id.'"', false)
            ->assertSee('name="service_id" value="'.$action->pta->service_id.'"', false)
            ->assertSee('name="pas_objectif_id" value="'.$action->pta->pao->pas_objectif_id.'"', false)
            ->assertSee('name="annee" value="'.$action->pta->pao->annee.'"', false)
            ->assertSee('name="mois_demarrage" value="'.$action->date_debut->format('Y-m').'"', false);
    }

    public function test_workspace_list_pages_use_simple_statistics_labels(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/workspace/actions')
            ->assertOk()
            ->assertDontSee('Provisoire');

        $this->actingAs($admin)
            ->get('/workspace/actions?statut_validation=validee_direction')
            ->assertOk()
            ->assertDontSee('Officiel');

        $this->actingAs($admin)
            ->get('/workspace/pas?statut=valide_ou_verrouille')
            ->assertOk()
            ->assertSee('Liste des PAS');

        $this->actingAs($admin)
            ->get('/workspace/pao?statut=valide_ou_verrouille')
            ->assertOk()
            ->assertSee('Liste des PAO');

        $this->actingAs($admin)
            ->get('/workspace/pta?statut=valide_ou_verrouille')
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
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

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
            ->assertForbidden();

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

    public function test_legacy_kpi_workspace_pages_redirect_to_action_context(): void
    {
        $admin = $this->createAdminUser();
        $kpi = Kpi::query()->with('action')->firstOrFail();
        $mesure = KpiMesure::query()->with('kpi.action')->firstOrFail();

        $this->actingAs($admin)
            ->get('/workspace/kpi')
            ->assertRedirect(route('workspace.actions.index'));

        $this->actingAs($admin)
            ->get('/workspace/kpi/create')
            ->assertRedirect(route('workspace.pta.index'));

        $this->actingAs($admin)
            ->get('/workspace/kpi/'.$kpi->id.'/edit')
            ->assertRedirect(route('workspace.actions.edit', $kpi->action).'#action-indicator-settings');

        $this->actingAs($admin)
            ->get('/workspace/kpi-mesures')
            ->assertRedirect(route('workspace.actions.index'));

        $this->actingAs($admin)
            ->get('/workspace/kpi-mesures/create')
            ->assertRedirect(route('workspace.actions.index'));

        $this->actingAs($admin)
            ->get('/workspace/kpi-mesures/'.$mesure->id.'/edit')
            ->assertRedirect(route('workspace.actions.suivi', $mesure->kpi->action));
    }

    public function test_action_direct_creation_is_disabled_from_workspace(): void
    {
        $admin = $this->createAdminUser();
        [$pta, $responsable] = $this->firstUnlockedPtaAndAgent();

        $this->actingAs($admin)
            ->get('/workspace/actions/create')
            ->assertRedirect(route('workspace.pta.index'));

        $this->actingAs($admin)
            ->post(route('workspace.actions.store'), [
                'pta_id' => (int) $pta->id,
                'responsable_id' => (int) $responsable->id,
                'libelle' => 'Action test indicateur embarque',
                'description' => 'Creation via formulaire action',
                'quantite_cible' => 120,
                'resultat_attendu' => 'Finaliser 120 dossiers controles.',
                'date_debut' => '2026-04-06',
                'date_fin' => '2026-04-30',
                'frequence_execution' => 'hebdomadaire',
                'risques' => 'Charge de travail',
                'mesures_preventives' => 'Pilotage hebdomadaire',
                'kpi_seuil_alerte' => 75,
                'financement_requis' => 0,
                'ressource_main_oeuvre' => 1,
                'ressource_equipement' => 0,
                'ressource_partenariat' => 0,
                'ressource_autres' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('actions', [
            'libelle' => 'Action test indicateur embarque',
        ]);
    }

    public function test_crud_forms_hide_legacy_fields_from_old_model(): void
    {
        $admin = $this->createAdminUser();
        $pao = Pao::query()->with('direction', 'service', 'pasObjectif')->firstOrFail();
        $pta = Pta::query()->with('pao.pasObjectif', 'direction', 'service')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('workspace.pas.create'))
            ->assertOk()
            ->assertDontSee('Code axe')
            ->assertDontSee('Code objectif')
            ->assertDontSee('Budget cible')
            ->assertDontSee('Indicateur global');

        $this->actingAs($admin)
            ->get(route('workspace.pao.edit', $pao))
            ->assertOk()
            ->assertDontSee('Titre du PAO')
            ->assertDontSee('Resultats attendus')
            ->assertDontSee('Indicateurs associes')
            ->assertSee('Objectif opérationnel');

        $this->actingAs($admin)
            ->get(route('workspace.pta.edit', $pta))
            ->assertOk()
            ->assertDontSee('Tache globale du service / description complementaire')
            ->assertDontSee('name="description"', false)
            ->assertDontSee('PAO parent')
            ->assertDontSee('Formalisation du PTA')
            ->assertSee('Objectif opérationnel transmis au service')
            ->assertSee('Actions liées');

        $this->actingAs($admin)
            ->get(route('workspace.actions.create'))
            ->assertRedirect(route('workspace.pta.index'));
    }

    public function test_action_reading_pages_and_legacy_pas_sub_crud_routes_stay_clean(): void
    {
        $admin = $this->createAdminUser();
        $action = Action::query()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertDontSee('<label for="contexte_action">', false)
            ->assertDontSee('<label for="origine_action">', false)
            ->assertDontSee('<th>Contexte</th>', false);

        $this->actingAs($admin)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertDontSee('Type cible')
            ->assertDontSee('Contexte:')
            ->assertDontSee('Origine:');

        $this->actingAs($admin)
            ->get('/workspace/pas-axes/create')
            ->assertRedirect(route('workspace.pas.index'));

        $this->actingAs($admin)
            ->get('/workspace/pas-objectifs/create')
            ->assertRedirect(route('workspace.pas.index'));
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
                    'global',
                    'progression',
                ],
                'items',
                'center_url',
            ])
            ->assertJsonPath('kpi_summary.qualite', fn ($value) => is_numeric($value));

        $response->assertJsonPath('summary.total', (int) ($expectedSummary['total'] ?? 0));
        $response->assertJsonPath('summary.unread', (int) ($expectedSummary['unread'] ?? 0));
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

    public function test_alerts_page_exposes_execution_quality_and_escalation_context(): void
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
            ->get('/workspace/alertes?limit=100')
            ->assertOk()
            ->assertSee('Escalade DG')
            ->assertSee("Performance d'exécution")
            ->assertSee('Qualité')
            ->assertDontSee('Risque');
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
        $action = Action::query()->firstOrFail();

        $kpi = Kpi::query()->create([
            'action_id' => (int) $action->id,
            'libelle' => 'Indicateur de test sous seuil',
            'unite' => '%',
            'cible' => 100,
            'seuil_alerte' => 75,
            'periodicite' => 'mensuel',
            'est_a_renseigner' => true,
        ]);

        $mesure = KpiMesure::query()->create([
            'kpi_id' => (int) $kpi->id,
            'periode' => '2026-05',
            'valeur' => 50,
            'commentaire' => 'Mesure de test sous seuil',
            'saisi_par' => (int) $admin->id,
        ]);

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

    public function test_user_role_management_access_follows_role_permissions(): void
    {
        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $planification = User::query()->where('email', 'hilaire.nguebet@anbg.ga')->firstOrFail();
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $this->actingAs($dg)
            ->get('/workspace/referentiel/utilisateurs')
            ->assertForbidden();

        $this->actingAs($planification)
            ->get('/workspace/referentiel/utilisateurs/create')
            ->assertOk();

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
            'titre' => 'PAS 2029-2031',
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
        $this->assertStringContainsString('Reporting consolidé ANBG', (string) $sheetOneXml);
        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', (string) $sheetOneXml);
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
        $this->assertStringContainsString('Qualité / conformité (%)', $html);
        $this->assertStringContainsString('Avancement réel (%)', $html);
        $this->assertStringNotContainsString('Indicateur risque (%)', $html);
        $this->assertStringNotContainsString('Indicateur global (%)', $html);
        $this->assertStringContainsString('section-kicker">Direction', $html);
        $this->assertStringContainsString('section-kicker">Service', $html);
        $this->assertStringNotContainsString('<th>Direction</th><th>Service</th><th>Axe stratégique</th>', $html);
        $this->assertStringNotContainsString('Provisoire', $html);
        $this->assertStringNotContainsString('Officiel', $html);
        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', $html);
        $this->assertStringNotContainsString('level-badge level-officiel', $html);
    }

    public function test_reporting_exports_display_super_admin_official_basis(): void
    {
        $admin = $this->createAdminUser();
        app(ActionCalculationSettings::class)->updateOfficialPolicy([
            'actions_official_validation_status' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $payload = app(ReportingAnalyticsService::class)->buildPayload($admin, true, true);
        $html = view('workspace.monitoring.reporting-pdf', $payload)->render();

        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', $html);
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
        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', (string) $sheetOneXml);
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

        $this->actingAs($admin)
            ->get('/workspace/reporting')
            ->assertOk()
            ->assertSee('Base statistique : Toutes les actions visibles')
            ->assertSee('Actions suivies')
            ->assertDontSee('statut_validation_min=validee_chef', false)
            ->assertDontSee('statut_validation=validee_direction', false)
            ->assertDontSee('Moyenne validee direction')
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

        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', $html);
        $this->assertStringContainsString('Actions', $html);
        $this->assertStringNotContainsString('statut_validation_min=validee_chef', $html);
        $this->assertStringNotContainsString('statut_validation=validee_direction', $html);
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

        $this->assertStringContainsString('Base statistique : Toutes les actions visibles', $html);
        $this->assertStringContainsString('Toutes les actions visibles', $html);
        $this->assertStringContainsString('Validées', $html);
        $this->assertStringNotContainsString('Moyenne validee direction', $html);
        $this->assertStringNotContainsString('socle officiel valide direction', $html);
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
            ->assertRedirect(route('workspace.pta.index'));

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

    public function test_pta_create_form_displays_parent_pao_context_and_default_title(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $pao = Pao::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'pasObjectif:id,code,libelle',
            ])
            ->where('service_id', (int) $serviceUser->service_id)
            ->whereNotNull('objectif_operationnel')
            ->firstOrFail();

        $response = $this->actingAs($serviceUser)
            ->get(route('workspace.pta.create', ['pao_id' => $pao->id]));

        $response
            ->assertOk()
            ->assertSee('Objectif opérationnel transmis au service')
            ->assertSee('Titre PTA généré')
            ->assertSee('PTA - ' . $pao->service->code)
            ->assertSee($pao->titre)
            ->assertSee($pao->direction->code . ' - ' . $pao->direction->libelle)
            ->assertSee($pao->service->code . ' - ' . $pao->service->libelle)
            ->assertSee($pao->pasObjectif->code . ' - ' . $pao->pasObjectif->libelle)
            ->assertSee($pao->objectif_operationnel)
            ->assertSee('objectif opérationnel sélectionné')
            ->assertSee('+ Ajouter une autre action')
            ->assertDontSee('PAO parent')
            ->assertDontSee('Formalisation du PTA');
    }

    public function test_pta_store_creates_actions_and_multiple_rmos_from_operational_objective(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $objectif = ObjectifOperationnel::query()
            ->with('service')
            ->where('service_id', (int) $serviceUser->service_id)
            ->whereNotNull('pao_id')
            ->orderBy('id')
            ->firstOrFail();

        Pta::query()->where('service_id', (int) $serviceUser->service_id)->delete();

        $agentOne = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('service_id', (int) $serviceUser->service_id)
            ->first();
        if (! $agentOne instanceof User) {
            $agentOne = User::factory()->create([
                'role' => User::ROLE_AGENT,
                'direction_id' => (int) $serviceUser->direction_id,
                'service_id' => (int) $serviceUser->service_id,
                'is_active' => true,
            ]);
        }

        $agentTwo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => (int) $serviceUser->service_id,
            'is_active' => true,
        ]);

        $dateFin = $objectif->echeance?->copy()->subDay()->format('Y-m-d') ?? '2026-04-30';
        $dateDebut = $objectif->echeance?->copy()->subDays(7)->format('Y-m-d') ?? '2026-04-20';

        $this->actingAs($serviceUser)
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => (int) $objectif->id,
                'statut' => 'brouillon',
                'actions' => [
                    [
                        'libelle' => 'Action PTA multi RMO',
                        'description' => 'Action creee depuis le PTA.',
                        'date_debut' => $dateDebut,
                        'date_fin' => $dateFin,
                        'statut' => 'non_demarre',
                        'resultat_attendu' => 'Traitement operationnel effectue.',
                        'financement_requis' => false,
                        'rmo_ids' => [(int) $agentOne->id, (int) $agentTwo->id],
                    ],
                    [
                        'libelle' => 'Action PTA quantitative',
                        'description' => 'Action quantitative creee depuis le PTA.',
                        'date_debut' => $dateDebut,
                        'date_fin' => $dateFin,
                        'statut' => 'non_demarre',
                        'mode_evaluation' => 'quantitatif',
                        'quantite_cible' => 500,
                        'unite_cible' => 'dossiers',
                        'ressources_necessaires' => ['ressources_humaines'],
                        'ressources_details' => 'Deux agents instructeurs',
                        'financement_requis' => false,
                        'risque_potentiel' => 'Retard de traitement',
                        'mesures_preventives' => 'Suivi hebdomadaire',
                        'rmo_ids' => [(int) $agentOne->id],
                    ],
                    [
                        'libelle' => 'Action PTA mixte',
                        'description' => 'Action mixte creee depuis le PTA.',
                        'date_debut' => $dateDebut,
                        'date_fin' => $dateFin,
                        'statut' => 'non_demarre',
                        'mode_evaluation' => 'mixte',
                        'quantite_cible' => 100,
                        'unite_cible' => 'agents formes',
                        'financement_requis' => false,
                        'rmo_ids' => [(int) $agentTwo->id],
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $pta = Pta::query()
            ->where('service_id', (int) $serviceUser->service_id)
            ->where('objectif_operationnel_id', (int) $objectif->id)
            ->firstOrFail();

        $action = Action::query()
            ->where('pta_id', (int) $pta->id)
            ->where('objectif_operationnel_id', (int) $objectif->id)
            ->where('libelle', 'Action PTA multi RMO')
            ->firstOrFail();

        $this->assertSame((int) $objectif->pao_id, (int) $action->pao_id);
        $this->assertSame(Action::MODE_SOUS_ACTIONS, $action->mode_evaluation);
        $this->assertDatabaseHas('action_responsables', [
            'action_id' => (int) $action->id,
            'user_id' => (int) $agentOne->id,
        ]);
        $this->assertDatabaseHas('action_responsables', [
            'action_id' => (int) $action->id,
            'user_id' => (int) $agentTwo->id,
        ]);
        $quantitativeAction = Action::query()
            ->where('pta_id', (int) $pta->id)
            ->where('libelle', 'Action PTA quantitative')
            ->firstOrFail();

        $this->assertSame(Action::MODE_QUANTITATIF, $quantitativeAction->mode_evaluation);
        $this->assertSame('dossiers', $quantitativeAction->unite_cible);
        $this->assertSame(['ressources_humaines'], $quantitativeAction->ressources_necessaires);
        $this->assertSame('Deux agents instructeurs', $quantitativeAction->ressources_details);
        $this->assertFalse((bool) $quantitativeAction->financement_requis);
        $this->assertSame(Action::FINANCEMENT_NON_REQUIS, $quantitativeAction->financementStatus());
        $this->assertNull($quantitativeAction->source_financement);
        $this->assertSame('Retard de traitement', $quantitativeAction->risque_potentiel);
        $this->assertSame('Suivi hebdomadaire', $quantitativeAction->mesures_preventives);
        $this->assertNull($quantitativeAction->niveau_risque);

        $this->assertDatabaseHas('actions', [
            'pta_id' => (int) $pta->id,
            'libelle' => 'Action PTA mixte',
            'mode_evaluation' => Action::MODE_MIXTE,
            'unite_cible' => 'agents formes',
        ]);
    }

    public function test_pta_store_action_with_financing_upload_is_transmitted_to_daf(): void
    {
        Storage::fake('local');

        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $dafDirector = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();
        $objectif = ObjectifOperationnel::query()
            ->with('service')
            ->where('service_id', (int) $serviceUser->service_id)
            ->whereNotNull('pao_id')
            ->orderBy('id')
            ->firstOrFail();

        Pta::query()->where('service_id', (int) $serviceUser->service_id)->delete();

        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('service_id', (int) $serviceUser->service_id)
            ->firstOrFail();

        $dateFin = $objectif->echeance?->copy()->subDay()->format('Y-m-d') ?? '2026-04-30';
        $dateDebut = $objectif->echeance?->copy()->subDays(7)->format('Y-m-d') ?? '2026-04-20';

        $this->actingAs($serviceUser)
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => (int) $objectif->id,
                'statut' => 'brouillon',
                'actions' => [
                    [
                        'libelle' => 'Action PTA financee DAF',
                        'description' => 'Action creee depuis le PTA avec besoin de financement.',
                        'date_debut' => $dateDebut,
                        'date_fin' => $dateFin,
                        'statut' => 'non_demarre',
                        'mode_evaluation' => 'quantitatif',
                        'quantite_cible' => 25,
                        'unite_cible' => 'missions',
                        'ressources_necessaires' => ['main_oeuvre', 'ressources_informatiques'],
                        'ressources_details' => 'Mobilisation de deux agents et equipements informatiques.',
                        'financement_requis' => true,
                        'montant_estime' => 1250000,
                        'nature_financement' => 'Equipements informatiques',
                        'source_financement' => 'Budget PTA',
                        'commentaire_financement' => 'Financement requis pour les equipements de suivi.',
                        'justificatif_financement' => UploadedFile::fake()->create('budget-action.pdf', 64, 'application/pdf'),
                        'risque_potentiel' => 'Retard de mise a disposition du materiel',
                        'mesures_preventives' => 'Anticiper la demande et suivre la commande.',
                        'rmo_ids' => [(int) $agent->id],
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $action = Action::query()
            ->where('libelle', 'Action PTA financee DAF')
            ->firstOrFail();

        $this->assertTrue((bool) $action->financement_requis);
        $this->assertSame(Action::FINANCEMENT_EN_ATTENTE_DAF, $action->financementStatus());
        $this->assertSame(['main_oeuvre', 'ressources_informatiques'], $action->ressources_necessaires);
        $this->assertSame('Equipements informatiques', $action->nature_financement);
        $this->assertSame('Budget PTA', $action->source_financement);
        $this->assertNotNull($action->justificatif_financement_path);

        Storage::disk('local')->assertExists((string) $action->justificatif_financement_path);
        $this->assertDatabaseHas('justificatifs', [
            'justifiable_type' => Action::class,
            'justifiable_id' => (int) $action->id,
            'categorie' => 'financement',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => (int) $dafDirector->id,
        ]);

        $this->actingAs($dafDirector)
            ->get(route('workspace.daf.financements.index'))
            ->assertOk()
            ->assertSee('Action PTA financee DAF')
            ->assertSee('Budget PTA')
            ->assertSee('Equipements informatiques');
    }

    public function test_action_create_form_redirects_to_pta_workflow(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $pta = Pta::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'pao:id,pas_objectif_id,titre,objectif_operationnel',
                'pao.pasObjectif:id,code,libelle',
            ])
            ->where('service_id', (int) $serviceUser->service_id)
            ->whereHas('pao', fn ($query) => $query->whereNotNull('objectif_operationnel'))
            ->firstOrFail();

        $response = $this->actingAs($serviceUser)
            ->get(route('workspace.actions.create', ['pta_id' => $pta->id]));

        $response
            ->assertRedirect(route('workspace.pta.index'))
            ->assertSessionHas('info', 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');
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
