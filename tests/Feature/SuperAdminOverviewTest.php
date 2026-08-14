<?php

namespace Tests\Feature;

use App\Models\DeletionRequest;
use App\Models\JournalAudit;
use App\Models\PlatformSettingSnapshot;
use App\Services\PlatformDiagnosticService;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminOverviewTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_administrators_open_a_command_center_limited_to_their_privileges(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('Centre de commandement')
            ->assertSee('À traiter')
            ->assertSee('État des brouillons')
            ->assertSee('Domaines d’administration')
            ->assertSee('Activité de configuration')
            ->assertSee('Rétention et archivage')
            ->assertSee(route('workspace.super-admin.roles.edit'), false)
            ->assertSee(route('workspace.retention.index'), false)
            ->assertViewHas('isTechnicalAdministrator', true)
            ->assertViewHas('summary', fn (array $summary): bool => array_key_exists('diagnostic_warnings', $summary)
                && array_key_exists('pending_deletion_requests', $summary)
                && array_key_exists('configuration_drafts', $summary));

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('Centre de commandement')
            ->assertSee(route('workspace.super-admin.appearance.edit'), false)
            ->assertSee(route('workspace.super-admin.organization.index'), false)
            ->assertDontSee(route('workspace.super-admin.settings.edit'), false)
            ->assertDontSee(route('workspace.super-admin.roles.edit'), false)
            ->assertDontSee(route('workspace.super-admin.snapshots.index'), false)
            ->assertDontSee(route('workspace.super-admin.simulation.index'), false)
            ->assertDontSee(route('workspace.super-admin.audit-diagnostic.index'), false)
            ->assertDontSee(route('workspace.super-admin.maintenance.index'), false)
            ->assertViewHas('isTechnicalAdministrator', false);
    }

    public function test_pending_governance_decision_and_configuration_draft_are_prioritized(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        DeletionRequest::query()->create([
            'requested_by' => $superAdmin->id,
            'module' => 'referentiel_utilisateur',
            'entity_type' => 'user',
            'entity_id' => $superAdmin->id,
            'entity_label' => 'Compte de test',
            'requested_action' => DeletionRequest::DECISION_DELETE,
            'status' => DeletionRequest::STATUS_PENDING,
            'reason' => 'Contrôle de la file de gouvernance.',
        ]);
        app(PlatformSettings::class)->updateDraft([
            'app_name' => 'PAS - brouillon de contrôle',
        ], $superAdmin);
        $this->assertDatabaseHas('platform_settings', ['group' => 'general_draft']);
        $this->assertTrue(app(PlatformSettings::class)->hasDraft());

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('1 demande(s) de suppression ouverte(s)')
            ->assertSee('brouillon(s) de configuration')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['pending_deletion_requests'] === 1
                && $summary['configuration_drafts'] >= 1)
            ->assertViewHas('configurationDrafts', function ($drafts): bool {
                $generalDraft = $drafts->firstWhere('key', 'general');

                return is_array($generalDraft) && $generalDraft['has_draft'] === true;
            });
    }

    public function test_missing_configuration_snapshot_is_reported_as_continuity_risk(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        PlatformSettingSnapshot::query()->delete();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('Aucun snapshot de configuration')
            ->assertSee('Point de restauration absent');
    }

    public function test_retention_activity_is_included_in_recent_sensitive_changes(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        JournalAudit::query()->create([
            'user_id' => $superAdmin->id,
            'module' => 'retention',
            'entite_type' => 'retention_run',
            'entite_id' => 44,
            'action' => 'retention_data_execute_completed',
            'ancienne_valeur' => null,
            'nouvelle_valeur' => ['processed' => 12],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.index'))
            ->assertOk()
            ->assertSee('Rétention')
            ->assertSee('Retention Data Execute Completed')
            ->assertViewHas('activity', fn ($activity): bool => $activity->sum('count') >= 1);
    }

    public function test_diagnostic_does_not_flag_removed_weekly_tracking(): void
    {
        $checks = collect(app(PlatformDiagnosticService::class)->checks());

        $this->assertFalse($checks->contains('code', 'actions_without_weeks'));
        $this->assertFalse($checks->contains('label', 'Actions sans periodes de suivi'));
    }
}
