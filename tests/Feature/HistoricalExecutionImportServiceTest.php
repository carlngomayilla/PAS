<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlanningImport;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Imports\HistoricalExecutionImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HistoricalExecutionImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preserves_historical_dates_progress_and_recalculates_kpi(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row($fixture['action']->code, [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '2026-01-05',
                'date_fin_reelle' => '2026-03-28',
                'progression_reelle' => 100,
                'quantite_realisee' => 100,
                'commentaire_historique' => 'PV du premier trimestre a joindre.',
            ]),
        ]));

        $this->assertFalse($preview['has_errors']);
        $import = $this->createImport($fixture['user'], $preview, 1);
        $service->execute($import, $fixture['user'], '127.0.0.1');
        $action = $fixture['action']->fresh(['actionKpi']);

        $this->assertSame('2026-01-05', $action->date_debut_reelle?->toDateString());
        $this->assertSame('2026-03-28', $action->date_fin_reelle?->toDateString());
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertSame('100.0000', (string) $action->quantite_realisee);
        $this->assertSame(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $action->statut_dynamique);
        $this->assertNotNull($action->historical_execution_recorded_at);
        $this->assertSame(100.0, (float) $action->actionKpi?->progression_reelle);
        $this->assertDatabaseHas('journal_audit', [
            'module' => HistoricalExecutionImportService::MODULE,
            'entite_id' => $action->id,
            'action' => 'historical_execution_import',
        ]);
    }

    public function test_import_accepts_non_executed_in_progress_and_completed_statuses(): void
    {
        $fixture = $this->fixture();
        $inProgress = $this->createAction($fixture, 'ACT-IN-PROGRESS', Action::TYPE_QUANTITATIVE);
        $notStarted = $this->createAction($fixture, 'ACT-NOT-STARTED', Action::TYPE_NON_QUANTITATIVE);
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row($fixture['action']->code, [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '2026-01-05',
                'date_fin_reelle' => '2026-03-28',
                'progression_reelle' => 100,
                'quantite_realisee' => 100,
                'commentaire_historique' => 'Action achevee et verifiee.',
            ]),
            $this->row($inProgress->code, [
                'statut_execution' => 'en_cours',
                'date_debut_reelle' => '2026-02-02',
                'progression_reelle' => 45,
                'quantite_realisee' => 45,
                'commentaire_historique' => 'Action demarree au premier trimestre.',
            ]),
            $this->row($notStarted->code, [
                'statut_execution' => 'non_executee',
                'progression_reelle' => 0,
                'commentaire_historique' => 'Action non demarree au premier trimestre.',
            ]),
        ]));

        $this->assertFalse($preview['has_errors']);
        $import = $this->createImport($fixture['user'], $preview, 3);
        $service->execute($import, $fixture['user']);

        $this->assertSame(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $fixture['action']->fresh()->statut_dynamique);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $inProgress->fresh()->statut_dynamique);
        $this->assertSame(ActionTrackingService::STATUS_NON_DEMARRE, $notStarted->fresh()->statut_dynamique);
        $this->assertSame('45.00', (string) $inProgress->fresh()->progression_reelle);
        $this->assertNull($notStarted->fresh()->date_debut_reelle);
    }

    public function test_import_accepts_completed_action_without_dates_without_inventing_delay_status(): void
    {
        $fixture = $this->fixture();
        $action = $this->createAction($fixture, 'ACT-COMPLETED-WITHOUT-DATES', Action::TYPE_NON_QUANTITATIVE);
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row($action->code, [
                'statut_execution' => 'achevee',
                'progression_reelle' => 100,
                'commentaire_historique' => 'Action achevee selon le PTA, dates reelles non renseignees.',
            ]),
        ]));

        $this->assertFalse($preview['has_errors']);

        $import = $this->createImport($fixture['user'], $preview, 1);
        $service->execute($import, $fixture['user']);
        $action = $action->fresh(['actionKpi']);

        $this->assertNull($action->date_debut_reelle);
        $this->assertNull($action->date_fin_reelle);
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertSame(ActionTrackingService::STATUS_ACHEVE, $action->statut_dynamique);
        $this->assertSame(ActionTrackingService::STATUS_ACHEVE, $action->actionKpi?->statut_calcule);
        $this->assertContains(ActionTrackingService::STATUS_ACHEVE, ActionTrackingService::completedActionStatuses());
    }

    public function test_import_accepts_in_progress_action_without_start_date(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row($fixture['action']->code, [
                'statut_execution' => 'en_cours',
                'progression_reelle' => 45,
                'quantite_realisee' => 45,
                'commentaire_historique' => 'Action en cours selon le PTA, date de debut non renseignee.',
            ]),
        ]));

        $this->assertFalse($preview['has_errors']);

        $import = $this->createImport($fixture['user'], $preview, 1);
        $service->execute($import, $fixture['user']);
        $action = $fixture['action']->fresh();

        $this->assertNull($action->date_debut_reelle);
        $this->assertNull($action->date_fin_reelle);
        $this->assertSame('45.00', (string) $action->progression_reelle);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut_dynamique);
    }

    public function test_status_specific_rules_remain_enforced_when_dates_are_optional(): void
    {
        $fixture = $this->fixture();
        $zeroProgress = $this->createAction($fixture, 'ACT-IN-PROGRESS-ZERO', Action::TYPE_NON_QUANTITATIVE);
        $completedProgress = $this->createAction($fixture, 'ACT-IN-PROGRESS-COMPLETE', Action::TYPE_NON_QUANTITATIVE);
        $endDated = $this->createAction($fixture, 'ACT-IN-PROGRESS-END-DATED', Action::TYPE_NON_QUANTITATIVE);
        $missingQuantity = $this->createAction($fixture, 'ACT-MISSING-QUANTITY', Action::TYPE_QUANTITATIVE);
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row($zeroProgress->code, [
                'statut_execution' => 'en_cours',
                'progression_reelle' => 0,
            ]),
            $this->row($completedProgress->code, [
                'statut_execution' => 'en_cours',
                'progression_reelle' => 100,
            ]),
            $this->row($endDated->code, [
                'statut_execution' => 'en_cours',
                'date_fin_reelle' => '2026-03-01',
                'progression_reelle' => 40,
            ]),
            $this->row($missingQuantity->code, [
                'statut_execution' => 'achevee',
                'progression_reelle' => 100,
            ]),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('strictement comprise entre 0 et 100', $preview['rows'][0]['message']);
        $this->assertStringContainsString('strictement comprise entre 0 et 100', $preview['rows'][1]['message']);
        $this->assertStringContainsString('ne doit pas avoir de date_fin_reelle', $preview['rows'][2]['message']);
        $this->assertStringContainsString('quantite_realisee est obligatoire', $preview['rows'][3]['message']);
    }

    public function test_preview_rejects_unknown_duplicate_future_and_incomplete_rows(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalExecutionImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row('UNKNOWN-ACTION', [
                'statut_execution' => 'en_cours',
                'date_debut_reelle' => '2027-01-01',
                'progression_reelle' => 20,
                'commentaire_historique' => 'Ligne invalide a corriger.',
            ]),
            $this->row($fixture['action']->code, [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '2026-03-28',
                'date_fin_reelle' => '2026-03-01',
                'progression_reelle' => 50,
                'commentaire_historique' => 'Dates incoherentes.',
            ]),
            $this->row($fixture['action']->code, [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '2026-01-01',
                'date_fin_reelle' => '2026-03-01',
                'progression_reelle' => 100,
                'quantite_realisee' => 100,
                'commentaire_historique' => 'Doublon volontaire.',
            ]),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('Action introuvable', $preview['rows'][0]['message']);
        $this->assertStringContainsString('futur', $preview['rows'][0]['message']);
        $this->assertStringContainsString('present plusieurs fois', $preview['rows'][2]['message']);
        $this->assertStringContainsString('100', $preview['rows'][1]['message']);
    }

    public function test_only_sciq_planning_and_their_chiefs_can_import(): void
    {
        $service = app(HistoricalExecutionImportService::class);

        foreach ([
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
        ] as $role) {
            $this->assertTrue($service->canImport(User::factory()->create(['role' => $role])));
        }

        $this->assertFalse($service->canImport(User::factory()->create(['role' => User::ROLE_AGENT])));
        $this->assertFalse($service->canImport(User::factory()->create(['role' => User::ROLE_SERVICE])));
    }

    public function test_historical_import_screen_is_forbidden_for_agents_and_accepts_csv_preview_for_planning(): void
    {
        $fixture = $this->fixture();
        $csv = implode(';', HistoricalExecutionImportService::COLUMNS)."\n"
            .implode(';', [$fixture['action']->code, 'achevee', '2026-01-05', '2026-03-28', '100', '100', 'PV historique a joindre'])."\n";
        $file = UploadedFile::fake()->createWithContent('reprise.csv', $csv);

        $this->actingAs($fixture['action']->responsable)
            ->get(route('workspace.imports.historical.index'))
            ->assertForbidden();

        $response = $this->actingAs($fixture['user'])
            ->post(route('workspace.imports.historical.preview'), ['file' => $file]);

        $response->assertRedirect();
        $import = PlanningImport::query()->where('module', HistoricalExecutionImportService::MODULE)->firstOrFail();
        $this->assertSame('preview_ready', $import->status);
        $this->assertSame(route('workspace.imports.historical.show', $import), $response->headers->get('Location'));
    }

    public function test_planning_import_history_does_not_mix_with_historical_reprises(): void
    {
        $fixture = $this->fixture();
        PlanningImport::query()->create([
            'user_id' => $fixture['user']->id,
            'filename' => 'reprise-a-ne-pas-melanger.xlsx',
            'module' => HistoricalExecutionImportService::MODULE,
            'mode' => HistoricalExecutionImportService::MODE,
            'status' => 'preview_ready',
        ]);

        $this->actingAs($fixture['user'])
            ->get(route('workspace.imports.index'))
            ->assertOk()
            ->assertDontSee('reprise-a-ne-pas-melanger.xlsx', false)
            ->assertSee('Reprise des executions', false);
    }

    public function test_preview_screen_exposes_file_level_format_errors(): void
    {
        $fixture = $this->fixture();
        $import = PlanningImport::query()->create([
            'user_id' => $fixture['user']->id,
            'filename' => 'format-invalide.xlsx',
            'module' => HistoricalExecutionImportService::MODULE,
            'mode' => HistoricalExecutionImportService::MODE,
            'status' => 'preview_errors',
            'preview_payload' => [
                'global_errors' => ['La feuille Excel doit etre nommee IMPORT_EXECUTION.'],
                'rows' => [],
            ],
        ]);

        $this->actingAs($fixture['user'])
            ->get(route('workspace.imports.historical.show', $import))
            ->assertOk()
            ->assertSee('Le fichier ne respecte pas le format attendu', false)
            ->assertSee('IMPORT_EXECUTION', false);
    }

    public function test_prefilled_template_download_is_available_for_existing_actions(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])
            ->get(route('workspace.imports.historical.template', ['prefill' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>}
     */
    private function sheet(array $rows): array
    {
        return [
            'sheet_count' => 2,
            'sheet_name' => HistoricalExecutionImportService::SHEET_NAME,
            'headers' => HistoricalExecutionImportService::COLUMNS,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function row(string $code, array $overrides = []): array
    {
        return array_merge([
            '_row_number' => 2,
            'code_action' => $code,
            'statut_execution' => 'non_executee',
            'date_debut_reelle' => '',
            'date_fin_reelle' => '',
            'progression_reelle' => 0,
            'quantite_realisee' => '',
            'commentaire_historique' => 'Commentaire historique de reprise.',
        ], $overrides);
    }

    private function createImport(User $user, array $preview, int $rowCount): PlanningImport
    {
        return PlanningImport::query()->create([
            'user_id' => $user->id,
            'filename' => 'reprise.xlsx',
            'module' => HistoricalExecutionImportService::MODULE,
            'mode' => HistoricalExecutionImportService::MODE,
            'total_rows' => $rowCount,
            'valid_rows' => $rowCount,
            'error_rows' => 0,
            'status' => 'preview_ready',
            'preview_payload' => $preview,
        ]);
    }

    /**
     * @return array{user:User,direction:Direction,service:Service,pta:Pta,action:Action}
     */
    private function fixture(): array
    {
        $direction = Direction::query()->create(['code' => 'D-HIST', 'libelle' => 'Direction historique']);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'S-HIST',
            'libelle' => 'Service historique',
        ]);
        $pas = Pas::query()->create(['titre' => 'PAS historique', 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'code' => 'AXE-HIST',
            'libelle' => 'Axe historique',
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-HIST',
            'libelle' => 'Objectif historique',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO historique',
            'annee' => 2026,
        ]);
        $objective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel historique',
            'echeance' => '2026-12-31',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA historique',
        ]);
        $user = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $action = $this->createAction(['pta' => $pta, 'service' => $service], 'ACT-HIST-001', Action::TYPE_QUANTITATIVE);

        return compact('user', 'direction', 'service', 'pta', 'action');
    }

    /**
     * @param  array{pta:Pta,service:Service}  $fixture
     */
    private function createAction(array $fixture, string $code, string $type): Action
    {
        return Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pta']->pao_id,
            'objectif_operationnel_id' => $fixture['pta']->objectif_operationnel_id,
            'code' => $code,
            'responsable_id' => User::factory()->create([
                'role' => User::ROLE_AGENT,
                'direction_id' => $fixture['service']->direction_id,
                'service_id' => $fixture['service']->id,
            ])->id,
            'libelle' => 'Action '.$code,
            'type_action' => $type,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'quantite_cible' => $type === Action::TYPE_QUANTITATIVE ? 100 : null,
            'quantite_realisee' => 0,
            'unite_cible' => $type === Action::TYPE_QUANTITATIVE ? 'dossiers' : null,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'justificatif_obligatoire' => false,
        ]);
    }
}
