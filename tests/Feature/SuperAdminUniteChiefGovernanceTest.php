<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\UniteDg;
use App\Models\User;
use App\Services\ChefUniteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminUniteChiefGovernanceTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_super_admin_can_atomically_move_a_compatible_chief_between_units(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$sciq, $dga] = $this->createUnits();
        $chief = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'is_active' => true,
            'unite_dg_id' => $dga->id,
        ]);
        $dga->forceFill(['chef_user_id' => $chief->id])->save();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => $chief->id,
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'));

        $this->assertSame((int) $chief->id, (int) $sciq->fresh()->chef_user_id);
        $this->assertNull($dga->fresh()->chef_user_id);
        $this->assertSame((int) $sciq->id, (int) $chief->fresh()->unite_dg_id);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'unite_dg_set_chef',
            'entite_id' => $sciq->id,
        ]);
    }

    public function test_inactive_chief_is_rejected_without_changing_existing_designation(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$sciq] = $this->createUnits();
        $incumbent = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'is_active' => true,
            'unite_dg_id' => $sciq->id,
        ]);
        $inactiveCandidate = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'is_active' => false,
        ]);
        $sciq->forceFill(['chef_user_id' => $incumbent->id])->save();

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.unites-dg.index'))
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => $inactiveCandidate->id,
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'))
            ->assertSessionHasErrors('chef_user_id');

        $this->assertSame((int) $incumbent->id, (int) $sciq->fresh()->chef_user_id);
        $this->assertNull($inactiveCandidate->fresh()->unite_dg_id);
        $this->assertDatabaseMissing('journal_audit', [
            'action' => 'unite_dg_set_chef',
        ]);
    }

    public function test_unattached_user_with_incompatible_role_is_rejected(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$sciq] = $this->createUnits();
        $candidate = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'unite_dg_id' => null,
        ]);

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.unites-dg.index'))
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => $candidate->id,
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'))
            ->assertSessionHasErrors('chef_user_id');

        $this->assertNull($sciq->fresh()->chef_user_id);
        $this->assertNull($candidate->fresh()->unite_dg_id);
    }

    public function test_attached_active_member_can_be_designated_then_removed_without_role_change(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$sciq] = $this->createUnits();
        $member = User::factory()->create([
            'role' => User::ROLE_COLLABORATEUR,
            'is_active' => true,
            'unite_dg_id' => $sciq->id,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => $member->id,
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'));

        $this->assertSame((int) $member->id, (int) $sciq->fresh()->chef_user_id);
        $this->assertSame(User::ROLE_COLLABORATEUR, $member->fresh()->role);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => '',
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'));

        $this->assertNull($sciq->fresh()->chef_user_id);
        $this->assertSame((int) $sciq->id, (int) $member->fresh()->unite_dg_id);
    }

    public function test_admin_can_remove_a_chief_and_sync_removes_an_inactive_chief(): void
    {
        $admin = $this->createAdminUser();
        [$sciq] = $this->createUnits();
        $chief = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'is_active' => true,
            'unite_dg_id' => $sciq->id,
        ]);
        $sciq->forceFill(['chef_user_id' => $chief->id])->save();

        $this->actingAs($admin)
            ->put(route('workspace.super-admin.unites-dg.set-chef', $sciq), [
                'chef_user_id' => null,
            ])
            ->assertRedirect(route('workspace.super-admin.unites-dg.index'));

        $this->assertNull($sciq->fresh()->chef_user_id);

        $sciq->forceFill(['chef_user_id' => $chief->id])->save();
        $chief->forceFill(['is_active' => false])->save();
        app(ChefUniteSyncService::class)->sync($chief);

        $this->assertNull($sciq->fresh()->chef_user_id);
    }

    /**
     * @return array{0: UniteDg, 1: UniteDg}
     */
    private function createUnits(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DG-TEST',
            'libelle' => 'Direction générale test',
            'actif' => true,
        ]);
        $sciq = UniteDg::query()->create([
            'direction_id' => $direction->id,
            'code' => UniteDg::CODE_SCIQ,
            'libelle' => 'SCIQ test',
            'portee_globale' => true,
            'actif' => true,
        ]);
        $dga = UniteDg::query()->create([
            'direction_id' => $direction->id,
            'code' => UniteDg::CODE_DGA,
            'libelle' => 'DGA test',
            'portee_globale' => true,
            'actif' => true,
        ]);

        return [$sciq, $dga];
    }
}
