<?php

namespace Tests\Feature;

use App\Jobs\NotifyImportedParametreActionsJob;
use App\Models\Action;
use App\Models\Direction;
use App\Models\PlanningImport;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Models\SousAction;
use App\Services\DeletionRequestService;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PlanningExcelImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_control_chiefs_can_import_planning_workbooks(): void
    {
        $service = app(PlanningExcelImportService::class);

        foreach ([User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->assertTrue($service->canImport($user), 'Role '.$role);
        }

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
        ]);
        $adminFonctionnel = User::factory()->create([
            'role' => User::ROLE_ADMIN_FONCTIONNEL,
            'is_active' => true,
        ]);

        $this->assertFalse($service->canImport($serviceUser));
        $this->assertFalse($service->canImport($adminFonctionnel));
    }

    public function test_valid_import_creates_grouped_planning_tree_and_action_to_configure(): void
    {
        $fixture = $this->fixture();
        $service = app(PlanningExcelImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row(['ordre_action' => 1, 'libelle_action' => 'Action 1']),
            $this->row(['ordre_action' => 2, 'libelle_action' => 'Action 2']),
        ]));

        $this->assertFalse($preview['has_errors']);

        $import = PlanningImport::query()->create([
            'user_id' => $fixture['admin']->id,
            'filename' => 'import.csv',
            'preview_payload' => $preview,
            'total_rows' => 2,
            'valid_rows' => 2,
            'error_rows' => 0,
            'status' => 'preview_ready',
        ]);

        $service->execute($import, PlanningImport::MODE_CREATE_ONLY, $fixture['admin'], '127.0.0.1');

        $this->assertDatabaseCount('pas', 1);
        $this->assertDatabaseCount('pas_axes', 1);
        $this->assertDatabaseCount('pas_objectifs', 1);
        $this->assertDatabaseCount('paos', 1);
        $this->assertDatabaseCount('objectifs_operationnels', 1);
        $this->assertDatabaseCount('ptas', 1);
        $this->assertDatabaseCount('actions', 2);
        $this->assertDatabaseHas('ptas', ['service_id' => $fixture['service']->id]);
        $this->assertDatabaseHas('actions', [
            'libelle' => 'Action 1',
            'statut' => 'non_demarre',
            'statut_dynamique' => 'non_demarre',
            'statut_parametrage' => 'a_parametrer',
            'nombre_sous_actions_prevu' => 2,
        ]);
        $action = Action::query()->where('libelle', 'Action 1')->firstOrFail();
        $this->assertSame((int) $fixture['agent']->id, (int) $action->responsable_id);
    }

    public function test_deleted_pas_clears_planning_tree_before_same_workbook_reimport(): void
    {
        $fixture = $this->fixture();
        $service = app(PlanningExcelImportService::class);

        $this->executeSingleRowImport($service, $fixture['admin']);

        $pas = Pas::query()->firstOrFail();
        app(DeletionRequestService::class)->deleteBusinessTarget($pas);

        $this->assertDatabaseCount('pas', 0);
        $this->assertDatabaseCount('paos', 0);
        $this->assertDatabaseCount('ptas', 0);
        $this->assertDatabaseCount('actions', 0);

        $this->executeSingleRowImport($service, $fixture['admin']);

        $this->assertDatabaseCount('pas', 1);
        $this->assertDatabaseCount('paos', 1);
        $this->assertDatabaseCount('ptas', 1);
        $this->assertDatabaseCount('actions', 1);
    }

    public function test_validation_rejects_missing_columns(): void
    {
        $this->fixture();
        $headers = array_values(array_diff(PlanningExcelImportService::REQUIRED_COLUMNS, ['libelle_action']));
        $sheet = ['sheet_count' => 1, 'sheet_name' => 'IMPORT_GLOBAL', 'headers' => $headers, 'rows' => [$this->row()]];

        $preview = app(PlanningExcelImportService::class)->validateSheet($sheet);

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('Colonnes manquantes', $preview['rows'][0]['message']);
    }

    public function test_validation_accepts_new_template_column_order_with_optional_guide_sheet(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet([
            'sheet_count' => 2,
            'sheet_name' => 'IMPORT_GLOBAL',
            'sheet_names' => ['IMPORT_GLOBAL', 'GUIDE'],
            'headers' => PlanningExcelImportService::IMPORT_COLUMNS,
            'rows' => [$this->row(['type_action' => 'NQ']) + ['_row_number' => 2]],
        ]);

        $this->assertFalse($preview['has_errors']);
    }

    public function test_column_mapping_allows_custom_source_headers_before_preview(): void
    {
        $this->fixture();
        $rawHeaders = [
            'debut pas',
            'fin pas',
            'axe ordre',
            'axe libelle',
            'os ordre',
            'os libelle',
            'os echeance',
            'dir',
            'svc',
            'oo ordre',
            'oo libelle',
            'oo echeance',
            'action ordre',
            'action libelle',
            'action debut',
            'action fin',
            'agents rmo',
            'seuil',
            'preuve attendue',
            'nb sous actions',
            'financement oui non',
            'nature',
            'montant',
            'risque texte',
            'materiel',
            'main oeuvre',
            'autres',
        ];
        $rawRow = array_combine($rawHeaders, array_values($this->row()));
        $rawRow['_row_number'] = 2;
        $import = PlanningImport::query()->create([
            'filename' => 'custom.xlsx',
            'status' => 'mapping_required',
            'preview_payload' => [
                'headers' => $rawHeaders,
                'required_columns' => PlanningExcelImportService::REQUIRED_COLUMNS,
                'raw_sheet' => [
                    'sheet_count' => 1,
                    'sheet_name' => 'IMPORT_GLOBAL',
                    'headers' => $rawHeaders,
                    'rows' => [$rawRow],
                ],
            ],
        ]);
        $mapping = array_combine(PlanningExcelImportService::REQUIRED_COLUMNS, $rawHeaders);

        app(PlanningExcelImportService::class)->applyColumnMapping($import, $mapping, User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail());

        $import->refresh();
        $this->assertSame('preview_ready', $import->status);
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame('Action importee', $import->preview_payload['rows'][0]['data']['libelle_action']);
    }

    public function test_validation_rejects_financing_without_amount(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['financement' => 1, 'nature_financement' => 'Budget', 'montant_financement' => '']),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('montant_financement', $preview['rows'][0]['message']);
    }

    public function test_validation_rejects_unknown_agent_code_but_allows_empty_rmo_codes(): void
    {
        $this->fixture();

        $unknownPreview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['codes_agents_rmo' => 'AG999']),
        ]));
        $emptyPreview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['codes_agents_rmo' => '']),
        ]));

        $this->assertTrue($unknownPreview['has_errors']);
        $this->assertStringContainsString('Code agent AG999 introuvable', $unknownPreview['rows'][0]['message']);
        $this->assertFalse($emptyPreview['has_errors']);
    }

    public function test_duplicate_agent_codes_are_ignored_with_warning(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['codes_agents_rmo' => 'AG001; AG001']),
        ]));

        $this->assertFalse($preview['has_errors']);
        $this->assertSame('Avertissement', $preview['rows'][0]['status']);
        $this->assertStringContainsString('doublon ignore', $preview['rows'][0]['message']);
    }

    public function test_inactive_agent_code_blocks_import_and_other_service_warns(): void
    {
        $fixture = $this->fixture();
        $otherService = Service::query()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => 'OTHER',
            'libelle' => 'Autre service',
            'actif' => true,
        ]);
        User::factory()->create([
            'name' => 'Agent Inactif',
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'AG002',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'is_active' => false,
        ]);
        User::factory()->create([
            'name' => 'Agent autre service',
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'AG003',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $otherService->id,
            'is_active' => true,
        ]);

        $inactivePreview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['codes_agents_rmo' => 'AG002']),
        ]));
        $otherServicePreview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['codes_agents_rmo' => 'AG003']),
        ]));

        $this->assertTrue($inactivePreview['has_errors']);
        $this->assertStringContainsString('utilisateur desactive', $inactivePreview['rows'][0]['message']);
        $this->assertFalse($otherServicePreview['has_errors']);
        $this->assertSame('Avertissement', $otherServicePreview['rows'][0]['status']);
        $this->assertStringContainsString('autre service', $otherServicePreview['rows'][0]['message']);
    }

    public function test_validation_rejects_service_outside_direction_and_multiple_pas_periods(): void
    {
        $this->fixture();
        Direction::query()->create(['code' => 'DAF', 'libelle' => 'Direction finances', 'actif' => true]);

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['direction' => 'DAF']),
            $this->row(['annee_debut_pas' => 2027, 'annee_fin_pas' => 2029]),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('n appartient pas', $preview['rows'][0]['message']);
        $this->assertStringContainsString('plusieurs periodes PAS', $preview['rows'][1]['message']);
    }

    public function test_validation_requires_import_global_sheet_name(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet([
            'sheet_count' => 1,
            'sheet_name' => 'Feuille1',
            'headers' => PlanningExcelImportService::REQUIRED_COLUMNS,
            'rows' => [$this->row()],
        ]);

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('IMPORT_GLOBAL', $preview['rows'][0]['message']);
    }

    public function test_strict_preview_rejects_forbidden_execution_columns(): void
    {
        $this->fixture();

        $sheet = $this->sheet([$this->row()]);
        $sheet['headers'][] = 'mode_execution';

        $preview = app(PlanningExcelImportService::class)->validateSheet($sheet);

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('mode_execution', $preview['rows'][0]['message']);
    }

    public function test_pta_form_generates_planned_sub_action_blocks_for_imported_action(): void
    {
        $fixture = $this->fixture();
        $service = app(PlanningExcelImportService::class);
        $preview = $service->validateSheet($this->sheet([$this->row()]));
        $import = PlanningImport::query()->create([
            'user_id' => $fixture['admin']->id,
            'filename' => 'import.csv',
            'preview_payload' => $preview,
            'total_rows' => 1,
            'valid_rows' => 1,
            'status' => 'preview_ready',
        ]);
        $service->execute($import, PlanningImport::MODE_CREATE_ONLY, $fixture['admin'], '127.0.0.1');

        $pta = Pta::query()->firstOrFail();
        $chief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'is_active' => true,
        ]);

        $this->actingAs($chief)
            ->get(route('workspace.pta.edit', $pta))
            ->assertOk()
            ->assertSee('Sous-action 1')
            ->assertSee('Sous-action 2');
    }

    public function test_quantitative_parametrage_columns_import_a_configured_action(): void
    {
        $fixture = $this->fixture();
        $this->muteNotifications();
        $service = app(PlanningExcelImportService::class);

        $preview = $service->validateSheet($this->sheet([
            $this->row([
                'libelle_action' => 'Action quantitative',
                'type_action' => 'Q',
                'quantite_cible' => 120,
                'unite_cible' => 'dossiers',
                'seuil_mode' => 'trimestriel',
                'seuil_t1' => 25,
                'seuil_t2' => 50,
                'seuil_t3' => 75,
                'seuil_t4' => 100,
            ]),
        ]));
        $this->assertFalse($preview['has_errors']);

        $this->executePreview($service, $fixture['admin'], $preview);

        $this->assertDatabaseHas('actions', [
            'libelle' => 'Action quantitative',
            'statut_parametrage' => 'parametre',
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'type_action' => Action::TYPE_QUANTITATIVE,
            'seuil_mode' => 'trimestriel',
        ]);
        $action = Action::query()->where('libelle', 'Action quantitative')->firstOrFail();
        $this->assertSame(120.0, (float) $action->quantite_cible);
        $this->assertSame('dossiers', $action->unite_cible);
        $this->assertSame(100.0, (float) $action->seuil_t4);

        // PTA sans action restante a parametrer → bascule automatique en cours.
        $this->assertDatabaseHas('ptas', ['statut' => Pta::STATUS_EN_COURS]);
    }

    public function test_composee_parametrage_columns_create_planned_sub_actions(): void
    {
        $fixture = $this->fixture();
        $this->muteNotifications();
        $service = app(PlanningExcelImportService::class);

        $preview = $service->validateSheet($this->sheet([
            $this->row([
                'libelle_action' => 'Action composee',
                'nombre_sous_actions' => 2,
                'type_action' => 'M',
                'sous_actions' => 'Former 20 agents|Q|60|20|agents ; Rediger le guide|NQ|40||',
            ]),
        ]));
        $this->assertFalse($preview['has_errors']);

        $this->executePreview($service, $fixture['admin'], $preview);

        $action = Action::query()->where('libelle', 'Action composee')->firstOrFail();
        $this->assertSame('parametre', $action->statut_parametrage);
        $this->assertSame(Action::TYPE_COMPOSEE, $action->type_action);
        $this->assertDatabaseCount('sous_actions', 2);
        $this->assertDatabaseHas('sous_actions', [
            'action_id' => $action->id,
            'libelle' => 'Former 20 agents',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
            'weight' => 60,
        ]);
        $this->assertDatabaseHas('sous_actions', [
            'libelle' => 'Rediger le guide',
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'weight' => 40,
        ]);
    }

    public function test_quantitative_parametrage_requires_target_value(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['type_action' => 'Q', 'quantite_cible' => '', 'unite_cible' => '']),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('quantite_cible', $preview['rows'][0]['message']);
        $this->assertStringContainsString('unite_cible', $preview['rows'][0]['message']);
    }

    public function test_trimestriel_threshold_requires_quarter_values(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row(['type_action' => 'NQ', 'seuil_mode' => 'trimestriel']),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('seuil_t1', $preview['rows'][0]['message']);
    }

    public function test_composee_rejects_sub_action_weights_not_summing_to_100(): void
    {
        $this->fixture();

        $preview = app(PlanningExcelImportService::class)->validateSheet($this->sheet([
            $this->row([
                'type_action' => 'M',
                'sous_actions' => 'A|NQ|30|| ; B|NQ|30||',
            ]),
        ]));

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('somme des poids', $preview['rows'][0]['message']);
    }

    public function test_empty_type_action_keeps_action_to_configure(): void
    {
        $fixture = $this->fixture();
        $service = app(PlanningExcelImportService::class);

        // Ligne avec colonnes de parametrage presentes mais type_action vide.
        $preview = $service->validateSheet($this->sheet([
            $this->row(['type_action' => '', 'quantite_cible' => '', 'sous_actions' => '']),
        ]));
        $this->assertFalse($preview['has_errors']);

        $this->executePreview($service, $fixture['admin'], $preview);

        $this->assertDatabaseHas('actions', [
            'libelle' => 'Action importee',
            'statut_parametrage' => 'a_parametrer',
        ]);
    }

    public function test_complete_import_queues_assigned_rmo_notifications(): void
    {
        $fixture = $this->fixture();
        Bus::fake([NotifyImportedParametreActionsJob::class]);

        $service = app(PlanningExcelImportService::class);
        $preview = $service->validateSheet($this->sheet([
            $this->row(['type_action' => 'NQ']),
        ]));

        $this->executePreview($service, $fixture['admin'], $preview);

        $action = Action::query()->where('libelle', 'Action importee')->firstOrFail();
        Bus::assertDispatched(NotifyImportedParametreActionsJob::class, fn (NotifyImportedParametreActionsJob $job): bool =>
            $job->actorId === (int) $fixture['admin']->id
            && $job->actionIds === [(int) $action->id]
        );
    }

    private function muteNotifications(): void
    {
        Bus::fake([NotifyImportedParametreActionsJob::class]);
    }

    private function executePreview(PlanningExcelImportService $service, User $admin, array $preview): PlanningImport
    {
        $import = PlanningImport::query()->create([
            'user_id' => $admin->id,
            'filename' => 'import.csv',
            'preview_payload' => $preview,
            'total_rows' => count($preview['rows']),
            'valid_rows' => count($preview['rows']),
            'error_rows' => 0,
            'status' => 'preview_ready',
        ]);

        return $service->execute($import, PlanningImport::MODE_CREATE_ONLY, $admin, '127.0.0.1');
    }

    private function fixture(): array
    {
        $direction = Direction::query()->create(['code' => 'DSIC', 'libelle' => 'Direction systemes', 'actif' => true]);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SIRS', 'libelle' => 'Service SIRS', 'actif' => true]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $agent = User::factory()->create([
            'name' => 'Agent Import',
            'email' => 'agent1@anbg.ga',
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'AG001',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);

        return compact('direction', 'service', 'admin', 'agent');
    }

    private function executeSingleRowImport(PlanningExcelImportService $service, User $admin): PlanningImport
    {
        $preview = $service->validateSheet($this->sheet([$this->row()]));
        $this->assertFalse($preview['has_errors']);

        $import = PlanningImport::query()->create([
            'user_id' => $admin->id,
            'filename' => 'import.csv',
            'preview_payload' => $preview,
            'total_rows' => 1,
            'valid_rows' => 1,
            'error_rows' => 0,
            'status' => 'preview_ready',
        ]);

        return $service->execute($import, PlanningImport::MODE_CREATE_ONLY, $admin, '127.0.0.1');
    }

    private function sheet(array $rows): array
    {
        return [
            'sheet_count' => 1,
            'sheet_name' => 'IMPORT_GLOBAL',
            'headers' => PlanningExcelImportService::REQUIRED_COLUMNS,
            'rows' => array_map(fn (array $row, int $index): array => $row + ['_row_number' => $index + 2], $rows, array_keys($rows)),
        ];
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'annee_debut_pas' => 2026,
            'annee_fin_pas' => 2028,
            'ordre_axe' => 1,
            'libelle_axe' => 'Axe transformation',
            'ordre_objectif_strategique' => 1,
            'libelle_objectif_strategique' => 'Objectif strategique',
            'date_echeance_objectif_strategique' => '2028-12-31',
            'direction' => 'DSIC',
            'service_unite' => 'SIRS',
            'ordre_objectif_operationnel' => 1,
            'libelle_objectif_operationnel' => 'Objectif operationnel',
            'date_echeance_objectif_operationnel' => '2026-12-31',
            'ordre_action' => 1,
            'libelle_action' => 'Action importee',
            'date_debut_action' => '2026-01-10',
            'date_fin_action' => '2026-06-30',
            'codes_agents_rmo' => 'AG001',
            'cible_minimum_execution' => 80,
            'justificatif_attendu' => 'Rapport',
            'nombre_sous_actions' => 2,
            'financement' => 0,
            'nature_financement' => '',
            'montant_financement' => '',
            'risque' => 'Retard',
            'ressources_materielles' => 'Postes',
            'main_oeuvre' => 'Equipe',
            'autres_ressources' => 'Support',
        ], $overrides);
    }
}
