<?php

namespace Tests\Feature;

use App\Support\UiLabel;
use Tests\TestCase;

class ActionWorkflowCircuitUiTest extends TestCase
{
    public function test_action_detail_stepper_declares_the_new_business_order(): void
    {
        $source = (string) file_get_contents(resource_path('views/workspace/actions/suivi.blade.php'));

        $labels = [
            "'label' => 'Agent'",
            "'label' => 'Chef'",
            "'label' => 'Planification'",
            "'label' => 'SCIQ'",
            "'label' => 'Action achevée'",
        ];
        $positions = array_map(static fn (string $label): int|false => strpos($source, $label), $labels);

        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertTrue($positions[0] < $positions[1]);
        $this->assertTrue($positions[1] < $positions[2]);
        $this->assertTrue($positions[2] < $positions[3]);
        $this->assertTrue($positions[3] < $positions[4]);
        $this->assertStringNotContainsString('Agent -> Chef de service -> Controle SCIQ -> Planification', $source);
    }

    public function test_new_workflow_statuses_have_operational_labels(): void
    {
        $this->assertSame('En attente du Chef', UiLabel::validationStatus('attente_validation_chef'));
        $this->assertSame('En attente de la Planification', UiLabel::validationStatus('attente_validation_planification'));
        $this->assertSame('En attente du SCIQ', UiLabel::validationStatus('attente_validation_sciq'));
        $this->assertSame('Achevée et validée', UiLabel::validationStatus('achevee_validee'));
    }
}
