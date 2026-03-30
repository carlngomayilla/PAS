<?php

namespace Tests\Unit;

use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\Governance\DelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelegationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_delegations_ignore_expired_cancelled_and_invalid_permissions(): void
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-DELEG',
            'libelle' => 'Direction Delegation',
            'actif' => true,
        ]);

        $serviceScope = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-DELEG',
            'libelle' => 'Service Delegation',
            'actif' => true,
        ]);

        $delegant = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $serviceScope->id,
            'password_changed_at' => now(),
        ]);

        $delegue = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);

        $active = Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegue->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $serviceScope->id,
            'permissions' => ['planning_write', 'action_review'],
            'motif' => 'Absence encadrement',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(5),
            'statut' => 'active',
        ]);

        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegue->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $serviceScope->id,
            'permissions' => ['planning_write'],
            'motif' => 'Delegation expiree',
            'date_debut' => now()->subDays(10),
            'date_fin' => now()->subDay(),
            'statut' => 'active',
        ]);

        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegue->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $serviceScope->id,
            'permissions' => ['planning_write'],
            'motif' => 'Delegation annulee',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(5),
            'statut' => 'cancelled',
        ]);

        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegue->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $serviceScope->id,
            'permissions' => ['planning_read'],
            'motif' => 'Permission non concernee',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(5),
            'statut' => 'active',
        ]);

        $delegationService = app(DelegationService::class);

        $planningWriteDelegations = $delegationService->activeDelegationsFor($delegue, 'planning_write');
        $this->assertCount(1, $planningWriteDelegations);
        $this->assertSame($active->id, $planningWriteDelegations->first()->id);
        $this->assertSame([], $delegue->delegatedDirectionIds('action_review'));

        $this->assertTrue($delegationService->canReviewServiceAction($delegue, $direction->id, $serviceScope->id));
        $this->assertFalse($delegationService->canReviewDirectionAction($delegue, $direction->id));
    }
}
