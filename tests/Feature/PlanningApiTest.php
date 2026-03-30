<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class PlanningApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_login_and_access_profile_endpoint(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure([
                'token_type',
                'access_token',
                'user' => ['id', 'email', 'role'],
                'interactions',
                'modules',
            ]);

        $token = (string) $loginResponse->json('access_token');

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $meResponse->assertOk()
            ->assertJsonPath('user.email', $admin->email);
    }

    public function test_direction_user_only_sees_his_direction_paos(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/paos');

        $response->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);

        foreach ($rows as $row) {
            $this->assertSame($dafDirectionId, (int) $row['direction_id']);
        }
    }

    public function test_service_user_cannot_create_pao_for_another_direction(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'arnold.mindzeli@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $objectifId = (int) PasObjectif::query()->value('id');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dafServiceId = (int) Service::query()->where('direction_id', $dafDirectionId)->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_objectif_id' => $objectifId,
                'direction_id' => $dafDirectionId,
                'service_id' => $dafServiceId,
                'annee' => 2028,
                'titre' => 'PAO test non autorise',
                'statut' => 'brouillon',
            ]);

        $response->assertForbidden();
    }

    public function test_service_user_cannot_create_pao_even_for_his_own_service(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'robert.ekomi@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-own-pao',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $serviceUser = \App\Models\User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $objectifId = (int) PasObjectif::query()->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_objectif_id' => $objectifId,
                'direction_id' => (int) $serviceUser->direction_id,
                'service_id' => (int) $serviceUser->service_id,
                'annee' => 2028,
                'titre' => 'PAO service interdit',
                'statut' => 'brouillon',
            ]);

        $response->assertForbidden();
    }

    public function test_direction_user_cannot_create_pao_when_pas_is_not_shared_with_his_direction(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-pao-scope',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dsicDirectionId = (int) Direction::query()->where('code', 'DSIC')->value('id');

        $pas = Pas::query()->create([
            'titre' => 'PAS non partage DAF',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $pas->directions()->sync([$dsicDirectionId]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-HORS-SCOPE',
            'libelle' => 'Axe hors scope',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-HORS-SCOPE',
            'libelle' => 'Objectif hors scope',
            'ordre' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_objectif_id' => (int) $objectif->id,
                'direction_id' => $dafDirectionId,
                'service_id' => (int) Service::query()->where('direction_id', $dafDirectionId)->value('id'),
                'annee' => 2027,
                'titre' => 'PAO DAF hors perimetre PAS',
                'statut' => 'brouillon',
            ]);

        $response->assertForbidden();
    }

    public function test_direction_user_can_create_pao_for_his_own_direction_and_service(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-own-pao',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $directionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $serviceId = (int) Service::query()->where('direction_id', $directionId)->orderBy('id')->value('id');

        $pas = Pas::query()->create([
            'titre' => 'PAS DAF autorise',
            'periode_debut' => 2027,
            'periode_fin' => 2029,
            'statut' => 'brouillon',
        ]);
        $pas->directions()->sync([$directionId]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-DAF',
            'libelle' => 'Axe DAF',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-DAF-1',
            'libelle' => 'Objectif DAF',
            'ordre' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_objectif_id' => (int) $objectif->id,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'annee' => 2027,
                'titre' => 'PAO DAF service autorise',
                'statut' => 'brouillon',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.direction_id', $directionId)
            ->assertJsonPath('data.service_id', $serviceId);
    }

    public function test_service_user_can_create_pta_only_for_his_service_pao(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'robert.ekomi@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-pta',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $serviceUser = \App\Models\User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $pao = Pao::query()->create([
            'pas_id' => (int) Pas::query()->value('id'),
            'pas_objectif_id' => (int) PasObjectif::query()->value('id'),
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => (int) $serviceUser->service_id,
            'annee' => 2029,
            'titre' => 'PAO service pour PTA',
            'statut' => 'brouillon',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/ptas', [
                'pao_id' => (int) $pao->id,
                'titre' => 'PTA service autorise',
                'statut' => 'brouillon',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.pao_id', (int) $pao->id)
            ->assertJsonPath('data.service_id', (int) $serviceUser->service_id);
    }

    public function test_direction_user_cannot_create_pta_directly(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-pta-forbidden',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $directionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $serviceId = (int) Service::query()->where('direction_id', $directionId)->orderBy('id')->value('id');

        $pao = Pao::query()->create([
            'pas_id' => (int) Pas::query()->value('id'),
            'pas_objectif_id' => (int) PasObjectif::query()->value('id'),
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'annee' => 2030,
            'titre' => 'PAO direction sans PTA',
            'statut' => 'brouillon',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/ptas', [
                'pao_id' => (int) $pao->id,
                'titre' => 'PTA direction interdit',
                'statut' => 'brouillon',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_create_pas_with_directions_axes_and_objectifs(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-create-pas-full',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $directionIds = Direction::query()
            ->whereIn('code', ['DAF', 'DSIC'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/pas', [
                'titre' => 'PAS test structure integree',
                'periode_debut' => 2027,
                'periode_fin' => 2029,
                'statut' => 'brouillon',
                'axes' => [
                    [
                        'direction_id' => $directionIds[0],
                        'code' => 'AXE-TEST-1',
                        'libelle' => 'Axe test',
                        'periode_debut' => '2027-01-01',
                        'periode_fin' => '2028-12-31',
                        'description' => 'Description axe test',
                        'ordre' => 1,
                        'objectifs' => [
                            [
                                'code' => 'OS-TEST-1',
                                'libelle' => 'Objectif test',
                                'description' => 'Description objectif test',
                                'indicateur_global' => 'Taux de completude',
                                'valeur_cible' => '100%',
                                'valeurs_cible' => [
                                    'taux_realisation' => 80,
                                    'budget' => 500000,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'PAS cree avec succes.')
            ->assertJsonPath('data.axes.0.periode_debut', '2027-01-01T00:00:00.000000Z')
            ->assertJsonPath('data.axes.0.objectifs.0.valeurs_cible.taux_realisation', 80);

        $pasId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $pasId);

        $this->assertDatabaseHas('pas_directions', [
            'pas_id' => $pasId,
            'direction_id' => $directionIds[0],
        ]);

        $this->assertDatabaseHas('pas_axes', [
            'pas_id' => $pasId,
            'code' => 'AXE-TEST-1',
            'libelle' => 'Axe test',
            'periode_debut' => '2027-01-01 00:00:00',
            'periode_fin' => '2028-12-31 00:00:00',
        ]);

        $axeId = (int) \App\Models\PasAxe::query()
            ->where('pas_id', $pasId)
            ->where('code', 'AXE-TEST-1')
            ->value('id');

        $this->assertGreaterThan(0, $axeId);

        $this->assertDatabaseHas('pas_objectifs', [
            'pas_axe_id' => $axeId,
            'code' => 'OS-TEST-1',
            'libelle' => 'Objectif test',
            'indicateur_global' => 'Taux de completude',
            'valeur_cible' => '100%',
        ]);
    }

    public function test_agent_user_can_submit_weekly_tracking_with_justificatif(): void
    {
        Storage::fake('local');

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'melissa.abogo@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-justificatif',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $agent = \App\Models\User::query()->where('email', 'melissa.abogo@anbg.ga')->firstOrFail();
        $action = Action::query()
            ->where('responsable_id', (int) $agent->id)
            ->firstOrFail();
        $weekId = (int) $action->weeks()->orderBy('numero_semaine')->value('id');
        $this->assertGreaterThan(0, $weekId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/v1/actions/{$action->id}/weeks/{$weekId}/submit", [
                'quantite_realisee' => 5,
                'commentaire' => 'Saisie API test',
                'difficultes' => 'RAS',
                'mesures_correctives' => 'Suivi renforce',
                'justificatif' => UploadedFile::fake()->create(
                    'preuve.pdf',
                    100,
                    'application/pdf'
                ),
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Semaine renseignee avec succes.');

        $justificatif = Justificatif::query()
            ->where('justifiable_type', Action::class)
            ->where('justifiable_id', (int) $action->id)
            ->where('action_week_id', $weekId)
            ->where('categorie', 'hebdomadaire')
            ->latest('id')
            ->firstOrFail();

        $storedPath = (string) $justificatif->chemin_stockage;
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_action_api_exposes_quality_and_risk_kpis(): void
    {
        $admin = $this->createAdminUser();
        $action = Action::query()->firstOrFail();

        $action->actionKpi()->updateOrCreate(
            ['action_id' => $action->id],
            [
                'kpi_delai' => 75,
                'kpi_performance' => 82,
                'kpi_conformite' => 91,
                'kpi_qualite' => 88,
                'kpi_risque' => 63,
                'kpi_global' => 80,
                'progression_reelle' => 64,
                'progression_theorique' => 70,
                'statut_calcule' => 'a_risque',
            ]
        );

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-action-kpis',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/actions/'.$action->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'action_kpi' => [
                        'kpi_qualite',
                        'kpi_risque',
                    ],
                ],
            ]);

        $payload = $response->json('data.action_kpi');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('kpi_qualite', $payload);
        $this->assertArrayHasKey('kpi_risque', $payload);
    }

    public function test_non_admin_cannot_list_users_from_referentiel_api(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'ingrid@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-non-admin-users-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/referentiel/utilisateurs');

        $response->assertForbidden();
    }

    public function test_admin_users_referentiel_api_is_paginated_and_can_filter_on_activity_status(): void
    {
        $admin = $this->createAdminUser();

        User::factory()->create([
            'name' => 'Utilisateur inactif API',
            'email' => 'inactive.api@anbg.test',
            'role' => User::ROLE_SERVICE,
            'is_active' => false,
            'password_changed_at' => now(),
        ]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-admin-users-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/referentiel/utilisateurs?per_page=5&is_active=0');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'per_page',
                'total',
            ])
            ->assertJsonPath('per_page', 5);

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('inactive.api@anbg.test', $rows[0]['email']);
        $this->assertFalse((bool) $rows[0]['is_active']);
    }

    public function test_alerts_api_returns_unified_items_with_read_state(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-alerts-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/alertes');

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'summary' => ['total', 'unread', 'urgence', 'critical', 'warning', 'info'],
                'level_unread_counts' => ['urgence', 'critical', 'warning', 'info'],
                'kpi_summary' => [
                    'delai',
                    'performance',
                    'conformite',
                    'qualite',
                    'risque',
                    'global',
                    'progression',
                ],
                'items',
                'alerts',
            ]);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertIsNumeric($response->json('kpi_summary.qualite'));
        $this->assertIsNumeric($response->json('kpi_summary.risque'));
        $this->assertArrayHasKey('source_type', $items[0]);
        $this->assertArrayHasKey('is_unread', $items[0]);
        $this->assertArrayHasKey('target_url', $items[0]);
        $this->assertArrayHasKey('read_endpoint', $items[0]);
    }

    public function test_alerts_api_applies_limit_globally(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-alerts-limit-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/alertes?limit=1');

        $response->assertOk()
            ->assertJsonPath('summary.total', 1);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
    }

    public function test_alerts_api_can_mark_one_alert_as_read(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-alerts-read-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $indexResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/alertes?limit=100');

        $indexResponse->assertOk();
        $first = $indexResponse->json('items.0');
        $this->assertIsArray($first);
        $this->assertNotEmpty($first['source_type'] ?? null);
        $this->assertNotEmpty($first['source_id'] ?? null);
        $this->assertNotEmpty($first['fingerprint'] ?? null);
        $this->assertTrue((bool) ($first['is_unread'] ?? false));

        $readResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/alertes/'.$first['source_type'].'/'.$first['source_id'].'/read');

        $readResponse->assertOk()
            ->assertJsonPath('fingerprint', $first['fingerprint']);

        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $admin->id,
            'fingerprint' => $first['fingerprint'],
            'source_type' => $first['source_type'],
            'source_id' => (int) $first['source_id'],
        ]);

        $afterResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/alertes?limit=100');

        $afterResponse->assertOk();
        $matching = collect($afterResponse->json('items'))
            ->first(function (array $item) use ($first): bool {
                return (string) ($item['source_type'] ?? '') === (string) $first['source_type']
                    && (int) ($item['source_id'] ?? 0) === (int) $first['source_id'];
            });

        $this->assertIsArray($matching);
        $this->assertFalse((bool) ($matching['is_unread'] ?? true));
    }

    public function test_admin_can_consult_reporting_and_audit_endpoints(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-reporting',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $reportingResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/reporting/overview');

        $reportingResponse->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'scope',
                'global',
                'kpi_summary' => [
                    'delai',
                    'performance',
                    'conformite',
                    'qualite',
                    'risque',
                    'global',
                    'progression',
                ],
                'statuts',
                'alertes',
            ]);

        $reportingResponse->assertJsonPath('kpi_summary.qualite', fn ($value) => is_numeric($value));
        $reportingResponse->assertJsonPath('kpi_summary.risque', fn ($value) => is_numeric($value));

        $auditResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/journal-audit');

        $auditResponse->assertOk();
    }
}

