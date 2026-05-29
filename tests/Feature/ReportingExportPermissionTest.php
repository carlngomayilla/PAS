<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingExportPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_export_requires_reporting_permission_not_only_alert_permission(): void
    {
        $this->setRolePermissions(User::ROLE_SERVICE, ['planning.read', 'alerts.read']);

        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.reporting.export.pdf'))
            ->assertForbidden();
    }

    public function test_pdf_export_accepts_reporting_permission_without_alert_permission(): void
    {
        $this->setRolePermissions(User::ROLE_SERVICE, ['planning.read', 'reporting.read']);

        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('workspace.reporting.export.pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('journal_audit', [
            'user_id' => $user->id,
            'module' => 'reporting_export',
            'action' => 'export_pdf',
        ]);
    }

    /**
     * @param list<string> $permissions
     */
    private function setRolePermissions(string $role, array $permissions): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
            ['value' => json_encode($permissions, JSON_UNESCAPED_SLASHES)]
        );

        app(RolePermissionSettings::class)->flush();
    }
}
