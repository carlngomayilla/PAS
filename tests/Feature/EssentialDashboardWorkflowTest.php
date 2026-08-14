<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Kpi;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Dashboard\EssentialDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EssentialDashboardWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_dashboard_respects_profile_card_density(): void
    {
        $fixture = $this->createPlanningFixture();
        $this->createAction($fixture);

        $dashboard = app(EssentialDashboardService::class)->forUser($fixture['agent']);

        $this->assertSame('agent', $dashboard['profile']);
        $this->assertLessThanOrEqual($dashboard['max_cards'], count($dashboard['cards']));
        $this->assertLessThanOrEqual(3, count($dashboard['cards']));
    }

    public function test_pilotage_page_renders_hierarchy_for_authorized_user(): void
    {
        $fixture = $this->createPlanningFixture();
        $this->createAction($fixture);

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pilotage'))
            ->assertOk()
            ->assertSee('Pilotage PAS/PAO/PTA')
            ->assertSee('PAS dashboard')
            ->assertSee('PAO dashboard')
            ->assertSee('PTA dashboard')
            ->assertSee('Action dashboard');
    }

    public function test_dashboard_page_renders_essential_view(): void
    {
        $fixture = $this->createPlanningFixture();
        $this->createAction($fixture);

        $this->actingAs($fixture['admin'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Synth')
            ->assertSee('Graphiques')
            ->assertSee('Vue détaillée')
            ->assertSee('Vue synthétique des axes')
            ->assertSee('Suivi PTA')
            ->assertSee('Axe dashboard sans action')
            ->assertSee('Objectif dashboard sans action');
    }

    public function test_suivi_evaluation_family_uses_same_profile(): void
    {
        $fixture = $this->createPlanningFixture();
        $this->createAction($fixture);

        foreach (['planification', 'chef_planification', 'sciq', 'chef_sciq'] as $userKey) {
            $dashboard = app(EssentialDashboardService::class)->forUser($fixture[$userKey]);

            $this->assertSame('suivi_evaluation', $dashboard['profile']);
            $this->assertSame('Vue suivi-evaluation', $dashboard['label']);
        }
    }

    public function test_planification_dashboard_counts_data_quality_issues(): void
    {
        $fixture = $this->createPlanningFixture();
        $this->createAction($fixture);
        $directionWithoutPta = Direction::query()->create([
            'code' => 'DIR-SANS-PTA',
            'libelle' => 'Direction sans PTA',
            'actif' => true,
        ]);

        Pao::query()->create([
            'pas_id' => $fixture['pas']->id,
            'pas_objectif_id' => $fixture['pas_objectif']->id,
            'direction_id' => $directionWithoutPta->id,
            'service_id' => null,
            'annee' => now()->year,
            'titre' => 'PAO sans PTA qualite',
            'objectif_operationnel' => 'PAO a decliner en PTA',
            'statut' => Pao::STATUS_VALIDE,
        ]);

        Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => null,
            'libelle' => 'Action sans objectif operationnel',
            'type_cible' => 'qualitative',
            'type_indicateur' => 'non_quantitatif',
            'livrable_attendu' => 'Rapport',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'date_echeance' => now()->addMonth()->toDateString(),
            'responsable_id' => $fixture['agent']->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_parametrage' => 'parametree',
            'progression_reelle' => 20,
            'progression_theorique' => 50,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);

        $dashboard = app(EssentialDashboardService::class)->forUser($fixture['planification']);
        $card = collect($dashboard['cards'])->firstWhere('key', 'data_quality');

        $this->assertNotNull($card);
        $this->assertSame('Qualite des donnees', $card['label']);
        $this->assertSame(2, $card['value']);
        $this->assertStringContainsString('1 PAO sans PTA', $card['caption']);
        $this->assertStringContainsString('1 action(s) sans objectif', $card['caption']);
        $this->assertSame(route('workspace.pilotage'), $card['href']);
    }

    public function test_planification_dashboard_counts_extended_data_quality_controls(): void
    {
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture);

        $otherPao = Pao::query()->create([
            'pas_id' => $fixture['pas']->id,
            'pas_objectif_id' => $fixture['pas_objectif']->id,
            'direction_id' => $fixture['direction']->id,
            'service_id' => null,
            'annee' => now()->year - 1,
            'titre' => 'PAO incoherent qualite',
            'objectif_operationnel' => 'Controle qualite avance',
            'statut' => Pao::STATUS_VALIDE,
        ]);

        Action::withoutTimestamps(function () use ($action, $otherPao): void {
            $action->forceFill([
                'pao_id' => $otherPao->id,
                'progression_reelle' => 100,
                'updated_at' => now()->subDays(45),
            ])->save();
        });

        Kpi::query()->create([
            'action_id' => $action->id,
            'libelle' => 'Indicateur sans source',
            'unite' => '%',
            'cible' => 100,
            'seuil_alerte' => 80,
            'periodicite' => 'mensuelle',
            'est_a_renseigner' => true,
        ]);

        $dashboard = app(EssentialDashboardService::class)->forUser($fixture['planification']);
        $card = collect($dashboard['cards'])->firstWhere('key', 'data_quality');

        $this->assertNotNull($card);
        $this->assertGreaterThanOrEqual(5, $card['value']);
        $this->assertStringContainsString('action(s) sans mise a jour recente', $card['caption']);
        $this->assertStringContainsString('indicateur(s) sans source', $card['caption']);
        $this->assertStringContainsString('action(s) a 100% sans justificatif', $card['caption']);
        $this->assertStringContainsString('incoherence(s) PAS/PAO/PTA', $card['caption']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-DASH',
            'libelle' => 'Direction dashboard',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-DASH',
            'libelle' => 'Service dashboard',
            'actif' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password_changed_at' => now(),
        ]);
        $planification = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'password_changed_at' => now(),
        ]);
        $chefPlanification = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'password_changed_at' => now(),
        ]);
        $sciq = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'password_changed_at' => now(),
        ]);
        $chefSciq = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS dashboard',
            'periode_debut' => now()->year,
            'periode_fin' => now()->year + 2,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-DASH',
            'libelle' => 'Axe dashboard',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-DASH',
            'libelle' => 'Objectif dashboard',
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
        $emptyAxe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-EMPTY',
            'libelle' => 'Axe dashboard sans action',
            'ordre' => 2,
        ]);
        PasObjectif::query()->create([
            'pas_axe_id' => $emptyAxe->id,
            'code' => 'OS-EMPTY',
            'libelle' => 'Objectif dashboard sans action',
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => null,
            'annee' => now()->year,
            'titre' => 'PAO dashboard',
            'objectif_operationnel' => 'Objectif operationnel dashboard',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel dashboard',
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA dashboard',
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        return [
            'direction' => $direction,
            'service' => $service,
            'agent' => $agent,
            'admin' => $admin,
            'planification' => $planification,
            'chef_planification' => $chefPlanification,
            'sciq' => $sciq,
            'chef_sciq' => $chefSciq,
            'pas' => $pas,
            'pas_objectif' => $objectif,
            'pao' => $pao,
            'pta' => $pta,
            'objectif_operationnel' => $objectifOperationnel,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function createAction(array $fixture): Action
    {
        return Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['objectif_operationnel']->id,
            'libelle' => 'Action dashboard',
            'description' => 'Action test dashboard',
            'type_cible' => 'quantitative',
            'type_indicateur' => 'quantitatif',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_a_realiser' => 10,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'date_echeance' => now()->addMonth()->toDateString(),
            'echeance_cible' => now()->addMonth()->toDateString(),
            'responsable_id' => $fixture['agent']->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_parametrage' => 'parametree',
            'progression_reelle' => 40,
            'progression_theorique' => 50,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
    }
}
