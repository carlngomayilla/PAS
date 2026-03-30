<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Policies\ActionPolicy;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ActionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ActionPolicy();
    }

    public function test_agent_can_only_handle_his_own_action_execution(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $otherAgent = $fixture['other_agent'];

        $this->assertTrue($this->policy->viewAny($agent));
        $this->assertTrue($this->policy->view($agent, $action));
        $this->assertTrue($this->policy->submitWeek($agent, $action));
        $this->assertTrue($this->policy->submitClosure($agent, $action));
        $this->assertTrue($this->policy->comment($agent, $action));

        $this->assertFalse($this->policy->update($agent, $action));
        $this->assertFalse($this->policy->delete($agent, $action));
        $this->assertFalse($this->policy->reviewByChef($agent, $action));
        $this->assertFalse($this->policy->reviewByDirection($agent, $action));

        $this->assertFalse($this->policy->view($otherAgent, $action));
        $this->assertFalse($this->policy->comment($otherAgent, $action));
        $this->assertFalse($this->policy->submitWeek($otherAgent, $action));
    }

    public function test_service_and_direction_scopes_match_action_workflow_rules(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $serviceUser = $fixture['service_user'];
        $otherServiceUser = $fixture['other_service_user'];
        $directionUser = $fixture['direction_user'];
        $otherDirectionUser = $fixture['other_direction_user'];

        $this->assertTrue($this->policy->view($serviceUser, $action));
        $this->assertTrue($this->policy->create($serviceUser, $action));
        $this->assertTrue($this->policy->update($serviceUser, $action));
        $this->assertTrue($this->policy->delete($serviceUser, $action));
        $this->assertTrue($this->policy->reviewByChef($serviceUser, $action));
        $this->assertFalse($this->policy->reviewByDirection($serviceUser, $action));

        $this->assertFalse($this->policy->view($otherServiceUser, $action));
        $this->assertFalse($this->policy->update($otherServiceUser, $action));
        $this->assertFalse($this->policy->reviewByChef($otherServiceUser, $action));

        $this->assertTrue($this->policy->view($directionUser, $action));
        $this->assertTrue($this->policy->create($directionUser, $action));
        $this->assertTrue($this->policy->update($directionUser, $action));
        $this->assertTrue($this->policy->delete($directionUser, $action));
        $this->assertTrue($this->policy->reviewByDirection($directionUser, $action));
        $this->assertFalse($this->policy->reviewByChef($directionUser, $action));

        $this->assertFalse($this->policy->view($otherDirectionUser, $action));
        $this->assertFalse($this->policy->update($otherDirectionUser, $action));
        $this->assertFalse($this->policy->reviewByDirection($otherDirectionUser, $action));
    }

    public function test_global_read_profile_can_view_without_managing(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $cabinetUser = $fixture['cabinet_user'];

        $this->assertTrue($this->policy->viewAny($cabinetUser));
        $this->assertTrue($this->policy->view($cabinetUser, $action));
        $this->assertTrue($this->policy->comment($cabinetUser, $action));
        $this->assertFalse($this->policy->create($cabinetUser, $action));
        $this->assertFalse($this->policy->update($cabinetUser, $action));
        $this->assertFalse($this->policy->delete($cabinetUser, $action));
        $this->assertFalse($this->policy->reviewByChef($cabinetUser, $action));
        $this->assertFalse($this->policy->reviewByDirection($cabinetUser, $action));
    }

    /**
     * @return array{
     *     action: Action,
     *     agent: User,
     *     other_agent: User,
     *     service_user: User,
     *     other_service_user: User,
     *     direction_user: User,
     *     other_direction_user: User,
     *     cabinet_user: User
     * }
     */
    private function createActionFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-POL',
            'libelle' => 'Direction Policy',
            'actif' => true,
        ]);

        $otherDirection = Direction::query()->create([
            'code' => 'DIR-OTH',
            'libelle' => 'Direction Externe',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-POL',
            'libelle' => 'Service Policy',
            'actif' => true,
        ]);

        $otherService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-AUT',
            'libelle' => 'Service Autre',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-POL-01',
            'password_changed_at' => now(),
        ]);

        $otherAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-POL-02',
            'password_changed_at' => now(),
        ]);

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $otherServiceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $otherService->id,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $otherDirectionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $otherDirection->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $cabinetUser = User::factory()->create([
            'role' => User::ROLE_CABINET,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS policy',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-POL',
            'libelle' => 'Axe policy',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-POL-1',
            'libelle' => 'Objectif policy',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO policy',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA policy',
            'statut' => 'brouillon',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action policy',
            'description' => 'Action policy test',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'date_echeance' => '2026-01-14',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        $action->setRelation('pta', $pta);

        return [
            'action' => $action,
            'agent' => $agent,
            'other_agent' => $otherAgent,
            'service_user' => $serviceUser,
            'other_service_user' => $otherServiceUser,
            'direction_user' => $directionUser,
            'other_direction_user' => $otherDirectionUser,
            'cabinet_user' => $cabinetUser,
        ];
    }
}
