<?php

namespace Tests\Feature;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ActionFinancingFormFieldsTest extends TestCase
{
    public function test_secondary_action_sections_are_collapsed_without_losing_inputs(): void
    {
        $html = $this->renderActionBlock();
        $this->assertMatchesRegularExpression('/data-action-secondary-section="resources"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-action-secondary-section="risks"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-action-secondary-section="financing"\s*>/', $html);
        $this->assertStringContainsString('name="actions[0][niveau_risque]"', $html);
        $this->assertStringContainsString('name="actions[0][ressources_details]"', $html);
        $this->assertStringContainsString('name="actions[0][financement_requis]"', $html);
    }

    public function test_secondary_errors_and_required_financing_reveal_the_relevant_section(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag([
            'actions.0.ressources_necessaires.0' => 'Ressource invalide.',
            'actions.0.niveau_risque' => 'Risque invalide.',
        ]));
        $html = $this->renderActionBlock(['financement_requis' => true], $errors);
        foreach (['resources', 'risks', 'financing'] as $section) {
            $this->assertMatchesRegularExpression('/data-action-secondary-section="'.$section.'"\s+open/', $html);
        }
    }

    private function renderActionBlock(array $rowData = [], ?ViewErrorBag $errors = null): string
    {
        return view('workspace.pta.partials.action-form-block', [
            'index' => 0,
            'rowData' => $rowData,
            'responsableOptions' => collect(),
            'errors' => $errors ?? new ViewErrorBag,
        ])->render();
    }

    public function test_financing_nature_field_is_visible_in_pta_action_block(): void
    {
        // Vue standalone workspace.actions.form.blade.php SUPPRIMEE (2026-05-29) :
        // toutes les actions sont creees/editees via le formulaire PTA. Seul le
        // partial action-form-block.blade.php et la JS du formulaire PTA exposent
        // desormais les champs financement.
        $ptaForm = (string) file_get_contents(resource_path('views/workspace/pta/form.blade.php'));
        $ptaActionBlock = (string) file_get_contents(resource_path('views/workspace/pta/partials/action-form-block.blade.php'));

        $this->assertStringContainsString('name="actions[{{ $index }}][nature_financement]"', $ptaActionBlock);
        $this->assertStringContainsString('Nature du financement', $ptaActionBlock);
        $this->assertStringContainsString("field.name.indexOf('[nature_financement]')", $ptaForm);
    }

    public function test_pta_financing_block_only_exposes_validated_fields(): void
    {
        $ptaActionBlock = (string) file_get_contents(resource_path('views/workspace/pta/partials/action-form-block.blade.php'));

        $this->assertStringContainsString('name="actions[{{ $index }}][financement_requis]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][montant_estime]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][nature_financement]"', $ptaActionBlock);
        $this->assertStringContainsString('name="actions[{{ $index }}][justificatif_financement]"', $ptaActionBlock);
        $this->assertStringNotContainsString('name="actions[{{ $index }}][source_financement]"', $ptaActionBlock);
        $this->assertStringNotContainsString('name="actions[{{ $index }}][commentaire_financement]"', $ptaActionBlock);
    }

    public function test_financing_validation_uses_visible_business_labels(): void
    {
        $storeActionRequest = (string) file_get_contents(app_path('Http/Requests/StoreActionRequest.php'));
        $updateActionRequest = (string) file_get_contents(app_path('Http/Requests/UpdateActionRequest.php'));
        $storePtaRequest = (string) file_get_contents(app_path('Http/Requests/StorePtaRequest.php'));
        $updatePtaRequest = (string) file_get_contents(app_path('Http/Requests/UpdatePtaRequest.php'));

        foreach ([$storeActionRequest, $updateActionRequest, $storePtaRequest, $updatePtaRequest] as $requestSource) {
            $this->assertStringContainsString('nature du financement', $requestSource);
            $this->assertStringContainsString('titre de l action', $requestSource);
        }
    }
}
