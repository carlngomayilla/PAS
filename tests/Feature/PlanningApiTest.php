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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlanningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_login_and_access_profile_endpoint(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@anbg.test',
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
            ->getJson('/api/me');

        $meResponse->assertOk()
            ->assertJsonPath('user.email', 'admin@anbg.test');
    }

    public function test_direction_user_only_sees_his_direction_paos(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'daf.direction@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/paos');

        $response->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);

        foreach ($rows as $row) {
            $this->assertSame($dafDirectionId, (int) $row['direction_id']);
        }
    }

    public function test_service_user_cannot_create_pao_for_another_direction(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'dev.service@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $objectifId = (int) PasObjectif::query()->value('id');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dafServiceId = (int) Service::query()->where('direction_id', $dafDirectionId)->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/paos', [
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
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'finance.service@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-own-pao',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $serviceUser = \App\Models\User::query()->where('email', 'finance.service@anbg.test')->firstOrFail();
        $objectifId = (int) PasObjectif::query()->value('id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/paos', [
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
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'daf.direction@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-pao-scope',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dsiDirectionId = (int) Direction::query()->where('code', 'DSI')->value('id');

        $pas = Pas::query()->create([
            'titre' => 'PAS non partage DAF',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $pas->directions()->sync([$dsiDirectionId]);
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
            ->postJson('/api/paos', [
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
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'daf.direction@anbg.test',
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
            ->postJson('/api/paos', [
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
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'finance.service@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-pta',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $serviceUser = \App\Models\User::query()->where('email', 'finance.service@anbg.test')->firstOrFail();

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
            ->postJson('/api/ptas', [
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
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'daf.direction@anbg.test',
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
            ->postJson('/api/ptas', [
                'pao_id' => (int) $pao->id,
                'titre' => 'PTA direction interdit',
                'statut' => 'brouillon',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_create_pas_with_directions_axes_and_objectifs(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-create-pas-full',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $directionIds = Direction::query()
            ->whereIn('code', ['DAF', 'DSI'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pas', [
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

    public function test_service_user_can_submit_weekly_tracking_with_justificatif(): void
    {
        Storage::fake('local');

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'finance.service@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-justificatif',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $action = Action::query()
            ->where('libelle', 'Automatiser le suivi des engagements')
            ->firstOrFail();
        $weekId = (int) $action->weeks()->orderBy('numero_semaine')->value('id');
        $this->assertGreaterThan(0, $weekId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/actions/{$action->id}/weeks/{$weekId}/submit", [
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

    public function test_non_admin_cannot_list_users_from_referentiel_api(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'dg@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-non-admin-users-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/referentiel/utilisateurs');

        $response->assertForbidden();
    }

    public function test_alerts_api_returns_unified_items_with_read_state(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-alerts-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/alertes');

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'summary' => ['total', 'unread', 'critical', 'warning', 'info'],
                'level_unread_counts' => ['critical', 'warning', 'info'],
                'items',
                'alerts',
            ]);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('source_type', $items[0]);
        $this->assertArrayHasKey('is_unread', $items[0]);
        $this->assertArrayHasKey('target_url', $items[0]);
        $this->assertArrayHasKey('read_endpoint', $items[0]);
    }

    public function test_alerts_api_can_mark_one_alert_as_read(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-alerts-read-api',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $indexResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/alertes');

        $indexResponse->assertOk();
        $first = $indexResponse->json('items.0');
        $this->assertIsArray($first);
        $this->assertNotEmpty($first['source_type'] ?? null);
        $this->assertNotEmpty($first['source_id'] ?? null);
        $this->assertNotEmpty($first['fingerprint'] ?? null);
        $this->assertTrue((bool) ($first['is_unread'] ?? false));

        $readResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/alertes/'.$first['source_type'].'/'.$first['source_id'].'/read');

        $readResponse->assertOk()
            ->assertJsonPath('fingerprint', $first['fingerprint']);

        $admin = \App\Models\User::query()->where('email', 'admin@anbg.test')->firstOrFail();
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $admin->id,
            'fingerprint' => $first['fingerprint'],
            'source_type' => $first['source_type'],
            'source_id' => (int) $first['source_id'],
        ]);

        $afterResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/alertes');

        $afterResponse->assertOk();
        $matching = collect($afterResponse->json('items'))
            ->firstWhere('fingerprint', $first['fingerprint']);

        $this->assertIsArray($matching);
        $this->assertFalse((bool) ($matching['is_unread'] ?? true));
    }

    public function test_admin_can_consult_reporting_and_audit_endpoints(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@anbg.test',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-reporting',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $reportingResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reporting/overview');

        $reportingResponse->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'scope',
                'global',
                'statuts',
                'alertes',
            ]);

        $auditResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/journal-audit');

        $auditResponse->assertOk();
    }
}
