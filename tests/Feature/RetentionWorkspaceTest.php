<?php

namespace Tests\Feature;

use App\Models\DataArchive;
use App\Models\JournalAudit;
use App\Models\RetentionRun;
use App\Models\User;
use App\Services\Governance\RetentionOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class RetentionWorkspaceTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_manager_can_view_workspace_and_dry_run_is_persisted_and_audited(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.retention.index'))
            ->assertOk()
            ->assertSee('Rétention et archivage')
            ->assertSee('Historique des exécutions');

        $this->actingAs($admin)
            ->post(route('workspace.retention.run'), [
                'scope' => RetentionRun::SCOPE_DATA,
                'mode' => 'dry-run',
            ])
            ->assertRedirect(route('workspace.retention.index'))
            ->assertSessionHas('success');

        $run = RetentionRun::query()->latest('id')->firstOrFail();
        $this->assertSame(RetentionRun::SCOPE_DATA, $run->scope);
        $this->assertSame(RetentionRun::MODE_DRY_RUN, $run->mode);
        $this->assertSame(RetentionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('web', $run->source);
        $this->assertSame($admin->id, $run->initiated_by);
        $this->assertNotNull($run->completed_at);

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'retention',
            'entite_type' => RetentionRun::class,
            'entite_id' => $run->id,
            'action' => 'retention_data_dry_run_completed',
        ]);

        $audit = JournalAudit::query()->where('entite_id', $run->id)->latest('id')->firstOrFail();
        $this->assertSame($run->candidates, $audit->nouvelle_valeur['candidates'] ?? null);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', ['operation_scope' => 'sensitive']))
            ->assertOk()
            ->assertSee('Retention Data Dry Run Completed');
    }

    public function test_archive_register_filters_exports_and_redacts_sensitive_payloads(): void
    {
        $admin = $this->createAdminUser(['name' => '=Opérateur archive']);
        $archive = DataArchive::query()->create([
            'entity_type' => 'action_log',
            'entity_id' => 41,
            'source_table' => 'action_logs',
            'scope_label' => '=Lot sensible',
            'batch_key' => 'RET-SECURE',
            'payload' => [
                'status' => 'archived',
                'password' => 'secret-before',
                'meta' => ['api_token' => 'secret-token'],
            ],
            'archived_at' => now(),
            'archived_by' => $admin->id,
        ]);
        DataArchive::query()->create([
            'entity_type' => 'pas',
            'entity_id' => 52,
            'source_table' => 'pas',
            'scope_label' => 'Archive hors filtre',
            'batch_key' => 'RET-OTHER',
            'payload' => ['status' => 'archived'],
            'archived_at' => now(),
            'archived_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.retention.index', [
                'source' => 'action_logs',
                'q' => 'Lot sensible',
            ]))
            ->assertOk()
            ->assertSee('RET-SECURE')
            ->assertSee('[MASQUÉ]')
            ->assertDontSee('secret-before')
            ->assertDontSee('secret-token')
            ->assertDontSee('Archive hors filtre');

        $jsonResponse = $this->actingAs($admin)
            ->get(route('workspace.retention.archives.download', $archive))
            ->assertOk()
            ->assertDownload('archive-retention-'.$archive->id.'.json');
        $json = $jsonResponse->streamedContent();
        $this->assertStringContainsString('[MASQUÉ]', $json);
        $this->assertStringContainsString('archived', $json);
        $this->assertStringNotContainsString('secret-before', $json);
        $this->assertStringNotContainsString('secret-token', $json);

        $csvResponse = $this->actingAs($admin)
            ->get(route('workspace.retention.export.csv', ['source' => 'action_logs']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('RET-SECURE', $csv);
        $this->assertStringContainsString("'=Lot sensible", $csv);
        $this->assertStringContainsString("'=Opérateur archive", $csv);
        $this->assertStringNotContainsString('RET-OTHER', $csv);
    }

    public function test_non_authorized_user_cannot_access_run_or_export_retention(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);
        $archive = DataArchive::query()->create([
            'entity_type' => 'pas',
            'entity_id' => 1,
            'source_table' => 'pas',
            'payload' => [],
            'archived_at' => now(),
        ]);

        $this->actingAs($agent)->get(route('workspace.retention.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('workspace.retention.export.csv'))->assertForbidden();
        $this->actingAs($agent)->get(route('workspace.retention.archives.download', $archive))->assertForbidden();
        $this->actingAs($agent)
            ->post(route('workspace.retention.run'), [
                'scope' => RetentionRun::SCOPE_DATA,
                'mode' => 'dry-run',
            ])
            ->assertForbidden();
    }

    public function test_invalid_retention_run_payload_is_rejected_without_creating_a_run(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('workspace.retention.run'), [
                'scope' => 'unknown',
                'mode' => 'force',
            ])
            ->assertSessionHasErrors(['scope', 'mode']);

        $this->assertDatabaseCount('retention_runs', 0);
        $this->assertDatabaseCount('journal_audit', 0);
    }

    public function test_concurrent_execution_is_rejected_and_failed_run_is_traced(): void
    {
        $admin = $this->createAdminUser();
        $operationService = app(RetentionOperationService::class);
        $lock = Cache::lock($operationService->lockKey(RetentionRun::SCOPE_DATA), 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->post(route('workspace.retention.run'), [
                    'scope' => RetentionRun::SCOPE_DATA,
                    'mode' => RetentionRun::MODE_EXECUTE,
                ])
                ->assertSessionHasErrors('mode');
        } finally {
            $lock->release();
        }

        $run = RetentionRun::query()->latest('id')->firstOrFail();
        $this->assertSame(RetentionRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('déjà en cours', (string) $run->error_message);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'retention',
            'entite_id' => $run->id,
            'action' => 'retention_data_execute_failed',
        ]);
    }

    public function test_array_filters_are_neutralized_and_console_runs_are_recorded(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.retention.index', [
                'q' => ['bad'],
                'source' => ['pas'],
                'batch' => ['RET'],
                'actor_id' => [$admin->id],
                'date_from' => ['2026-01-01'],
                'date_to' => ['2026-12-31'],
                'sort' => ['oldest'],
                'per_page' => [100],
            ]))
            ->assertOk()
            ->assertViewHas('filters', function (array $filters): bool {
                return $filters['q'] === ''
                    && $filters['source'] === ''
                    && $filters['batch'] === ''
                    && $filters['actor_id'] === null
                    && $filters['date_from'] === ''
                    && $filters['date_to'] === ''
                    && $filters['sort'] === 'recent'
                    && $filters['per_page'] === 20;
            });

        $this->artisan('anbg:retention-run')->assertExitCode(0);

        $run = RetentionRun::query()->latest('id')->firstOrFail();
        $this->assertSame('console', $run->source);
        $this->assertSame(RetentionRun::STATUS_COMPLETED, $run->status);
    }
}
