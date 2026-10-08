<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionProgressiveFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $this->actingAs(User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]));
    }

    public function test_default_advanced_filters_are_collapsed_and_primary_search_is_preserved(): void
    {
        $response = $this->get(route('workspace.actions.index', ['q' => 'Dossiers', 'annee' => now()->year, 'per_page' => 15, 'sort' => '']))->assertOk();
        $document = $this->document($response->getContent());
        $details = $document->query('//details[@data-action-advanced-filters]')->item(0);
        $this->assertNotNull($details);
        $this->assertFalse($details->hasAttribute('open'));
        $this->assertSame('Dossiers', $document->query('//input[@name="q"]')->item(0)->getAttribute('value'));
        $this->assertSame(4, $document->query('//div[contains(@class,"action-primary-filter-grid")]/div')->length);
        $this->assertSame(1, $document->query('//form[@method="GET" and @data-auto-filter-form]')->length);
        $this->assertSame(0, $document->query('//span[@class="action-advanced-filter-count"]')->length);
        $this->assertSame(1, $document->query('//table[contains(@class,"action-list-table")]')->length);
    }

    public function test_zero_financing_filter_is_active_and_selected(): void
    {
        $response = $this->get(route('workspace.actions.index', ['financement_requis' => '0']))->assertOk();
        $document = $this->document($response->getContent());
        $this->assertTrue($document->query('//details[@data-action-advanced-filters]')->item(0)->hasAttribute('open'));
        $this->assertSame('1 actif', trim($document->query('//span[@class="action-advanced-filter-count"]')->item(0)->textContent));
        $this->assertSame('0', $document->query('//select[@name="financement_requis"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_advanced_sort_pagination_and_hidden_context_survive_filter_submission(): void
    {
        $response = $this->get(route('workspace.actions.index', ['sort' => 'progression_desc', 'per_page' => 25, 'without_kpi' => 1, 'layout' => 'kanban']))->assertOk();
        $document = $this->document($response->getContent());
        $this->assertTrue($document->query('//details[@data-action-advanced-filters]')->item(0)->hasAttribute('open'));
        $this->assertSame('3 actifs', trim($document->query('//span[@class="action-advanced-filter-count"]')->item(0)->textContent));
        $this->assertSame('progression_desc', $document->query('//select[@name="sort"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('25', $document->query('//select[@name="per_page"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('kanban', $document->query('//input[@name="layout"]')->item(0)->getAttribute('value'));
        $this->assertSame('1', $document->query('//input[@name="without_kpi"]')->item(0)->getAttribute('value'));
        $this->assertSame(10, $document->query('//details[@data-action-advanced-filters]//div[contains(@class,"action-advanced-filter-grid")]/div')->length);
    }

    public function test_planning_validation_queue_is_preserved_in_auto_filter_form(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'password_changed_at' => now(),
        ]));

        $response = $this->get(route('workspace.actions.index', [
            'vue' => 'validations',
            'validation_queue' => 'planification_rejets',
        ]))->assertOk();
        $document = $this->document($response->getContent());

        $this->assertSame('planification_rejets', $document->query('//form[@data-auto-filter-form]/input[@name="validation_queue"]')->item(0)->getAttribute('value'));
        $this->assertSame('validations', $document->query('//form[@data-auto-filter-form]/input[@name="vue"]')->item(0)->getAttribute('value'));
        $this->assertTrue($document->query('//details[@data-action-advanced-filters]')->item(0)->hasAttribute('open'));
        $this->assertSame('1 actif', trim($document->query('//span[@class="action-advanced-filter-count"]')->item(0)->textContent));
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
