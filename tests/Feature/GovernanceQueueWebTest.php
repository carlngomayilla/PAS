<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\DeletionRequest;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Service;
use App\Models\User;
use App\Models\UserAssignmentHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class GovernanceQueueWebTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_overlapping_delegation_is_rejected_and_creation_is_audited(): void
    {
        [$admin, $direction, $service, $delegant, $delegate] = $this->delegationContext('OVR');
        $payload = $this->delegationPayload($direction, $service, $delegant, $delegate);

        $this->actingAs($admin)
            ->post(route('workspace.delegations.store'), $payload)
            ->assertRedirect(route('workspace.delegations.index'));

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'delegations',
            'action' => 'create',
        ]);

        $this->actingAs($admin)
            ->post(route('workspace.delegations.store'), [
                ...$payload,
                'date_debut' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'date_fin' => now()->addDays(9)->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('date_debut');

        $this->assertSame(1, Delegation::query()->count());
    }

    public function test_delegation_cannot_be_cancelled_twice_and_array_filters_are_safe(): void
    {
        [$admin, $direction, $service, $delegant, $delegate] = $this->delegationContext('CAN');
        $payload = $this->delegationPayload($direction, $service, $delegant, $delegate);
        $delegation = Delegation::query()->create([
            ...$payload,
            'statut' => 'active',
            'cree_par' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('workspace.delegations.cancel', $delegation), [
                'motif_annulation' => 'Retour anticipé du responsable titulaire.',
            ])
            ->assertRedirect(route('workspace.delegations.index'));

        $this->actingAs($admin)
            ->post(route('workspace.delegations.cancel', $delegation), [
                'motif_annulation' => 'Nouvelle tentative administrative.',
            ])
            ->assertSessionHasErrors('motif_annulation');

        $this->assertSame(1, JournalAudit::query()
            ->where('module', 'delegations')
            ->where('action', 'cancel')
            ->where('entite_id', $delegation->id)
            ->count());

        $this->actingAs($admin)
            ->get(route('workspace.delegations.index').'?status[0]=active&per_page[0]=50')
            ->assertOk()
            ->assertSee('Registre des délégations');
    }

    public function test_agent_cannot_manage_delegations(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.delegations.index'))
            ->assertForbidden();
    }

    public function test_requester_sees_only_personal_deletion_history_and_array_filters_are_safe(): void
    {
        $requester = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'password_changed_at' => now(),
        ]);
        $otherRequester = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'password_changed_at' => now(),
        ]);

        $this->deletionRequest($requester, 'Demande visible du demandeur');
        $this->deletionRequest($otherRequester, 'Demande confidentielle tierce');

        $this->actingAs($requester)
            ->get(route('workspace.deletion-requests.index', [
                'q' => 'visible du demandeur',
                'status' => DeletionRequest::STATUS_PENDING,
                'module' => 'pta',
            ]))
            ->assertOk()
            ->assertSee('Demande visible du demandeur')
            ->assertDontSee('Demande confidentielle tierce');

        $this->actingAs($requester)
            ->get(route('workspace.deletion-requests.index').'?status[0]=pending&per_page[0]=50')
            ->assertOk();
    }

    public function test_super_admin_sees_global_queue_and_recent_assignment_history(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $requester = User::factory()->create(['password_changed_at' => now()]);
        $managedUser = User::factory()->create(['name' => 'Compte réaffecté', 'password_changed_at' => now()]);
        $actor = User::factory()->create(['name' => 'Administrateur affectation', 'password_changed_at' => now()]);
        $this->deletionRequest($requester, 'Demande globale gouvernance');

        UserAssignmentHistory::query()->create([
            'user_id' => $managedUser->id,
            'changed_by' => $actor->id,
            'previous_role' => User::ROLE_AGENT,
            'new_role' => User::ROLE_SERVICE,
            'reason' => 'Promotion validée par la gouvernance.',
            'changed_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.deletion-requests.index', ['sort' => 'pending_first']))
            ->assertOk()
            ->assertSee('Demande globale gouvernance')
            ->assertSee('Compte réaffecté')
            ->assertSee('Administrateur affectation');
    }

    public function test_requester_can_resubmit_requested_complement_with_audit_and_notification(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $requester = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'password_changed_at' => now(),
        ]);
        $target = User::factory()->create(['password_changed_at' => now()]);
        $deletionRequest = DeletionRequest::query()->create([
            'requested_by' => $requester->id,
            'reviewed_by' => $superAdmin->id,
            'module' => 'referentiel_utilisateur',
            'entity_type' => User::class,
            'entity_id' => $target->id,
            'entity_label' => 'Compte à préciser',
            'requested_action' => 'delete',
            'status' => DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            'reason' => 'Demande initiale.',
            'reviewer_note' => 'Préciser la date de départ et le repreneur.',
            'decision' => DeletionRequest::DECISION_REQUEST_COMPLEMENT,
            'decided_at' => now(),
        ]);

        $this->actingAs($requester)
            ->post(route('workspace.deletion-requests.complement.store', $deletionRequest), [
                'complement' => 'Départ confirmé vendredi, aucune tâche ouverte restante.',
            ])
            ->assertRedirect(route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_PENDING]));

        $deletionRequest->refresh();
        $this->assertSame(DeletionRequest::STATUS_PENDING, $deletionRequest->status);
        $this->assertStringContainsString('Départ confirmé vendredi', $deletionRequest->reason);
        $this->assertNull($deletionRequest->decision);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'deletion_requests',
            'action' => 'complement_resubmitted',
            'entite_id' => $deletionRequest->id,
        ]);
        $this->assertTrue(DB::table('notifications')
            ->where('notifiable_id', $superAdmin->id)
            ->where('data', 'like', '%Demande de suppression a traiter%')
            ->exists());
    }

    public function test_super_admin_decision_can_return_to_governance_queue(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $requester = User::factory()->create(['password_changed_at' => now()]);
        $deletionRequest = $this->deletionRequest($requester, 'Demande à refuser');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.deletion-requests.decision', $deletionRequest), [
                'decision' => DeletionRequest::DECISION_REJECT,
                'reviewer_note' => 'Demande refusée après contrôle du contexte.',
                'return_to' => 'governance',
            ])
            ->assertRedirect(route('workspace.deletion-requests.index'));

        $this->assertSame(DeletionRequest::STATUS_REJECTED, $deletionRequest->fresh()->status);
    }

    public function test_other_user_cannot_resubmit_a_complement(): void
    {
        $requester = User::factory()->create(['password_changed_at' => now()]);
        $otherUser = User::factory()->create(['password_changed_at' => now()]);
        $deletionRequest = $this->deletionRequest($requester, 'Demande protégée');
        $deletionRequest->forceFill(['status' => DeletionRequest::STATUS_COMPLEMENT_REQUESTED])->save();

        $this->actingAs($otherUser)
            ->post(route('workspace.deletion-requests.complement.store', $deletionRequest), [
                'complement' => 'Tentative de réponse par un tiers non autorisé.',
            ])
            ->assertForbidden();

        $this->assertSame(DeletionRequest::STATUS_COMPLEMENT_REQUESTED, $deletionRequest->fresh()->status);
    }

    /**
     * @return array{0:User,1:Direction,2:Service,3:User,4:User}
     */
    private function delegationContext(string $suffix): array
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password_changed_at' => now(),
        ]);
        $direction = Direction::query()->create([
            'code' => 'DIR-'.$suffix,
            'libelle' => 'Direction '.$suffix,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-'.$suffix,
            'libelle' => 'Service '.$suffix,
            'actif' => true,
        ]);
        $delegant = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $delegate = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);

        return [$admin, $direction, $service, $delegant, $delegate];
    }

    /** @return array<string, mixed> */
    private function delegationPayload(Direction $direction, Service $service, User $delegant, User $delegate): array
    {
        return [
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegate->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'permissions' => ['planning_read', 'action_review'],
            'date_debut' => now()->format('Y-m-d H:i:s'),
            'date_fin' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'motif' => 'Continuité de service planifiée.',
        ];
    }

    private function deletionRequest(User $requester, string $label): DeletionRequest
    {
        return DeletionRequest::query()->create([
            'requested_by' => $requester->id,
            'module' => 'pta',
            'entity_type' => Direction::class,
            'entity_id' => 999,
            'entity_label' => $label,
            'requested_action' => 'delete',
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => 'Motif de test suffisamment détaillé.',
            'impact_summary' => ['total' => 0, 'linked_records' => []],
        ]);
    }
}
