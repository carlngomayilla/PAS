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
use App\Services\Actions\ActionTrackingService;
use App\Services\DeadlineExtensionQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeadlineExtensionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_extension_requires_supporting_document(): void
    {
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), [
                'change_fields' => ['deadline'],
                'requested_deadline' => now()->addMonths(2)->toDateString(),
                'motif' => 'Contraintes operationnelles',
                'justification' => 'Les ressources critiques sont indisponibles sur la periode initiale.',
            ])
            ->assertSessionHasErrors('piece_justificative');

        $this->assertDatabaseCount('deadline_extension_requests', 0);
    }

    public function test_deadline_extension_follows_rmo_chef_director_dg_and_applies_exact_changes(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $originalDeadline = now()->addMonth()->toDateString();
        $requestedDeadline = now()->addMonths(3)->toDateString();
        $action = $this->createAction($fixture, $originalDeadline);
        $secondRmo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), $this->changePayload($requestedDeadline, [
                'change_fields' => ['deadline', 'libelle', 'responsables', 'priorite'],
                'requested_libelle' => 'Action replanifiee',
                'requested_responsable_ids' => [$fixture['agent']->id, $secondRmo->id],
                'requested_priorite' => 'haute',
            ]))
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $this->assertSame(DeadlineExtensionRequest::STATUS_SOUMISE, $deadlineRequest->status);
        $this->assertSame(['deadline', 'libelle', 'responsables', 'priorite'], array_keys($deadlineRequest->requested_changes));
        $this->assertSame('Action report', $action->fresh()->libelle);
        $this->assertSame($originalDeadline, $action->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['director'])
            ->post(route('workspace.deadline-extension.direction', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Tentative de saut du chef.',
            ])
            ->assertSessionHasErrors('decision');

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Validation du chef de service.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION, $deadlineRequest->fresh()->status);

        $outsideDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'password_changed_at' => now(),
        ]);
        $this->actingAs($outsideDirector)
            ->post(route('workspace.deadline-extension.direction', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Hors périmètre.',
            ])
            ->assertForbidden();

        $this->actingAs($fixture['director'])
            ->post(route('workspace.deadline-extension.direction', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Accord du directeur.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DG, $deadlineRequest->fresh()->status);

        $this->actingAs($fixture['chef_planification'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'comment' => 'Tentative non autorisée.',
            ])
            ->assertForbidden();

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'comment' => 'Accord final DG.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $action->refresh()->load('responsables');

        $this->assertSame(DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE, $deadlineRequest->status);
        $this->assertSame($fixture['dg']->id, $deadlineRequest->applied_by);
        $this->assertSame('Action replanifiee', $action->libelle);
        $this->assertSame('haute', $action->priorite);
        $this->assertSame($requestedDeadline, $action->date_fin->toDateString());
        $this->assertSame($requestedDeadline, $action->date_echeance->toDateString());
        $this->assertEqualsCanonicalizing(
            [$fixture['agent']->id, $secondRmo->id],
            $action->responsables->pluck('id')->all()
        );
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'deadline_extension_dg_approved_and_applied',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'reports_echeance',
            'action' => 'final_decision',
        ]);

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
            ])
            ->assertSessionHasErrors('decision');
    }

    public function test_chef_planification_cannot_replace_the_dg_for_final_approval(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $request = $this->submitAndReachDg($fixture, $action, now()->addMonths(2)->toDateString());

        $this->actingAs($fixture['chef_planification'])
            ->post(route('workspace.deadline-extension.final', $request), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'comment' => 'Le chef planification ne remplace pas la DG.',
            ])
            ->assertForbidden();

        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DG, $request->fresh()->status);
        $this->assertNull($request->fresh()->applied_at);
    }

    public function test_sub_action_changes_are_applied_only_to_the_selected_sub_action(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $actionDeadline = now()->addMonths(4)->toDateString();
        $subActionDeadline = now()->addMonth()->toDateString();
        $requestedDeadline = now()->addMonths(3)->toDateString();
        $action = $this->createAction($fixture, $actionDeadline);
        $subAction = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'Sous-action initiale',
            'date_debut' => now()->toDateString(),
            'date_fin' => $subActionDeadline,
            'statut' => 'non_demarre',
        ]);

        $this->actingAs($fixture['agent'])->post(
            route('workspace.actions.deadline-extension.store', $action),
            $this->changePayload($requestedDeadline, [
                'sous_action_id' => $subAction->id,
                'change_fields' => ['deadline', 'libelle'],
                'requested_libelle' => 'Sous-action ajustée',
            ])
        );

        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $this->reviewChefDirectorDg($fixture, $deadlineRequest);

        $this->assertSame('Sous-action ajustée', $subAction->fresh()->libelle);
        $this->assertSame($requestedDeadline, $subAction->fresh()->date_fin->toDateString());
        $this->assertSame('Action report', $action->fresh()->libelle);
        $this->assertSame($actionDeadline, $action->fresh()->date_fin->toDateString());
    }

    public function test_requester_can_complete_and_content_changes_restart_the_circuit(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $initialDeadline = now()->addMonths(2)->toDateString();

        $this->actingAs($fixture['agent'])->post(
            route('workspace.actions.deadline-extension.store', $action),
            $this->changePayload($initialDeadline)
        );
        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();

        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord chef.',
        ]);
        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_COMPLEMENT,
            'comment' => 'Ajouter un calendrier détaillé.',
        ]);

        $this->actingAs($fixture['director'])
            ->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), $this->changePayload($initialDeadline))
            ->assertForbidden();

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), $this->changePayload($initialDeadline, [
                'justification' => 'Le calendrier détaillé demandé est désormais joint au dossier.',
            ]));

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->chef_avis);
        $this->assertNull($deadlineRequest->director_decision);

        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord direction.',
        ]);
        $this->actingAs($fixture['dg'])->post(route('workspace.deadline-extension.final', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::DECISION_COMPLEMENT,
            'comment' => 'Réviser la date demandée.',
        ]);

        $changedDeadline = now()->addMonths(4)->toDateString();
        $this->actingAs($fixture['agent'])
            ->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), $this->changePayload($changedDeadline));

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_SOUMISE, $deadlineRequest->status);
        $this->assertNull($deadlineRequest->chef_avis);
        $this->assertNull($deadlineRequest->director_decision);
        $this->assertNull($deadlineRequest->final_decision);
        $this->assertSame(2, $deadlineRequest->metadata['revision_count']);
    }

    public function test_report_queue_routes_the_request_to_chef_director_then_dg(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $queue = app(DeadlineExtensionQueueService::class);

        $this->actingAs($fixture['agent'])->post(
            route('workspace.actions.deadline-extension.store', $action),
            $this->changePayload(now()->addMonths(2)->toDateString())
        );
        $request = DeadlineExtensionRequest::query()->firstOrFail();

        $this->assertSame(1, $queue->actionableCount($fixture['chef']));
        $this->assertSame(0, $queue->actionableCount($fixture['director']));
        $this->assertSame(0, $queue->actionableCount($fixture['dg']));

        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord chef.',
        ]);
        $this->assertSame(0, $queue->actionableCount($fixture['chef']));
        $this->assertSame(1, $queue->actionableCount($fixture['director']));

        $directorPage = $this->actingAs($fixture['director'])
            ->get(route('workspace.deadline-extension.show', $request))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.direction', $request))
            ->assertDontSee(route('workspace.deadline-extension.final', $request));
        $this->assertStringContainsString('data-sidebar-badge-for="reports_echeance"', $directorPage->getContent());

        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord direction.',
        ]);
        $this->assertSame(0, $queue->actionableCount($fixture['director']));
        $this->assertSame(1, $queue->actionableCount($fixture['dg']));

        $this->actingAs($fixture['dg'])
            ->get(route('workspace.deadline-extension.show', $request))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.final', $request));

        $this->actingAs($fixture['dg'])->post(route('workspace.deadline-extension.final', $request), [
            'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
            'comment' => 'Accord final.',
        ]);
        $this->assertSame(0, $queue->actionableCount($fixture['dg']));
    }

    public function test_report_queue_searches_and_paginates_a_large_task_list(): void
    {
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());

        foreach (range(1, 25) as $index) {
            DeadlineExtensionRequest::query()->create([
                'action_id' => $action->id,
                'target_type' => 'action',
                'old_deadline' => now()->addMonth()->toDateString(),
                'requested_deadline' => now()->addMonths(2)->addDays($index)->toDateString(),
                'requested_changes' => ['deadline' => now()->addMonths(2)->addDays($index)->toDateString()],
                'original_values' => ['deadline' => now()->addMonth()->toDateString()],
                'requested_by' => $fixture['agent']->id,
                'motif' => sprintf('Dossier volume %02d', $index),
                'justification' => 'Dossier genere pour tester la recherche et la pagination de la file.',
                'attachment_path' => 'reports-echeance/test/volume-'.$index.'.pdf',
                'attachment_name' => 'volume-'.$index.'.pdf',
                'attachment_mime' => 'application/pdf',
                'attachment_size' => 100,
                'status' => DeadlineExtensionRequest::STATUS_SOUMISE,
            ]);
        }

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertSee('25 dossier(s)')
            ->assertSee('Dossier volume 25')
            ->assertDontSee('Dossier volume 01');

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.index', ['recherche' => 'volume 03']))
            ->assertOk()
            ->assertSee('1 dossier(s)')
            ->assertSee('Dossier volume 03')
            ->assertDontSee('Dossier volume 04');
    }

    public function test_non_rmo_and_forbidden_fields_are_rejected(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $nonRmo = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($nonRmo)
            ->post(route('workspace.actions.deadline-extension.store', $action), $this->changePayload(now()->addMonths(2)->toDateString()))
            ->assertForbidden();

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), $this->changePayload(now()->addMonths(2)->toDateString(), [
                'change_fields' => ['statut'],
                'statut' => 'cloturee',
            ]))
            ->assertSessionHasErrors('change_fields.0');

        $this->assertDatabaseCount('deadline_extension_requests', 0);
    }

    public function test_dg_approval_rolls_back_when_a_requested_value_has_drifted(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $request = $this->submitAndReachDg($fixture, $action, now()->addMonths(2)->toDateString());

        $action->forceFill(['date_fin' => now()->addWeeks(6)->toDateString()])->save();

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $request), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'comment' => 'Accord final.',
            ])
            ->assertSessionHasErrors('decision');

        $request->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DG, $request->status);
        $this->assertNull($request->final_decision);
        $this->assertNull($request->applied_at);
    }

    public function test_deleted_sub_action_never_falls_back_to_the_parent_action(): void
    {
        Storage::fake('local');
        $fixture = $this->createPlanningFixture();
        $actionDeadline = now()->addMonths(4)->toDateString();
        $action = $this->createAction($fixture, $actionDeadline);
        $subAction = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'Sous-action temporaire',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'statut' => 'non_demarre',
        ]);

        $this->actingAs($fixture['agent'])->post(
            route('workspace.actions.deadline-extension.store', $action),
            $this->changePayload(now()->addMonths(2)->toDateString(), ['sous_action_id' => $subAction->id])
        );
        $request = DeadlineExtensionRequest::query()->firstOrFail();
        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
        ]);
        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
        ]);
        $subAction->delete();

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $request), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
            ])
            ->assertSessionHasErrors('decision');

        $this->assertSame($actionDeadline, $action->fresh()->date_fin->toDateString());
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DG, $request->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function changePayload(string $deadline, array $overrides = []): array
    {
        return array_replace([
            'change_fields' => ['deadline'],
            'requested_deadline' => $deadline,
            'motif' => 'Contraintes operationnelles',
            'justification' => 'Les contraintes documentees imposent cette modification du planning.',
            'piece_justificative' => UploadedFile::fake()->create('justificatif.pdf', 12, 'application/pdf'),
        ], $overrides);
    }

    /** @param array<string, mixed> $fixture */
    private function submitAndReachDg(array $fixture, Action $action, string $deadline): DeadlineExtensionRequest
    {
        $this->actingAs($fixture['agent'])->post(
            route('workspace.actions.deadline-extension.store', $action),
            $this->changePayload($deadline)
        );
        $request = DeadlineExtensionRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord chef.',
        ]);
        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Accord direction.',
        ]);

        return $request->fresh();
    }

    /** @param array<string, mixed> $fixture */
    private function reviewChefDirectorDg(array $fixture, DeadlineExtensionRequest $request): void
    {
        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
        ]);
        $this->actingAs($fixture['director'])->post(route('workspace.deadline-extension.direction', $request), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
        ]);
        $this->actingAs($fixture['dg'])->post(route('workspace.deadline-extension.final', $request), [
            'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
        ]);
    }

    /** @return array<string, mixed> */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create(['code' => 'DIR-REP', 'libelle' => 'Direction report', 'actif' => true]);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SER-REP', 'libelle' => 'Service report', 'actif' => true]);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $direction->id, 'service_id' => $service->id, 'password_changed_at' => now()]);
        $chef = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id, 'password_changed_at' => now()]);
        $director = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $direction->id, 'password_changed_at' => now()]);
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION, 'direction_id' => $direction->id, 'password_changed_at' => now()]);
        $chefPlanification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION, 'password_changed_at' => now()]);
        $dg = User::factory()->create(['role' => User::ROLE_DG, 'password_changed_at' => now()]);
        $pas = Pas::query()->create(['titre' => 'PAS report', 'periode_debut' => now()->year, 'periode_fin' => now()->year + 2, 'statut' => 'actif']);
        $axe = PasAxe::query()->create(['pas_id' => $pas->id, 'code' => 'AXE-REP', 'libelle' => 'Axe report', 'ordre' => 1]);
        $objectif = PasObjectif::query()->create(['pas_axe_id' => $axe->id, 'code' => 'OS-REP', 'libelle' => 'Objectif report', 'date_echeance' => now()->addYears(2)->toDateString(), 'ordre' => 1]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => null,
            'annee' => now()->year,
            'titre' => 'PAO report',
            'objectif_operationnel' => 'Objectif operationnel report',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel report',
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA report',
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        return compact('direction', 'service', 'agent', 'chef', 'director', 'planification', 'chefPlanification', 'dg', 'pao', 'pta', 'objectifOperationnel') + [
            'chef_planification' => $chefPlanification,
            'objectif_operationnel' => $objectifOperationnel,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function createAction(array $fixture, string $deadline): Action
    {
        $action = Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['objectif_operationnel']->id,
            'libelle' => 'Action report',
            'description' => 'Action test report echeance',
            'type_cible' => 'quantitative',
            'type_indicateur' => 'quantitatif',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_a_realiser' => 10,
            'date_debut' => now()->toDateString(),
            'date_fin' => $deadline,
            'date_echeance' => $deadline,
            'echeance_cible' => $deadline,
            'responsable_id' => $fixture['agent']->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_parametrage' => 'parametre',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
        $action->responsables()->sync([$fixture['agent']->id => ['is_primary' => true]]);

        return $action;
    }
}
