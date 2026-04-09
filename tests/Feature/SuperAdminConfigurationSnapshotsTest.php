<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\PlatformSettingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminConfigurationSnapshotsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_create_and_restore_a_configuration_snapshot(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        PlatformSetting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'app_name'],
            ['value' => 'Version A']
        );

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.snapshots.index'))
            ->assertOk()
            ->assertSee('Sauvegarde et restauration');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.snapshots.store'), [
                'label' => 'Socle stable',
                'description' => 'Reference avant changement',
            ])
            ->assertRedirect(route('workspace.super-admin.snapshots.index'));

        $snapshot = PlatformSettingSnapshot::query()->firstOrFail();

        PlatformSetting::query()
            ->where('group', 'general')
            ->where('key', 'app_name')
            ->update(['value' => 'Version B']);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.snapshots.restore', $snapshot))
            ->assertRedirect(route('workspace.super-admin.snapshots.index'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'general',
            'key' => 'app_name',
            'value' => 'Version A',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'configuration_snapshot_restore',
        ]);

        $snapshot->refresh();
        $this->assertNotNull($snapshot->last_restored_at);
        $this->assertNotNull($snapshot->restored_by);
    }

    public function test_super_admin_can_compare_two_configuration_snapshots(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        PlatformSettingSnapshot::query()->create([
            'label' => 'Version A',
            'payload' => [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_name', 'value' => 'PAS A'],
                    ['group' => 'general', 'key' => 'footer_text', 'value' => 'Footer A'],
                ],
            ],
            'created_by' => $superAdmin->id,
        ]);

        PlatformSettingSnapshot::query()->create([
            'label' => 'Version B',
            'payload' => [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_name', 'value' => 'PAS B'],
                    ['group' => 'general', 'key' => 'institution_label', 'value' => 'ANBG'],
                ],
            ],
            'created_by' => $superAdmin->id,
        ]);

        $left = PlatformSettingSnapshot::query()->where('label', 'Version A')->firstOrFail();
        $right = PlatformSettingSnapshot::query()->where('label', 'Version B')->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.snapshots.index', [
                'compare_left' => $left->id,
                'compare_right' => $right->id,
            ]))
            ->assertOk()
            ->assertSee('Differences detectees')
            ->assertSee('app_name')
            ->assertSee('institution_label')
            ->assertSee('footer_text');
    }

    public function test_super_admin_can_restore_only_selected_snapshot_groups(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        PlatformSetting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'app_name'],
            ['value' => 'Version Courante']
        );
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'appearance', 'key' => 'default_theme'],
            ['value' => 'dark']
        );

        $snapshot = PlatformSettingSnapshot::query()->create([
            'label' => 'Snapshot cible',
            'payload' => [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_name', 'value' => 'Version Snapshot'],
                    ['group' => 'appearance', 'key' => 'default_theme', 'value' => 'light'],
                ],
            ],
            'created_by' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.snapshots.restore', $snapshot), [
                'partial_restore' => '1',
                'groups' => ['general'],
            ])
            ->assertRedirect(route('workspace.super-admin.snapshots.index'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'general',
            'key' => 'app_name',
            'value' => 'Version Snapshot',
        ]);
        $this->assertDatabaseHas('platform_settings', [
            'group' => 'appearance',
            'key' => 'default_theme',
            'value' => 'dark',
        ]);
    }
}
