<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DynamicFilterFormsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function filterViewProvider(): array
    {
        return [
            'dashboard' => ['partials/dashboard-analytics.blade.php'],
            'actions' => ['workspace/actions/index.blade.php'],
            'financements DAF' => ['workspace/actions/financements-daf.blade.php'],
            'audit' => ['workspace/audit/index.blade.php'],
            'reports échéance' => ['workspace/deadline-extensions/index.blade.php'],
            'finance' => ['workspace/finance/index.blade.php'],
            'rétention' => ['workspace/governance/retention.blade.php'],
            'délégations' => ['workspace/governance/delegations/index.blade.php'],
            'demandes suppression' => ['workspace/governance/deletion-requests/index.blade.php'],
            'réunions' => ['workspace/meetings/index.blade.php'],
            'reporting' => ['workspace/monitoring/reporting.blade.php'],
            'notifications' => ['workspace/notifications/index.blade.php'],
            'PAO' => ['workspace/pao/index.blade.php'],
            'PAS' => ['workspace/pas/index.blade.php'],
            'PTA' => ['workspace/pta/index.blade.php'],
            'suivi PTA' => ['workspace/pta-suivi/index.blade.php'],
            'directions' => ['workspace/referentiel/directions/index.blade.php'],
            'services' => ['workspace/referentiel/services/index.blade.php'],
            'utilisateurs' => ['workspace/referentiel/utilisateurs/index.blade.php'],
            'rapports' => ['workspace/reports/index.blade.php'],
            'recherche globale' => ['workspace/search/index.blade.php'],
            'organisation' => ['workspace/super_admin/organization.blade.php'],
            'modèles' => ['workspace/super_admin/templates/index.blade.php'],
            'tâches' => ['workspace/tasks/index.blade.php'],
        ];
    }

    #[DataProvider('filterViewProvider')]
    public function test_filter_forms_are_dynamic_and_have_no_execution_button(string $relativePath): void
    {
        $view = (string) file_get_contents(resource_path('views/'.$relativePath));
        preg_match_all(
            '/<form\b(?=[^>]*\bdata-auto-filter-form\b)[^>]*>.*?<\/form>/si',
            $view,
            $matches,
        );

        $this->assertNotEmpty($matches[0], 'Aucun formulaire dynamique trouvé dans '.$relativePath);

        foreach ($matches[0] as $form) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:button|input)\b[^>]*\btype=["\']submit["\']/i',
                $form,
                'Un bouton d’exécution subsiste dans '.$relativePath,
            );
        }
    }

    public function test_auto_filter_script_debounces_text_and_submits_other_controls_immediately(): void
    {
        $script = (string) file_get_contents(resource_path('js/auto-filter-forms.js'));
        $entrypoint = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import './auto-filter-forms';", $entrypoint);
        $this->assertStringContainsString("const AUTO_FILTER_FORM_SELECTOR = 'form[data-auto-filter-form]'", $script);
        $this->assertStringContainsString('scheduleSubmission(form, 450);', $script);
        $this->assertStringContainsString('scheduleSubmission(form);', $script);
        $this->assertStringContainsString("control.name === 'direction_id'", $script);
        $this->assertStringContainsString("resetSelect(form, 'service_id');", $script);
        $this->assertStringContainsString("resetSelect(form, 'responsable_id');", $script);
        $this->assertStringContainsString('form.requestSubmit();', $script);
    }

    public function test_operational_get_forms_remain_manual(): void
    {
        $organization = (string) file_get_contents(resource_path('views/workspace/super_admin/organization.blade.php'));
        $roles = (string) file_get_contents(resource_path('views/workspace/super_admin/roles.blade.php'));
        $snapshots = (string) file_get_contents(resource_path('views/workspace/super_admin/snapshots.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/<form\b(?=[^>]*form-grid-compact)(?=[^>]*data-auto-filter-form)/i',
            $organization,
        );
        $this->assertStringNotContainsString('data-auto-filter-form', $roles);
        $this->assertStringNotContainsString('data-auto-filter-form', $snapshots);
    }
}
