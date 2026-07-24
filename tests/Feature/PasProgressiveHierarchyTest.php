<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasProgressiveHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_reader_sees_the_complete_progressive_hierarchy_and_its_gaps(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pas.show', $fixture['pas']))
            ->assertOk()
            ->assertSee('Du PAS jusqu\'aux actions', false)
            ->assertSee($fixture['primary_axis']->libelle)
            ->assertSee($fixture['empty_objective']->libelle)
            ->assertSee($fixture['primary_pao']->titre)
            ->assertSee($fixture['complete_objective']->libelle)
            ->assertSee($fixture['complete_pta']->titre)
            ->assertSee('1 Objectifs strategiques non declines')
            ->assertSee('1 Objectifs operationnels sans PTA')
            ->assertSee('1 PTA sans action')
            ->assertSee('PTA manquant')
            ->assertSee('Aucune action')
            ->assertSee('75%');
    }

    public function test_direction_reader_only_sees_operational_data_from_its_direction(): void
    {
        $fixture = $this->createFixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['primary_direction']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.pas.show', $fixture['pas']))
            ->assertOk()
            ->assertSee($fixture['primary_pao']->titre)
            ->assertSee($fixture['complete_objective']->libelle)
            ->assertSee($fixture['without_pta_objective']->libelle)
            ->assertDontSee($fixture['foreign_pao']->titre)
            ->assertDontSee($fixture['foreign_operational_objective']->libelle)
            ->assertDontSee($fixture['foreign_pta']->titre);
    }

    public function test_service_reader_only_sees_operational_data_from_its_service(): void
    {
        $fixture = $this->createFixture();
        $serviceChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['primary_direction']->id,
            'service_id' => $fixture['primary_service']->id,
        ]);

        $this->actingAs($serviceChief)
            ->get(route('workspace.pas.show', $fixture['pas']))
            ->assertOk()
            ->assertSee($fixture['complete_objective']->libelle)
            ->assertSee($fixture['complete_pta']->titre)
            ->assertDontSee($fixture['without_pta_objective']->libelle)
            ->assertDontSee($fixture['secondary_service']->libelle)
            ->assertDontSee($fixture['foreign_operational_objective']->libelle);
    }

    public function test_scoped_reader_cannot_open_a_pas_outside_its_direction(): void
    {
        $fixture = $this->createFixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['primary_direction']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.pas.show', $fixture['foreign_pas']))
            ->assertForbidden();
    }

    public function test_agent_cannot_open_the_strategic_explorer(): void
    {
        $fixture = $this->createFixture();
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['primary_direction']->id,
            'service_id' => $fixture['primary_service']->id,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.pas.show', $fixture['pas']))
            ->assertForbidden();
    }

    public function test_pas_list_exposes_the_explorer_to_a_read_only_profile(): void
    {
        $fixture = $this->createFixture();
        $reader = User::factory()->create(['role' => User::ROLE_CABINET]);

        $this->actingAs($reader)
            ->get(route('workspace.pas.index'))
            ->assertOk()
            ->assertSee('Explorer')
            ->assertSee(route('workspace.pas.show', $fixture['pas']), false)
            ->assertDontSee('Modifier le PAS');
    }

    /**
     * @return array<string, mixed>
     */
    private function createFixture(): array
    {
        $primaryDirection = Direction::query()->create([
            'code' => 'DIR-HIER-1',
            'libelle' => 'Direction hierarchie principale',
            'actif' => true,
        ]);
        $foreignDirection = Direction::query()->create([
            'code' => 'DIR-HIER-2',
            'libelle' => 'Direction hierarchie externe',
            'actif' => true,
        ]);
        $primaryService = Service::query()->create([
            'direction_id' => $primaryDirection->id,
            'code' => 'SER-HIER-1',
            'libelle' => 'Service hierarchie principal',
            'actif' => true,
        ]);
        $secondaryService = Service::query()->create([
            'direction_id' => $primaryDirection->id,
            'code' => 'SER-HIER-2',
            'libelle' => 'Service hierarchie secondaire',
            'actif' => true,
        ]);
        $foreignService = Service::query()->create([
            'direction_id' => $foreignDirection->id,
            'code' => 'SER-HIER-3',
            'libelle' => 'Service hierarchie externe',
            'actif' => true,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $pas = Pas::query()->create([
            'titre' => 'PAS exploration progressive',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $primaryAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AX-HIER-1',
            'libelle' => 'Axe execution et qualite',
            'ordre' => 1,
        ]);
        $secondaryAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AX-HIER-2',
            'libelle' => 'Axe transformation et controle',
            'ordre' => 2,
        ]);
        $completeStrategicObjective = $this->createStrategicObjective($primaryAxis, 'OS-HIER-1', 'Objectif strategique complet', 1);
        $withoutPtaStrategicObjective = $this->createStrategicObjective($primaryAxis, 'OS-HIER-2', 'Objectif strategique sans PTA', 2);
        $withoutActionStrategicObjective = $this->createStrategicObjective($secondaryAxis, 'OS-HIER-3', 'Objectif strategique sans action', 1);
        $emptyObjective = $this->createStrategicObjective($secondaryAxis, 'OS-HIER-4', 'Objectif strategique non decline', 2);

        $primaryPao = Pao::query()->create([
            'code' => 'PAO-HIER-1',
            'pas_id' => $pas->id,
            'pas_objectif_id' => $completeStrategicObjective->id,
            'direction_id' => $primaryDirection->id,
            'service_id' => $primaryService->id,
            'annee' => 2026,
            'titre' => 'PAO direction principale',
        ]);
        $foreignPao = Pao::query()->create([
            'code' => 'PAO-HIER-2',
            'pas_id' => $pas->id,
            'pas_objectif_id' => $withoutActionStrategicObjective->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'annee' => 2026,
            'titre' => 'PAO direction externe',
        ]);

        $completeObjective = $this->createOperationalObjective(
            $primaryPao,
            $completeStrategicObjective,
            $primaryDirection,
            $primaryService,
            'Objectif operationnel complet'
        );
        $withoutPtaObjective = $this->createOperationalObjective(
            $primaryPao,
            $withoutPtaStrategicObjective,
            $primaryDirection,
            $secondaryService,
            'Objectif operationnel reserve au second service'
        );
        $foreignOperationalObjective = $this->createOperationalObjective(
            $foreignPao,
            $withoutActionStrategicObjective,
            $foreignDirection,
            $foreignService,
            'Objectif operationnel direction externe'
        );

        $completePta = Pta::query()->create([
            'code' => 'PTA-HIER-1',
            'pao_id' => $primaryPao->id,
            'objectif_operationnel_id' => $completeObjective->id,
            'direction_id' => $primaryDirection->id,
            'service_id' => $primaryService->id,
            'titre' => 'PTA complet avec action',
            'statut' => Pta::STATUS_EN_COURS,
        ]);
        $foreignPta = Pta::query()->create([
            'code' => 'PTA-HIER-2',
            'pao_id' => $foreignPao->id,
            'objectif_operationnel_id' => $foreignOperationalObjective->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'titre' => 'PTA externe sans action',
            'statut' => Pta::STATUS_EN_COURS,
        ]);
        Action::query()->create([
            'pta_id' => $completePta->id,
            'pao_id' => $primaryPao->id,
            'objectif_operationnel_id' => $completeObjective->id,
            'responsable_id' => $admin->id,
            'libelle' => 'Action complete de test',
            'statut' => 'non_demarre',
        ]);

        $foreignPas = Pas::query()->create([
            'titre' => 'PAS hors perimetre',
            'periode_debut' => 2027,
            'periode_fin' => 2029,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $foreignPasAxis = PasAxe::query()->create([
            'pas_id' => $foreignPas->id,
            'code' => 'AX-HORS',
            'libelle' => 'Axe hors perimetre',
            'ordre' => 1,
        ]);
        $foreignPasObjective = $this->createStrategicObjective($foreignPasAxis, 'OS-HORS', 'Objectif hors perimetre', 1);
        Pao::query()->create([
            'pas_id' => $foreignPas->id,
            'pas_objectif_id' => $foreignPasObjective->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'annee' => 2027,
            'titre' => 'PAO exclusivement hors perimetre',
        ]);

        return [
            'admin' => $admin,
            'pas' => $pas,
            'primary_axis' => $primaryAxis,
            'empty_objective' => $emptyObjective,
            'primary_pao' => $primaryPao,
            'foreign_pao' => $foreignPao,
            'complete_objective' => $completeObjective,
            'without_pta_objective' => $withoutPtaObjective,
            'foreign_operational_objective' => $foreignOperationalObjective,
            'complete_pta' => $completePta,
            'foreign_pta' => $foreignPta,
            'foreign_pas' => $foreignPas,
            'primary_direction' => $primaryDirection,
            'primary_service' => $primaryService,
            'secondary_service' => $secondaryService,
        ];
    }

    private function createStrategicObjective(PasAxe $axis, string $code, string $label, int $order): PasObjectif
    {
        return PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => $code,
            'libelle' => $label,
            'date_echeance' => '2026-12-31',
            'ordre' => $order,
        ]);
    }

    private function createOperationalObjective(
        Pao $pao,
        PasObjectif $strategicObjective,
        Direction $direction,
        Service $service,
        string $label
    ): ObjectifOperationnel {
        return ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pao->pas_id,
            'pas_axe_id' => $strategicObjective->pas_axe_id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => $label,
            'echeance' => '2026-11-30',
            'statut' => 'en_cours',
        ]);
    }
}
