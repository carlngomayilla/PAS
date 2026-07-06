<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthUiPolishTest extends TestCase
{
    public function test_auth_password_controls_use_accessible_icon_component(): void
    {
        $component = (string) file_get_contents(resource_path('views/components/auth/password-toggle.blade.php'));
        $authViews = [
            resource_path('views/auth/login.blade.php'),
            resource_path('views/auth/lamp-login.blade.php'),
            resource_path('views/auth/passwords/reset.blade.php'),
        ];
        $workspacePasswordViews = [
            resource_path('views/workspace/profile/edit.blade.php'),
            resource_path('views/workspace/referentiel/utilisateurs/form.blade.php'),
            resource_path('views/workspace/super_admin/organization.blade.php'),
        ];

        $this->assertStringContainsString('data-password-toggle', $component);
        $this->assertStringContainsString('aria-controls', $component);
        $this->assertStringContainsString('aria-pressed', $component);
        $this->assertStringContainsString('data-password-toggle-icon="show"', $component);
        $this->assertStringContainsString('data-password-toggle-icon="hide"', $component);
        $this->assertStringContainsString('Afficher le mot de passe', $component);

        foreach ($authViews as $viewPath) {
            $view = (string) file_get_contents($viewPath);

            $this->assertStringContainsString('<x-auth.password-toggle', $view);
            $this->assertStringNotContainsString('>Voir<', $view);
            $this->assertStringNotContainsString('>VOIR<', $view);
            $this->assertStringNotContainsString('>Cacher<', $view);
            $this->assertStringNotContainsString('>CACHER<', $view);
        }

        foreach ($workspacePasswordViews as $viewPath) {
            $view = (string) file_get_contents($viewPath);

            $this->assertStringContainsString('<x-auth.password-toggle', $view);
            $this->assertStringNotContainsString('button.textContent = isHidden', $view);
            $this->assertStringNotContainsString('text-xs font-semibold text-[#3996d3]" data-password-toggle', $view);
        }
    }

    public function test_global_ui_polish_styles_are_registered(): void
    {
        $appCss = (string) file_get_contents(resource_path('css/app.css'));
        $guestCss = (string) file_get_contents(resource_path('css/guest.css'));

        $this->assertStringContainsString('.auth-password-toggle', $appCss);
        $this->assertStringContainsString('focus-visible', $appCss);
        $this->assertStringContainsString('scrollbar-color', $appCss);
        $this->assertStringContainsString('.admin-navbar-icon-button', $appCss);
        $this->assertStringContainsString('.auth-password-toggle', $guestCss);
        $this->assertStringContainsString('.login-input[data-password-toggle-target]', $guestCss);
    }
}
