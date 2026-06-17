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
use App\Models\SousAction;
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

    public function test_pta_edit_preserves_workflow_v2_options_for_direct_actions(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['serviceUser'])
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'actions' => [
                    [
                        'libelle' => 'Action composee avec options',
                        'date_debut' => '2026-03-01',
                        'date_fin' => '2026-06-30',
                        'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
                        'type_action' => Action::TYPE_COMPOSEE,
                        'requires_comment' => '1',
                        'allows_difficulty' => '0',
                        'rmo_ids' => [$fixture['agent']->id],
                        'financement_requis' => '0',
                        'sous_actions' => [
                            [
                                'agent_id' => $fixture['agent']->id,
                                'libelle' => 'Sous-action avec options',
                                'date_debut' => '2026-03-01',
                                'date_fin' => '2026-04-30',
                                'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
                                'cible_prevue' => '10',
                                'unite' => 'dossiers',
                                'weight' => '100',
                                'requires_proof' => '0',
                                'requires_comment' => '1',
                                'allows_difficulty' => '0',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $pta = Pta::query()->firstOrFail();
        $action = Action::query()->where('libelle', 'Action composee avec options')->firstOrFail();
        $sousAction = SousAction::query()->where('libelle', 'Sous-action avec options')->firstOrFail();

        $this->assertSame(Action::TYPE_COMPOSEE, $action->type_action);
        $this->assertTrue((bool) $action->requires_comment);
        $this->assertFalse((bool) $action->allows_difficulty);
        $this->assertSame(SousAction::TYPE_QUANTITATIVE, $sousAction->sub_action_type);
        $this->assertEquals(100, (float) $sousAction->weight);
        $this->assertFalse((bool) $sousAction->requires_proof);
        $this->assertTrue((bool) $sousAction->requires_comment);
        $this->assertFalse((bool) $sousAction->allows_difficulty);

        $response = $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.edit', $pta))
            ->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<span[^>]*data-action-title-label[^>]*>\s*Action composee avec options\s*<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span[^>]*data-action-summary[^>]*>\s*Action composee avec options\s*<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+name="actions\\[0\\]\\[requires_comment\\]"[^>]+value="1"[^>]+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="actions\\[0\\]\\[allows_difficulty\\]"[^>]+value="1"[^>]+checked/', $html);
        $this->assertMatchesRegularExpression('/name="actions\\[0\\]\\[sous_actions\\]\\[0\\]\\[weight\\]"[^>]+value="100(?:\\.00)?"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="actions\\[0\\]\\[sous_actions\\]\\[0\\]\\[requires_proof\\]"[^>]+value="1"[^>]+checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+name="actions\\[0\\]\\[sous_actions\\]\\[0\\]\\[requires_comment\\]"[^>]+value="1"[^>]+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="actions\\[0\\]\\[sous_actions\\]\\[0\\]\\[allows_difficulty\\]"[^>]+value="1"[^>]+checked/', $html);
    }

    public function test_pta_edit_lists_actions_by_quarter_and_deadline(): void
    {
        $fixture = $this->fixture();
        $pta = Pta::query()->create([
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['ownObjective']->id,
            'direction_id' => $fixture['ownService']->direction_id,
            'service_id' => $fixture['ownService']->id,
            'titre' => 'PTA ordre trimestriel',
        ]);

        $makeAction = function (string $libelle, string $dateDebut, string $dateFin) use ($fixture, $pta): Action {
            $action = Action::query()->create([
                'pta_id' => $pta->id,
                'pao_id' => $fixture['pao']->id,
                'objectif_operationnel_id' => $fixture['ownObjective']->id,
                'responsable_id' => $fixture['agent']->id,
                'libelle' => $libelle,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'date_echeance' => $dateFin,
                'statut' => 'non_demarre',
                'statut_parametrage' => 'parametre',
                'contexte_action' => Action::CONTEXT_PILOTAGE,
                'origine_action' => Action::ORIGIN_PTA,
            ]);
            $action->responsables()->attach($fixture['agent']->id, ['is_primary' => true]);

            return $action;
        };

        $makeAction('Action PTA T3', '2026-07-01', '2026-09-10');
        $makeAction('Action PTA T1', '2026-01-10', '2026-02-15');
        $makeAction('Action PTA T2', '2026-03-01', '2026-05-20');

        $html = $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.edit', $pta))
            ->assertOk()
            ->assertSee('T1 2026')
            ->assertSee('T2 2026')
            ->assertSee('T3 2026')
            ->getContent();

        $posT1 = strpos($html, 'Action PTA T1');
        $posT2 = strpos($html, 'Action PTA T2');
        $posT3 = strpos($html, 'Action PTA T3');

        $this->assertNotFalse($posT1);
        $this->assertNotFalse($posT2);
        $this->assertNotFalse($posT3);
        $this->assertTrue($posT1 < $posT2 && $posT2 < $posT3, 'Les actions du PTA doivent etre triees par trimestre, de l echeance la plus proche a la plus lointaine.');
    }

    public function test_pta_edit_filters_actions_by_selected_operational_objective(): void
    {
        $fixture = $this->fixture();
        $secondObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $fixture['pao']->id,
            'pas_id' => $fixture['ownObjective']->pas_id,
            'pas_axe_id' => $fixture['ownObjective']->pas_axe_id,
            'pas_objectif_id' => $fixture['ownObjective']->pas_objectif_id,
            'direction_id' => $fixture['ownService']->direction_id,
            'service_id' => $fixture['ownService']->id,
            'libelle' => 'Deuxieme objectif transmis au service cible',
            'echeance' => '2026-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['ownObjective']->id,
            'direction_id' => $fixture['ownService']->direction_id,
            'service_id' => $fixture['ownService']->id,
            'titre' => 'PTA multi objectifs',
        ]);

        $makeAction = function (ObjectifOperationnel $objectif, string $libelle) use ($fixture, $pta): Action {
            $action = Action::query()->create([
                'pta_id' => $pta->id,
                'pao_id' => $fixture['pao']->id,
                'objectif_operationnel_id' => $objectif->id,
                'responsable_id' => $fixture['agent']->id,
                'libelle' => $libelle,
                'date_debut' => '2026-03-01',
                'date_fin' => '2026-06-30',
                'date_echeance' => '2026-06-30',
                'statut' => 'non_demarre',
                'statut_parametrage' => 'parametre',
                'contexte_action' => Action::CONTEXT_PILOTAGE,
                'origine_action' => Action::ORIGIN_PTA,
            ]);
            $action->responsables()->attach($fixture['agent']->id, ['is_primary' => true]);

            return $action;
        };

        $makeAction($fixture['ownObjective'], 'Action objectif un');
        $makeAction($secondObjective, 'Action objectif deux');

        $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.edit', $pta))
            ->assertOk()
            ->assertSee('Action objectif un')
            ->assertDontSee('Action objectif deux');

        $this->actingAs($fixture['serviceUser'])
            ->get(route('workspace.pta.edit', [
                'pta' => $pta,
                'objectif_operationnel_id' => $secondObjective->id,
            ]))
            ->assertOk()
            ->assertSee('Action objectif deux')
            ->assertDontSee('Action objectif un');
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
