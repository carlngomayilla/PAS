<?php

namespace Tests\Feature;

use App\Models\InstitutionalReport;
use App\Models\Service;
use App\Models\User;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalReportsNextActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_report_search_keeps_visibility_and_other_filters(): void
    {
        $service = Service::factory()->create();
        $viewer = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $service->direction_id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $visibleIds = [];
        foreach (['title', 'summary', 'actual_agenda', 'decisions'] as $field) {
            $visibleIds[] = InstitutionalReport::factory()->create([
                'submitted_by' => $viewer->id,
                'service_id' => $service->id,
                'direction_id' => $service->direction_id,
                'status' => $field === 'summary' ? InstitutionalReport::STATUS_RETURNED : InstitutionalReport::STATUS_DRAFT,
                $field => 'Compte rendu de maintenance préventive des équipements',
            ])->id;
        }
        $outsideService = Service::factory()->create();
        $outsideReport = InstitutionalReport::factory()->create([
            'service_id' => $outsideService->id,
            'direction_id' => $outsideService->direction_id,
            'title' => 'Maintenance confidentielle hors périmètre',
            'decisions' => 'Maintenance à planifier',
        ]);

        $reporting = app(InstitutionalReportingService::class);
        $results = $reporting->filteredVisibleQuery($viewer, ['q' => ' MAINTENANCE '])->pluck('id')->all();
        $this->assertEqualsCanonicalizing($visibleIds, $results);
        $this->assertNotContains($outsideReport->id, $results);
        $this->assertSame(4, $reporting->summaryFor($viewer, ['q' => 'maintenance'])['total']);
        $this->assertSame(3, $reporting->filteredVisibleQuery($viewer, ['q' => 'tenance', 'status' => 'draft'])->count());
        $this->assertSame(0, $reporting->filteredVisibleQuery($viewer, ['q' => 'texte absent du rapport'])->count());

        $this->actingAs($viewer)->get(route('workspace.reports.index', ['q' => 'tenance']))
            ->assertOk()
            ->assertSee('Compte rendu de maintenance préventive des équipements')
            ->assertDontSee('Maintenance confidentielle hors périmètre');
    }

    public function test_report_filters_keep_selected_values_and_mobile_rows_identifiable(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_PLANIFICATION, 'is_active' => true]);
        $report = InstitutionalReport::factory()->create([
            'title' => 'Rapport lisible',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $viewer->id,
            'created_at' => '2026-11-03 12:00:00',
            'scheduled_at' => '2026-11-03 12:00:00',
        ]);

        $response = $this->actingAs($viewer)->get(route('workspace.reports.index', [
            'q' => 'Rapport lisible', 'status' => 'draft', 'year' => '2026', 'quarter' => '4', 'month' => '11',
        ]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@method="GET" and contains(@class,"reports-filter-form")]//button[@type="submit"]')->length);
        $this->assertSame('Rapport lisible', $xpath->evaluate('string(//input[@name="q"]/@value)'));
        $this->assertSame('draft', $xpath->evaluate('string(//select[@name="status"]/option[@selected]/@value)'));
        $this->assertSame('11', $xpath->evaluate('string(//select[@name="month"]/option[@selected]/@value)'));
        $this->assertSame(7, $xpath->query('//table[@data-mobile-cards]/tbody/tr/td[@data-label]')->length);
        $response->assertSee(route('workspace.reports.show', $report), false);
    }

    public function test_institutional_reports_index_displays_next_action_for_visible_reports(): void
    {
        $viewer = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'is_active' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        InstitutionalReport::factory()->create([
            'title' => 'Rapport brouillon',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport SCIQ',
            'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport retourne',
            'status' => InstitutionalReport::STATUS_RETURNED,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport verifie',
            'status' => InstitutionalReport::STATUS_VERIFIED,
            'submitted_by' => $submitter->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('workspace.reports.index'))
            ->assertOk()
            ->assertSee('Prochaine action')
            ->assertSee('Soumettre au SCIQ')
            ->assertSee('Contrôle attendu du SCIQ')
            ->assertSee('Correction attendue du déposant')
            ->assertSee('Circuit terminé');
    }
}
