<?php

namespace Tests\Feature;

use App\Services\Ai\PtaActionParameterizationService;
use Tests\TestCase;

class AiPtaActionParameterizationTest extends TestCase
{
    public function test_simple_report_is_non_quantitative_with_unique_threshold(): void
    {
        $result = app(PtaActionParameterizationService::class)->parameterize([
            'libelle_action' => 'Rediger un rapport',
            'indicateur' => 'Rapport redige',
            'date_debut' => '2026-11-15',
            'date_fin' => '2026-11-30',
        ]);

        $this->assertSame('NQ', $result['type_action']);
        $this->assertSame('unique', $result['seuil_mode']);
        $this->assertSame(0, $result['nombre_sous_actions']);
        $this->assertSame('faible', $result['niveau_risque']);
        $this->assertSame([], $result['validation_warnings']);
    }

    public function test_training_formula_is_quantitative_without_inventing_missing_quantity(): void
    {
        $result = app(PtaActionParameterizationService::class)->parameterize([
            'libelle_action' => 'Organiser les formations retenues',
            'indicateur' => 'Nombre de formations organisees / Nombre de formations retenues x 100',
            'cible' => '100%',
            'date_debut' => '02/03/26',
            'date_fin' => '15/10/26',
        ]);

        $this->assertSame('Q', $result['type_action']);
        $this->assertNull($result['quantite_cible']);
        $this->assertSame('formations', $result['unite_cible']);
        $this->assertSame('trimestriel', $result['seuil_mode']);
        $this->assertSame([25, 50, 75, 100], [
            $result['seuil_t1'],
            $result['seuil_t2'],
            $result['seuil_t3'],
            $result['seuil_t4'],
        ]);
        $this->assertSame('modere', $result['niveau_risque']);
        $this->assertNotEmpty($result['validation_warnings']);
    }

    public function test_glpi_application_is_composite_with_project_thresholds(): void
    {
        $result = app(PtaActionParameterizationService::class)->parameterize([
            'libelle_action' => 'Implementer l application Gestion Libre du Parc Informatique',
            'indicateur' => 'Mise en production',
            'risque' => 'Difficultes techniques lors de l installation',
            'date_debut' => '2026-01-15',
            'date_fin' => '2026-03-30',
        ]);

        $this->assertSame('M', $result['type_action']);
        $this->assertSame('trimestriel', $result['seuil_mode']);
        $this->assertSame([20, 50, 80, 100], [
            $result['seuil_t1'],
            $result['seuil_t2'],
            $result['seuil_t3'],
            $result['seuil_t4'],
        ]);
        $this->assertGreaterThanOrEqual(5, $result['nombre_sous_actions']);
        $this->assertSame('eleve', $result['niveau_risque']);
    }

    public function test_backup_policy_is_composite_and_critical(): void
    {
        $result = app(PtaActionParameterizationService::class)->parameterize([
            'libelle_action' => 'Mettre en place une politique de sauvegarde des donnees',
            'indicateur' => 'Politique de sauvegarde formalisee et validee',
            'risque' => 'Perte de donnees en cas de panne',
            'date_debut' => '2026-01-20',
            'date_fin' => '2026-03-30',
        ]);

        $this->assertSame('M', $result['type_action']);
        $this->assertSame('critique', $result['niveau_risque']);
        $this->assertGreaterThanOrEqual(6, $result['nombre_sous_actions']);
        $this->assertSame(1, $result['commentaire_obligatoire']);
    }
}
