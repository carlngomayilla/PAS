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
use App\Services\PersonalTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Circuit de modification d'action :
 * Demandeur -> controleur SCIQ/Planification -> DG (decision).
 */
class PlanningUnlockCircuitV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_full_circuit_chef_controller_dg(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);

        // 1. Le chef demande la modification -> statut soumise (attente controleur).
        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Besoin de corriger la cible.');
        $this->assertSame(PlanningUnlockRequest::STATUS_SOUMISE, $req->status);
        $this->assertNull($req->transferred_by);

        // 2. Le controleur SCIQ/Planification donne un avis et transmet a la DG.
        $locks->recordPlanifAvis($req, $f['planif'], PlanningUnlockRequest::AVIS_FAVORABLE, 'RAS.');
        $req->refresh();
        $this->assertSame(PlanningUnlockRequest::STATUS_TRANSMISE, $req->status);
        $this->assertSame((int) $f['planif']->id, (int) $req->transferred_by);
        $this->assertSame(PlanningUnlockRequest::AVIS_FAVORABLE, $req->planif_avis);
        $this->assertSame((int) $f['planif']->id, (int) $req->planif_avis_by);

        // 3. Le DG approuve -> action deverrouillee.
        $locks->approve($req, $f['dg'], 'Accord DG.');
        $req->refresh();
        $f['action']->refresh();
        $this->assertSame(PlanningUnlockRequest::STATUS_APPROUVEE, $req->status);
        $this->assertNotNull($f['action']->modification_unlocked_at);
        $this->assertFalse($locks->isLocked($f['action']), 'L\'action est rouverte en écriture.');
    }

    public function test_dg_cannot_decide_before_controller_transmission(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);

        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        // Le DG tente de decider alors que le controleur n'a pas transmis -> 409.
        $this->expectExceptionMessage('transmise par un controleur');
        $locks->approve($req, $f['dg'], 'Trop tôt.');
    }

    public function test_directeur_cannot_transmit_to_dg(): void
    {
        $f = $this->fixture();
        $locks = app(PlanningModificationLockService::class);
        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        $this->assertFalse($locks->canTransfer($f['directeur'], $req));
    }

    public function test_controller_sees_unlock_requests_to_transmit(): void
    {
        $f = $this->fixture('CTRLVIEW');
        $locks = app(PlanningModificationLockService::class);

        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        $this->actingAs($f['planif'])
            ->get(route('workspace.planning-unlocks.index'))
            ->assertOk()
            ->assertSee('Demande #'.$req->id)
            ->assertSee('Transmettre à la DG');
    }

    public function test_planning_control_chiefs_can_see_and_transmit_to_dg(): void
    {
        foreach ([User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $index => $role) {
            $f = $this->fixture('TRANS'.$index);
            $locks = app(PlanningModificationLockService::class);
            $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

            $controller = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->assertTrue($locks->canGivePlanifAvis($controller));

            $this->actingAs($controller)
                ->get(route('workspace.planning-unlocks.index'))
                ->assertOk()
                ->assertSee('Demande #'.$req->id)
                ->assertSee('Transmettre à la DG');

            $this->actingAs($controller)
                ->post(route('workspace.planning-unlocks.planif', $req), [
                    'planif_avis' => PlanningUnlockRequest::AVIS_FAVORABLE,
                    'planif_comment' => 'Avis controle principal.',
                ])
                ->assertRedirect(route('workspace.planning-unlocks.index'));

            $req->refresh();
            $this->assertSame(PlanningUnlockRequest::STATUS_TRANSMISE, $req->status);
            $this->assertSame(PlanningUnlockRequest::AVIS_FAVORABLE, $req->planif_avis);
            $this->assertSame((int) $controller->id, (int) $req->transferred_by);
            $this->assertSame((int) $controller->id, (int) $req->planif_avis_by);
        }
    }

    public function test_unlock_requests_move_between_controller_and_dg_personal_tasks(): void
    {
        $f = $this->fixture('TASKS');
        $locks = app(PlanningModificationLockService::class);
        $tasks = app(PersonalTaskService::class);

        $req = $locks->requestUnlock($f['action'], $f['chef'], 'Motif valable.');

        $controllerTasks = collect($tasks->forUser($f['planif'])['items'] ?? []);
        $this->assertTrue(
            $controllerTasks->contains(fn (array $task): bool => (string) ($task['type'] ?? '') === 'controle_modification'
                && str_contains((string) ($task['subject'] ?? ''), 'Action verrouill')
            ),
            'La demande soumise doit apparaitre chez le controleur.'
        );

        $dgTasksBefore = collect($tasks->forUser($f['dg'])['items'] ?? []);
        $this->assertFalse(
            $dgTasksBefore->contains(fn (array $task): bool => (string) ($task['type'] ?? '') === 'decision_modification_dg'),
            'La DG ne doit pas recevoir la tache avant transmission controleur.'
        );

        $locks->recordPlanifAvis($req, $f['planif'], PlanningUnlockRequest::AVIS_FAVORABLE, 'Transmission DG.');

        $dgTasksAfter = collect($tasks->forUser($f['dg'])['items'] ?? []);
        $this->assertTrue(
            $dgTasksAfter->contains(fn (array $task): bool => (string) ($task['type'] ?? '') === 'decision_modification_dg'
                && str_contains((string) ($task['subject'] ?? ''), 'Action verrouill')
            ),
            'La demande transmise doit apparaitre chez la DG.'
        );
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
