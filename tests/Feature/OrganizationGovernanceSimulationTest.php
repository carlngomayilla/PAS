<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\OrganizationGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class OrganizationGovernanceSimulationTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_merge_and_transfer_simulations_display_real_impact_counts(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$sourceDirection, $sourceService] = $this->createScope('SRC');
        [$targetDirection, $targetService] = $this->createScope('DST');
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $sourceDirection->id,
            'service_id' => $sourceService->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $sourceDirection->id,
            'service_id' => $sourceService->id,
            'is_active' => true,
        ]);
        $pta = $this->createPta($sourceDirection, $sourceService);
        $firstAction = $this->createAction($pta, $chef, 'Action simulation 1');
        $this->createAction($pta, $chef, 'Action simulation 2');
        Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $firstAction->id,
            'categorie' => 'final',
            'nom_original' => 'preuve-simulation.pdf',
            'chemin_stockage' => 'tests/preuve-simulation.pdf',
            'mime_type' => 'application/pdf',
            'taille_octets' => 100,
            'ajoute_par' => $chef->id,
        ]);

        $service = app(OrganizationGovernanceService::class);
        $merge = $service->simulateServiceMerge($sourceService, $targetService);
        $transfer = $service->simulateServiceTransfer($sourceService, $targetDirection);

        $this->assertSame([
            'users' => 2,
            'ptas' => 1,
            'actions' => 2,
            'justificatifs' => 1,
        ], $merge['impacts']);
        $this->assertSame([
            'users' => 2,
            'ptas' => 1,
            'actions' => 2,
        ], $transfer['impacts']);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.index', [
                'merge_source_service_id' => $sourceService->id,
                'merge_target_service_id' => $targetService->id,
            ]))
            ->assertOk()
            ->assertSee('data-simulation="merge"', false)
            ->assertSee('data-impact-metric="users" data-impact-value="2"', false)
            ->assertSee('data-impact-metric="actions" data-impact-value="2"', false)
            ->assertSee('data-impact-metric="ptas" data-impact-value="1"', false)
            ->assertSee('data-impact-metric="justificatifs" data-impact-value="1"', false)
            ->assertSee($merge['target_label']);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.index', [
                'transfer_service_id' => $sourceService->id,
                'transfer_direction_id' => $targetDirection->id,
            ]))
            ->assertOk()
            ->assertSee('data-simulation="transfer"', false)
            ->assertSee('data-impact-metric="users" data-impact-value="2"', false)
            ->assertSee('data-impact-metric="actions" data-impact-value="2"', false)
            ->assertSee('data-impact-metric="ptas" data-impact-value="1"', false)
            ->assertSee($transfer['target_label']);
    }

    /**
     * @return array{0: Direction, 1: Service}
     */
    private function createScope(string $suffix): array
    {
        $direction = Direction::query()->create([
            'code' => 'SIM-'.$suffix,
            'libelle' => 'Direction simulation '.$suffix,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SIM-S-'.$suffix,
            'libelle' => 'Service simulation '.$suffix,
            'actif' => true,
        ]);

        return [$direction, $service];
    }

    private function createPta(Direction $direction, Service $service): Pta
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS simulation gouvernance',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO simulation gouvernance',
        ]);

        return Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA simulation gouvernance',
        ]);
    }

    private function createAction(Pta $pta, User $responsible, string $label): Action
    {
        return Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => $label,
            'type_cible' => 'qualitative',
            'responsable_id' => $responsible->id,
        ]);
    }
}
