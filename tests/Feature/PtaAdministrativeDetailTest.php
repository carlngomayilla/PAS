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
use App\Models\SousAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PtaAdministrativeDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_reader_sees_the_complete_pta_detail_and_governed_action_links(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertOk()
            ->assertSee('Fiche administrative PTA')
            ->assertSee('Du PAS au PTA')
            ->assertSee('Tableau des actions et sous-actions')
            ->assertSee($fixture['strategic_objective']->libelle)
            ->assertSee($fixture['operational_objective']->libelle)
            ->assertSee($fixture['action']->libelle)
            ->assertSee('10 dossiers')
            ->assertSee('1 Actions en retard')
            ->assertSee('1 Reports d echeance actifs')
            ->assertSee('50,0%')
            ->assertSee('Faire le suivi')
            ->assertSee("Report d'échéance", false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']), false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']).'#action-echeances', false)
            ->assertSee(route('workspace.deadline-extension.show', $fixture['deadline_request']), false);
    }

    public function test_service_reader_can_open_the_pta_from_its_own_service(): void
    {
        $fixture = $this->createFixture();
        $serviceChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);

        $this->actingAs($serviceChief)
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertOk()
            ->assertSee($fixture['action']->libelle)
            ->assertSee($fixture['service']->libelle);
    }

    public function test_direction_reader_can_open_a_pta_from_its_own_direction(): void
    {
        $fixture = $this->createFixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertOk()
            ->assertSee($fixture['action']->libelle);
    }

    public function test_service_reader_cannot_open_a_pta_from_another_service(): void
    {
        $fixture = $this->createFixture();
        $serviceChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);

        $this->actingAs($serviceChief)
            ->get(route('workspace.pta.show', $fixture['foreign_pta']))
            ->assertForbidden();
    }

    public function test_direction_reader_cannot_open_a_pta_from_another_direction(): void
    {
        $fixture = $this->createFixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.pta.show', $fixture['foreign_pta']))
            ->assertForbidden();
    }

    public function test_agent_cannot_open_the_administrative_pta_detail(): void
    {
        $fixture = $this->createFixture();
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertForbidden();
    }

    public function test_pta_without_actions_has_an_explicit_empty_state(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pta.show', $fixture['foreign_pta']))
            ->assertOk()
            ->assertSee('Aucune action parametree')
            ->assertSee('Ce PTA ne contient encore aucune action.');
    }

    public function test_read_only_profile_can_open_the_detail_from_the_pta_list(): void
    {
        $fixture = $this->createFixture();
        $reader = User::factory()->create(['role' => User::ROLE_CABINET]);

        $this->actingAs($reader)
            ->get(route('workspace.pta.index'))
            ->assertOk()
            ->assertSee('Explorer')
            ->assertSee(route('workspace.pta.show', $fixture['pta']), false);

        $this->actingAs($reader)
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertOk()
            ->assertDontSee('Parametrer le PTA');
    }

    public function test_pao_explorer_links_directly_to_the_pta_detail(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pao.show', $fixture['pao']))
            ->assertOk()
            ->assertSee('Ouvrir le PTA')
            ->assertSee(route('workspace.pta.show', $fixture['pta']), false);
    }

    public function test_pta_progress_uses_official_target_weighting_and_completion_threshold(): void
    {
        $fixture = $this->createFixture();
        $completedAtThreshold = Action::query()->create([
            'code' => 'ACT-PTA-DETAIL-2',
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['operational_objective']->id,
            'responsable_id' => $fixture['admin']->id,
            'libelle' => 'Action realisee au seuil configure',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'type_indicateur' => 'quantitatif',
            'indicateurs_attendus' => 'Nombre de controles acheves',
            'quantite_cible' => 100,
            'unite_cible' => 'controles',
            'seuil_minimum' => 80,
            'statut_parametrage' => 'parametre',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
        ]);
        $completedAtThreshold->forceFill([
            'quantite_realisee' => 80,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
        ])->save();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pta.show', $fixture['pta']))
            ->assertOk()
            ->assertSee('77,3%')
            ->assertSee('1 Actions en retard')
            ->assertDontSee('2 Actions en retard')
            ->assertSee('Realisee');
    }

    public function test_archived_pta_does_not_offer_parameterization(): void
    {
        $fixture = $this->createFixture();
        $fixture['foreign_pta']->forceFill(['statut' => Pta::STATUS_ARCHIVE])->save();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pta.show', $fixture['foreign_pta']))
            ->assertOk()
            ->assertDontSee('Parametrer le PTA');
    }

    /**
     * @return array<string, mixed>
     */
    private function createFixture(): array
    {
        $direction = $this->createDirection('DIR-PTA-DETAIL-1', 'Direction PTA detail principal');
        $foreignDirection = $this->createDirection('DIR-PTA-DETAIL-2', 'Direction PTA detail externe');
        $service = $this->createService($direction, 'SER-PTA-DETAIL-1', 'Service PTA detail principal');
        $foreignService = $this->createService($foreignDirection, 'SER-PTA-DETAIL-2', 'Service PTA detail externe');
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $pas = Pas::query()->create([
            'titre' => 'PAS source du PTA detaille',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AX-PTA-DETAIL',
            'libelle' => 'Axe du PTA detaille',
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-PTA-DETAIL',
            'libelle' => 'Objectif strategique du PTA detaille',
            'date_echeance' => '2026-12-31',
            'ordre' => 1,
        ]);
        $pao = $this->createPao($pas, $strategicObjective, $direction, $service, 'PAO-PTA-DETAIL-1', 'PAO du PTA detaille');
        $foreignPao = $this->createPao($pas, $strategicObjective, $foreignDirection, $foreignService, 'PAO-PTA-DETAIL-2', 'PAO PTA externe');
        $operationalObjective = $this->createOperationalObjective(
            $pao,
            $strategicObjective,
            $direction,
            $service,
            'Objectif operationnel du PTA detaille'
        );
        $foreignOperationalObjective = $this->createOperationalObjective(
            $foreignPao,
            $strategicObjective,
            $foreignDirection,
            $foreignService,
            'Objectif operationnel PTA externe'
        );
        $pta = Pta::query()->create([
            'code' => 'PTA-DETAIL-1',
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA administratif detaille',
        ]);
        $foreignPta = Pta::query()->create([
            'code' => 'PTA-DETAIL-2',
            'pao_id' => $foreignPao->id,
            'objectif_operationnel_id' => $foreignOperationalObjective->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'titre' => 'PTA administratif vide externe',
        ]);
        $action = Action::query()->create([
            'code' => 'ACT-PTA-DETAIL-1',
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'responsable_id' => $admin->id,
            'libelle' => 'Action PTA detail a suivre et reporter',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'type_indicateur' => 'quantitatif',
            'indicateurs_attendus' => 'Nombre de dossiers finalises',
            'quantite_cible' => 10,
            'unite_cible' => 'dossiers',
            'resultat_attendu' => 'Dossiers controles et finalises',
            'statut_parametrage' => 'parametre',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
        ]);
        $action->forceFill([
            'quantite_realisee' => 5,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'statut_validation' => 'soumise_chef',
        ])->save();
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $admin->id,
            'libelle' => 'Sous-action administrative du PTA',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-15',
            'statut' => 'non_demarre',
        ]);
        $deadlineRequest = DeadlineExtensionRequest::query()->create([
            'action_id' => $action->id,
            'target_type' => 'action',
            'old_deadline' => '2026-01-31',
            'requested_deadline' => '2026-03-31',
            'requested_by' => $admin->id,
            'motif' => 'Report actif pour la fiche PTA',
            'justification' => 'Justification complete du report actif pour la fiche administrative PTA.',
            'attachment_path' => 'deadline-extensions/test/report-pta.pdf',
            'attachment_name' => 'report-pta.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 128,
            'status' => DeadlineExtensionRequest::STATUS_SOUMISE,
        ]);

        return [
            'admin' => $admin,
            'direction' => $direction,
            'service' => $service,
            'strategic_objective' => $strategicObjective,
            'operational_objective' => $operationalObjective,
            'pao' => $pao,
            'pta' => $pta,
            'foreign_pta' => $foreignPta,
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

    private function createPao(
        Pas $pas,
        PasObjectif $strategicObjective,
        Direction $direction,
        Service $service,
        string $code,
        string $title
    ): Pao {
        return Pao::query()->create([
            'code' => $code,
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => $title,
            'echeance' => '2026-12-31',
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
