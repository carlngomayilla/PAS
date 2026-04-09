<?php

namespace Tests\Unit;

use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Service;
use App\Models\User;
use App\Policies\PasPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasPolicyTest extends TestCase
{
    use RefreshDatabase;

    private PasPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PasPolicy();
    }

    public function test_policy_matches_pas_scope_rules_for_global_direction_and_service_profiles(): void
    {
        $fixture = $this->createFixture();

        $this->assertTrue($this->policy->viewAny($fixture['admin']));
        $this->assertTrue($this->policy->view($fixture['admin'], $fixture['pas']));
        $this->assertTrue($this->policy->create($fixture['admin']));
        $this->assertTrue($this->policy->update($fixture['admin'], $fixture['pas']));
        $this->assertTrue($this->policy->delete($fixture['admin'], $fixture['pas']));

        $this->assertTrue($this->policy->viewAny($fixture['cabinet']));
        $this->assertTrue($this->policy->view($fixture['cabinet'], $fixture['pas']));
        $this->assertTrue($this->policy->create($fixture['cabinet']));
        $this->assertTrue($this->policy->update($fixture['cabinet'], $fixture['pas']));
        $this->assertTrue($this->policy->delete($fixture['cabinet'], $fixture['pas']));

        $this->assertTrue($this->policy->viewAny($fixture['direction_user']));
        $this->assertTrue($this->policy->view($fixture['direction_user'], $fixture['pas']));
        $this->assertFalse($this->policy->create($fixture['direction_user']));
        $this->assertFalse($this->policy->update($fixture['direction_user'], $fixture['pas']));
        $this->assertFalse($this->policy->delete($fixture['direction_user'], $fixture['pas']));

        $this->assertTrue($this->policy->viewAny($fixture['service_user']));
        $this->assertTrue($this->policy->view($fixture['service_user'], $fixture['pas']));
        $this->assertFalse($this->policy->create($fixture['service_user']));
        $this->assertFalse($this->policy->update($fixture['service_user'], $fixture['pas']));
        $this->assertFalse($this->policy->delete($fixture['service_user'], $fixture['pas']));

        $this->assertTrue($this->policy->viewAny($fixture['other_direction_user']));
        $this->assertTrue($this->policy->view($fixture['other_direction_user'], $fixture['pas']));

        $this->assertTrue($this->policy->viewAny($fixture['other_service_user']));
        $this->assertTrue($this->policy->view($fixture['other_service_user'], $fixture['pas']));
    }

    /**
     * @return array{
     *     admin: User,
     *     cabinet: User,
     *     direction_user: User,
     *     other_direction_user: User,
     *     service_user: User,
     *     other_service_user: User,
     *     pas: Pas
     * }
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-PAS',
            'libelle' => 'Direction PAS',
            'actif' => true,
        ]);

        $otherDirection = Direction::query()->create([
            'code' => 'DIR-EXT',
            'libelle' => 'Direction Externe',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-PAS',
            'libelle' => 'Service PAS',
            'actif' => true,
        ]);

        $otherService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-AUT',
            'libelle' => 'Service Autre',
            'actif' => true,
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password_changed_at' => now(),
        ]);

        $cabinet = User::factory()->create([
            'role' => User::ROLE_CABINET,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);

        $otherDirectionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $otherDirection->id,
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

        $pas = Pas::query()->create([
            'titre' => 'PAS politique',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-PAS',
            'libelle' => 'Axe PAS',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-PAS',
            'libelle' => 'Objectif PAS',
            'ordre' => 1,
        ]);

        Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO rattache',
            'statut' => 'brouillon',
        ]);

        return [
            'admin' => $admin,
            'cabinet' => $cabinet,
            'direction_user' => $directionUser,
            'other_direction_user' => $otherDirectionUser,
            'service_user' => $serviceUser,
            'other_service_user' => $otherServiceUser,
            'pas' => $pas,
        ];
    }
}
