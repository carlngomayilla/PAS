<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DependencyAuditWorkflowTest extends TestCase
{
    public function test_ci_audits_locked_backend_and_high_severity_frontend_dependencies(): void
    {
        $configuration = $this->workflowConfiguration();
        $auditSteps = [];

        foreach ($configuration['jobs'] as $jobName => $job) {
            foreach ($job['steps'] ?? [] as $stepIndex => $step) {
                $command = $step['run'] ?? null;

                if (! is_string($command) || ! str_contains($command, ' audit')) {
                    continue;
                }

                $auditSteps[] = [
                    'location' => sprintf('%s.%d', $jobName, $stepIndex),
                    'command' => trim($command),
                ];
            }
        }

        $composerAudit = $this->auditStepFor($auditSteps, 'composer audit');
        $npmAudit = $this->auditStepFor($auditSteps, 'npm audit');

        $this->assertSame('composer audit --locked', $composerAudit['command']);
        $this->assertSame('npm audit --audit-level=high', $npmAudit['command']);
        $this->assertNotSame($composerAudit['location'], $npmAudit['location']);
        $this->assertStringNotContainsString('--force', $npmAudit['command']);
    }

    public function test_every_ci_job_using_php_matches_the_project_php_version(): void
    {
        $configuration = $this->workflowConfiguration();
        $phpJobs = 0;

        foreach ($configuration['jobs'] as $jobName => $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (($step['uses'] ?? null) !== 'shivammathur/setup-php@v2') {
                    continue;
                }

                $phpJobs++;
                $this->assertSame(
                    '8.4',
                    $step['with']['php-version'] ?? null,
                    sprintf('The %s CI job must use PHP 8.4.', $jobName),
                );
                $this->assertStringContainsString(
                    'PHP 8.4',
                    (string) ($step['name'] ?? ''),
                    sprintf('The %s CI job must advertise its actual PHP version.', $jobName),
                );
            }
        }

        $this->assertGreaterThanOrEqual(4, $phpJobs);
    }

    /**
     * @return array{jobs: array<string, array<string, mixed>>}
     */
    private function workflowConfiguration(): array
    {
        $configuration = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

        $this->assertIsArray($configuration);
        $this->assertArrayHasKey('jobs', $configuration);
        $this->assertIsArray($configuration['jobs']);

        return $configuration;
    }

    /**
     * @param  array<int, array{location: string, command: string}>  $auditSteps
     * @return array{location: string, command: string}
     */
    private function auditStepFor(array $auditSteps, string $auditCommand): array
    {
        $matches = array_values(array_filter(
            $auditSteps,
            static fn (array $step): bool => str_starts_with($step['command'], $auditCommand),
        ));

        $this->assertCount(1, $matches, sprintf('Expected exactly one %s CI step.', $auditCommand));

        return $matches[0];
    }
}
