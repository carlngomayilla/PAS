<?php

namespace Tests\Feature;

use App\Models\JournalAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminAuditDiagnosticTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_access_audit_diagnostic_page(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        JournalAudit::query()->create([
            'user_id' => $superAdmin->id,
            'module' => 'super_admin',
            'entite_type' => 'diagnostic',
            'entite_id' => 1,
            'action' => 'workflow_settings_update',
            'ancienne_valeur' => ['before' => 'x'],
            'nouvelle_valeur' => ['after' => 'y'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.audit-diagnostic.index'))
            ->assertOk()
            ->assertSee('Audit et diagnostic')
            ->assertSee('Controle de coherence')
            ->assertSee('Changements sensibles')
            ->assertSee('Actions organisation')
            ->assertSee('Modules touches');
    }

    public function test_audit_index_displays_reinforced_summary_and_date_filters(): void
    {
        $admin = $this->createAdminUser();

        JournalAudit::query()->create([
            'user_id' => $admin->id,
            'module' => 'super_admin',
            'entite_type' => 'diagnostic',
            'entite_id' => 2,
            'action' => 'maintenance_clear_views',
            'ancienne_valeur' => ['before' => 'x'],
            'nouvelle_valeur' => ['after' => 'y'],
            'adresse_ip' => '127.0.0.2',
            'user_agent' => 'PHPUnit',
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Entrees filtrees')
            ->assertSee('Actions sensibles')
            ->assertSee('Date debut')
            ->assertSee('Date fin');
    }

    public function test_audit_index_can_export_filtered_logs_to_csv(): void
    {
        $admin = $this->createAdminUser();

        JournalAudit::query()->create([
            'user_id' => $admin->id,
            'module' => 'super_admin',
            'entite_type' => 'diagnostic',
            'entite_id' => 7,
            'action' => 'configuration_snapshot_create',
            'ancienne_valeur' => ['before' => 'old'],
            'nouvelle_valeur' => ['after' => 'new'],
            'adresse_ip' => '127.0.0.9',
            'user_agent' => 'PHPUnit',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('workspace.audit.export', [
                'module' => 'super_admin',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('journal_audit_', (string) $response->headers->get('content-disposition'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('configuration_snapshot_create', $csv);
        $this->assertStringContainsString('super_admin', $csv);
    }
}
