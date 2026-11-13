<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalReportsNextActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_institutional_reports_module_is_not_available_to_users(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/workspace/rapports')
            ->assertStatus(410);

        $this->assertFalse(
            collect(app(UserWorkspaceService::class)->modulesFor($user))
                ->contains('code', 'institutional_reports')
        );
    }
}
