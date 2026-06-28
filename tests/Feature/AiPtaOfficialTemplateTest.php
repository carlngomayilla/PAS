<?php

namespace Tests\Feature;

use App\Services\Ai\PtaAgentResolverService;
use App\Services\Ai\PtaDocumentToImportGlobalMapperService;
use App\Services\Ai\PtaImportTemplateAnalyzerService;
use Tests\TestCase;

class AiPtaOfficialTemplateTest extends TestCase
{
    public function test_official_import_global_template_is_read(): void
    {
        $template = app(PtaImportTemplateAnalyzerService::class)->analyze();

        $this->assertContains('annee_debut_pas', $template['columns']);
        $this->assertContains('codes_agents_rmo', $template['columns']);
        $this->assertContains('champ_difficulte', $template['columns']);
        $this->assertCount(40, $template['columns']);
        $this->assertNotEmpty($template['guide']);
    }

    public function test_agent_reference_accepts_only_known_import_codes(): void
    {
        $resolver = app(PtaAgentResolverService::class);

        $result = $resolver->verifyCodes('DG-006;INCONNU-999');

        $this->assertSame(['DG-006'], $result['valid']);
        $this->assertSame(['INCONNU-999'], $result['invalid']);
    }

    public function test_structured_pta_document_maps_to_import_global_rows(): void
    {
        $result = app(PtaDocumentToImportGlobalMapperService::class)->map([
            'document' => [
                'type_document' => 'PTA',
                'annee_debut_pas' => 2026,
                'annee_fin_pas' => 2028,
                'direction' => 'Cabinet du DG',
                'service_unite' => 'Collaborateurs',
            ],
            'items' => [[
                'ordre_axe' => 2,
                'libelle_axe' => 'REDRESSEMENT DE LA SITUATION FINANCIERE',
                'ordre_objectif_strategique' => 1,
                'libelle_objectif_strategique' => 'Rationaliser la depense de bourse',
                'ordre_objectif_operationnel' => 1,
                'libelle_objectif_operationnel' => 'Detruire les archives perimees',
                'ordre_action' => 1,
                'libelle_action' => 'Selectionner les documents perimes',
                'rmo_raw' => 'DG-006',
                'cible' => '100%',
                'date_debut_action' => '02/03/26',
                'date_fin_action' => '13/03/26',
                'indicateurs_performance' => 'Objectifs definis',
            ]],
        ]);

        $this->assertSame(1, $result['valid']);
        $this->assertSame(0, $result['invalid']);
        $this->assertCount(40, $result['columns']);
        $this->assertSame('DG-006', $result['rows'][0]['codes_agents_rmo']);
        $this->assertSame('2026-03-02', $result['rows'][0]['date_debut_action']);
        $this->assertSame('2026-03-13', $result['rows'][0]['date_fin_action']);
        $this->assertSame('NQ', $result['rows'][0]['type_action']);
        $this->assertSame('unique', $result['rows'][0]['seuil_mode']);
    }
}
