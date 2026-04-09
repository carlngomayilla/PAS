<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminGeneralSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_general_settings_and_see_them_on_login_and_dashboard(): void
    {
        Storage::fake('public');

        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.settings.edit'))
            ->assertOk()
            ->assertSee('Parametres generaux');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.settings.update'), [
                'app_name' => 'PAS Institutionnel',
                'app_short_name' => 'PASI',
                'institution_label' => 'Agence Nationale des Bourses du Gabon',
                'default_locale' => 'en',
                'default_timezone' => 'UTC',
                'date_format' => 'Y-m-d',
                'datetime_format' => 'Y-m-d H:i',
                'number_precision' => '3',
                'number_decimal_separator' => '.',
                'number_thousands_separator' => ',',
                'sidebar_caption' => 'STRATEGIE',
                'admin_header_eyebrow' => 'Plateforme centrale',
                'guest_space_label' => 'Acces public',
                'login_page_title' => 'Connexion institutionnelle',
                'login_welcome_title' => 'Bienvenue sur PAS Institutionnel',
                'login_welcome_text' => 'Connectez-vous pour acceder au dispositif de pilotage.',
                'login_form_title' => 'Authentification',
                'login_form_subtitle' => 'Acces reserve aux utilisateurs habilites.',
                'login_identifier_label' => 'Identifiant',
                'login_identifier_placeholder' => 'Email ou matricule',
                'login_helper_text' => 'Support ANBG - utilisez vos identifiants de test.',
                'footer_text' => 'PASI | Diffusion interne ANBG',
                'logo_mark' => UploadedFile::fake()->createWithContent('logo-mark.png', 'fake-image'),
                'favicon' => UploadedFile::fake()->createWithContent('favicon.png', 'fake-favicon'),
            ])
            ->assertRedirect(route('workspace.super-admin.settings.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'app_name',
            'value' => 'PAS Institutionnel',
        ]);
        $this->assertDatabaseHas('platform_settings', [
            'key' => 'sidebar_caption',
            'value' => 'STRATEGIE',
        ]);
        $this->assertDatabaseHas('platform_settings', [
            'key' => 'default_locale',
            'value' => 'en',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'general_settings_update',
        ]);
        $logoMarkPath = \App\Models\PlatformSetting::query()->where('key', 'logo_mark_path')->value('value');
        $faviconPath = \App\Models\PlatformSetting::query()->where('key', 'favicon_path')->value('value');
        $this->assertIsString($logoMarkPath);
        $this->assertIsString($faviconPath);
        $this->assertStringStartsWith('branding/', $logoMarkPath);
        $this->assertStringStartsWith('branding/', $faviconPath);

        auth()->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Connexion institutionnelle')
            ->assertSee('Bienvenue sur PAS Institutionnel')
            ->assertSee('Authentification')
            ->assertSee('Acces public')
            ->assertSee('PASI | Diffusion interne ANBG')
            ->assertSee('/storage/branding/', false);

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Plateforme centrale')
            ->assertSee('STRATEGIE')
            ->assertSee('PASI | Diffusion interne ANBG');
    }

    public function test_admin_cannot_access_or_update_super_admin_general_settings(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.settings.edit'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('workspace.super-admin.settings.update'), [
                'app_name' => 'Tentative admin',
                'app_short_name' => 'ADM',
                'institution_label' => 'ANBG',
                'default_locale' => 'fr',
                'default_timezone' => 'Africa/Libreville',
                'date_format' => 'd/m/Y',
                'datetime_format' => 'd/m/Y H:i',
                'number_precision' => '2',
                'number_decimal_separator' => ',',
                'number_thousands_separator' => ' ',
                'sidebar_caption' => 'TEST',
                'admin_header_eyebrow' => 'TEST',
                'guest_space_label' => 'TEST',
                'login_page_title' => 'TEST',
                'login_welcome_title' => 'TEST',
                'login_welcome_text' => 'TEST',
                'login_form_title' => 'TEST',
                'login_form_subtitle' => 'TEST',
                'login_identifier_label' => 'TEST',
                'login_identifier_placeholder' => 'TEST',
                'login_helper_text' => 'TEST',
                'footer_text' => 'TEST',
            ])
            ->assertForbidden();
    }
}
