<?php

namespace Tests\Feature;

use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminDocumentPolicyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_document_policy(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.documents.edit'))
            ->assertOk()
            ->assertSee('Documents et justificatifs');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.documents.update'), [
                'allowed_extensions' => "pdf\ndocx\npng",
                'max_upload_mb' => 12,
                'retention_days' => 730,
                'upload_roles' => ['agent', 'service', 'super_admin'],
                'view_roles' => ['direction', 'dg', 'super_admin'],
                'category_visibility' => [
                    'hebdomadaire' => ['agent', 'service', 'super_admin'],
                    'final' => ['service', 'direction', 'super_admin'],
                    'evaluation_chef' => ['service', 'direction', 'super_admin'],
                    'evaluation_direction' => ['direction', 'dg', 'super_admin'],
                    'financement' => ['direction', 'dg', 'super_admin'],
                ],
            ])
            ->assertRedirect(route('workspace.super-admin.documents.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'document_policy',
            'key' => 'allowed_extensions',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'document_policy_update',
        ]);

        $settings = app(DocumentPolicySettings::class);
        $this->assertSame(['pdf', 'docx', 'png'], $settings->allowedExtensions());
        $this->assertSame(12, $settings->maxUploadMb());
        $this->assertSame(730, $settings->retentionDays());
    }
}
