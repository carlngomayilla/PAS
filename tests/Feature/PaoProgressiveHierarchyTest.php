<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
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

class PaoProgressiveHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_reader_sees_the_complete_pao_hierarchy_and_action_workflows(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pao.show', $fixture['pao']))
            ->assertOk()
            ->assertSee('Objectifs, PTA et actions')
            ->assertSee($fixture['complete_strategic_objective']->libelle)
            ->assertSee($fixture['without_pta_objective']->libelle)
            ->assertSee($fixture['empty_pta']->titre)
            ->assertSee($fixture['action']->libelle)
            ->assertSee('1 Objectifs operationnels sans PTA')
            ->assertSee('1 PTA sans action')
            ->assertSee('1 Actions en retard')
            ->assertSee('1 Reports d echeance actifs')
            ->assertSee('50,0%')
            ->assertSee(route('workspace.actions.suivi', $fixture['action']), false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']).'#action-echeances', false)
            ->assertSee(route('workspace.deadline-extension.show', $fixture['deadline_request']), false);
    }

    public function test_service_reader_only_sees_operational_objectives_from_its_service(): void
    {
        $fixture = $this->createFixture();
        $serviceChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['primary_service']->id,
        ]);

        $this->actingAs($serviceChief)
            ->get(route('workspace.pao.show', $fixture['pao']))
            ->assertOk()
            ->assertSee($fixture['complete_objective']->libelle)
            ->assertSee($fixture['empty_pta_objective']->libelle)
            ->assertSee($fixture['action']->libelle)
            ->assertDontSee($fixture['without_pta_objective']->libelle)
            ->assertDontSee($fixture['secondary_service']->libelle);
    }

    public function test_direction_reader_cannot_open_a_pao_from_another_direction(): void
    {
        $fixture = $this->createFixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.pao.show', $fixture['foreign_pao']))
            ->assertForbidden();
    }

    public function test_agent_cannot_open_the_pao_explorer(): void
    {
        $fixture = $this->createFixture();
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['primary_service']->id,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.pao.show', $fixture['pao']))
            ->assertForbidden();
    }

    public function test_pao_without_operational_objectives_has_an_explicit_empty_state(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pao.show', $fixture['empty_pao']))
            ->assertOk()
            ->assertSee('Aucun objectif operationnel visible dans votre perimetre pour ce PAO.');
    }

    public function test_read_only_profile_can_open_the_explorer_from_the_pao_list(): void
    {
        $fixture = $this->createFixture();
        $reader = User::factory()->create(['role' => User::ROLE_CABINET]);

        $this->actingAs($reader)
            ->get(route('workspace.pao.index', ['annee' => 2026]))
            ->assertOk()
            ->assertSee('Explorer')
            ->assertSee(route('workspace.pao.show', $fixture['pao']), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function createFixture(): array
    {
        $direction = $this->createDirection('DIR-PAO-1', 'Direction PAO principale');
        $foreignDirection = $this->createDirection('DIR-PAO-2', 'Direction PAO externe');
        $emptyDirection = $this->createDirection('DIR-PAO-3', 'Direction PAO vide');
        $primaryService = $this->createService($direction, 'SER-PAO-1', 'Service PAO principal');
        $secondaryService = $this->createService($direction, 'SER-PAO-2', 'Service PAO secondaire');
        $foreignService = $this->createService($foreignDirection, 'SER-PAO-3', 'Service PAO externe');
        $emptyService = $this->createService($emptyDirection, 'SER-PAO-4', 'Service PAO vide');
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $pas = Pas::query()->create([
            'titre' => 'PAS source du PAO detaille',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AX-PAO-1',
            'libelle' => 'Axe PAO execution',
            'ordre' => 1,
        ]);
        $completeStrategicObjective = $this->createStrategicObjective($axis, 'OS-PAO-1', 'Objectif strategique PAO complet', 1);
        $withoutPtaStrategicObjective = $this->createStrategicObjective($axis, 'OS-PAO-2', 'Objectif strategique PAO sans PTA', 2);
        $emptyPtaStrategicObjective = $this->createStrategicObjective($axis, 'OS-PAO-3', 'Objectif strategique PAO sans action', 3);

        $pao = Pao::query()->create([
            'code' => 'PAO-DETAIL-2026',
            'pas_id' => $pas->id,
            'pas_objectif_id' => $completeStrategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $primaryService->id,
            'annee' => 2026,
            'titre' => 'PAO detaille direction principale',
            'echeance' => '2026-12-31',
        ]);
        $completeObjective = $this->createOperationalObjective(
            $pao,
            $completeStrategicObjective,
            $direction,
            $primaryService,
            'Objectif operationnel avec action'
        );
        $withoutPtaObjective = $this->createOperationalObjective(
            $pao,
            $withoutPtaStrategicObjective,
            $direction,
            $secondaryService,
            'Objectif operationnel du second service sans PTA'
        );
        $emptyPtaObjective = $this->createOperationalObjective(
            $pao,
            $emptyPtaStrategicObjective,
            $direction,
            $primaryService,
            'Objectif operationnel avec PTA sans action'
        );

        $completePta = Pta::query()->create([
            'code' => 'PTA-PAO-1',
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $completeObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $primaryService->id,
            'titre' => 'PTA avec action suivie',
        ]);
        $emptyPta = Pta::query()->create([
            'code' => 'PTA-PAO-2',
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $emptyPtaObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $primaryService->id,
            'titre' => 'PTA explicitement sans action',
        ]);
        $action = Action::query()->create([
            'code' => 'ACT-PAO-1',
            'pta_id' => $completePta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $completeObjective->id,
            'responsable_id' => $admin->id,
            'libelle' => 'Action PAO a suivre et reporter',
            'statut_parametrage' => 'parametre',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'quantite_cible' => 10,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
        ]);
        $action->forceFill([
            'quantite_realisee' => 5,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
        ])->save();
        $deadlineRequest = DeadlineExtensionRequest::query()->create([
            'action_id' => $action->id,
            'target_type' => 'action',
            'old_deadline' => '2026-01-31',
            'requested_deadline' => '2026-03-31',
            'requested_by' => $admin->id,
            'motif' => 'Report actif pour la fiche PAO',
            'justification' => 'Justification complete du report actif pour la fiche PAO.',
            'attachment_path' => 'deadline-extensions/test/report-pao.pdf',
            'attachment_name' => 'report-pao.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 128,
            'status' => DeadlineExtensionRequest::STATUS_SOUMISE,
        ]);

        $foreignPao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $completeStrategicObjective->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'annee' => 2026,
            'titre' => 'PAO direction hors perimetre',
        ]);
        $emptyPao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $completeStrategicObjective->id,
            'direction_id' => $emptyDirection->id,
            'service_id' => $emptyService->id,
            'annee' => 2026,
            'titre' => 'PAO sans objectif operationnel',
        ]);

        return [
            'admin' => $admin,
            'pao' => $pao,
            'foreign_pao' => $foreignPao,
            'empty_pao' => $emptyPao,
            'direction' => $direction,
            'primary_service' => $primaryService,
            'secondary_service' => $secondaryService,
            'complete_strategic_objective' => $completeStrategicObjective,
            'complete_objective' => $completeObjective,
            'without_pta_objective' => $withoutPtaObjective,
            'empty_pta_objective' => $emptyPtaObjective,
            'empty_pta' => $emptyPta,
            'action' => $action,
            'deadline_request' => $deadlineRequest,
        ];
    }

    private function createDirection(string $code, string $label): Direction
    {
        return Direction::query()->create([
            'code' => $code,
            'libelle' => $label,
            'actif' => true,
        ]);
    }

    private function createService(Direction $direction, string $code, string $label): Service
    {
        return Service::query()->create([
            'direction_id' => $direction->id,
            'code' => $code,
            'libelle' => $label,
            'actif' => true,
        ]);
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
