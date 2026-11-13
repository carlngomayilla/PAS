<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InstitutionalReportWorkflowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_institutional_reports_workflow_has_no_named_routes(): void
    {
        $this->assertFalse(Route::has('workspace.reports.index'));
        $this->assertFalse(Route::has('workspace.reports.store'));
        $this->assertFalse(Route::has('workspace.reports.show'));
        $this->assertFalse(Route::has('workspace.reports.submit'));
        $this->assertFalse(Route::has('workspace.reports.resubmit'));
        $this->assertFalse(Route::has('workspace.reports.review'));
        $this->assertFalse(Route::has('workspace.reports.attachments.download'));
    }

    public function test_legacy_report_urls_return_gone_for_authenticated_users(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password_changed_at' => now(),
        ]);

        foreach ([
            ['GET', '/workspace/rapports'],
            ['POST', '/workspace/rapports'],
            ['GET', '/workspace/rapports/1'],
            ['POST', '/workspace/rapports/1/soumettre'],
            ['POST', '/workspace/rapports/1/corriger'],
            ['POST', '/workspace/rapports/1/verification'],
            ['GET', '/workspace/rapports/1/pieces/1/telecharger'],
        ] as [$method, $path]) {
            $this->actingAs($user)->call($method, $path)->assertStatus(410);
        }
    }
}
