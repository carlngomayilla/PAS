<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardProfileInteractionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_sees_profile_interactions_on_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee($user->name);
        $response->assertSee($user->roleLabel());
        $response->assertDontSee('Interactions disponibles pour ce profil');
        $response->assertSee('Synth');
        $response->assertSee('Graphiques');
        $response->assertSee('Vue détaillée');
        $response->assertSee('Pilotage du service');
        $response->assertSee('Flux à traiter');
        $response->assertSee("Reports d'échéance");
    }

    public function test_seeded_service_user_can_open_dashboard_without_scope_error(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'r.ekomi.anbg@gmail.com')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Pilotage du service');
        $overview->assertSee('valider');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('avancement du PAS');
        $charts->assertDontSee('Analytique avancee');
        // Graphique « Repartition des statuts » retire pour tous les roles (2026-06-10).
        $charts->assertDontSee('dashboard-role-status-chart', false);
        $charts->assertSee('dashboard-role-support-chart', false);

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Agents');
        $tables->assertSee('Synthèse');
    }

    public function test_seeded_agent_user_sees_agent_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'm.abogo.anbg@gmail.com')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Suivi personnel');

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Priorites');
        $tables->assertSee('Retards');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('actions par statut');
        $charts->assertSee('dashboard-role-trend-chart', false);
    }

    public function test_seeded_direction_user_sees_direction_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Pilotage directionnel et comparaison des services');

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Services');
        $tables->assertSee('Actions critiques');
        $tables->assertSee('SFC');
        $tables->assertSee('AJARH');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('dashboard-role-support-chart', false);
    }

    public function test_seeded_sciq_user_sees_suivi_evaluation_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'k.angue.anbg@gmail.com')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Controle et suivi-evaluation');
        $overview->assertSee('Actions suivies');
        $overview->assertSee('Avancement global');
        $overview->assertSee('Pilotage administratif');
        $overview->assertSee("Reports d'échéance");

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Directions');
        $tables->assertDontSee('Directions sous vigilance');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('avancement du PAS');
        $charts->assertSee('Graphiques du PTA trimestriel');
        $charts->assertSee('dashboard-pta-axis-progression-chart-charts', false);
        $charts->assertSee('dashboard-pta-monthly-rate-chart-charts', false);
        $charts->assertSee('dashboard-pta-axis-rate-chart-charts', false);
        $charts->assertSee('dashboard-pta-service-rate-chart-charts', false);
        $charts->assertDontSee('Evolution du suivi');
        $charts->assertDontSee('Directions sous vigilance');
        // Le profil suivi-evaluation ne declare aucun graphique de role : ses
        // graphiques macro sont ceux du PTA trimestriel, plus haut dans l'onglet.
        $charts->assertDontSee('dashboard-role-trend-chart', false);
        $charts->assertDontSee('dashboard-role-support-chart', false);
    }

    public function test_seeded_planification_user_sees_suivi_evaluation_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'r.dogui.anbg@gmail.com')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Controle et suivi-evaluation');
        $overview->assertSee('Actions suivies');
        $overview->assertSee('Avancement global');

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Directions');
        $tables->assertDontSee('Directions sous vigilance');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('avancement du PAS');
        $charts->assertSee('Graphiques du PTA trimestriel');
        $charts->assertSee('dashboard-pta-axis-progression-chart-charts', false);
        $charts->assertSee('dashboard-pta-monthly-rate-chart-charts', false);
        $charts->assertSee('dashboard-pta-axis-rate-chart-charts', false);
        $charts->assertSee('dashboard-pta-service-rate-chart-charts', false);
        $charts->assertDontSee('Evolution du suivi');
        $charts->assertDontSee('Directions sous vigilance');
        // Le profil suivi-evaluation ne declare aucun graphique de role : ses
        // graphiques macro sont ceux du PTA trimestriel, plus haut dans l'onglet.
        $charts->assertDontSee('dashboard-role-trend-chart', false);
        $charts->assertDontSee('dashboard-role-support-chart', false);
    }

    public function test_dashboard_overview_limits_primary_kpis_for_progressive_density(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'r.dogui.anbg@gmail.com')->firstOrFail();

        $content = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertLessThanOrEqual(6, substr_count($content, 'data-dashboard-primary-kpi'));
    }

    public function test_seeded_dg_user_sees_dg_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Lecture');
        $overview->assertSee('institutionnelle');
        $overview->assertSee('Pilotage exécutif');
        $overview->assertSee('Flux à traiter');
        $overview->assertSee('Actions');
        $overview->assertSee('Taux validation');
        $overview->assertSee('globale');
        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('avancement du PAS');
        $charts->assertSee('objectifs opérationnels');
        $charts->assertSee('actions par statut');
        $charts->assertSee('niveaux de pilotage');
        $charts->assertDontSee('dashboard-direction-performance-chart', false);
        $charts->assertDontSee('dashboard-service-performance-chart', false);
        $charts->assertSee('dashboard-role-support-chart', false);

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Directions en');
    }

    public function test_seeded_cabinet_user_sees_cabinet_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'l.adan.anbg@gmail.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Suivi transverse');
        $response->assertSee('Validations en attente');
        $response->assertSee('Directions');
        $response->assertSee('Actions');
    }

    public function test_seeded_service_user_sees_role_aware_pilotage_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'r.ekomi.anbg@gmail.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/workspace/pilotage')
            ->assertOk()
            ->assertSee('Pilotage PAS/PAO/PTA');

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();
        $response->assertSee('Pilotage du service');
        $response->assertSee('Actions');
    }

    public function test_seeded_dg_user_sees_role_aware_reporting_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/reporting');

        $response->assertOk();
        $response->assertSee('Centre');
        $response->assertSee('Actions');

        $response->assertDontSee('Lecture DG : operationnel vs consolide');
        $response->assertDontSee('Execution consolidee');

    }

    public function test_seeded_dg_user_sees_role_aware_pilotage_comparison_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $this->actingAs($user)
            ->get('/workspace/pilotage')
            ->assertOk()
            ->assertSee('Pilotage PAS/PAO/PTA');

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();
        $response->assertSee('institutionnelle');
        $response->assertSee('Actions');

        $response->assertDontSee('Lecture DG : operationnel vs consolide');
        $response->assertDontSee('Execution consolidee');
    }

    public function test_seeded_cabinet_user_sees_role_aware_reporting_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'l.adan.anbg@gmail.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/reporting');

        $response->assertOk();
        $response->assertSee('Centre de diffusion transverse');
        $response->assertSee('Actions');
        $response->assertDontSee('Provisoire');
        $response->assertDontSee('Officiel');
    }
}
