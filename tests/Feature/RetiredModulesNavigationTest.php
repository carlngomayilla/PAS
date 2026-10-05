<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\RoleRegistryService;
use App\Services\UserWorkspaceService;
use App\Services\WorkspaceModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RetiredModulesNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_modules_are_absent_for_every_system_role(): void
    {
        foreach (array_keys(app(RoleRegistryService::class)->systemRoles()) as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $modules = collect(app(UserWorkspaceService::class)->modulesFor($user));
            $this->assertFalse($modules->contains('code', 'reports'), $role);
            $this->assertFalse($modules->contains('code', 'ai_imports'), $role);
            $this->assertFalse($modules->contains('endpoint', '/workspace/reunions'), $role);
            $this->assertFalse($modules->contains('endpoint', '/workspace/ai-imports/pta'), $role);
            if ($user->hasPermission('reporting.read')) {
                $this->assertSame('/workspace/rapports', $modules->firstWhere('code', 'institutional_reports')['endpoint']);
            }
        }
    }

    public function test_old_published_settings_cannot_restore_retired_modules(): void
    {
        foreach (['reports', 'ai_imports'] as $code) {
            PlatformSetting::query()->create([
                'group' => 'workspace_modules',
                'key' => 'workspace_module_'.$code,
                'value' => json_encode(['enabled' => true, 'label' => 'Ancien module'], JSON_THROW_ON_ERROR),
            ]);
        }
        $settings = app(WorkspaceModuleSettings::class);
        $this->assertArrayNotHasKey('reports', $settings->all());
        $this->assertArrayNotHasKey('ai_imports', $settings->all());
        $this->assertSame([], $settings->applyToModules([
            ['code' => 'reports', 'endpoint' => '/workspace/reunions'],
            ['code' => 'ai_imports', 'endpoint' => '/workspace/ai-imports/pta'],
        ]));
    }

    public function test_retired_urls_reject_read_and_write_even_for_administrators(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $this->actingAs($user);
        foreach (['/workspace/reunions', '/workspace/reunions/1/pv', '/workspace/ai-imports', '/workspace/ai-imports/pta/1/import'] as $path) {
            foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
                $this->call($method, $path)->assertStatus(410);
            }
        }
        $this->assertFalse(Route::has('workspace.meetings.index'));
        $this->assertFalse(Route::has('workspace.ai-imports.pta.import'));
        $this->assertTrue(Route::has('workspace.imports.index'));
        $this->assertTrue(Route::has('workspace.reports.index'));
        $this->assertTrue(Route::has('workspace.ai-reports.index'));
    }

    public function test_sidebar_keeps_standard_imports_and_institutional_reports_without_retired_links(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_PLANIFICATION, 'is_active' => true]);
        $this->actingAs($user);
        $html = (string) $this->blade('<x-admin.sidebar :workspace-modules="$modules" />', [
            'modules' => app(UserWorkspaceService::class)->modulesFor($user),
        ]);
        $this->assertStringContainsString('/workspace/imports-excel', $html);
        $this->assertStringContainsString('/workspace/rapports', $html);
        $this->assertStringNotContainsString('/workspace/reunions', $html);
        $this->assertStringNotContainsString('/workspace/ai-imports', $html);
    }
}
