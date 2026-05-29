<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Models\UserAssignmentHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminOrgCrudTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ───────────────────────────────────────────────
    // Directions
    // ───────────────────────────────────────────────

    public function test_super_admin_can_create_and_update_a_direction(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.directions.store'), [
                'code'    => 'DTEST',
                'libelle' => 'Direction de test',
                'actif'   => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $direction = Direction::query()->where('code', 'DTEST')->firstOrFail();
        $this->assertSame('Direction de test', $direction->libelle);
        $this->assertTrue((bool) $direction->actif);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_direction_create',
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.directions.update', $direction), [
                'code'    => 'DTEST',
                'libelle' => 'Direction modifiee',
                'actif'   => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertDatabaseHas('directions', [
            'code'    => 'DTEST',
            'libelle' => 'Direction modifiee',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_direction_update',
        ]);
    }

    public function test_super_admin_can_toggle_direction_status(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction  = Direction::query()->firstOrFail();
        $initial    = (bool) $direction->actif;

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.directions.toggle', $direction))
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertSame(! $initial, (bool) $direction->fresh()->actif);
    }

    public function test_direction_code_must_be_unique(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $existing   = Direction::query()->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.directions.store'), [
                'code'    => $existing->code,
                'libelle' => 'Doublon',
                'actif'   => '1',
            ])
            ->assertSessionHasErrors('code');
    }

    // ───────────────────────────────────────────────
    // Services
    // ───────────────────────────────────────────────

    public function test_super_admin_can_create_and_update_a_service(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction  = Direction::query()->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.services.store'), [
                'direction_id' => $direction->id,
                'code'         => 'SVTEST',
                'libelle'      => 'Service de test',
                'actif'        => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $service = Service::query()->where('code', 'SVTEST')->firstOrFail();
        $this->assertSame((int) $direction->id, (int) $service->direction_id);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_service_create',
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.services.update', $service), [
                'direction_id' => $direction->id,
                'code'         => 'SVTEST',
                'libelle'      => 'Service modifie',
                'actif'        => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertDatabaseHas('services', ['code' => 'SVTEST', 'libelle' => 'Service modifie']);
    }

    public function test_super_admin_can_toggle_service_status(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $service    = Service::query()->firstOrFail();
        $initial    = (bool) $service->actif;

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.services.toggle', $service))
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertSame(! $initial, (bool) $service->fresh()->actif);
    }

    // ───────────────────────────────────────────────
    // Users
    // ───────────────────────────────────────────────

    public function test_super_admin_can_create_update_and_toggle_a_user(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction = Direction::query()->whereIn('code', ['DAF', 'DSIC', 'DS'])->firstOrFail();
        $service = Service::query()->where('direction_id', $direction->id)->firstOrFail();

        // Create
        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.store'), [
                'name'                  => 'Agent test unitaire',
                'email'                 => 'agent.crud@anbg.test',
                'role'                  => User::ROLE_AGENT,
                'direction_id'          => $direction->id,
                'service_id'            => $service->id,
                'is_active'             => '1',
                'is_agent'              => '1',
                'agent_matricule'       => 'MAT-CRUD-001',
                'agent_fonction'        => 'Agent test',
                'agent_telephone'       => '060101010',
                'password'              => 'Password-Crud@123',
                'password_confirmation' => 'Password-Crud@123',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $managed = User::query()->where('email', 'agent.crud@anbg.test')->firstOrFail();
        $this->assertTrue(Hash::check('Password-Crud@123', $managed->password));
        $this->assertSame('MAT-CRUD-001', $managed->agent_matricule);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_create',
        ]);

        // Update (sans changer le MDP)
        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.users.update', $managed), [
                'name'      => 'Agent test modifie',
                'email'     => 'agent.crud@anbg.test',
                'role'      => User::ROLE_AGENT,
                'is_active' => '1',
                'is_agent'  => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertSame('Agent test modifie', $managed->fresh()->name);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_update',
        ]);

        // Toggle (désactivation)
        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.toggle', $managed))
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertFalse((bool) $managed->fresh()->is_active);
    }

    public function test_super_admin_cannot_deactivate_own_account(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.toggle', $superAdmin))
            ->assertSessionHasErrors('general');

        $this->assertTrue((bool) $superAdmin->fresh()->is_active);
    }

    public function test_super_admin_deactivation_transfers_open_assignments_and_audits_governance_metadata(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeOrganizationScope('LIFE');

        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $replacement = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pta = $this->makePtaForScope($direction, $service, 'PTA transfert utilisateur');
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action ouverte a transferer',
            'description' => 'Action test lifecycle.',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'date_echeance' => now()->addWeek()->toDateString(),
            'responsable_id' => $target->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);

        DB::table('action_responsables')->updateOrInsert(
            ['action_id' => $action->id, 'user_id' => $target->id],
            ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $sousAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $target->id,
            'libelle' => 'Sous-action ouverte a transferer',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'statut' => 'en_cours',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.toggle', $target), [
                'transfer_to_user_id' => $replacement->id,
                'motif' => 'Depart de service avec transfert des taches ouvertes.',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame((int) $superAdmin->id, (int) $target->deactivated_by);
        $this->assertSame((int) $replacement->id, (int) $target->tasks_transferred_to);

        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'responsable_id' => $replacement->id,
        ]);
        $this->assertDatabaseMissing('action_responsables', [
            'action_id' => $action->id,
            'user_id' => $target->id,
        ]);
        $this->assertDatabaseHas('action_responsables', [
            'action_id' => $action->id,
            'user_id' => $replacement->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('sous_actions', [
            'id' => $sousAction->id,
            'agent_id' => $replacement->id,
        ]);

        $audit = JournalAudit::query()
            ->where('module', 'super_admin')
            ->where('action', 'organization_user_toggle')
            ->where('entite_id', $target->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($replacement->id, $audit->nouvelle_valeur['lifecycle']['replacement_user_id'] ?? null);
        $this->assertSame(1, $audit->nouvelle_valeur['lifecycle']['transfers']['actions_responsable'] ?? null);
        $this->assertSame(1, $audit->nouvelle_valeur['lifecycle']['transfers']['sous_actions'] ?? null);
    }

    public function test_deactivation_with_open_tasks_is_blocked_without_active_replacement(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeOrganizationScope('NOREP');

        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pta = $this->makePtaForScope($direction, $service, 'PTA sans repreneur');
        Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action bloquante sans repreneur',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'responsable_id' => $target->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.toggle', $target), [
                'motif' => 'Tentative sans repreneur.',
            ])
            ->assertSessionHasErrors('transfer_to_user_id');

        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    public function test_service_change_transfers_open_assignments_and_records_assignment_history(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction = Direction::query()->where('code', 'DSIC')->firstOrFail();
        $oldService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'TRH-OLD',
            'libelle' => 'Service historique ancien',
            'actif' => true,
        ]);
        $newService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'TRH-NEW',
            'libelle' => 'Service historique nouveau',
            'actif' => true,
        ]);

        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $oldService->id,
            'password_changed_at' => now(),
        ]);
        $replacement = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $oldService->id,
            'password_changed_at' => now(),
        ]);

        $pta = $this->makePtaForScope($direction, $oldService, 'PTA historique rattachement');
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action ouverte avant changement',
            'description' => 'Action test changement de service.',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'date_echeance' => now()->addWeek()->toDateString(),
            'responsable_id' => $target->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);
        DB::table('action_responsables')->updateOrInsert(
            ['action_id' => $action->id, 'user_id' => $target->id],
            ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => User::ROLE_AGENT,
                'direction_id' => $direction->id,
                'service_id' => $newService->id,
                'is_active' => '1',
                'is_agent' => '1',
                'transfer_to_user_id' => $replacement->id,
                'motif' => 'Mutation de service avec reprise des taches ouvertes.',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $target->refresh();
        $this->assertSame((int) $newService->id, (int) $target->service_id);
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'responsable_id' => $replacement->id,
        ]);
        $this->assertDatabaseMissing('action_responsables', [
            'action_id' => $action->id,
            'user_id' => $target->id,
        ]);

        $history = UserAssignmentHistory::query()->where('user_id', $target->id)->latest('id')->firstOrFail();
        $this->assertSame((int) $oldService->id, (int) $history->previous_service_id);
        $this->assertSame((int) $newService->id, (int) $history->new_service_id);
        $this->assertSame((int) $replacement->id, (int) $history->transfer_to_user_id);
        $this->assertSame(1, $history->transfer_summary['actions_responsable'] ?? null);
        $this->assertSame(1, $history->transfer_summary['actions_rmo'] ?? null);

        $audit = JournalAudit::query()
            ->where('module', 'super_admin')
            ->where('action', 'organization_user_update')
            ->where('entite_id', $target->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($history->id, $audit->nouvelle_valeur['assignment_change']['history_id'] ?? null);
        $this->assertSame($replacement->id, $audit->nouvelle_valeur['assignment_change']['replacement_user_id'] ?? null);
    }

    public function test_service_change_with_open_assignments_is_blocked_without_replacement(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction = Direction::query()->where('code', 'DSIC')->firstOrFail();
        $oldService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'BLK-OLD',
            'libelle' => 'Service blocage ancien',
            'actif' => true,
        ]);
        $newService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'BLK-NEW',
            'libelle' => 'Service blocage nouveau',
            'actif' => true,
        ]);

        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $oldService->id,
            'password_changed_at' => now(),
        ]);

        $pta = $this->makePtaForScope($direction, $oldService, 'PTA blocage rattachement');
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action bloquante avant changement',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'responsable_id' => $target->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => User::ROLE_AGENT,
                'direction_id' => $direction->id,
                'service_id' => $newService->id,
                'is_active' => '1',
                'is_agent' => '1',
                'motif' => 'Tentative de mutation sans repreneur.',
            ])
            ->assertSessionHasErrors('transfer_to_user_id');

        $this->assertSame((int) $oldService->id, (int) $target->fresh()->service_id);
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'responsable_id' => $target->id,
        ]);
        $this->assertDatabaseCount('user_assignment_histories', 0);
    }

    public function test_super_admin_cannot_remove_own_super_admin_role(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.organization.users.update', $superAdmin), [
                'name'      => $superAdmin->name,
                'email'     => $superAdmin->email,
                'role'      => User::ROLE_ADMIN,
                'is_active' => '1',
                'is_agent'  => '0',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_SUPER_ADMIN, $superAdmin->fresh()->role);
    }

    public function test_organization_screen_exposes_governance_simulations_and_history(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.index'))
            ->assertOk()
            ->assertSee('Simulation de fusion de services')
            ->assertSee('Simulation de transfert de service')
            ->assertSee('Historique d organisation');
    }

    // ───────────────────────────────────────────────
    // Reset password — sécurité
    // ───────────────────────────────────────────────

    public function test_reset_password_generates_random_password_and_does_not_expose_it_in_success_message(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $agent      = User::factory()->create([
            'role'              => User::ROLE_AGENT,
            'email'             => 'reset.security@anbg.test',
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.reset-password', $agent))
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        // Le MDP NE doit PAS apparaître dans le message flash success
        $sessionSuccess = $response->getSession()->get('success', '');
        $this->assertStringNotContainsString('@', $sessionSuccess, 'Le mot de passe ne doit pas être visible dans le flash success.');

        // Le MDP temporaire doit être disponible séparément (flash dédié)
        $this->assertNotNull($response->getSession()->get('temporary_password_value'));
        $this->assertSame($agent->email, $response->getSession()->get('temporary_password_user'));

        // Vérifier que l'agent peut se connecter avec le nouveau MDP
        $temporaryPassword = $response->getSession()->get('temporary_password_value');
        $agent->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $agent->password));

        // Vérifier que le MDP n'est pas prévisible (pas de format TempPass@)
        $this->assertStringNotContainsString('TempPass@', $temporaryPassword);
        $this->assertGreaterThanOrEqual(10, strlen($temporaryPassword));

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_password_reset',
        ]);
    }

    public function test_csv_import_rejects_file_exceeding_2000_rows(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        // Construire un CSV avec 2001 lignes de données
        $lines = ['name;email;role;direction_code;service_code;is_active;is_agent;password'];
        for ($i = 1; $i <= 2001; $i++) {
            $lines[] = "Agent{$i};agent{$i}@test.test;agent;;;1;1;Password@123";
        }
        $csv = implode(PHP_EOL, $lines);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.import'), [
                'users_file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('too-large.csv', $csv),
            ])
            ->assertSessionHasErrors('users_file');
    }

    /**
     * @return array{0:Direction,1:Service}
     */
    private function makeOrganizationScope(string $suffix): array
    {
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

        return [$direction, $service];
    }

    private function makePtaForScope(Direction $direction, Service $service, string $title): Pta
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS '.$title,
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'valide',
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO '.$title,
            'statut' => 'valide',
        ]);

        return Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => $title,
            'statut' => 'en_cours',
        ]);
    }
}
