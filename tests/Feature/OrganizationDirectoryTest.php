<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class OrganizationDirectoryTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_directory_filters_account_health_and_neutralizes_array_filters(): void
    {
        $admin = $this->createAdminUser();
        [$direction, $service] = $this->operationalScope();

        $suspended = User::factory()->create([
            'name' => 'Compte suspendu ciblé',
            'email' => 'suspended.directory@anbg.test',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'suspended_until' => now()->addWeek(),
            'password_changed_at' => now(),
        ]);
        $renewal = User::factory()->create([
            'name' => 'Compte renouvellement ciblé',
            'email' => 'renewal.directory@anbg.test',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => null,
        ]);
        $unscoped = User::factory()->create([
            'name' => 'Compte sans rattachement ciblé',
            'email' => 'unscoped.directory@anbg.test',
            'role' => User::ROLE_AGENT,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $suspendedResponse = $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.index', [
                'account_state' => 'suspended',
                'q' => 'ciblé',
            ]))
            ->assertOk()
            ->assertSee($suspended->email)
            ->assertDontSee($renewal->email)
            ->assertSee('Suspendu');

        $this->assertSame(1, $suspendedResponse->viewData('rows')->total());

        $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.index', [
                'account_state' => 'renewal',
                'q' => 'ciblé',
            ]))
            ->assertOk()
            ->assertSee($renewal->email)
            ->assertDontSee($suspended->email)
            ->assertSee('Renouvellement requis');

        $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.index', [
                'account_state' => 'unscoped',
                'q' => 'ciblé',
            ]))
            ->assertOk()
            ->assertSee($unscoped->email)
            ->assertSee('Rattachement incomplet');

        $this->actingAs($admin)
            ->get('/workspace/referentiel/utilisateurs?q[]=incorrect&direction_id[]=1&account_state[]=active')
            ->assertOk();
        $this->actingAs($admin)
            ->get('/workspace/referentiel/directions?q[]=incorrect&actif[]=1')
            ->assertOk();
        $this->actingAs($admin)
            ->get('/workspace/referentiel/services?direction_id[]=1&sort[]=size')
            ->assertOk();
    }

    public function test_directory_and_csv_exports_respect_organizational_scope_and_escape_formulas(): void
    {
        [$allowedDirection, $allowedService] = $this->createScope('ALLOWED');
        [$otherDirection, $otherService] = $this->createScope('OTHER');

        $viewer = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $allowedDirection->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);
        $allowedUser = User::factory()->create([
            'name' => '=FORMULE INTERDITE',
            'email' => 'allowed.scope@anbg.test',
            'role' => User::ROLE_AGENT,
            'direction_id' => $allowedDirection->id,
            'service_id' => $allowedService->id,
            'password_changed_at' => now(),
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Utilisateur hors périmètre',
            'email' => 'other.scope@anbg.test',
            'role' => User::ROLE_AGENT,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertSee($allowedUser->email)
            ->assertDontSee($otherUser->email);

        $usersCsv = $this->actingAs($viewer)
            ->get(route('workspace.referentiel.utilisateurs.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString($allowedUser->email, $usersCsv);
        $this->assertStringNotContainsString($otherUser->email, $usersCsv);
        $this->assertStringContainsString("'=FORMULE INTERDITE", $usersCsv);

        $servicesCsv = $this->actingAs($viewer)
            ->get(route('workspace.referentiel.services.export.csv'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($allowedService->code, $servicesCsv);
        $this->assertStringNotContainsString($otherService->code, $servicesCsv);

        $directionsCsv = $this->actingAs($viewer)
            ->get(route('workspace.referentiel.directions.export.csv', ['actif' => '']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($allowedDirection->code, $directionsCsv);
        $this->assertStringNotContainsString($otherDirection->code, $directionsCsv);

        $forbidden = User::factory()->create([
            'role' => User::ROLE_INVITE_LECTURE,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($forbidden)
            ->get(route('workspace.referentiel.utilisateurs.export.csv'))
            ->assertForbidden();
    }

    public function test_new_user_without_password_receives_unique_temporary_credentials_and_forced_renewal(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->operationalScope();

        $response = $this->actingAs($superAdmin)
            ->post(route('workspace.referentiel.utilisateurs.store'), [
                'name' => 'Nouvel agent sécurisé',
                'email' => 'secure.initial@anbg.test',
                'role' => User::ROLE_AGENT,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'agent_matricule' => 'SEC-001',
                'agent_fonction' => 'Chargé de suivi',
                'is_active' => '1',
            ])
            ->assertRedirect(route('workspace.referentiel.utilisateurs.index'))
            ->assertSessionHas('temporary_password_value')
            ->assertSessionHas('temporary_password_user', 'secure.initial@anbg.test');

        $temporaryPassword = (string) $response->getSession()->get('temporary_password_value');
        $created = User::query()->where('email', 'secure.initial@anbg.test')->firstOrFail();

        $this->assertNotSame('Anbg@2026!Pas', $temporaryPassword);
        $this->assertGreaterThanOrEqual(12, strlen($temporaryPassword));
        $this->assertTrue(Hash::check($temporaryPassword, $created->password));
        $this->assertNull($created->password_changed_at);

        $this->actingAs($superAdmin)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertSee('Identifiants temporaires')
            ->assertSee($temporaryPassword);
    }

    public function test_bulk_password_reset_generates_a_distinct_password_for_each_user(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $first = User::factory()->create(['password_changed_at' => now()]);
        $second = User::factory()->create(['password_changed_at' => now()]);

        $response = $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.bulk'), [
                'user_ids' => [$first->id, $second->id],
                'bulk_action' => 'reset_password',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'))
            ->assertSessionHas('temporary_credentials');

        $credentials = collect($response->getSession()->get('temporary_credentials'));
        $this->assertCount(2, $credentials);
        $this->assertCount(2, $credentials->pluck('password')->unique());

        foreach ([$first->fresh(), $second->fresh()] as $user) {
            $credential = $credentials->firstWhere('user', $user->email);
            $this->assertIsArray($credential);
            $this->assertTrue(Hash::check((string) $credential['password'], $user->password));
            $this->assertNull($user->password_changed_at);
        }
    }

    /**
     * @return array{0: Direction, 1: Service}
     */
    private function operationalScope(): array
    {
        $direction = Direction::query()->whereIn('code', ['DAF', 'DSIC', 'DS'])->firstOrFail();
        $service = Service::query()->where('direction_id', $direction->id)->firstOrFail();

        return [$direction, $service];
    }

    /**
     * @return array{0: Direction, 1: Service}
     */
    private function createScope(string $suffix): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-'.$suffix,
            'libelle' => 'Direction '.$suffix,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SVC-'.$suffix,
            'libelle' => 'Service '.$suffix,
            'actif' => true,
        ]);

        return [$direction, $service];
    }
}
