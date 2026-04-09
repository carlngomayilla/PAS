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
        $response->assertSee('Interactions disponibles pour ce profil');
        $response->assertSee('Espace de travail (interactions utilisables)');
        $response->assertSee('Profil utilisateur');
    }

    public function test_seeded_service_user_can_open_dashboard_without_scope_error(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Pilotage du service');
        $response->assertSee('Actions a valider');
        $response->assertSee('Performance des agents');
        $response->assertSee('Diagramme de Gantt');
        $response->assertSee('dashboard-role-status-chart', false);
        $response->assertSee('dashboard-role-support-chart', false);
        $response->assertSee('"dgPayload"', false);
        $response->assertSee('"kpi_summary"', false);
        $response->assertSee('"kpi_qualite"', false);
        $response->assertSee('"kpi_risque"', false);
    }

    public function test_seeded_agent_user_sees_agent_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'melissa.abogo@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Suivi personnel de l execution');
        $response->assertSee('Mes actions prioritaires');
        $response->assertSee('Mes actions en retard');
        $response->assertSee('dashboard-role-trend-chart', false);
    }

    public function test_seeded_direction_user_sees_direction_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'directeur.daf@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Pilotage directionnel et comparaison des services');
        $response->assertSee('Performance par service');
        $response->assertSee('Actions critiques de la direction');
        $response->assertSee('SFC');
        $response->assertSee('AJARH');
        $response->assertSee('dashboard-role-support-chart', false);
    }

    public function test_seeded_planification_user_sees_planification_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'hilaire.nguebet@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Consolidation transverse du pilotage');
        $response->assertSee('Classement des directions');
        $response->assertSee('Actions critiques validees');
        $response->assertSee('Actions validees');
    }

    public function test_seeded_dg_user_sees_dg_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Lecture strategique institutionnelle');
        $response->assertSee('Actions validees');
        $response->assertSee('Taux validation');
        $response->assertSee('Execution globale');
        $response->assertSee('Score global');
        $response->assertSee('Performance par direction');
        $response->assertSee('Indicateurs mensuels');
        $response->assertSee('Directions en difficulte');
        $response->assertSee('dashboard-role-support-chart', false);
    }

    public function test_seeded_cabinet_user_sees_cabinet_dashboard_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Suivi transverse et appui decisionnel');
        $response->assertSee('Validations en attente');
        $response->assertSee('Alertes critiques transverses');
        $response->assertSee('Actions validees');
    }

    public function test_seeded_service_user_sees_role_aware_pilotage_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/pilotage');

        $response->assertOk();
        $response->assertSee('Suivi du service et ruptures de chaine');
        $response->assertSee('Actions validees');
        $response->assertSee('Base statistique : Toutes les actions visibles');
    }

    public function test_seeded_dg_user_sees_role_aware_reporting_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/reporting');

        $response->assertOk();
        $response->assertSee('Centre d export institutionnel');
        $response->assertSee('Actions validees');
        $response->assertSee('Base statistique : Toutes les actions visibles');
        $response->assertDontSee('Lecture DG : operationnel vs consolide');
        $response->assertDontSee('Execution consolidee');
        $response->assertSee('Dashboard analytique');
    }

    public function test_seeded_dg_user_sees_role_aware_pilotage_comparison_sections(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/pilotage');

        $response->assertOk();
        $response->assertSee('Pilotage institutionnel');
        $response->assertSee('Actions validees');
        $response->assertSee('Base statistique : Toutes les actions visibles');
        $response->assertDontSee('Lecture DG : operationnel vs consolide');
        $response->assertDontSee('Execution consolidee');
    }

    public function test_seeded_cabinet_user_sees_role_aware_reporting_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/workspace/reporting');

        $response->assertOk();
        $response->assertSee('Centre de diffusion transverse');
        $response->assertSee('Actions validees');
        $response->assertDontSee('Provisoire');
        $response->assertDontSee('Officiel');
    }
}
