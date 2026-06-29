<?php

namespace Tests\Feature;

use App\Services\Ai\PtaAgentResolverService;
use App\Services\Ai\PtaDocumentStructureExtractorService;
use App\Services\Ai\PtaDocumentToImportGlobalMapperService;
use App\Services\Ai\PtaImportTemplateAnalyzerService;
use App\Services\Imports\PlanningExcelImportService;
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
        $this->assertSame(PlanningExcelImportService::IMPORT_COLUMNS, $template['columns']);
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

    public function test_plain_text_pta_table_extracts_hierarchy_and_maps_to_import_global(): void
    {
        $text = implode("\n", [
            'PLAN DE TRAVAIL ANNUEL 2026',
            'Direction: Direction SI',
            'Service: Service Applications',
            'AXE STRATEGIQUE 2',
            'REDRESSEMENT DE LA SITUATION FINANCIERE',
            'OBJECTIF STRATEGIQUE 1',
            'Rationaliser la depense de bourse',
            'OBJECTIF OPERATIONNEL N 1',
            'Detruire les archives perimees',
            'DESCRIPTION DES ACTIONS DETAILLEES | RMO | CIBLE | DEBUT | FIN | ETAT DE REALISATION | RESSOURCES REQUISES | INDICATEURS DE PERFORMANCE | RISQUES POTENTIELS',
            'Selectionner les documents perimes | DG-006 | 100% | 02/03/26 | 13/03/26 | Non demarre | Personnel archives | Objectifs definis | Detruire les documents non perimes',
        ]);

        $structured = app(PtaDocumentStructureExtractorService::class)->extractFromText($text);

        $this->assertSame('PTA', $structured['document']['type_document']);
        $this->assertSame(2026, $structured['document']['annee_debut_pas']);
        $this->assertCount(1, $structured['items']);
        $this->assertSame(2, $structured['items'][0]['ordre_axe']);
        $this->assertSame('REDRESSEMENT DE LA SITUATION FINANCIERE', $structured['items'][0]['libelle_axe']);
        $this->assertSame('Detruire les archives perimees', $structured['items'][0]['libelle_objectif_operationnel']);

        $result = app(PtaDocumentToImportGlobalMapperService::class)->map($structured);

        $this->assertSame(1, $result['valid']);
        $this->assertSame(2, $result['rows'][0]['ordre_axe']);
        $this->assertSame('Direction SI', $result['rows'][0]['direction']);
        $this->assertSame('Service Applications', $result['rows'][0]['service_unite']);
        $this->assertSame('Selectionner les documents perimes', $result['rows'][0]['libelle_action']);
        $this->assertSame('2026-03-02', $result['rows'][0]['date_debut_action']);
    }

    public function test_ocr_box_table_extracts_full_import_columns(): void
    {
        $text = implode("\n", [
            'PLAN DE TRAVAIL ANNUEL 2026',
            '@@OCR_BOX|1|10|10|AXE STRATEGIQUE',
            '@@OCR_BOX|1|10|40|REDRESSEMENT DE LA SITUATION FINANCIERE',
            '@@OCR_BOX|1|10|80|OBJECTIF STRATEGIQUE',
            '@@OCR_BOX|1|10|110|Rationaliser la depense de bourse',
            '@@OCR_BOX|1|10|150|OBJECTIF OPERATIONNEL N 1',
            '@@OCR_BOX|1|10|180|Detruire les archives perimees',
            '@@OCR_BOX|1|10|230|DESCRIPTION DES ACTIONS',
            '@@OCR_BOX|1|200|230|RMO',
            '@@OCR_BOX|1|310|230|CIBLE',
            '@@OCR_BOX|1|430|230|DEBUT',
            '@@OCR_BOX|1|560|230|FIN',
            '@@OCR_BOX|1|700|230|ETAT DE REALISATION',
            '@@OCR_BOX|1|900|230|RESSOURCES',
            '@@OCR_BOX|1|1120|230|INDICATEURS DE PERFORMANCE',
            '@@OCR_BOX|1|1420|230|RISQUES POTENTIELS',
            '@@OCR_BOX|1|10|320|Selectionner les documents',
            '@@OCR_BOX|1|10|350|perimes',
            '@@OCR_BOX|1|200|320|Clovis',
            '@@OCR_BOX|1|200|350|/Slash',
            '@@OCR_BOX|1|310|320|100%',
            '@@OCR_BOX|1|430|320|02/03/26',
            '@@OCR_BOX|1|560|320|13/03/26',
            '@@OCR_BOX|1|700|320|Non demarre',
            '@@OCR_BOX|1|900|320|Personnel archives',
            '@@OCR_BOX|1|1120|320|Objectifs definis',
            '@@OCR_BOX|1|1420|320|Documents non perimes',
        ]);

        $structured = app(PtaDocumentStructureExtractorService::class)->extractFromText($text);

        $this->assertCount(1, $structured['items']);
        $item = $structured['items'][0];
        $this->assertSame('REDRESSEMENT DE LA SITUATION FINANCIERE', $item['libelle_axe']);
        $this->assertSame('Rationaliser la depense de bourse', $item['libelle_objectif_strategique']);
        $this->assertSame('Detruire les archives perimees', $item['libelle_objectif_operationnel']);
        $this->assertSame('Selectionner les documents perimes', $item['libelle_action']);
        $this->assertSame('Clovis /Slash', $item['rmo_raw']);
        $this->assertSame('100', $item['cible']);
        $this->assertSame('02/03/26', $item['date_debut_action']);
        $this->assertSame('13/03/26', $item['date_fin_action']);
        $this->assertSame('Personnel archives', $item['ressources_requises']);
        $this->assertSame('Objectifs definis', $item['indicateurs_performance']);
        $this->assertSame('Documents non perimes', $item['risques_potentiels']);

        $result = app(PtaDocumentToImportGlobalMapperService::class)->map($structured);

        $this->assertSame(PlanningExcelImportService::IMPORT_COLUMNS, $result['columns']);
        $this->assertSame('2026-03-02', $result['rows'][0]['date_debut_action']);
        $this->assertSame('2026-03-13', $result['rows'][0]['date_fin_action']);
        $this->assertSame('Objectifs definis', $result['rows'][0]['justificatif_attendu']);
        $this->assertSame('Documents non perimes', $result['rows'][0]['risque']);
    }
}
