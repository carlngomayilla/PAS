<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\UniteDg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ucas_profiles_can_open_their_dashboard(): void
    {
        $fixture = $this->ucasFixture();

        foreach ([$fixture['ucas'], $fixture['chief']] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    public function test_ucas_directory_is_read_only_and_limited_to_the_users_unit(): void
    {
        $fixture = $this->ucasFixture();
        $visibleUser = User::factory()->create([
            'name' => 'Agent UCAS visible',
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'unite_dg_id' => $fixture['unit']->id,
        ]);
        $otherService = Service::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => 'SCIQ-TEST',
        ]);
        $otherUnit = UniteDg::query()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => UniteDg::CODE_SCIQ,
            'libelle' => 'Unite SCIQ de test',
            'portee_globale' => true,
            'actif' => true,
        ]);
        $hiddenUser = User::factory()->create([
            'name' => 'Agent SCIQ masque',
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $otherService->id,
            'unite_dg_id' => $otherUnit->id,
        ]);

        foreach ([$fixture['ucas'], $fixture['chief']] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('workspace.referentiel.utilisateurs.index'))
                ->assertOk()
                ->assertSee($visibleUser->name)
                ->assertDontSee($hiddenUser->name)
                ->assertViewHas('canWrite', false)
                ->assertViewHas('canManageRoles', false);
        }

        $this->actingAs($fixture['chief'])
            ->get(route('workspace.referentiel.directions.index'))
            ->assertForbidden();

        $this->actingAs($fixture['chief'])
            ->get(route('workspace.referentiel.utilisateurs.create'))
            ->assertForbidden();

        $this->actingAs($fixture['chief'])
            ->put(route('workspace.referentiel.utilisateurs.update', $visibleUser), [
                'name' => 'Modification interdite',
            ])
            ->assertForbidden();

        $this->actingAs($fixture['chief'])
            ->delete(route('workspace.referentiel.utilisateurs.destroy', $visibleUser))
            ->assertForbidden();

        $outsideAgent = User::factory()->create(['role' => User::ROLE_AGENT]);

        $this->actingAs($outsideAgent)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertForbidden();
    }

    public function test_ucas_without_an_assigned_unit_cannot_see_directory_rows(): void
    {
        $fixture = $this->ucasFixture();
        $viewer = User::factory()->create([
            'name' => 'UCAS sans unite',
            'role' => User::ROLE_UCAS,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'unite_dg_id' => null,
        ]);

        $this->actingAs($viewer)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertDontSee($fixture['ucas']->name)
            ->assertDontSee($fixture['chief']->name);
    }

    public function test_ucas_actions_are_read_only_and_limited_to_the_users_unit(): void
    {
        $fixture = $this->ucasFixture();
        $otherUnit = UniteDg::query()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => UniteDg::CODE_SCIQ,
            'libelle' => 'Unite SCIQ actions',
            'portee_globale' => true,
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS actions UCAS',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'titre' => 'PAO actions UCAS',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'titre' => 'PTA actions UCAS',
        ]);
        $visibleAction = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $fixture['ucas']->id,
            'unite_dg_id' => $fixture['unit']->id,
            'libelle' => 'Action visible UCAS',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'quantite_cible' => 10,
            'justificatif_obligatoire' => false,
        ]);
        $hiddenAction = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $fixture['chief']->id,
            'unite_dg_id' => $otherUnit->id,
            'libelle' => 'Action masquee autre unite',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'quantite_cible' => 10,
            'justificatif_obligatoire' => false,
        ]);

        $this->actingAs($fixture['ucas'])
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertSee($visibleAction->libelle)
            ->assertDontSee($hiddenAction->libelle)
            ->assertViewHas('canWrite', false);

        $modules = collect($fixture['ucas']->workspaceModules())->keyBy('code');
        $this->assertFalse($modules->get('pta')['can_write']);
        $this->assertFalse($modules->get('execution')['can_write']);
    }

    public function test_admin_layout_exposes_an_accessible_mobile_menu_trigger(): void
    {
        $fixture = $this->ucasFixture();

        $this->actingAs($fixture['ucas'])
            ->get(route('workspace.index'))
            ->assertOk()
            ->assertSee('id="admin-sidebar-open"', false)
            ->assertSee('aria-controls="admin-sidebar"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('lg:hidden', false);
    }

    /**
     * @return array{direction: Direction, service: Service, unit: UniteDg, ucas: User, chief: User}
     */
    private function ucasFixture(): array
    {
        $direction = Direction::factory()->create([
            'code' => 'DG-TEST',
            'libelle' => 'Direction generale de test',
        ]);
        $service = Service::factory()->create([
            'direction_id' => $direction->id,
            'code' => 'UCAS-TEST',
            'libelle' => 'Service UCAS de test',
        ]);
        $unit = UniteDg::query()->create([
            'direction_id' => $direction->id,
            'code' => UniteDg::CODE_UCAS,
            'libelle' => 'Unite UCAS de test',
            'portee_globale' => false,
            'actif' => true,
        ]);

        return [
            'direction' => $direction,
            'service' => $service,
            'unit' => $unit,
            'ucas' => User::factory()->create([
                'role' => User::ROLE_UCAS,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'unite_dg_id' => $unit->id,
            ]),
            'chief' => User::factory()->create([
                'role' => User::ROLE_CHEF_UNITE_UCAS,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'unite_dg_id' => $unit->id,
            ]),
        ];
    }
}
