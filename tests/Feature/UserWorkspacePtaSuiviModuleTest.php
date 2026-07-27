<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWorkspacePtaSuiviModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_and_deadline_features_do_not_create_duplicate_sidebar_modules(): void
    {
        $roles = [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_DIRECTION,
            User::ROLE_SERVICE,
            User::ROLE_AGENT,
            User::ROLE_CABINET,
            User::ROLE_DGA_SUPERVISION,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_UCAS,
        ];

        foreach ($roles as $role) {
            $user = User::factory()->create();
            $user->forceFill([
                'role' => $role,
                'is_active' => true,
            ])->save();

            $modules = collect(app(UserWorkspaceService::class)->modulesFor($user));

            $this->assertNull($modules->firstWhere('code', 'pta_suivi'));
            $this->assertNull($modules->firstWhere('code', 'reports_echeance'));
        }
    }

    public function test_read_only_profiles_do_not_receive_operational_modules(): void
    {
        foreach ([User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);
            $modules = collect(app(UserWorkspaceService::class)->modulesFor($user));

            $this->assertNull($modules->firstWhere('code', 'pta_suivi'));
            $this->assertNull($modules->firstWhere('code', 'reports_echeance'));
            $this->assertNull($modules->firstWhere('code', 'execution'));
        }
    }
}
