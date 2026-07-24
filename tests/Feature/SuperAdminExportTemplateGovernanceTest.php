<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\ExportTemplateVersion;
use App\Models\Service;
use App\Models\User;
use App\Services\Exports\ExportTemplateAssignmentService;
use App\Services\Exports\ExportTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminExportTemplateGovernanceTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_editing_a_published_template_opens_a_draft_and_deactivates_assignments(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin, [
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_default' => true,
            'published_at' => now(),
        ]);
        $assignment = $this->createAssignment($template, $superAdmin, [
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.templates.update', $template), [
                ...$this->templatePayload($template),
                'name' => 'Version de travail contrôlée',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $template->refresh();
        $assignment->refresh();
        $this->assertSame(ExportTemplate::STATUS_DRAFT, $template->status);
        $this->assertSame('Version de travail contrôlée', $template->name);
        $this->assertFalse((bool) $template->is_default);
        $this->assertTrue((bool) $template->is_active);
        $this->assertNull($template->published_at);
        $this->assertFalse((bool) $assignment->is_default);
        $this->assertFalse((bool) $assignment->is_active);
    }

    public function test_archive_is_versioned_and_neutralizes_template_assignments(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin, [
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'is_default' => true,
            'published_at' => now(),
        ]);
        $assignment = $this->createAssignment($template, $superAdmin, [
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.archive', $template), [
                'note' => 'Fin de diffusion officielle',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $template->refresh();
        $assignment->refresh();
        $version = $template->versions()->firstOrFail();
        $this->assertSame(ExportTemplate::STATUS_ARCHIVED, $template->status);
        $this->assertFalse((bool) $template->is_active);
        $this->assertFalse((bool) $template->is_default);
        $this->assertFalse((bool) $assignment->is_active);
        $this->assertFalse((bool) $assignment->is_default);
        $this->assertSame(1, $version->version_number);
        $this->assertSame(ExportTemplate::STATUS_ARCHIVED, $version->status);
    }

    public function test_assignment_table_exposes_mobile_card_labels(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin, [
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createAssignment($template, $superAdmin, [
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.templates.show', $template))
            ->assertOk()
            ->assertSee('mobile-card-table', false)
            ->assertSee('data-label="Profil"', false)
            ->assertSee('data-label="Action"', false);
    }

    public function test_active_assignment_cannot_be_created_for_a_draft_template(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin);

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.templates.show', $template))
            ->post(route('workspace.super-admin.templates.assignments.store', $template), $this->assignmentPayload($template))
            ->assertRedirect(route('workspace.super-admin.templates.show', $template))
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseCount('export_template_assignments', 0);
        $this->assertDatabaseMissing('journal_audit', [
            'action' => 'assign',
        ]);
    }

    public function test_assignment_identity_tampering_and_duplicate_scope_are_rejected(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin, [
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createAssignment($template, $superAdmin, [
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.templates.show', $template))
            ->post(route('workspace.super-admin.templates.assignments.store', $template), [
                ...$this->assignmentPayload($template),
                'module' => 'pta',
            ])
            ->assertSessionHasErrors('module');

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.templates.show', $template))
            ->post(route('workspace.super-admin.templates.assignments.store', $template), $this->assignmentPayload($template))
            ->assertSessionHasErrors('target_profile');

        $this->assertDatabaseCount('export_template_assignments', 1);
    }

    public function test_service_scope_must_belong_to_selected_direction(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin, [
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $firstDirection = Direction::query()->create([
            'code' => 'TPL-D1',
            'libelle' => 'Direction modèle 1',
            'actif' => true,
        ]);
        $secondDirection = Direction::query()->create([
            'code' => 'TPL-D2',
            'libelle' => 'Direction modèle 2',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $secondDirection->id,
            'code' => 'TPL-S2',
            'libelle' => 'Service modèle 2',
            'actif' => true,
        ]);

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.templates.show', $template))
            ->post(route('workspace.super-admin.templates.assignments.store', $template), [
                ...$this->assignmentPayload($template),
                'target_profile' => User::ROLE_AGENT,
                'direction_id' => $firstDirection->id,
                'service_id' => $service->id,
            ])
            ->assertSessionHasErrors('service_id');

        $this->assertDatabaseCount('export_template_assignments', 0);
    }

    public function test_new_default_assignment_demotes_previous_default_in_same_scope(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $firstTemplate = $this->createTemplate($superAdmin, [
            'code' => 'template-affectation-premier',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $secondTemplate = $this->createTemplate($superAdmin, [
            'code' => 'template-affectation-second',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $direction = Direction::query()->create([
            'code' => 'TPL-DEF',
            'libelle' => 'Direction modèle défaut',
            'actif' => true,
        ]);
        $previousDefault = $this->createAssignment($firstTemplate, $superAdmin, [
            'target_profile' => User::ROLE_AGENT,
            'reading_level' => 'interne',
            'direction_id' => $direction->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.assignments.store', $secondTemplate), [
                ...$this->assignmentPayload($secondTemplate),
                'target_profile' => User::ROLE_AGENT,
                'reading_level' => 'interne',
                'direction_id' => $direction->id,
                'is_default' => '1',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $secondTemplate));

        $newDefault = ExportTemplateAssignment::query()
            ->where('export_template_id', $secondTemplate->id)
            ->firstOrFail();
        $this->assertFalse((bool) $previousDefault->fresh()->is_default);
        $this->assertTrue((bool) $newDefault->is_default);
        $this->assertTrue((bool) $newDefault->is_active);
    }

    public function test_publication_versions_increment_and_foreign_version_restore_returns_not_found(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $template = $this->createTemplate($superAdmin);
        $otherTemplate = $this->createTemplate($superAdmin, [
            'code' => 'template-version-etrangere',
            'report_type' => 'other_report',
        ]);
        app(ExportTemplateAssignmentService::class)->createInitial($template, $superAdmin);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.publish', $template), [
                'mark_as_default' => '1',
                'note' => 'Version 1',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));
        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.publish', $template), [
                'mark_as_default' => '1',
                'note' => 'Version 2',
            ])
            ->assertRedirect(route('workspace.super-admin.templates.show', $template));

        $this->assertSame([2, 1], $template->versions()->pluck('version_number')->all());

        $foreignVersion = ExportTemplateVersion::query()->create([
            'export_template_id' => $otherTemplate->id,
            'version_number' => 1,
            'status' => ExportTemplate::STATUS_DRAFT,
            'snapshot' => [],
            'created_by' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.templates.versions.restore', [$template, $foreignVersion]))
            ->assertNotFound();

        $this->assertSame(ExportTemplate::STATUS_PUBLISHED, $template->fresh()->status);
    }

    public function test_non_super_admin_cannot_publish_or_manage_assignments(): void
    {
        $admin = $this->createAdminUser();
        $template = $this->createTemplate($admin);

        $this->actingAs($admin)
            ->post(route('workspace.super-admin.templates.publish', $template), [
                'mark_as_default' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workspace.super-admin.templates.assignments.store', $template), $this->assignmentPayload($template))
            ->assertForbidden();
    }

    public function test_resolver_selects_most_specific_published_assignment_in_database(): void
    {
        $actor = $this->createSuperAdminUser();
        $direction = Direction::query()->create([
            'code' => 'TPL-RES',
            'libelle' => 'Direction résolution',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'TPL-RES-S',
            'libelle' => 'Service résolution',
            'actif' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $globalTemplate = $this->createTemplate($actor, [
            'code' => 'template-resolution-global',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $serviceTemplate = $this->createTemplate($actor, [
            'code' => 'template-resolution-service',
            'status' => ExportTemplate::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createAssignment($globalTemplate, $actor, [
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->createAssignment($serviceTemplate, $actor, [
            'target_profile' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);

        $resolved = app(ExportTemplateResolver::class)->resolve(
            $user,
            'reporting',
            'governance_reporting',
            ExportTemplate::FORMAT_PDF,
            'officiel'
        );

        $this->assertNotNull($resolved);
        $this->assertSame($serviceTemplate->id, $resolved->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(User $actor, array $overrides = []): ExportTemplate
    {
        return ExportTemplate::query()->create(array_merge([
            'code' => 'template-gouvernance-'.strtolower(substr(md5(uniqid('', true)), 0, 8)),
            'name' => 'Template gouvernance',
            'format' => ExportTemplate::FORMAT_PDF,
            'module' => 'reporting',
            'report_type' => 'governance_reporting',
            'target_profile' => null,
            'reading_level' => 'officiel',
            'status' => ExportTemplate::STATUS_DRAFT,
            'is_default' => false,
            'is_active' => true,
            'blocks_config' => [],
            'layout_config' => [],
            'content_config' => [],
            'style_config' => [],
            'meta_config' => [],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAssignment(
        ExportTemplate $template,
        User $actor,
        array $overrides = []
    ): ExportTemplateAssignment {
        return ExportTemplateAssignment::query()->create(array_merge([
            'export_template_id' => $template->id,
            'module' => $template->module,
            'report_type' => $template->report_type,
            'format' => $template->format,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'direction_id' => null,
            'service_id' => null,
            'is_default' => false,
            'is_active' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function templatePayload(ExportTemplate $template): array
    {
        return [
            'name' => $template->name,
            'code' => $template->code,
            'description' => $template->description,
            'format' => $template->format,
            'module' => $template->module,
            'report_type' => $template->report_type,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'orientation' => 'landscape',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentPayload(ExportTemplate $template): array
    {
        return [
            'module' => $template->module,
            'report_type' => $template->report_type,
            'format' => $template->format,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'direction_id' => null,
            'service_id' => null,
            'is_default' => '0',
            'is_active' => '1',
        ];
    }
}
