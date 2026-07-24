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

class ActionApiDateGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_rejects_direct_action_date_changes(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['admin'])
            ->putJson(route('v1.actions.update', $fixture['action']), $this->updatePayload($fixture, [
                'date_debut' => '2026-02-01',
                'date_fin' => '2026-11-30',
                'date_echeance' => '2026-11-30',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_debut', 'date_fin', 'date_echeance']);

        $action = $fixture['action']->fresh();
        $this->assertSame('2026-01-10', $action->date_debut->toDateString());
        $this->assertSame('2026-06-30', $action->date_fin->toDateString());
        $this->assertSame('2026-06-30', $action->date_echeance->toDateString());
    }

    public function test_api_can_update_other_fields_while_preserving_action_dates(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['admin'])
            ->putJson(route('v1.actions.update', $fixture['action']), $this->updatePayload($fixture, [
                'libelle' => 'Action modifiee sans toucher aux dates',
            ]))
            ->assertOk();

        $action = $fixture['action']->fresh();
        $this->assertSame('Action modifiee sans toucher aux dates', $action->libelle);
        $this->assertSame('2026-01-10', $action->date_debut->toDateString());
        $this->assertSame('2026-06-30', $action->date_fin->toDateString());
        $this->assertSame('2026-06-30', $action->date_echeance->toDateString());
        $this->assertSame('2026-06-30', $action->echeance_cible->toDateString());
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'objectif_operationnel_id' => $fixture['objectif_operationnel']->id,
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'libelle' => $fixture['action']->libelle,
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => '2026-01-10',
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'responsable_id' => $fixture['agent']->id,
            'rmo_ids' => [$fixture['agent']->id],
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'kpi_periodicite' => 'mensuel',
            'kpi_est_a_renseigner' => true,
            'financement_requis' => false,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $direction = Direction::query()->create(['code' => 'API-DIR', 'libelle' => 'Direction API', 'actif' => true]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'API-SER',
            'libelle' => 'Service API',
            'actif' => true,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS API',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'API-AXE',
            'libelle' => 'Axe API',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'API-OS',
            'libelle' => 'Objectif API',
            'date_echeance' => '2028-12-31',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO API',
            'objectif_operationnel' => 'Objectif operationnel API',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel API',
            'echeance' => '2026-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA API',
            'statut' => Pta::STATUS_EN_COURS,
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'libelle' => 'Action API',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => '2026-01-10',
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'echeance_cible' => '2026-06-30',
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'financement_requis' => false,
        ]);

        return compact('admin', 'agent', 'pao', 'pta', 'objectifOperationnel', 'action') + [
            'objectif_operationnel' => $objectifOperationnel,
        ];
    }
}
