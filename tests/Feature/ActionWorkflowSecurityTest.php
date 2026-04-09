<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\WorkflowSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionWorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_access_another_agent_action_from_web_or_api(): void
    {
        $fixture = $this->createActionFixture();
        $otherAgent = $fixture['other_agent'];
        $action = $fixture['action'];

        $this->actingAs($otherAgent)
            ->get(route('workspace.actions.suivi', $action))
            ->assertForbidden();

        $token = $otherAgent->createToken('phpunit-agent-scope')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/actions/'.$action->id)
            ->assertForbidden();
    }

    public function test_action_validation_workflow_reaches_direction_approval(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];
        $week = $action->weeks()->orderBy('numero_semaine')->firstOrFail();

        $this->actingAs($agent)
            ->post(route('workspace.actions.weeks.submit', [$action, $week]), [
                'quantite_realisee' => 100,
                'commentaire' => 'Execution complete',
                'difficultes' => 'Aucune',
                'mesures_correctives' => 'RAS',
                'justificatif' => UploadedFile::fake()->create('preuve.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame('100.00', (string) $action->progression_reelle);

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $action), [
                'date_fin_reelle' => '2026-01-07',
                'rapport_final' => 'Travail termine',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 92,
                'evaluation_commentaire' => 'Travail conforme',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 95,
                'evaluation_commentaire' => 'Validation finale',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, $action->statut_validation);
        $this->assertTrue((bool) $action->validation_hierarchique);
        $this->assertDatabaseHas('action_kpis', [
            'action_id' => $action->id,
        ]);
    }

    public function test_delegated_service_user_can_review_submitted_action(): void
    {
        Storage::fake('local');

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $delegateServiceUser = $fixture['delegate_service_user'];
        $week = $action->weeks()->orderBy('numero_semaine')->firstOrFail();

        Delegation::query()->create([
            'delegant_id' => $serviceUser->id,
            'delegue_id' => $delegateServiceUser->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $serviceUser->direction_id,
            'service_id' => $serviceUser->service_id,
            'permissions' => ['action_review'],
            'motif' => 'Absence temporaire',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(7),
            'statut' => 'active',
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.actions.weeks.submit', [$action, $week]), [
                'quantite_realisee' => 100,
                'commentaire' => 'Execution complete',
                'difficultes' => 'Aucune',
                'mesures_correctives' => 'RAS',
                'justificatif' => UploadedFile::fake()->create('preuve.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $action), [
                'date_fin_reelle' => '2026-01-07',
                'rapport_final' => 'Travail termine',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->actingAs($delegateServiceUser)
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertSee('Action securite test');

        $this->actingAs($delegateServiceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 88,
                'evaluation_commentaire' => 'Validation par delegation',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
    }

    public function test_comment_thread_is_persisted_on_action(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $serviceUser = $fixture['service_user'];

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.comment', $action), [
                'message' => 'Merci de preciser le point de blocage.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'commentaire',
            'message' => 'Merci de preciser le point de blocage.',
            'utilisateur_id' => $serviceUser->id,
        ]);
    }

    public function test_workflow_can_finalize_at_service_level_when_direction_step_is_disabled(): void
    {
        Storage::fake('local');

        app(WorkflowSettings::class)->updateActionWorkflow([
            'actions_service_validation_enabled' => '1',
            'actions_direction_validation_enabled' => '0',
            'actions_rejection_comment_required' => '1',
        ]);

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];
        $week = $action->weeks()->orderBy('numero_semaine')->firstOrFail();

        $this->actingAs($agent)
            ->post(route('workspace.actions.weeks.submit', [$action, $week]), [
                'quantite_realisee' => 100,
                'commentaire' => 'Execution complete',
                'difficultes' => 'Aucune',
                'mesures_correctives' => 'RAS',
                'justificatif' => UploadedFile::fake()->create('preuve.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $action), [
                'date_fin_reelle' => '2026-01-07',
                'rapport_final' => 'Travail termine',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 92,
                'evaluation_commentaire' => 'Validation finale service',
                'validation_sans_correction' => 1,
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, $action->statut_validation);
        self::assertTrue((bool) $action->validation_hierarchique);

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 95,
                'evaluation_commentaire' => 'Tentative hors circuit',
            ])
            ->assertForbidden();
    }

    public function test_workflow_can_skip_service_and_send_action_directly_to_direction(): void
    {
        Storage::fake('local');

        app(WorkflowSettings::class)->updateActionWorkflow([
            'actions_service_validation_enabled' => '0',
            'actions_direction_validation_enabled' => '1',
            'actions_rejection_comment_required' => '1',
        ]);

        $fixture = $this->createActionFixture();
        $action = $fixture['action'];
        $agent = $fixture['agent'];
        $serviceUser = $fixture['service_user'];
        $directionUser = $fixture['direction_user'];
        $week = $action->weeks()->orderBy('numero_semaine')->firstOrFail();

        $this->actingAs($agent)
            ->post(route('workspace.actions.weeks.submit', [$action, $week]), [
                'quantite_realisee' => 100,
                'commentaire' => 'Execution complete',
                'difficultes' => 'Aucune',
                'mesures_correctives' => 'RAS',
                'justificatif' => UploadedFile::fake()->create('preuve.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->actingAs($agent)
            ->post(route('workspace.actions.close', $action), [
                'date_fin_reelle' => '2026-01-07',
                'rapport_final' => 'Travail termine',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);

        $this->actingAs($serviceUser)
            ->post(route('workspace.actions.review', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 92,
                'evaluation_commentaire' => 'Tentative hors circuit',
                'validation_sans_correction' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($directionUser)
            ->post(route('workspace.actions.review-direction', $action), [
                'decision_validation' => 'valider',
                'evaluation_note' => 95,
                'evaluation_commentaire' => 'Validation direction',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        self::assertSame(ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, $action->statut_validation);
    }

    /**
     * @return array{action: Action, agent: User, other_agent: User, service_user: User, direction_user: User, delegate_service_user: User}
     */
    private function createActionFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-SEC',
            'libelle' => 'Direction Securite',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-SEC',
            'libelle' => 'Service Securite',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-SEC-01',
            'agent_fonction' => 'Agent execution',
            'password_changed_at' => now(),
        ]);

        $otherAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-SEC-02',
            'agent_fonction' => 'Autre agent',
            'password_changed_at' => now(),
        ]);

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $delegateServiceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS securite',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-SEC',
            'libelle' => 'Axe securite',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-SEC-1',
            'libelle' => 'Objectif securite',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO securite',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA securite',
            'statut' => 'brouillon',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action securite test',
            'description' => 'Action de test securite',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'date_echeance' => '2026-01-14',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        app(ActionTrackingService::class)->initializeActionTracking($action, $serviceUser);

        return [
            'action' => $action->fresh(),
            'agent' => $agent,
            'other_agent' => $otherAgent,
            'service_user' => $serviceUser,
            'direction_user' => $directionUser,
            'delegate_service_user' => $delegateServiceUser,
        ];
    }
}

