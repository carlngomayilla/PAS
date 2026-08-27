<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DashboardNextPilotEntrypointTest extends TestCase
{
    public function test_next_pilot_link_is_hidden_when_the_feature_is_disabled(): void
    {
        config()->set('dashboard.next_pilot.enabled', false);

        $view = $this->blade('<x-dashboard.next-pilot-link :filters="$filters" />', [
            'filters' => ['direction_id' => 12],
        ]);

        $view->assertDontSee('data-next-dashboard-pilot-link', false);
        $view->assertDontSee('Nouveau Pilotage');
    }

    public function test_next_pilot_link_is_visible_and_preserves_only_supported_filters(): void
    {
        config()->set('dashboard.next_pilot.enabled', true);
        config()->set('dashboard.next_pilot.url', '/dashboard-pilot');

        $rendered = (string) $this->blade('<x-dashboard.next-pilot-link :filters="$filters" />', [
            'filters' => [
                'direction_id' => 12,
                'service_id' => 34,
                'exercice' => 2026,
                'periode' => 'q2',
                'responsable_id' => 56,
                'statut_action' => 'acheve',
                'statut_suivi' => 'en_cours',
                'statut_delai' => 'all',
                'alerte_echeance' => '',
                'redirect' => 'https://example.invalid',
                'nested' => ['forbidden'],
            ],
        ]);

        $this->assertStringContainsString('data-next-dashboard-pilot-link', $rendered);
        $this->assertStringContainsString('Nouveau Pilotage', $rendered);
        $this->assertStringContainsString('Pilote', $rendered);
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $rendered, $matches));

        $href = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('/dashboard-pilot', parse_url($href, PHP_URL_PATH));
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        $this->assertSame('12', $query['direction_id']);
        $this->assertSame('34', $query['service_id']);
        $this->assertSame('2026', $query['exercice']);
        $this->assertSame('q2', $query['periode']);
        $this->assertSame('56', $query['responsable_id']);
        $this->assertSame('acheve', $query['statut_action']);
        $this->assertSame('en_cours', $query['statut_suivi']);
        $this->assertCount(7, $query);
        $this->assertArrayNotHasKey('redirect', $query);
        $this->assertArrayNotHasKey('nested', $query);
    }

    public function test_external_or_malformed_configured_urls_fall_back_to_the_same_origin_path(): void
    {
        config()->set('dashboard.next_pilot.enabled', true);

        foreach ([
            'https://example.invalid/dashboard-pilot',
            '//example.invalid/dashboard-pilot',
            '/dashboard-pilot?redirect=https://example.invalid',
            '/dashboard'.chr(92).'pilot',
        ] as $configuredUrl) {
            config()->set('dashboard.next_pilot.url', $configuredUrl);

            $rendered = (string) $this->blade('<x-dashboard.next-pilot-link />');

            $this->assertStringContainsString('href="/dashboard-pilot"', $rendered);
            $this->assertStringNotContainsString('example.invalid', $rendered);
        }
    }

    public function test_next_pilot_configuration_defaults_are_safe_for_rollback(): void
    {
        $configuration = (string) file_get_contents(config_path('dashboard.php'));
        $environmentExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("'enabled' => (bool) env('DASHBOARD_NEXT_PILOT_ENABLED', false)", $configuration);
        $this->assertStringContainsString("'url' => env('DASHBOARD_NEXT_PILOT_URL', '/dashboard-pilot')", $configuration);
        $this->assertStringContainsString('DASHBOARD_NEXT_PILOT_ENABLED=false', $environmentExample);
        $this->assertStringContainsString('DASHBOARD_NEXT_PILOT_URL=/dashboard-pilot', $environmentExample);
    }

    public function test_dashboard_navigation_uses_a_passive_component_without_database_query(): void
    {
        $dashboard = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $component = (string) file_get_contents(resource_path('views/components/dashboard/next-pilot-link.blade.php'));

        $this->assertStringContainsString('$nextPilotFilters = [', $dashboard);
        $this->assertStringContainsString('<x-dashboard.next-pilot-link :filters="$nextPilotFilters" />', $dashboard);
        $this->assertStringNotContainsString('DB::', $component);
        $this->assertStringNotContainsString('::query(', $component);
        $this->assertStringNotContainsString('->where(', $component);
    }

    public function test_next_pilot_runtime_is_built_proxied_and_controlled_by_the_feature_flag(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
        $deployment = (string) file_get_contents(base_path('scripts/deploy.sh'));
        $service = (string) file_get_contents(base_path('scripts/anbg-dashboard-pilot.service.example'));
        $runtimeEnvironment = (string) file_get_contents(base_path('scripts/dashboard-pilot.env.example'));
        $nginx = (string) file_get_contents(base_path('scripts/nginx-dashboard-pilot.conf.example'));
        $environmentExample = (string) file_get_contents(base_path('.env.example'));
        $productionEnvironment = (string) file_get_contents(base_path('.env.production.example'));

        foreach ([
            'npm run next:typecheck',
            'npm run next:lint',
            'npm run next:test',
            'npm run next:build',
        ] as $command) {
            $this->assertStringContainsString($command, $workflow);
        }

        $this->assertStringContainsString('npm run next:build', $deployment);
        $this->assertStringContainsString('config:show dashboard.next_pilot.enabled --no-ansi', $deployment);
        $this->assertStringContainsString('NEXT_PILOT_SERVICE', $deployment);
        $this->assertStringContainsString('NEXT_PILOT_ENV_FILE', $deployment);
        $this->assertStringContainsString('validate_next_pilot_runtime', $deployment);
        $this->assertStringContainsString('$port <= 65535', $deployment);
        $this->assertStringContainsString('systemctl enable "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertStringContainsString('systemctl restart "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertStringContainsString('systemctl disable --now "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertStringContainsString('systemctl --user disable --now "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertStringContainsString('systemctl is-enabled --quiet "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertStringContainsString('systemctl --user is-enabled --quiet "$NEXT_PILOT_SERVICE"', $deployment);
        $this->assertMatchesRegularExpression('/else\s+stop_next_pilot_runtime\s+fi/', $deployment);
        $this->assertLessThan(
            strpos($deployment, 'php artisan anbg:health-check'),
            strpos($deployment, 'systemctl restart "$NEXT_PILOT_SERVICE"'),
        );
        $this->assertLessThan(
            strpos($deployment, 'php artisan down'),
            strpos($deployment, 'NEXT_PILOT_PREFLIGHT_ENABLED='),
        );
        $this->assertStringContainsString('EnvironmentFile=/etc/anbg-pas/dashboard-pilot.env', $service);
        $this->assertStringContainsString(
            'NEXT_PILOT_ENV_FILE="/etc/anbg-pas/dashboard-pilot.env"',
            $deployment,
        );
        $this->assertStringNotContainsString('NEXT_PILOT_ENV_FILE="${NEXT_PILOT_ENV_FILE:-', $deployment);
        $this->assertStringContainsString(
            'ExecStart=/usr/bin/npm run next:start -- --hostname 127.0.0.1',
            $service,
        );
        $this->assertStringNotContainsString('Environment=HOSTNAME=', $service);
        $this->assertStringContainsString('Restart=always', $service);
        $this->assertStringContainsString('NoNewPrivileges=true', $service);
        $this->assertSame(1, substr_count($runtimeEnvironment, 'LARAVEL_INTERNAL_URL='));
        $this->assertStringContainsString('LARAVEL_INTERNAL_URL=http://127.0.0.1', $runtimeEnvironment);
        $this->assertStringContainsString('location = /dashboard-pilot', $nginx);
        $this->assertStringContainsString('location ^~ /dashboard-pilot/', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:3000;', $nginx);
        $this->assertStringContainsString('proxy_set_header Host $host;', $nginx);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Host $host;', $nginx);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $nginx);
        $this->assertStringContainsString(
            'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            $nginx,
        );
        $this->assertStringContainsString(
            'DASHBOARD_NEXT_PILOT_HEALTH_URL=http://127.0.0.1:3000/dashboard-pilot/health',
            $environmentExample,
        );
        $this->assertStringContainsString('DASHBOARD_NEXT_PILOT_ENABLED=false', $productionEnvironment);
        $this->assertStringContainsString(
            'DASHBOARD_NEXT_PILOT_HEALTH_URL=http://127.0.0.1:3000/dashboard-pilot/health',
            $productionEnvironment,
        );
    }

    public function test_production_environment_keeps_strong_security_and_resilient_cache_versions(): void
    {
        $productionEnvironment = (string) file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('SESSION_PARTITIONED_COOKIE=false', $productionEnvironment);
        $this->assertStringContainsString('SECURITY_PASSWORD_MIN_LENGTH=12', $productionEnvironment);
        $this->assertStringContainsString('SECURITY_PASSWORD_REQUIRE_MIXED_CASE=true', $productionEnvironment);
        $this->assertStringContainsString('SECURITY_PASSWORD_REQUIRE_SYMBOLS=true', $productionEnvironment);
        $this->assertStringContainsString('CACHE_VERSION_STORE=database', $productionEnvironment);
        $this->assertStringNotContainsString("CACHE_VERSION_STORE=redis\n", $productionEnvironment);
    }

    public function test_openapi_contract_documents_the_versioned_dashboard_endpoint(): void
    {
        $specification = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $operation = data_get($specification, 'paths./dashboard/overview.get');
        $schema = data_get($specification, 'components.schemas.DashboardOverview');

        $this->assertSame('/api/v1', data_get($specification, 'servers.0.url'));
        $this->assertIsArray($operation);
        $this->assertSame(['Dashboard'], $operation['tags']);
        $this->assertArrayHasKey('200', $operation['responses']);
        $this->assertArrayHasKey('304', $operation['responses']);
        $this->assertArrayHasKey('401', $operation['responses']);
        $this->assertArrayHasKey('403', $operation['responses']);
        $this->assertArrayHasKey('422', $operation['responses']);
        $this->assertArrayHasKey('429', $operation['responses']);
        $this->assertContains(['bearerAuth' => []], $operation['security']);
        $this->assertContains(['sessionCookie' => []], $operation['security']);
        $this->assertSame('cookie', data_get(
            $specification,
            'components.securitySchemes.sessionCookie.in',
        ));
        $this->assertSame('1.0', data_get($schema, 'properties.schema_version.const'));
        $this->assertContains('filter_options', $schema['required']);
        $this->assertContains('links', $schema['required']);
        $this->assertSame(
            ['enabled', 'selected_id', 'selected_label', 'service_selected_id', 'service_selected_label', 'options', 'service_options'],
            data_get($schema, 'properties.direction_selector.required'),
        );
        $this->assertSame(
            ['totals', 'alerts', 'status_breakdown', 'action_scope'],
            data_get($schema, 'properties.metrics.required'),
        );
        $this->assertContains('statut_action', data_get($schema, 'properties.filters.required'));
        $this->assertContains('action_statuses', data_get($schema, 'properties.filter_options.required'));
        $this->assertSame(
            ['actions', 'workflow', 'alerts'],
            data_get($specification, 'components.schemas.DashboardBreakdownLinks.required'),
        );
        $this->assertSame(
            false,
            data_get($specification, 'components.schemas.DashboardBreakdownLinks.additionalProperties'),
        );
        $this->assertContains('breakdowns', data_get($schema, 'properties.links.required'));
        $this->assertContains(
            'a_corriger',
            data_get($specification, 'components.schemas.DashboardBreakdownLinks.properties.actions.required'),
        );
        $this->assertSame(
            '#/components/schemas/DashboardBreakdownLinks',
            data_get($schema, 'properties.links.properties.breakdowns.$ref'),
        );
        $this->assertArrayNotHasKey('email', data_get(
            $specification,
            'components.schemas.ResponsibleOption.properties',
        ));
    }
}
