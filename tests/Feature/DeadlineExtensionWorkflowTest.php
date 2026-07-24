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
                'requested_deadline' => now()->addMonths(2)->toDateString(),
                'motif' => 'Contraintes operationnelles',
                'justification' => 'Les ressources critiques sont indisponibles sur la periode initiale.',
            ])
            ->assertSessionHasErrors('piece_justificative');

        $this->assertDatabaseCount('deadline_extension_requests', 0);
    }

    public function test_deadline_extension_follows_the_full_governed_circuit_before_controller_applies_date(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());
        $requestedDeadline = now()->addMonths(2)->toDateString();
        $approvedDeadline = now()->addMonths(3)->toDateString();

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), [
                'requested_deadline' => $requestedDeadline,
                'motif' => 'Contraintes operationnelles',
                'justification' => 'Les ressources critiques sont indisponibles sur la periode initiale.',
                'piece_justificative' => UploadedFile::fake()->create('report.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();

        $this->assertSame(DeadlineExtensionRequest::STATUS_SOUMISE, $deadlineRequest->status);
        $this->assertSame($requestedDeadline, $deadlineRequest->requested_deadline->toDateString());
        $this->assertNotNull($deadlineRequest->attachment_path);
        $this->assertSame(now()->addMonth()->toDateString(), $action->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.attachment', $deadlineRequest))
            ->assertOk();

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Tentative de saut du chef.',
            ])
            ->assertSessionHasErrors('decision');
        $this->assertSame(DeadlineExtensionRequest::STATUS_SOUMISE, $deadlineRequest->fresh()->status);

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Avis favorable du chef de service.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->chef_avis);
        $this->assertSame(now()->addMonth()->toDateString(), $action->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Dossier conforme pour validation finale.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->sciq_avis);
        $this->assertSame(now()->addMonth()->toDateString(), $action->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'approved_deadline' => $approvedDeadline,
                'comment' => 'Report approuve.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $action->refresh();

        $this->assertSame(DeadlineExtensionRequest::STATUS_APPROUVEE, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::DECISION_APPROUVER, $deadlineRequest->final_decision);
        $this->assertSame($approvedDeadline, $deadlineRequest->approved_deadline->toDateString());
        $this->assertSame(now()->addMonth()->toDateString(), $action->date_fin->toDateString());

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.apply', $deadlineRequest))
            ->assertForbidden();
        $this->actingAs($fixture['chef_planification'])
            ->post(route('workspace.deadline-extension.apply', $deadlineRequest))
            ->assertForbidden();
        $this->assertSame(now()->addMonth()->toDateString(), $action->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.apply', $deadlineRequest))
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $action->refresh();

        $this->assertSame(DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE, $deadlineRequest->status);
        $this->assertSame($fixture['planification']->id, $deadlineRequest->applied_by);
        $this->assertSame($approvedDeadline, $action->date_fin->toDateString());
        $this->assertSame($approvedDeadline, $action->date_echeance->toDateString());
        $this->assertSame($approvedDeadline, $action->echeance_cible->toDateString());
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'deadline_extension_applied_by_controller',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'reports_echeance',
            'action' => 'deadline_applied',
        ]);
    }

    public function test_chef_planification_can_give_final_approval_without_applying_date(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $originalDeadline = now()->addMonth()->toDateString();
        $action = $this->createAction($fixture, $originalDeadline);

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), [
                'requested_deadline' => now()->addMonths(2)->toDateString(),
                'motif' => 'Charge additionnelle',
                'justification' => 'Une charge additionnelle documentee impose un report de l echeance.',
                'piece_justificative' => UploadedFile::fake()->create('charge.pdf', 12, 'application/pdf'),
            ]);

        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $this->actingAs($fixture['chef'])
            ->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Avis chef favorable.',
            ]);
        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Avis controle favorable.',
            ]);
        $this->actingAs($fixture['chef_planification'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'approved_deadline' => now()->addMonths(3)->toDateString(),
                'comment' => 'Validation finale Chef Planification.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_APPROUVEE, $deadlineRequest->status);
        $this->assertSame(User::ROLE_CHEF_PLANIFICATION, $deadlineRequest->final_approver_role);
        $this->assertSame($originalDeadline, $action->fresh()->date_fin->toDateString());
        $this->assertNull($deadlineRequest->applied_at);
    }

    public function test_sub_action_deadline_is_only_changed_after_the_same_full_circuit(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $actionDeadline = now()->addMonths(4)->toDateString();
        $subActionDeadline = now()->addMonth()->toDateString();
        $approvedDeadline = now()->addMonths(3)->toDateString();
        $action = $this->createAction($fixture, $actionDeadline);
        $subAction = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'Sous-action report',
            'date_debut' => now()->toDateString(),
            'date_fin' => $subActionDeadline,
            'statut' => 'non_demarre',
        ]);

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.deadline-extension.store', $action), [
                'sous_action_id' => $subAction->id,
                'requested_deadline' => now()->addMonths(2)->toDateString(),
                'motif' => 'Dependance externe',
                'justification' => 'Une dependance externe documentee retarde uniquement cette sous-action.',
                'piece_justificative' => UploadedFile::fake()->create('dependance.pdf', 12, 'application/pdf'),
            ]);

        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $this->assertSame('sous_action', $deadlineRequest->target_type);

        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Avis chef favorable.',
        ]);
        $this->assertSame($subActionDeadline, $subAction->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['planification'])->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Avis controle favorable.',
        ]);
        $this->actingAs($fixture['dg'])->post(route('workspace.deadline-extension.final', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
            'approved_deadline' => $approvedDeadline,
            'comment' => 'Report de la sous-action approuve.',
        ]);

        $this->assertSame($subActionDeadline, $subAction->fresh()->date_fin->toDateString());

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.apply', $deadlineRequest))
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame($approvedDeadline, $subAction->fresh()->date_fin->toDateString());
        $this->assertSame($actionDeadline, $action->fresh()->date_fin->toDateString());
        $this->assertSame(DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE, $deadlineRequest->fresh()->status);
    }

    public function test_requester_can_complete_the_same_request_at_each_review_stage(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $originalDeadline = now()->addMonth()->toDateString();
        $action = $this->createAction($fixture, $originalDeadline);

        $this->actingAs($fixture['agent'])->post(route('workspace.actions.deadline-extension.store', $action), [
            'requested_deadline' => now()->addMonths(2)->toDateString(),
            'motif' => 'Dependance initiale',
            'justification' => 'Une premiere dependance documentee impose le report de cette action.',
            'piece_justificative' => UploadedFile::fake()->create('initial.pdf', 12, 'application/pdf'),
        ]);
        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $queueService = app(DeadlineExtensionQueueService::class);

        $this->assertSame(1, $queueService->actionableCount($fixture['chef']));
        $this->assertSame(0, $queueService->actionableCount($fixture['planification']));
        $this->assertSame(0, $queueService->actionableCount($fixture['dg']));

        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_COMPLEMENT,
            'comment' => 'Ajouter le calendrier du prestataire.',
        ]);
        $this->assertSame(DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE, $deadlineRequest->fresh()->status);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Complément demandé')
            ->assertSee(route('workspace.deadline-extension.resubmit', $deadlineRequest));
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertDontSee(route('workspace.deadline-extension.resubmit', $deadlineRequest));

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), [
                'requested_deadline' => now()->addMonths(3)->toDateString(),
                'motif' => 'Tentative non autorisee',
                'justification' => 'Un autre utilisateur ne doit pas completer la demande.',
                'piece_justificative' => UploadedFile::fake()->create('interdit.pdf', 12, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), [
                'requested_deadline' => now()->addMonths(3)->toDateString(),
                'motif' => 'Calendrier prestataire',
                'justification' => 'Le calendrier detaille du prestataire est maintenant disponible.',
            ])
            ->assertSessionHasErrors('piece_justificative');

        $this->actingAs($fixture['agent'])->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), [
            'requested_deadline' => now()->addMonths(3)->toDateString(),
            'motif' => 'Calendrier prestataire',
            'justification' => 'Le calendrier detaille du prestataire est maintenant disponible.',
            'piece_justificative' => UploadedFile::fake()->create('calendrier.pdf', 12, 'application/pdf'),
        ]);

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_SOUMISE, $deadlineRequest->status);
        $this->assertNull($deadlineRequest->chef_avis);
        $this->assertSame(1, $deadlineRequest->metadata['revision_count']);
        $this->assertSame('initial.pdf', $deadlineRequest->metadata['revision_history'][0]['previous_attachment_name']);

        $revisionUrl = route('workspace.deadline-extension.attachment.revision', [$deadlineRequest, 0]);
        $this->actingAs($fixture['agent'])
            ->get($revisionUrl)
            ->assertOk();
        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee($revisionUrl)
            ->assertSee('initial.pdf');
        $this->actingAs($fixture['agent'])
            ->get(route('workspace.deadline-extension.attachment.revision', [$deadlineRequest, 99]))
            ->assertNotFound();

        $outsideAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);
        $this->actingAs($outsideAgent)
            ->get($revisionUrl)
            ->assertForbidden();
        $this->actingAs($outsideAgent)
            ->get(route('workspace.deadline-extension.show', $deadlineRequest))
            ->assertForbidden();

        $this->actingAs($fixture['chef'])->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Dossier complete par le demandeur.',
        ]);
        $this->actingAs($fixture['planification'])->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_COMPLEMENT,
            'comment' => 'Ajouter le detail de la marge planning.',
        ]);

        $this->actingAs($fixture['agent'])->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), [
            'requested_deadline' => now()->addMonths(4)->toDateString(),
            'motif' => 'Marge planning documentee',
            'justification' => 'La marge planning demandee par le controleur est jointe au dossier.',
            'piece_justificative' => UploadedFile::fake()->create('marge-planning.pdf', 12, 'application/pdf'),
        ]);

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->chef_avis);
        $this->assertNull($deadlineRequest->sciq_avis);
        $this->assertSame(2, $deadlineRequest->metadata['revision_count']);

        $this->actingAs($fixture['planification'])->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
            'comment' => 'Dossier conforme apres complement.',
        ]);
        $this->actingAs($fixture['dg'])->post(route('workspace.deadline-extension.final', $deadlineRequest), [
            'decision' => DeadlineExtensionRequest::DECISION_COMPLEMENT,
            'comment' => 'Ajouter une note de synthese finale.',
        ]);

        $this->actingAs($fixture['agent'])->post(route('workspace.deadline-extension.resubmit', $deadlineRequest), [
            'requested_deadline' => now()->addMonths(5)->toDateString(),
            'motif' => 'Note de synthese finale',
            'justification' => 'La note de synthese demandee pour la validation finale est jointe.',
            'piece_justificative' => UploadedFile::fake()->create('note-synthese.pdf', 12, 'application/pdf'),
        ]);

        $deadlineRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE, $deadlineRequest->status);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->chef_avis);
        $this->assertSame(DeadlineExtensionRequest::AVIS_FAVORABLE, $deadlineRequest->sciq_avis);
        $this->assertNull($deadlineRequest->final_decision);
        $this->assertSame(3, $deadlineRequest->metadata['revision_count']);
        $this->assertSame($originalDeadline, $action->fresh()->date_fin->toDateString());
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'deadline_extension_resubmitted',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'reports_echeance',
            'action' => 'resubmit',
        ]);
    }

    public function test_report_queue_routes_the_request_to_the_current_actor(): void
    {
        Notification::fake();
        Storage::fake('local');

        $fixture = $this->createPlanningFixture();
        $action = $this->createAction($fixture, now()->addMonth()->toDateString());

        $initialChefDashboard = $this->actingAs($fixture['chef'])
            ->get(route('dashboard'))
            ->assertOk();
        $this->assertStringContainsString(
            'data-dashboard-flow="deadline-extensions" data-flow-count="0"',
            $initialChefDashboard->getContent()
        );

        $this->actingAs($fixture['agent'])->post(route('workspace.actions.deadline-extension.store', $action), [
            'requested_deadline' => now()->addMonths(2)->toDateString(),
            'motif' => 'Report a router',
            'justification' => 'Cette demande permet de verifier la file de traitement par profil.',
            'piece_justificative' => UploadedFile::fake()->create('routage.pdf', 12, 'application/pdf'),
        ]);
        $deadlineRequest = DeadlineExtensionRequest::query()->firstOrFail();
        $queueService = app(DeadlineExtensionQueueService::class);

        $this->assertSame(1, $queueService->actionableCount($fixture['chef']));
        $this->assertSame(0, $queueService->actionableCount($fixture['planification']));
        $this->assertSame(0, $queueService->actionableCount($fixture['dg']));

        $chefDashboard = $this->actingAs($fixture['chef'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee("Reports d'échéance");
        $this->assertStringContainsString(
            'data-dashboard-flow="deadline-extensions" data-flow-count="1"',
            $chefDashboard->getContent()
        );

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.deadline-extension.index', ['vue' => 'mes_demandes']))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Avis chef attendu');
        $chefQueueResponse = $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Traiter')
            ->assertSee(route('workspace.deadline-extension.show', $deadlineRequest));
        $this->assertStringContainsString('data-sidebar-badge-for="reports_echeance"', $chefQueueResponse->getContent());
        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.show', $deadlineRequest))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.chef', $deadlineRequest))
            ->assertDontSee(route('workspace.deadline-extension.controller', $deadlineRequest));
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.deadline-extension.chef', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Transmettre au controle.',
                'return_to' => 'report_detail',
            ])
            ->assertRedirect(route('workspace.deadline-extension.show', $deadlineRequest));

        $this->assertSame(0, $queueService->actionableCount($fixture['chef']));
        $this->assertSame(1, $queueService->actionableCount($fixture['planification']));

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Controle attendu');
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.show', $deadlineRequest))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.controller', $deadlineRequest))
            ->assertDontSee(route('workspace.deadline-extension.final', $deadlineRequest));
        $this->actingAs($fixture['dg'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.controller', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'comment' => 'Transmettre en validation finale.',
                'return_to' => 'report_detail',
            ])
            ->assertRedirect(route('workspace.deadline-extension.show', $deadlineRequest));

        $this->assertSame(0, $queueService->actionableCount($fixture['planification']));
        $this->assertSame(1, $queueService->actionableCount($fixture['dg']));

        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');
        $this->actingAs($fixture['dg'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Validation finale attendue');
        $this->actingAs($fixture['dg'])
            ->get(route('workspace.deadline-extension.show', $deadlineRequest))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.final', $deadlineRequest))
            ->assertDontSee(route('workspace.deadline-extension.apply', $deadlineRequest));

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.deadline-extension.final', $deadlineRequest), [
                'decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'approved_deadline' => now()->addMonths(3)->toDateString(),
                'comment' => 'Report approuve.',
                'return_to' => 'report_detail',
            ])
            ->assertRedirect(route('workspace.deadline-extension.show', $deadlineRequest));

        $this->assertSame(0, $queueService->actionableCount($fixture['dg']));
        $this->assertSame(1, $queueService->actionableCount($fixture['planification']));

        $this->actingAs($fixture['dg'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Application controleur attendue');
        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.show', $deadlineRequest))
            ->assertOk()
            ->assertSee(route('workspace.deadline-extension.apply', $deadlineRequest))
            ->assertDontSee(route('workspace.deadline-extension.final', $deadlineRequest));

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.deadline-extension.apply', $deadlineRequest), [
                'return_to' => 'report_detail',
            ])
            ->assertRedirect(route('workspace.deadline-extension.show', $deadlineRequest));

        $this->assertSame(0, $queueService->actionableCount($fixture['planification']));

        $this->actingAs($fixture['planification'])
            ->get(route('workspace.deadline-extension.index'))
            ->assertOk()
            ->assertDontSee('Action report');
        $this->actingAs($fixture['agent'])
            ->get(route('workspace.deadline-extension.index', ['vue' => 'mes_demandes']))
            ->assertOk()
            ->assertSee('Action report')
            ->assertSee('Date appliquee');
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
            ->get(route('workspace.deadline-extension.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Dossier volume 01');

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.deadline-extension.index', ['recherche' => 'volume 03']))
            ->assertOk()
            ->assertSee('1 dossier(s)')
            ->assertSee('Dossier volume 03')
            ->assertDontSee('Dossier volume 04');
    }

    /**
     * @return array<string, mixed>
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-REP',
            'libelle' => 'Direction report',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-REP',
            'libelle' => 'Service report',
            'actif' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $planification = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $chefPlanification = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'password_changed_at' => now(),
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS report',
            'periode_debut' => now()->year,
            'periode_fin' => now()->year + 2,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-REP',
            'libelle' => 'Axe report',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-REP',
            'libelle' => 'Objectif report',
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
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

        return [
            'direction' => $direction,
            'service' => $service,
            'agent' => $agent,
            'planification' => $planification,
            'chef' => $chef,
            'chef_planification' => $chefPlanification,
            'dg' => $dg,
            'pao' => $pao,
            'pta' => $pta,
            'objectif_operationnel' => $objectifOperationnel,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function createAction(array $fixture, string $deadline): Action
    {
        return Action::query()->create([
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
            'statut_parametrage' => 'parametree',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
    }
}
