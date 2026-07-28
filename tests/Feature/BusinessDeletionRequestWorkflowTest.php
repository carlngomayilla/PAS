<?php

namespace Tests\Feature;

use App\Models\DeletionRequest;
use App\Models\Pas;
use App\Models\User;
use App\Services\DeletionRequestService;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class BusinessDeletionRequestWorkflowTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_delete_creates_request_without_deleting_data(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $pas = $this->makePas('PAS soumis');

        $this->actingAs($superAdmin)
            ->delete(route('workspace.pas.destroy', $pas), [
                'motif' => 'Document créé en double par erreur.',
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertDatabaseHas('pas', ['id' => $pas->id]);
        $this->assertDatabaseHas('deletion_requests', [
            'entity_type' => Pas::class,
            'entity_id' => $pas->id,
            'status' => DeletionRequest::STATUS_PENDING,
        ]);
    }

    public function test_deletion_requires_planning_chief_approval_then_admin_execution(): void
    {
        $requester = $this->createSuperAdminUser();
        $planningChief = $this->makeUser(User::ROLE_CHEF_PLANIFICATION);
        $admin = $this->makeUser(User::ROLE_ADMIN_FONCTIONNEL);
        $pas = $this->makePas('PAS gouverné');
        $service = app(DeletionRequestService::class);

        $deletionRequest = $service->requestBusinessDeletion(
            $pas,
            $requester,
            'Suppression contrôlée du document.',
            'pas'
        );

        try {
            $service->execute(
                $deletionRequest,
                $admin,
                DeletionRequest::DECISION_DELETE,
                'Tentative avant validation.'
            );
            $this->fail('Une suppression non approuvée ne doit jamais être exécutée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        $approved = $service->approve(
            $deletionRequest,
            $planningChief,
            DeletionRequest::DECISION_APPROVE,
            'Accord après vérification du motif.'
        );

        $this->assertSame(DeletionRequest::STATUS_APPROVED, $approved->status);
        $this->assertDatabaseHas('pas', ['id' => $pas->id]);

        $service->execute(
            $approved,
            $admin,
            DeletionRequest::DECISION_DELETE,
            'Exécution administrative après accord.'
        );

        $this->assertDatabaseMissing('pas', ['id' => $pas->id]);
        $this->assertSame(DeletionRequest::STATUS_DELETED, $approved->fresh()->status);
        $this->assertSame($planningChief->id, $approved->fresh()->approved_by);
        $this->assertSame($admin->id, $approved->fresh()->reviewed_by);
    }

    public function test_dg_cannot_approve_or_execute_a_deletion(): void
    {
        $requester = $this->createSuperAdminUser();
        $dg = $this->makeUser(User::ROLE_DG);
        $pas = $this->makePas('PAS protégé');
        $deletionRequest = app(DeletionRequestService::class)->requestBusinessDeletion(
            $pas,
            $requester,
            'Vérification de la séparation des responsabilités.',
            'pas'
        );

        foreach (['approve', 'execute'] as $operation) {
            try {
                if ($operation === 'approve') {
                    app(DeletionRequestService::class)->approve(
                        $deletionRequest,
                        $dg,
                        DeletionRequest::DECISION_APPROVE,
                        'Validation non autorisée.'
                    );
                } else {
                    app(DeletionRequestService::class)->execute(
                        $deletionRequest,
                        $dg,
                        DeletionRequest::DECISION_DELETE,
                        'Exécution non autorisée.'
                    );
                }
                $this->fail('Le DG ne doit pas pouvoir '.$operation.' cette demande.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertDatabaseHas('pas', ['id' => $pas->id]);
    }

    public function test_role_permissions_are_applied_only_after_two_step_governance(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $planningChief = $this->makeUser(User::ROLE_CHEF_PLANIFICATION);
        $settings = app(RolePermissionSettings::class);
        $before = $settings->all();
        $submitted = $before;
        $submitted[User::ROLE_AGENT] = array_values(array_unique([
            ...($submitted[User::ROLE_AGENT] ?? []),
            'reporting.read',
        ]));

        $governanceRequest = app(DeletionRequestService::class)->requestRolePermissionChange(
            $submitted,
            $superAdmin,
            'Ajout contrôlé d une permission de reporting.'
        );

        $this->assertSame($before, $settings->all());

        $approved = app(DeletionRequestService::class)->approve(
            $governanceRequest,
            $planningChief,
            DeletionRequest::DECISION_APPROVE,
            'Modification cohérente avec les responsabilités.'
        );
        $this->assertSame($before, $settings->all());

        app(DeletionRequestService::class)->execute(
            $approved,
            $superAdmin,
            DeletionRequest::DECISION_APPLY,
            'Application de la matrice approuvée.'
        );

        $this->assertContains('reporting.read', $settings->forRole(User::ROLE_AGENT));
        $this->assertSame(DeletionRequest::STATUS_CORRECTED, $approved->fresh()->status);
    }

    public function test_user_role_assignment_waits_for_planning_approval_and_admin_execution(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $planningChief = $this->makeUser(User::ROLE_CHEF_PLANIFICATION);
        $target = $this->makeUser(User::ROLE_AGENT);
        $service = app(DeletionRequestService::class);

        $roleRequest = $service->requestUserRoleChange(
            $target,
            User::ROLE_SERVICE,
            $superAdmin,
            'Évolution de responsabilité validée par la hiérarchie.'
        );

        $this->assertSame(User::ROLE_AGENT, $target->fresh()->role);

        $approved = $service->approve(
            $roleRequest,
            $planningChief,
            DeletionRequest::DECISION_APPROVE,
            'Le changement de rôle est justifié.'
        );
        $this->assertSame(User::ROLE_AGENT, $target->fresh()->role);

        $service->execute(
            $approved,
            $superAdmin,
            DeletionRequest::DECISION_APPLY,
            'Application administrative du rôle approuvé.'
        );

        $this->assertSame(User::ROLE_SERVICE, $target->fresh()->role);
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
    }

    private function makePas(string $title): Pas
    {
        return Pas::query()->create([
            'titre' => $title,
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
    }
}
