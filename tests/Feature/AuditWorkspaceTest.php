<?php

namespace Tests\Feature;

use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class AuditWorkspaceTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_audit_workspace_filters_searches_and_paginates_complete_history(): void
    {
        $admin = $this->createAdminUser();
        $otherActor = User::factory()->create([
            'name' => 'Equipe Controle',
            'email' => 'equipe.controle@anbg.test',
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);

        $rows = collect(range(1, 35))->map(function (int $index) use ($admin): array {
            return [
                'user_id' => $admin->id,
                'module' => 'action',
                'entite_type' => 'action',
                'entite_id' => $index,
                'action' => 'action_update',
                'ancienne_valeur' => json_encode(['progression' => $index - 1], JSON_THROW_ON_ERROR),
                'nouvelle_valeur' => json_encode(['progression' => $index], JSON_THROW_ON_ERROR),
                'adresse_ip' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ];
        });
        JournalAudit::query()->insert($rows->all());
        $this->createAudit($otherActor, [
            'module' => 'pta',
            'action' => 'pta_control',
            'entite_type' => 'pta',
            'entite_id' => 900,
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'module' => 'action',
                'per_page' => 15,
            ]))
            ->assertOk()
            ->assertViewHas('logs', function ($paginator): bool {
                return $paginator->total() === 35 && count($paginator->items()) === 15;
            })
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 35)
            ->assertSee('Action Update');

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'q' => 'Equipe Controle',
            ]))
            ->assertOk()
            ->assertSee('equipe.controle@anbg.test')
            ->assertViewHas('logs', fn ($paginator): bool => $paginator->total() === 1);
    }

    public function test_audit_workspace_scopes_events_and_neutralizes_array_filters(): void
    {
        $admin = $this->createAdminUser();
        $this->createAudit($admin, [
            'module' => 'super_admin',
            'action' => 'workflow_settings_update',
            'entite_type' => 'workflow',
            'entite_id' => 1,
        ]);
        $this->createAudit($admin, [
            'module' => 'action',
            'action' => 'review_action_validate',
            'entite_type' => 'action',
            'entite_id' => 2,
        ]);
        $this->createAudit($admin, [
            'module' => 'referentiel',
            'action' => 'organization_service_update',
            'entite_type' => 'service',
            'entite_id' => 3,
        ]);
        $this->createAudit($admin, [
            'module' => 'profil_utilisateur',
            'action' => 'update',
            'entite_type' => 'user',
            'entite_id' => 4,
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'operation_scope' => 'sensitive',
            ]))
            ->assertOk()
            ->assertViewHas('logs', function ($paginator): bool {
                return $paginator->total() === 1
                    && collect($paginator->items())->first()['action'] === 'workflow_settings_update';
            });

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', [
                'q' => ['invalid'],
                'module' => ['action'],
                'action' => ['update'],
                'user_id' => [$admin->id],
                'entite_type' => ['action'],
                'entite_id' => [2],
                'date_from' => ['2026-01-01'],
                'date_to' => ['2026-12-31'],
                'operation_scope' => ['sensitive'],
                'sort' => ['oldest'],
                'per_page' => [100],
                'page' => [2],
            ]))
            ->assertOk()
            ->assertViewHas('filters', function (array $filters): bool {
                return $filters['q'] === ''
                    && $filters['module'] === ''
                    && $filters['action'] === ''
                    && $filters['user_id'] === null
                    && $filters['entite_type'] === ''
                    && $filters['entite_id'] === null
                    && $filters['date_from'] === ''
                    && $filters['date_to'] === ''
                    && $filters['operation_scope'] === ''
                    && $filters['sort'] === 'recent'
                    && $filters['per_page'] === 30
                    && $filters['page'] === 1;
            });
    }

    public function test_audit_details_and_api_redact_secret_values(): void
    {
        $admin = $this->createAdminUser();
        $audit = $this->createAudit($admin, [
            'module' => 'super_admin',
            'action' => 'user_credentials_update',
            'entite_type' => 'user',
            'entite_id' => $admin->id,
            'ancienne_valeur' => [
                'status' => 'pending',
                'password' => 'secret-before',
            ],
            'nouvelle_valeur' => [
                'status' => 'active',
                'security' => ['api_token' => 'secret-after'],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index'))
            ->assertOk()
            ->assertSee('[MASQUÉ]')
            ->assertSee('active')
            ->assertDontSee('secret-before')
            ->assertDontSee('secret-after');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/journal-audit/'.$audit->id)
            ->assertOk()
            ->assertJsonPath('data.ancienne_valeur.password', '[MASQUÉ]')
            ->assertJsonPath('data.nouvelle_valeur.security.api_token', '[MASQUÉ]')
            ->assertJsonPath('data.nouvelle_valeur.status', 'active')
            ->assertJsonMissing(['secret-before'])
            ->assertJsonMissing(['secret-after']);
    }

    public function test_audit_csv_export_is_authorized_filtered_and_formula_safe(): void
    {
        $admin = $this->createAdminUser(['name' => '=Formule interdite']);
        $this->createAudit($admin, [
            'module' => 'action',
            'action' => 'action_update',
            'entite_type' => 'action',
            'entite_id' => 77,
            'ancienne_valeur' => ['status' => 'pending', 'password' => 'export-secret'],
            'nouvelle_valeur' => ['status' => 'active'],
        ]);
        $this->createAudit($admin, [
            'module' => 'pta',
            'action' => 'pta_update',
            'entite_type' => 'pta',
            'entite_id' => 88,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('workspace.audit.export.csv', ['module' => 'action']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString("'=Formule interdite", $content);
        $this->assertStringContainsString('action_update', $content);
        $this->assertStringContainsString('[MASQUÉ]', $content);
        $this->assertStringNotContainsString('export-secret', $content);
        $this->assertStringNotContainsString('pta_update', $content);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.audit.index'))
            ->assertForbidden();
        $this->actingAs($agent)
            ->get(route('workspace.audit.export.csv'))
            ->assertForbidden();

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/journal-audit')->assertForbidden();
    }

    public function test_sciq_can_review_global_execution_and_procedure_history(): void
    {
        $sciq = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'password_changed_at' => now(),
        ]);
        $this->createAudit($sciq, [
            'module' => 'action',
            'action' => 'review_control_validate',
            'entite_type' => 'action',
            'entite_id' => 701,
        ]);
        $this->createAudit($sciq, [
            'module' => 'profil_utilisateur',
            'action' => 'profile_update',
            'entite_type' => 'user',
            'entite_id' => 702,
        ]);

        $this->actingAs($sciq)
            ->get(route('workspace.audit.index', ['operation_scope' => 'execution']))
            ->assertOk()
            ->assertSee('Exécution &amp; procédures', false)
            ->assertViewHas('logs', fn ($paginator): bool => $paginator->total() === 1)
            ->assertSee('Review Control Validate');

        Sanctum::actingAs($sciq);
        $this->getJson('/api/v1/journal-audit')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAudit(?User $actor, array $overrides = []): JournalAudit
    {
        return JournalAudit::query()->create(array_merge([
            'user_id' => $actor?->id,
            'module' => 'action',
            'entite_type' => 'action',
            'entite_id' => 1,
            'action' => 'action_update',
            'ancienne_valeur' => ['status' => 'before'],
            'nouvelle_valeur' => ['status' => 'after'],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ], $overrides));
    }
}
