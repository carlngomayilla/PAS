<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWorkspacePtaSuiviModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_profile_receives_the_pta_tracking_module(): void
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
            User::ROLE_INVITE_LECTURE,
        ];

        foreach ($roles as $role) {
            $user = User::factory()->create();
            $user->forceFill([
                'role' => $role,
                'is_active' => true,
            ])->save();

            $module = collect(app(UserWorkspaceService::class)->modulesFor($user))
                ->firstWhere('code', 'pta_suivi');

            $this->assertNotNull($module, "Le module Suivi PTA manque pour le profil {$role}.");
            $this->assertSame('/pta/suivi', $module['endpoint']);
            $this->assertSame(['Consulter', 'Faire le suivi', 'Demander un report'], $module['actions']);

            $deadlineModule = collect(app(UserWorkspaceService::class)->modulesFor($user))
                ->firstWhere('code', 'reports_echeance');
            $this->assertNotNull($deadlineModule, "Le module Reports echeance manque pour le profil {$role}.");
            $this->assertSame('/workspace/reports-echeance', $deadlineModule['endpoint']);
            $this->assertSame(['A traiter', 'Mes demandes', 'Consulter'], $deadlineModule['actions']);
        }
    }
}
