<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Action;
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

class PtaServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_user_only_sees_transmitted_objectives_for_own_service_on_pta_form(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.create', [
                'objectif_operationnel_id' => $fixture['otherObjective']->id,
            ]))
            ->assertOk()
            ->assertSee('Objectif transmis au service cible')
            ->assertDontSee('Objectif transmis a un autre service')
            ->assertDontSee('name="service_id"', false)
            ->assertDontSee('name="pao_id"', false);
    }

    public function test_pta_creation_uses_detected_service_even_if_payload_contains_another_service(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'service_id' => $fixture['otherService']->id,
                'pao_id' => $fixture['pao']->id,
                'actions' => [
                    [
                        'libelle' => 'Action PTA service',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2026-06-30',
                        'mode_evaluation' => 'sous_actions',
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                        'sous_actions' => [
                            [
                                'agent_id' => $fixture['agent']->id,
                                'libelle' => 'Sous-action planifiee PTA',
                                'date_debut' => '2026-03-01',
                                'date_fin' => '2026-04-30',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $pta = Pta::query()->firstOrFail();

        $this->assertSame((int) $fixture['ownService']->id, (int) $pta->service_id);
        $this->assertSame((int) $fixture['ownObjective']->id, (int) $pta->objectif_operationnel_id);
        $this->assertDatabaseHas('actions', [
            'pta_id' => $pta->id,
            'objectif_operationnel_id' => $fixture['ownObjective']->id,
        ]);
        $this->assertDatabaseHas('sous_actions', [
            'libelle' => 'Sous-action planifiee PTA',
            'statut' => 'non_demarre',
            'est_effectuee' => false,
        ]);
    }

    public function test_unit_chief_with_service_write_permission_can_create_pta_for_own_unit(): void
    {
        $fixture = $this->fixture(User::ROLE_CHEF_UNITE_UCAS);

        $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.create', [
                'objectif_operationnel_id' => $fixture['otherObjective']->id,
            ]))
            ->assertOk()
            ->assertSee('Objectif transmis au service cible')
            ->assertDontSee('Objectif transmis a un autre service');

        $this->actingAs($fixture['serviceUser'])
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'actions' => [
                    [
                        'libelle' => 'Action PTA unite UCAS',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2026-06-30',
                        'mode_evaluation' => 'sous_actions',
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $this->assertDatabaseHas('ptas', [
            'service_id' => $fixture['ownService']->id,
            'objectif_operationnel_id' => $fixture['ownObjective']->id,
        ]);
    }

    public function test_pta_action_can_be_configured_without_quantity_or_sub_actions(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'actions' => [
                    [
                        'libelle' => 'Action sans quantite',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2026-06-30',
                        'mode_evaluation' => Action::MODE_SANS_QUANTITE,
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $action = Action::query()->where('libelle', 'Action sans quantite')->firstOrFail();

        $this->assertSame(Action::MODE_SANS_QUANTITE, $action->mode_evaluation);
        $this->assertNull($action->quantite_cible);
        $this->assertNull($action->unite_cible);
        $this->assertSame('qualitative', $action->type_cible);
        $this->assertSame('binary_completion', $action->methode_calcul);
        $this->assertDatabaseCount('sous_actions', 0);
    }

    public function test_pta_action_date_fin_cannot_exceed_operational_objective_deadline(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->from(route('workspace.pta.create', ['objectif_operationnel_id' => $fixture['ownObjective']->id]))
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'actions' => [
                    [
                        'libelle' => 'Action hors echeance',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2027-01-01',
                        'mode_evaluation' => 'sous_actions',
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.create', ['objectif_operationnel_id' => $fixture['ownObjective']->id]))
            ->assertSessionHasErrors('actions.0.date_fin');

        $this->assertDatabaseCount('ptas', 0);
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_pta_sub_action_date_fin_cannot_exceed_operational_objective_deadline(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->from(route('workspace.pta.create', ['objectif_operationnel_id' => $fixture['ownObjective']->id]))
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'actions' => [
                    [
                        'libelle' => 'Action avec sous-action hors echeance',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2026-12-31',
                        'mode_evaluation' => 'sous_actions',
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                        'sous_actions' => [
                            [
                                'agent_id' => $fixture['agent']->id,
                                'libelle' => 'Sous-action hors echeance objectif',
                                'date_debut' => '2026-03-01',
                                'date_fin' => '2027-01-01',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.create', ['objectif_operationnel_id' => $fixture['ownObjective']->id]))
            ->assertSessionHasErrors('actions.0.sous_actions.0.date_fin');

        $this->assertDatabaseCount('ptas', 0);
        $this->assertDatabaseCount('actions', 0);
        $this->assertDatabaseCount('sous_actions', 0);
    }

    /**
     * @return array{
     *     serviceUser: User,
     *     agent: User,
     *     ownService: Service,
     *     otherService: Service,
     *     pao: Pao,
     *     ownObjective: ObjectifOperationnel,
     *     otherObjective: ObjectifOperationnel
     * }
     */
    private function fixture(string $serviceRole = User::ROLE_SERVICE): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-PTA',
            'libelle' => 'Direction PTA',
            'actif' => true,
        ]);
        $ownService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-PTA',
            'libelle' => 'Service PTA',
            'actif' => true,
        ]);
        $otherService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-AUTRE',
            'libelle' => 'Service autre',
            'actif' => true,
        ]);

        $serviceUser = User::factory()->create([
            'role' => $serviceRole,
            'direction_id' => $direction->id,
            'service_id' => $ownService->id,
            'is_active' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'direction_id' => $direction->id,
            'service_id' => $ownService->id,
            'is_active' => true,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS PTA service',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-PTA',
            'libelle' => 'Axe PTA',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-PTA',
            'libelle' => 'Objectif strategique PTA',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => null,
            'annee' => 2026,
            'titre' => 'PAO directionnel PTA',
            'echeance' => '2026-12-31',
            'objectif_operationnel' => 'PAO directionnel',
        ]);
        $pao->forceFill(['statut' => Pao::STATUS_VALIDE])->save();

        $ownObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $ownService->id,
            'libelle' => 'Objectif transmis au service cible',
            'echeance' => '2026-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $otherObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $otherService->id,
            'libelle' => 'Objectif transmis a un autre service',
            'echeance' => '2026-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);

        return compact(
            'serviceUser',
            'agent',
            'ownService',
            'otherService',
            'pao',
            'ownObjective',
            'otherObjective'
        );
    }
}
