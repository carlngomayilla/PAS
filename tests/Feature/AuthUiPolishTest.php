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

        $login = (string) file_get_contents(resource_path('views/auth/lamp-login.blade.php'));
        $this->assertStringContainsString('aria-label="Identifiant de connexion"', $login);
        $this->assertStringContainsString('aria-label="Mot de passe"', $login);
        $this->assertStringNotContainsString('auth-password-toggle-label', $component);

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

    public function test_admin_navbar_keeps_actions_grouped_without_filters(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));

        $this->assertStringContainsString('admin-navbar-actions', $layout);
        $this->assertStringContainsString('admin-navbar-action-button', $layout);
        $this->assertStringContainsString('admin-navbar-notification-badge', $layout);
        $this->assertStringContainsString('admin-navbar-user', $layout);
        $this->assertStringContainsString('admin-navbar-profile', $layout);
        $this->assertStringContainsString('data-navbar-user-copy', $layout);
        $this->assertStringContainsString('$layoutUserRoleLabel', $layout);
        $this->assertStringNotContainsString('admin-exercise-filter', $layout);
        $this->assertStringNotContainsString('name="exercice"', $layout);
        $this->assertStringNotContainsString('name="trimestre"', $layout);
    }

    public function test_admin_navigation_uses_hover_with_keyboard_fallback_and_discreet_back_control(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));
        $script = (string) file_get_contents(resource_path('js/ui-enhancements.js'));
        $adminShell = (string) file_get_contents(resource_path('js/admin-shell.js'));
        $css = (string) file_get_contents(resource_path('css/anbg-glass.css'));

        $this->assertStringContainsString('admin-navbar-back-button', $layout);
        $this->assertStringNotContainsString('anbg:sidebar:collapsed', $layout);
        $this->assertStringContainsString('data-sidebar-keyboard-expand', $sidebar);
        $this->assertStringNotContainsString('data-sidebar-auto-expand', $sidebar);
        $this->assertStringNotContainsString('data-sidebar-collapse-toggle', $sidebar);
        $this->assertStringNotContainsString('initSidebarCollapse', $script);
        $this->assertStringContainsString('data-sidebar-keyboard-expanded', $adminShell);
        $this->assertStringNotContainsString('setSidebarAutoExpanded', $adminShell);
        $this->assertStringContainsString('#admin-sidebar:hover', $css);
        $this->assertStringContainsString('#admin-sidebar[data-sidebar-keyboard-expanded="true"]', $css);
        $this->assertStringNotContainsString('#admin-sidebar:has(:focus-visible)', $css);
        $this->assertStringNotContainsString('#admin-sidebar:focus-within', $css);
        $this->assertStringContainsString('.admin-navbar-back-button', $css);
        $this->assertStringContainsString("asset('favicon.png')", $sidebar);
        $this->assertStringContainsString('app-sidebar-logo-flame', $sidebar);
        $this->assertStringContainsString('.admin-navbar-notification-badge', $css);
        $this->assertStringContainsString('.admin-navbar-profile', $css);
        $this->assertStringContainsString('@media (min-width: 768px) and (max-width: 1180px)', $css);
        $this->assertFileExists(public_path('images/logo-anbg-flamme.png'));
        $this->assertGreaterThan(1024, filesize(public_path('images/logo-anbg-flamme.png')));
    }
}
