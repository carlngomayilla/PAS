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
            'nouvelle_valeur' => ['after' => 'y', 'motif' => 'Controle initial du diagnostic'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        JournalAudit::query()->create([
            'user_id' => null,
            'module' => 'super_admin',
            'entite_type' => 'deletion_request',
            'entite_id' => 10,
            'action' => 'deletion_request_decision',
            'ancienne_valeur' => ['status' => 'pending'],
            'nouvelle_valeur' => ['status' => 'approved'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        JournalAudit::query()->create([
            'user_id' => $superAdmin->id,
            'module' => 'pas',
            'entite_type' => 'pas',
            'entite_id' => 11,
            'action' => 'pas_update',
            'ancienne_valeur' => null,
            'nouvelle_valeur' => null,
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        JournalAudit::query()->create([
            'user_id' => $superAdmin->id,
            'module' => 'super_admin',
            'entite_type' => 'workflow',
            'entite_id' => 12,
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
            ->assertSee('Contrôle de cohérence')
            ->assertSee('Changements sensibles')
            ->assertSee('Actions organisation')
            ->assertSee('Modules touchés')
            ->assertSee('Sensibles sans auteur')
            ->assertSee('Sensibles sans valeurs')
            ->assertSee('Sensibles sans motif')
            ->assertSee('Audit sensible sans auteur')
            ->assertSee('Audit sensible sans ancienne/nouvelle valeur')
            ->assertSee('Audit sensible sans motif');
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

    public function test_audit_index_can_filter_intervention_journal_entries(): void
    {
        $admin = $this->createAdminUser();

        JournalAudit::query()->create([
            'user_id' => $admin->id,
            'module' => 'action',
            'entite_type' => 'action',
            'entite_id' => 10,
            'action' => 'review_action_validate',
            'ancienne_valeur' => ['statut_validation' => 'soumise_chef'],
            'nouvelle_valeur' => ['statut_validation' => 'validee_chef'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        JournalAudit::query()->create([
            'user_id' => $admin->id,
            'module' => 'profil_utilisateur',
            'entite_type' => 'user',
            'entite_id' => 11,
            'action' => 'update',
            'ancienne_valeur' => ['name' => 'A'],
            'nouvelle_valeur' => ['name' => 'B'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'operation_scope' => 'interventions',
            ]))
            ->assertOk()
            ->assertSee('Interventions')
            ->assertSee('review_action_validate')
            ->assertDontSee('profil_utilisateur');
    }

}
