<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\WorkspaceModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminPlatformConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_configure_workspace_modules_and_the_changes_affect_workspace_navigation(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $payload = [];
        foreach (app(WorkspaceModuleSettings::class)->all() as $code => $module) {
            $payload[$code] = [
                'label' => $module['label'],
                'description' => $module['description'],
                'order' => $module['order'],
                'enabled' => $module['enabled'] ? '1' : '0',
            ];
        }

        $payload['referentiel']['label'] = 'Gouvernance coeur';
        $payload['retention']['enabled'] = '0';

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.modules.edit'))
            ->assertOk()
            ->assertSee('Modules et navigation');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.modules.update'), [
                'modules' => $payload,
            ])
            ->assertRedirect(route('workspace.super-admin.modules.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'workspace_modules',
            'key' => 'workspace_module_referentiel',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'workspace_module_settings_update',
        ]);

        $this->actingAs($admin)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Gouvernance coeur')
            ->assertDontSee('Retention');
    }

    public function test_super_admin_can_save_publish_and_discard_workspace_module_draft(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $payload = [];
        foreach (app(WorkspaceModuleSettings::class)->all() as $code => $module) {
            $payload[$code] = [
                'label' => $module['label'],
                'description' => $module['description'],
                'order' => $module['order'],
                'enabled' => $module['enabled'] ? '1' : '0',
            ];
        }

        $payload['reporting']['label'] = 'Analyse publiee';
        $payload['reporting']['enabled'] = '0';

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.modules.draft'), ['modules' => $payload])
            ->assertRedirect(route('workspace.super-admin.modules.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'workspace_modules_draft',
            'key' => 'workspace_module_draft_reporting',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'workspace_module_settings_draft_update',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.modules.publish-draft'))
            ->assertRedirect(route('workspace.super-admin.modules.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'workspace_modules',
            'key' => 'workspace_module_reporting',
        ]);
        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'workspace_modules_draft',
            'key' => 'workspace_module_draft_reporting',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'workspace_module_settings_draft_publish',
        ]);

        $draftAgain = $payload;
        $draftAgain['alertes']['label'] = 'Alertes laterales';

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.modules.draft'), ['modules' => $draftAgain])
            ->assertRedirect(route('workspace.super-admin.modules.edit'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.modules.discard-draft'))
            ->assertRedirect(route('workspace.super-admin.modules.edit'));

        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'workspace_modules_draft',
            'key' => 'workspace_module_draft_alertes',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'workspace_module_settings_draft_discard',
        ]);
    }

    public function test_super_admin_can_update_appearance_and_the_new_palette_is_rendered_in_public_layouts(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.appearance.edit'))
            ->assertOk()
            ->assertSee('Apparence de la plateforme');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.appearance.update'), $this->appearancePayload([
                'primary_color' => '#102A43',
                'secondary_color' => '#2563EB',
                'surface_color' => '#0F172A',
                'success_color' => '#10B981',
                'accent_color' => '#FDE047',
                'warning_color' => '#F59E0B',
                'danger_color' => '#EF4444',
                'text_color' => '#0B1220',
                'muted_text_color' => '#526277',
                'border_color' => '#D0DAE8',
                'card_background_color' => '#FFFFFF',
                'input_background_color' => '#F8FAFC',
                'font_family' => 'Poppins',
                'heading_font_family' => 'Manrope',
                'default_theme' => 'light',
                'page_background_style' => 'soft',
                'sidebar_style' => 'solid',
                'header_style' => 'solid',
                'card_style' => 'soft',
                'button_style' => 'solid',
                'input_style' => 'filled',
                'table_style' => 'contrast',
                'card_radius' => '18px',
                'button_radius' => '16px',
                'input_radius' => '14px',
                'card_shadow_strength' => 'strong',
                'card_blur' => '8px',
                'visual_density' => 'compact',
                'content_width' => 'reading',
                'sidebar_width' => 'wide',
            ]))
            ->assertRedirect(route('workspace.super-admin.appearance.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'appearance',
            'key' => 'appearance_primary_color',
            'value' => '#102A43',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'appearance_settings_update',
        ]);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('#102A43', false)
            ->assertSee('Poppins', false)
            ->assertSee('Manrope', false)
            ->assertSee('var theme = "light"', false)
            ->assertSee('--app-screen-max-width: 1180px', false)
            ->assertSee('--app-sidebar-width: 152px', false);
    }

    public function test_super_admin_can_request_appearance_preview_without_persisting_changes(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->actingAs($superAdmin)
            ->postJson(route('workspace.super-admin.appearance.preview'), $this->appearancePayload([
                'primary_color' => '#17324D',
                'secondary_color' => '#315D8A',
                'success_color' => '#1E8F5B',
                'accent_color' => '#B6925A',
                'warning_color' => '#9D6C2C',
                'danger_color' => '#9B4A45',
                'text_color' => '#111827',
                'muted_text_color' => '#6B7280',
                'border_color' => '#CBD5E1',
                'font_family' => 'Public Sans',
                'heading_font_family' => 'Source Serif 4',
                'default_theme' => 'dark',
                'page_background_style' => 'aurora',
                'sidebar_style' => 'soft',
                'header_style' => 'glass',
                'card_style' => 'glass',
                'button_style' => 'soft',
                'input_style' => 'outline',
                'table_style' => 'lined',
                'card_radius' => '20px',
                'button_radius' => '14px',
                'input_radius' => '12px',
                'card_shadow_strength' => 'soft',
                'card_blur' => '6px',
                'visual_density' => 'comfortable',
                'content_width' => 'fluid',
                'sidebar_width' => 'compact',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('settings.primary_color', '#17324D')
            ->assertJsonPath('settings.sidebar_width', 'compact');

        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'appearance',
            'key' => 'appearance_primary_color',
            'value' => '#17324D',
        ]);
    }

    public function test_super_admin_can_save_publish_and_discard_an_appearance_draft(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $draftPayload = $this->appearancePayload([
            'primary_color' => '#17324D',
            'secondary_color' => '#315D8A',
            'default_theme' => 'light',
            'content_width' => 'reading',
            'sidebar_width' => 'wide',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.appearance.draft'), $draftPayload)
            ->assertRedirect(route('workspace.super-admin.appearance.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'appearance_draft',
            'key' => 'appearance_draft_primary_color',
            'value' => '#17324D',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'appearance_settings_draft_update',
        ]);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('#17324D', false);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.appearance.publish-draft'))
            ->assertRedirect(route('workspace.super-admin.appearance.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'appearance',
            'key' => 'appearance_primary_color',
            'value' => '#17324D',
        ]);
        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'appearance_draft',
            'key' => 'appearance_draft_primary_color',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'appearance_settings_draft_publish',
        ]);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('#17324D', false);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.appearance.draft'), $this->appearancePayload([
                'primary_color' => '#4B5563',
                'secondary_color' => '#6B7280',
            ]))
            ->assertRedirect(route('workspace.super-admin.appearance.edit'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.appearance.discard-draft'))
            ->assertRedirect(route('workspace.super-admin.appearance.edit'));

        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'appearance_draft',
            'key' => 'appearance_draft_primary_color',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'appearance_settings_draft_discard',
        ]);
    }

    public function test_super_admin_can_save_publish_and_discard_general_settings_draft(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $draftPayload = $this->generalSettingsPayload([
            'app_name' => 'PAS ANBG Draft',
            'footer_text' => 'Brouillon institutionnel',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.settings.draft'), $draftPayload)
            ->assertRedirect(route('workspace.super-admin.settings.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'general_draft',
            'key' => 'general_draft_app_name',
            'value' => 'PAS ANBG Draft',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'general_settings_draft_update',
        ]);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Brouillon institutionnel');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.settings.publish-draft'))
            ->assertRedirect(route('workspace.super-admin.settings.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'general',
            'key' => 'app_name',
            'value' => 'PAS ANBG Draft',
        ]);
        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'general_draft',
            'key' => 'general_draft_app_name',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'general_settings_draft_publish',
        ]);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('Brouillon institutionnel');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.settings.draft'), $this->generalSettingsPayload([
                'app_name' => 'PAS ANBG Another Draft',
            ]))
            ->assertRedirect(route('workspace.super-admin.settings.edit'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.settings.discard-draft'))
            ->assertRedirect(route('workspace.super-admin.settings.edit'));

        $this->assertDatabaseMissing('platform_settings', [
            'group' => 'general_draft',
            'key' => 'general_draft_app_name',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'general_settings_draft_discard',
        ]);
    }

    public function test_super_admin_can_run_safe_maintenance_actions_and_admin_cannot_access_the_screen(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.maintenance.index'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.maintenance.index'))
            ->assertOk()
            ->assertSee('Maintenance legere');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.maintenance.run', 'clear_views'))
            ->assertRedirect(route('workspace.super-admin.maintenance.index'));

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'maintenance_clear_views',
        ]);

        $anchor = PlatformSetting::query()
            ->where('group', 'maintenance')
            ->where('key', 'maintenance_last_action')
            ->first();

        $this->assertNotNull($anchor);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function appearancePayload(array $overrides = []): array
    {
        return array_merge([
            'primary_color' => '#243B5A',
            'secondary_color' => '#516B8B',
            'surface_color' => '#162338',
            'success_color' => '#607861',
            'accent_color' => '#A78F63',
            'warning_color' => '#8E6A38',
            'danger_color' => '#8B4D4A',
            'text_color' => '#0F172A',
            'muted_text_color' => '#64748B',
            'border_color' => '#CBD5E1',
            'card_background_color' => '#FFFFFF',
            'input_background_color' => '#FFFFFF',
            'font_family' => 'Public Sans',
            'heading_font_family' => 'Source Serif 4',
            'default_theme' => 'dark',
            'sidebar_style' => 'aurora',
            'header_style' => 'glass',
            'page_background_style' => 'aurora',
            'card_style' => 'glass',
            'button_style' => 'gradient',
            'input_style' => 'soft',
            'table_style' => 'soft',
            'card_radius' => '1.5rem',
            'button_radius' => '1.25rem',
            'input_radius' => '0.85rem',
            'card_shadow_strength' => 'soft',
            'card_blur' => '4px',
            'visual_density' => 'comfortable',
            'content_width' => 'wide',
            'sidebar_width' => 'normal',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function generalSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'PAS ANBG',
            'app_short_name' => 'ANBG',
            'institution_label' => 'Agence Nationale des Bourses du Gabon',
            'default_locale' => 'fr',
            'default_timezone' => 'Africa/Libreville',
            'date_format' => 'd/m/Y',
            'datetime_format' => 'd/m/Y H:i',
            'number_precision' => '2',
            'number_decimal_separator' => ',',
            'number_thousands_separator' => '.',
            'sidebar_caption' => 'PILOTAGE',
            'admin_header_eyebrow' => 'Administration',
            'guest_space_label' => 'Espace invite',
            'login_page_title' => 'Connexion - PAS',
            'login_welcome_title' => "Bienvenue dans l'espace ANBG",
            'login_welcome_text' => 'Tire sur la corde puis connecte-toi a ton espace de pilotage.',
            'login_form_title' => 'Connexion',
            'login_form_subtitle' => 'Accede a ton espace.',
            'login_identifier_label' => 'Email ou matricule',
            'login_identifier_placeholder' => 'ex: admin@anbg.ga ou ADM-001',
            'login_helper_text' => 'Identifiants de demonstration disponibles sur l environnement local.',
            'footer_text' => 'ANBG | Systeme institutionnel de pilotage PAS / PAO / PTA',
        ], $overrides);
    }
}
