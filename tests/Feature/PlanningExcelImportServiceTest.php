<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\PlanningImport;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningExcelImportServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_validation_rejects_missing_columns(): void
    {
        $this->fixture();
        $headers = array_values(array_diff(PlanningExcelImportService::REQUIRED_COLUMNS, ['libelle_action']));
        $sheet = ['sheet_count' => 1, 'sheet_name' => 'IMPORT_GLOBAL', 'headers' => $headers, 'rows' => [$this->row()]];

        $preview = app(PlanningExcelImportService::class)->validateSheet($sheet);

        $this->assertTrue($preview['has_errors']);
        $this->assertStringContainsString('Colonnes manquantes', $preview['rows'][0]['message']);
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
