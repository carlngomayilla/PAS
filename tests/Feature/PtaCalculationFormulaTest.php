<?php

namespace Tests\Feature;

use App\Services\Ai\ActionReportMetricsBuilder;
use App\Services\PtaSuiviService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class PtaCalculationFormulaTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_pta_advancement_and_due_execution_use_distinct_denominators(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');
        $fixture = $this->createReportFixture();
        $futureCompleted = $fixture['action'];
        $futureCompleted->forceFill([
            'date_fin' => '2026-12-31',
            'statut' => 'termine',
            'statut_dynamique' => 'cloturee',
            'statut_validation' => 'validee_controle',
        ])->save();

        $dueCompleted = $futureCompleted->replicate();
        $dueCompleted->forceFill([
            'code' => 'ACT-FORMULE-002',
            'date_fin' => '2026-05-31',
            'statut' => 'termine',
            'statut_dynamique' => 'cloturee',
            'statut_validation' => 'validee_controle',
        ])->save();

        $dueIncomplete = $futureCompleted->replicate();
        $dueIncomplete->forceFill([
            'code' => 'ACT-FORMULE-003',
            'date_fin' => '2026-04-30',
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'statut_validation' => 'non_soumise',
            'progression_reelle' => 50,
        ])->save();

        $metrics = app(ActionReportMetricsBuilder::class)->build('pta');
        $summary = $metrics['pta_analyse']['synthese'];

        $this->assertSame(3, $summary['actions_prevues']);
        $this->assertSame(2, $summary['actions_echues']);
        $this->assertSame(2, $summary['actions_realisees']);
        $this->assertSame(1, $summary['actions_echues_realisees']);
        $this->assertSame(50.0, $summary['taux_realisation']);
        $this->assertSame(66.67, $summary['taux_global_avancement']);

        $comparison = $metrics['pta_analyse']['comparaison_indicateurs'];
        $this->assertSame('Actions réalisées / actions prévues × 100', $comparison[0]['formule']);
        $this->assertSame('Actions échues réalisées / actions échues × 100', $comparison[1]['formule']);
    }

    public function test_pta_rates_are_zero_when_no_action_is_due(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        $this->createReportFixture();

        $metrics = app(ActionReportMetricsBuilder::class)->build('pta');
        $summary = $metrics['pta_analyse']['synthese'];

        $this->assertSame(0, $summary['actions_echues']);
        $this->assertSame(0, $summary['actions_echues_realisees']);
        $this->assertSame(0.0, $summary['taux_realisation']);
        $this->assertSame(0.0, $summary['taux_global_avancement']);
    }

    /**
     * Regle metier du 2026-08-04 : chaque enfant pese autant que les autres et
     * sa cible de performance vaut 100 %. Le taux d'un niveau est donc la
     * moyenne des taux de ses enfants.
     *
     * OP1 = 50/100 = 50 %, OP2 = 300/300 = 100 % -> OS = (50 + 100) / 2 = 75 %.
     * L'ancienne ponderation par la cible aurait donne 350/400 = 87,5 %.
     */
    public function test_hierarchy_averages_child_rates_at_every_level(): void
    {
        $rows = collect([
            $this->hierarchyRow('op-1', 100, 50, 1),
            $this->hierarchyRow('op-2', 300, 300, 2),
        ]);

        $pas = app(PtaSuiviService::class)->groupRows($rows)->first();
        $axis = $pas['axes']->first();
        $strategicObjective = $axis['objectifs']->first();
        $operationalObjectives = $strategicObjective['objectifs_operationnels'];

        $this->assertSame(50.0, $operationalObjectives[0]['performance']);
        $this->assertSame(100.0, $operationalObjectives[1]['performance']);
        $this->assertSame(75.0, $strategicObjective['performance']);
        $this->assertSame(75.0, $axis['performance']);
        $this->assertSame(75.0, $pas['performance']);

        // Les cumuls en unites restent disponibles a titre informatif.
        $this->assertSame(400.0, $axis['cible_cumulee']);
        $this->assertSame(350.0, $axis['realisation_cumulee']);
    }

    /** @return array<string, mixed> */
    private function hierarchyRow(string $operationalObjective, float $target, float $realized, int $order): array
    {
        return [
            'pas_key' => 'pas-1',
            'pas_code' => 'PAS-1',
            'pas_label' => 'PAS test',
            'axe_key' => 'axe-1',
            'axe_label' => 'Axe test',
            'objectif_strategique_key' => 'os-1',
            'objectif_strategique_label' => 'Objectif strategique test',
            'objectif_operationnel_key' => $operationalObjective,
            'objectif_operationnel_label' => 'Objectif operationnel '.$operationalObjective,
            'calcul_cible' => $target,
            'calcul_realise' => $realized,
            'calcul_configured' => true,
            'ordre' => $order,
        ];
    }
}
