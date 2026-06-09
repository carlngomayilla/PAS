<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PlanningUnlockRequest;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\PlanningModificationLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Circuit de modification d'action V2 :
 * Chef → Directeur (transfère) → Planification (avis) → DG (décision).
 * Voir docs/WORKFLOW-SUIVI-V2.md.
 */
class PlanningUnlockCircuitV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_full_circuit_chef_directeur_planif_dg(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);

        // 1. Le chef demande la modification → statut soumise (attente directeur).
        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Besoin de corriger la cible.');
        $this->assertSame(PlanningUnlockRequest::STATUS_SOUMISE, $req->status);

        // 2. Le directeur transfère → statut transmise.
        $locks->transferByDirecteur($req, $f['directeur'], 'Transfert validé.');
        $req->refresh();
        $this->assertSame(PlanningUnlockRequest::STATUS_TRANSMISE, $req->status);
        $this->assertSame((int) $f['directeur']->id, (int) $req->transferred_by);

        // 3. La planification rend un avis consultatif (ne change pas le statut décisif).
        $locks->recordPlanifAvis($req, $f['planif'], PlanningUnlockRequest::AVIS_FAVORABLE, 'RAS.');
        $req->refresh();
        $this->assertSame('favorable', $req->planif_avis);
        $this->assertSame(PlanningUnlockRequest::STATUS_TRANSMISE, $req->status, 'L\'avis ne tranche pas.');

        // 4. Le DG approuve → action déverrouillée.
        $locks->approve($req, $f['dg'], 'Accord DG.');
        $req->refresh();
        $f['action']->refresh();
        $this->assertSame(PlanningUnlockRequest::STATUS_APPROUVEE, $req->status);
        $this->assertNotNull($f['action']->modification_unlocked_at);
        $this->assertFalse($locks->isLocked($f['action']), 'L\'action est rouverte en écriture.');
    }

    public function test_dg_cannot_decide_before_directeur_transfer(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);

        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        // Le DG tente de décider alors que le directeur n'a pas transféré → 409.
        $this->expectExceptionMessage('transférée par le directeur');
        $locks->approve($req, $f['dg'], 'Trop tôt.');
    }

    public function test_directeur_of_another_direction_cannot_transfer(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);
        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        $otherDir = Direction::query()->create(['code' => 'OTH', 'libelle' => 'Autre direction']);
        $otherDirecteur = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $otherDir->id]);

        $this->assertFalse($locks->canTransfer($otherDirecteur, $req));
    }

    public function test_directeur_sees_direction_unlock_requests_to_transfer(): void
    {
        $f = $this->fixture('DIRVIEW');
        $locks = app(PlanningModificationLockService::class);

        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        $this->actingAs($f['directeur'])
            ->get(route('workspace.planning-unlocks.index'))
            ->assertOk()
            ->assertSee('Demande #'.$req->id)
            ->assertSee('Planif + DG');
    }

    public function test_planning_control_chiefs_can_see_and_give_planif_avis(): void
    {
        foreach ([User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $index => $role) {
            $f = $this->fixture('AVIS'.$index);
            $locks = app(PlanningModificationLockService::class);
            $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');
            $locks->transferByDirecteur($req, $f['directeur'], 'Transfert.');
            $req->refresh();

            $controller = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->assertTrue($locks->canGivePlanifAvis($controller));

            $this->actingAs($controller)
                ->get(route('workspace.planning-unlocks.index'))
                ->assertOk()
                ->assertSee('Demande #'.$req->id)
                ->assertSee("Enregistrer l'avis", false);

            $this->actingAs($controller)
                ->post(route('workspace.planning-unlocks.planif', $req), [
                    'planif_avis' => PlanningUnlockRequest::AVIS_FAVORABLE,
                    'planif_comment' => 'Avis controle principal.',
                ])
                ->assertRedirect(route('workspace.planning-unlocks.index'));

            $req->refresh();
            $this->assertSame(PlanningUnlockRequest::AVIS_FAVORABLE, $req->planif_avis);
            $this->assertSame((int) $controller->id, (int) $req->planif_avis_by);
        }
    }

    public function test_action_tracking_exposes_unlock_processing_button_for_dg_and_control_chiefs(): void
    {
        $f = $this->fixture('BTN');

        foreach ([User::ROLE_DG, User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $role) {
            $actor = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($actor)
                ->get(route('workspace.actions.suivi', $f['action']))
                ->assertOk()
                ->assertSee('Traiter le deverrouillage');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $suffix = 'U'): array
    {
        $direction = Direction::query()->create(['code' => 'DIR'.$suffix, 'libelle' => 'Direction Unlock '.$suffix]);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SRV'.$suffix, 'libelle' => 'Service Unlock '.$suffix]);
        $chef = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);
        $directeur = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $direction->id]);
        $planif = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $dg = User::factory()->create(['role' => User::ROLE_DG]);

        $pas = Pas::query()->create(['titre' => 'PAS '.$suffix, 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PAO '.$suffix, 'annee' => 2026]);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA '.$suffix]);
        $action = Action::query()->create([
            'pta_id' => $pta->id, 'libelle' => 'Action verrouillée', 'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre', 'modification_locked_at' => now(),
        ]);

        return compact('direction', 'service', 'chef', 'directeur', 'planif', 'dg', 'action');
    }
}
