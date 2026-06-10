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
        $response->assertDontSee('Interactions disponibles pour ce profil');
        $response->assertSee('Synth');
        $response->assertSee('Graphiques');
        $response->assertSee('Tableaux');
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
        $charts->assertSee('Indicateurs KPI');
        $charts->assertDontSee('Analytique avancee');
        // Graphique « Repartition des statuts » retire pour tous les roles (2026-06-10).
        $charts->assertDontSee('dashboard-role-status-chart', false);
        $charts->assertSee('dashboard-role-support-chart', false);

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Performance des agents');
        $tables->assertSee('Tableau de synth');
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
        $tables->assertSee('Mes actions prioritaires');
        $tables->assertSee('Mes actions en retard');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
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
        $tables->assertSee('Performance par service');
        $tables->assertSee('Actions critiques de la direction');
        $tables->assertSee('SFC');
        $tables->assertSee('AJARH');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('dashboard-role-support-chart', false);
    }

    public function test_seeded_planification_user_sees_planification_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'r.dogui.anbg@gmail.com')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Consolidation transverse du pilotage');
        $overview->assertSee('Actions');

        $tables = $this->actingAs($user)->get('/dashboard?dashboardTab=tables');
        $tables->assertOk();
        $tables->assertSee('Classement des directions');
        $tables->assertSee('Tableau de synth');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('Indicateurs KPI');
    }

    public function test_seeded_dg_user_sees_dg_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $overview = $this->actingAs($user)->get('/dashboard');
        $overview->assertOk();
        $overview->assertSee('Lecture');
        $overview->assertSee('institutionnelle');
        $overview->assertSee('Actions');
        $overview->assertSee('Taux validation');
        $overview->assertSee('globale');
        $overview->assertSee('Avancement reel');

        $charts = $this->actingAs($user)->get('/dashboard?dashboardTab=charts');
        $charts->assertOk();
        $charts->assertSee('Indicateurs KPI');
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

        $this->actingAs($user)->get('/workspace/pilotage')->assertRedirect('/dashboard');

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

        $this->actingAs($user)->get('/workspace/pilotage')->assertRedirect('/dashboard');

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
