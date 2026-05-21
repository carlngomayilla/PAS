<?php

namespace Tests\Feature;

use App\Models\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre A23 (Phase 3, version defensive — refacto suppression colonnes
 * legacy reporte). Sentinelle de coherence entre les colonnes `ressources_*`
 * legacy booleennes et la source canonique `ressources_necessaires` (array).
 */
class Phase3DLegacyColumnsCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a23_canonical_resources_falls_back_to_legacy_booleans(): void
    {
        $action = new Action();
        $action->forceFill([
            'ressources_necessaires' => null,
            'ressource_main_oeuvre' => true,
            'ressource_partenariat' => true,
        ]);

        $canonical = $action->canonicalResources();

        $this->assertContains('main_oeuvre', $canonical);
        $this->assertContains('partenariat', $canonical);
    }

    public function test_a23_canonical_resources_prefers_array_over_booleans(): void
    {
        $action = new Action();
        $action->forceFill([
            'ressources_necessaires' => ['ressources_humaines', 'ressources_informatiques'],
            // Booleen legacy contradictoire : doit etre IGNORE au profit du JSON.
            'ressource_main_oeuvre' => true,
        ]);

        $canonical = $action->canonicalResources();

        $this->assertEqualsCanonicalizing(
            ['ressources_humaines', 'ressources_informatiques'],
            $canonical,
            'A23 — La source canonique (ressources_necessaires) doit primer sur les booleens legacy.'
        );
    }

    public function test_a23_resource_labels_match_options(): void
    {
        $action = new Action();
        $action->forceFill([
            'ressources_necessaires' => ['ressources_humaines'],
        ]);

        $labels = $action->resourceLabels();
        $options = Action::resourceOptions();

        $this->assertSame([$options['ressources_humaines']], $labels);
    }
}
