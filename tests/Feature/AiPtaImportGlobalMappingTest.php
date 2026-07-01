<?php

namespace Tests\Feature;

use App\Services\Ai\PtaDocumentToImportGlobalMapperService;
use App\Services\Ai\PtaImportTemplateAnalyzerService;
use App\Services\Imports\PlanningExcelImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public function test_ai_rows_and_log_are_merged_for_scanned_pdf_control_metadata(): void
    {
        $mapped = app(PtaDocumentToImportGlobalMapperService::class)->map([
            'document' => [
                'annee_debut_pas' => 2026,
                'annee_fin_pas' => 2028,
            ],
            'rows' => [[
                'ligne_import' => 1,
                'ordre_axe' => 2,
                'libelle_axe' => 'REDRESSEMENT DE LA SITUATION FINANCIERE',
                'ordre_objectif_strategique' => 1,
                'libelle_objectif_strategique' => 'Rationaliser la depense de bourse',
                'direction' => 'Cabinet du DG',
                'service_unite' => 'Collaborateurs',
                'ordre_objectif_operationnel' => 1,
                'libelle_objectif_operationnel' => 'Detruire les archives perimees',
                'ordre_action' => 1,
                'libelle_action' => 'Selectionner les documents perimes',
                'date_debut_action' => '02/03/26',
                'date_fin_action' => '13/03/26',
                'codes_agents_rmo' => 'DG-006',
                'cible_minimum_execution' => '100%',
                'justificatif_attendu' => 'Objectifs definis',
                'type_action' => 'NQ',
                'seuil_mode' => 'unique',
                'page_pdf' => null,
                'score_confiance_ia' => null,
                'note_normalisation' => null,
            ]],
            'log' => [[
                'ligne_import' => 1,
                'page_pdf' => 8,
                'score_confiance_ia' => 0.62,
                'note_normalisation' => 'Date normalisee depuis OCR peu lisible',
                'etat_pdf' => 'Non demarre',
            ]],
        ]);

        $row = $mapped['rows'][0];

        $this->assertSame(1, $mapped['valid']);
        $this->assertSame(8, $row['page_pdf']);
        $this->assertSame(0.62, $row['score_confiance_ia']);
        $this->assertSame(0.62, $row['confidence_score']);
        $this->assertSame('Date normalisee depuis OCR peu lisible', $row['note_normalisation']);
        $this->assertSame('Non demarre', $row['etat_pdf']);
        $this->assertSame('Non demarre', $row['etat_realisation_initial']);
        $this->assertSame('1', $row['commentaire_obligatoire']);
        $this->assertSame('1', $row['champ_difficulte']);
    }

    public function test_enriched_import_workbook_training_sheets_are_read(): void
    {
        $spreadsheet = new Spreadsheet;
        $importSheet = $spreadsheet->getActiveSheet();
        $importSheet->setTitle('IMPORT_GLOBAL');
        $importSheet->fromArray([PlanningExcelImportService::IMPORT_COLUMNS]);

        $guideSheet = $spreadsheet->createSheet();
        $guideSheet->setTitle('GUIDE');
        $guideSheet->fromArray([
            ['colonne', 'description'],
            ['libelle_action', 'Action PTA executable'],
        ]);

        $logSheet = $spreadsheet->createSheet();
        $logSheet->setTitle('LOG_EXTRACTION');
        $logSheet->fromArray([
            ['ligne_import', 'page_pdf', 'score_confiance_ia', 'note_normalisation'],
            [1, 8, 0.82, 'Extraction OCR + structuration IA'],
        ]);

        $promptSheet = $spreadsheet->createSheet();
        $promptSheet->setTitle('PROMPT_IA');
        $promptSheet->fromArray([
            ['section', 'contenu'],
            ['role', 'Tu es une IA specialisee PAS PAO PTA'],
            ['sortie', 'Retourne rows[] et log[]'],
        ]);

        $pipelineSheet = $spreadsheet->createSheet();
        $pipelineSheet->setTitle('PIPELINE_OUTILS');
        $pipelineSheet->fromArray([
            ['Etape', 'Outil Laravel / serveur', 'Role', 'Sortie attendue'],
            ['OCR', 'Tesseract', 'Lire le PDF scanne', 'Texte structure'],
        ]);

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('SYNTHESE_IMPORT');
        $summarySheet->fromArray([
            ['Cle', 'Valeur'],
            ['Nombre actions', 60],
        ]);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pta-template-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $template = app(PtaImportTemplateAnalyzerService::class)->analyze($path);

            $this->assertSame(PlanningExcelImportService::IMPORT_COLUMNS, $template['columns']);
            $this->assertSame(8, $template['training']['log_extraction'][0]['page_pdf']);
            $this->assertStringContainsString('rows[] et log[]', $template['training']['prompt_ia']);
            $this->assertSame('Tesseract', $template['training']['pipeline_outils'][0]['Outil Laravel / serveur']);
            $this->assertSame(60, $template['training']['synthese_import'][0]['Valeur']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
