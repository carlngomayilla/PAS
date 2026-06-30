<?php

namespace Tests\Feature;

use App\Services\Ai\PtaDocumentToImportGlobalMapperService;
use Tests\TestCase;

class AiPtaImportGlobalMappingTest extends TestCase
{
    public function test_document_terms_are_mapped_to_official_import_global_columns(): void
    {
        $mapped = app(PtaDocumentToImportGlobalMapperService::class)->map([
            'document' => [
                'annee' => 2026,
            ],
            'items' => [[
                'axe' => 'Gouvernance institutionnelle',
                'objectif_strategique_pas' => 'Ameliorer le pilotage',
                'objectif_operationnel_pao' => 'Digitaliser le suivi PTA',
                'action_pta' => 'Developper le module de suivi PTA',
                'direction_responsable' => 'Direction SI',
                'service_responsable' => 'Service Applications',
                'indicateur' => 'Module disponible',
                'cible' => '100%',
                'date_debut' => '2026-01-01',
                'date_fin' => '2026-03-31',
                'budget' => 150000,
                'risque' => 'Retard de validation',
                'ressources_techniques' => 'Serveur applicatif',
            ]],
        ]);

        $row = $mapped['rows'][0];

        $this->assertSame('Gouvernance institutionnelle', $row['libelle_axe']);
        $this->assertSame('Ameliorer le pilotage', $row['libelle_objectif_strategique']);
        $this->assertSame('Digitaliser le suivi PTA', $row['libelle_objectif_operationnel']);
        $this->assertSame('Developper le module de suivi PTA', $row['libelle_action']);
        $this->assertSame('Direction SI', $row['direction']);
        $this->assertSame('Service Applications', $row['service_unite']);
        $this->assertSame('Module disponible', $row['justificatif_attendu']);
        $this->assertSame(100.0, $row['cible_minimum_execution']);
        $this->assertSame('2026-01-01', $row['date_debut_action']);
        $this->assertSame('2026-03-31', $row['date_fin_action']);
        $this->assertSame(150000, $row['montant_financement']);
        $this->assertSame('Serveur applicatif', $row['ressources_materielles']);
        $this->assertSame(1, $mapped['valid']);
    }
}
