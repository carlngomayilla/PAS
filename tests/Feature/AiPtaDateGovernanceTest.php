<?php

namespace Tests\Feature;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\SousAction;
use App\Services\Ai\PtaFinalImportService;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPtaDateGovernanceTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_ai_reimport_preserves_existing_dates_and_sub_actions(): void
    {
        $fixture = $this->createReportFixture();
        $actor = $this->createAiUser();
        $action = $fixture['action'];
        $action->forceFill([
            'date_debut' => '2026-01-10',
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'echeance_cible' => '2026-06-30',
            'responsable_id' => $actor->id,
        ])->save();
        $subAction = $action->sousActions()->create([
            'agent_id' => $actor->id,
            'libelle' => 'Sous-action existante',
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'date_debut' => '2026-01-10',
            'date_fin' => '2026-05-31',
            'statut' => 'non_demarre',
        ]);

        $batch = AiImportBatch::query()->create([
            'user_id' => $actor->id,
            'original_filename' => 'reimport-pta.xlsx',
            'file_path' => 'ai-imports/pta/reimport-pta.xlsx',
            'file_type' => 'xlsx',
            'status' => AiImportBatch::STATUS_MAPPED,
            'detected_year' => 2026,
            'detected_direction' => $fixture['direction']->libelle,
            'detected_service' => $fixture['service']->libelle,
        ]);
        AiImportRow::query()->create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_payload' => [],
            'normalized_payload' => array_merge(array_fill_keys(PtaNormalizationService::FIELDS, null), [
                'pta_id' => $fixture['pta']->id,
                'code_action' => $action->code,
                'exercice' => '2026',
                'libelle_action' => 'Action corrigee par import IA',
                'direction' => $fixture['direction']->libelle,
                'service' => $fixture['service']->libelle,
                'date_debut' => '2026-03-01',
                'date_fin' => '2026-11-30',
                'echeance' => '2026-12-15',
                'statut_initial' => 'non_demarre',
                'type_action' => 'M',
                'sous_actions' => 'Nouvelle sous-action proposee',
                'seuil_mode' => 'unique',
            ]),
            'validation_errors' => null,
            'status' => AiImportRow::STATUS_VALID,
        ]);

        $result = app(PtaFinalImportService::class)->import($batch, $actor);

        $action->refresh();
        $subAction->refresh();

        $this->assertSame(['imported' => 1, 'ignored' => 0], $result);
        $this->assertSame('Action corrigee par import IA', $action->libelle);
        $this->assertSame('2026-01-10', $action->date_debut->toDateString());
        $this->assertSame('2026-06-30', $action->date_fin->toDateString());
        $this->assertSame('2026-06-30', $action->date_echeance->toDateString());
        $this->assertSame('2026-06-30', $action->echeance_cible->toDateString());
        $this->assertDatabaseCount('sous_actions', 1);
        $this->assertSame('Sous-action existante', $subAction->libelle);
        $this->assertSame('2026-05-31', $subAction->date_fin->toDateString());
    }
}
