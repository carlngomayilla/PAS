<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\FinancialTransaction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\FinancialMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialDashboardKpiTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_kpis_are_calculated_for_a_service_scope_and_hidden_from_agents(): void
    {
        $fixture = $this->createFixture();
        FinancialTransaction::query()->create([
            'action_id' => $fixture['action']->id,
            'operation_type' => FinancialTransaction::TYPE_COMMITMENT,
            'amount' => 40000,
            'operated_on' => '2026-08-01',
            'recorded_by' => $fixture['serviceChief']->id,
        ]);
        FinancialTransaction::query()->create([
            'action_id' => $fixture['action']->id,
            'operation_type' => FinancialTransaction::TYPE_DISBURSEMENT,
            'amount' => 25000,
            'operated_on' => '2026-08-01',
            'recorded_by' => $fixture['serviceChief']->id,
        ]);

        $finance = app(FinancialMonitoringService::class);
        $summary = $finance->dashboardSummary($fixture['serviceChief']);

        $this->assertSame(100000.0, $summary['budget']);
        $this->assertSame(40000.0, $summary['engaged']);
        $this->assertSame(25000.0, $summary['disbursed']);
        $this->assertSame(75000.0, $summary['remaining']);
        $this->assertSame(25.0, $summary['disbursement_rate']);
        $this->assertNull($finance->dashboardSummary($fixture['agent']));
        foreach (['director', 'planning', 'planningChief', 'sciq', 'sciqChief', 'dg'] as $profile) {
            $this->assertNotNull($finance->dashboardSummary($fixture[$profile]));
        }

        $controller = file_get_contents(app_path('Http/Controllers/DashboardController.php'));
        $dashboardPanel = file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-overview.blade.php'));

        $this->assertIsString($controller);
        $this->assertIsString($dashboardPanel);
        $this->assertStringContainsString("['financial_summary']", $controller);
        $this->assertStringContainsString('Suivi budgétaire', $dashboardPanel);
        $this->assertStringContainsString('Solde à décaisser', $dashboardPanel);
    }

    /** @return array{action:Action,serviceChief:User,agent:User,director:User,planning:User,planningChief:User,sciq:User,sciqChief:User,dg:User} */
    private function createFixture(): array
    {
        $direction = Direction::query()->create(['code' => 'DOP', 'libelle' => 'Direction opérationnelle', 'actif' => true]);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SOP', 'libelle' => 'Service opérationnel', 'actif' => true]);
        $serviceChief = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $direction->id, 'service_id' => $service->id, 'agent_matricule' => 'AGT-2026-001']);
        $director = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $direction->id]);
        $planning = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $planningChief = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $sciq = User::factory()->create(['role' => User::ROLE_SCIQ]);
        $sciqChief = User::factory()->create(['role' => User::ROLE_CHEF_UNITE_SCIQ]);
        $dg = User::factory()->create(['role' => User::ROLE_DG]);
        $pas = Pas::query()->create(['titre' => 'PAS financier', 'periode_debut' => 2026, 'periode_fin' => 2028, 'statut' => 'brouillon']);
        $axe = PasAxe::query()->create(['pas_id' => $pas->id, 'code' => 'AX-FIN', 'libelle' => 'Axe financier', 'ordre' => 1]);
        $objective = PasObjectif::query()->create(['pas_axe_id' => $axe->id, 'code' => 'OS-FIN', 'libelle' => 'Objectif financier', 'ordre' => 1]);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'pas_objectif_id' => $objective->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'annee' => 2026, 'titre' => 'PAO financier', 'statut' => 'brouillon']);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA financier', 'statut' => 'brouillon']);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action budgétaire',
            'description' => 'Action de suivi des financements.',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'responsable_id' => $serviceChief->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => 'non_demarre',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'montant_estime' => 100000,
        ]);

        return compact('action', 'serviceChief', 'agent', 'director', 'planning', 'planningChief', 'sciq', 'sciqChief', 'dg');
    }
}
