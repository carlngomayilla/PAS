<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFilterDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_filters_remain_outside_the_secondary_disclosure(): void
    {
        $xpath = $this->dashboard();

        foreach (['exercice', 'periode', 'direction_id'] as $field) {
            $this->assertSame(1, $xpath->query('//*[@data-dashboard-primary-filters]//select[@name="'.$field.'"]')->length);
        }

        foreach (['service_id', 'responsable_id', 'statut_action', 'statut_suivi', 'statut_delai', 'alerte_echeance'] as $field) {
            $this->assertSame(1, $xpath->query('//details[@data-dashboard-additional-filters]//select[@name="'.$field.'"]')->length);
        }

        $this->assertSame(0, $xpath->query('//details[@data-dashboard-additional-filters and @open]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-synthesis-filter-form and @data-mobile-collapsed]')->length);
    }

    public function test_active_secondary_filters_are_visible_and_preserve_their_values(): void
    {
        $xpath = $this->dashboard('?statut_action=en_cours&statut_delai=hors_delai');

        $this->assertSame(1, $xpath->query('//details[@data-dashboard-additional-filters and @open]')->length);
        $this->assertSame('2 actif(s)', trim($xpath->query('//*[@data-dashboard-active-filter-count]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//select[@name="statut_action"]/option[@value="en_cours" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="statut_delai"]/option[@value="hors_delai" and @selected]')->length);
    }

    private function dashboard(string $query = ''): DOMXPath
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN_FONCTIONNEL]);
        $content = $this->actingAs($user)->get('/dashboard'.$query)->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$content);

        return new DOMXPath($document);
    }
}
