<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\ObjectifOperationnel;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
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

    public function test_service_user_sees_pao_transmitted_through_operational_objective(): void
    {
        $serviceUser = User::query()->where('email', 'r.ekomi.anbg@gmail.com')->firstOrFail();
        $year = app(\App\Services\ExerciceContext::class)->selectedYear();
        $pas = Pas::query()->create([
            'titre' => 'PAS test transmission service',
            'periode_debut' => $year,
            'periode_fin' => $year,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => (int) $pas->id,
            'code' => 'AXE-SVC',
            'libelle' => 'Axe service',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => (int) $axe->id,
            'code' => 'OS-SVC',
            'libelle' => 'Objectif service',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => (int) $pas->id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => null,
            'annee' => $year,
            'titre' => 'PAO directionnel transmis service',
            'echeance' => $year.'-12-31',
            'objectif_operationnel' => 'Objectif porte par le service',
        ]);
        $pao->forceFill(['statut' => Pao::STATUS_VALIDE])->save();

        ObjectifOperationnel::query()->create([
            'pao_id' => (int) $pao->id,
            'pas_id' => (int) $pas->id,
            'pas_axe_id' => (int) $objectif->pas_axe_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => (int) $serviceUser->service_id,
            'libelle' => 'Objectif operationnel transmis',
            'echeance' => $year.'-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $serviceUser->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-pao-objective',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/paos?annee='.$year);

        $response->assertOk();

        $this->assertContains((int) $pao->id, collect($response->json('data'))->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/paos/'.$pao->id)
            ->assertOk()
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.objectifs_operationnels.0.service_id', (int) $serviceUser->service_id);

        $axesResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/pas-axes');
        $axesResponse->assertOk();
        $this->assertContains((int) $axe->id, collect($axesResponse->json('data'))->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $objectifsResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/pas-objectifs');
        $objectifsResponse->assertOk();
        $this->assertContains((int) $objectif->id, collect($objectifsResponse->json('data'))->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_service_user_cannot_create_pao_for_another_direction(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'a.mindzeli.anbg@gmail.com',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $objectifId = (int) PasObjectif::query()->value('id');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dafServiceId = (int) Service::query()->where('direction_id', $dafDirectionId)->orderBy('id')->value('id');

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
            'email' => 'r.ekomi.anbg@gmail.com',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-service-own-pao',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $serviceUser = \App\Models\User::query()->where('email', 'r.ekomi.anbg@gmail.com')->firstOrFail();
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

    public function test_direction_user_can_create_pao_even_when_pas_has_no_explicit_direction_sharing(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-pao-scope',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $dafDirectionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $dafServiceId = (int) Service::query()->where('direction_id', $dafDirectionId)->orderBy('id')->value('id');

        $pas = Pas::query()->create([
            'titre' => 'PAS global sans partage explicite',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-GLOBAL',
            'libelle' => 'Axe global',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-GLOBAL',
            'libelle' => 'Objectif global',
            'ordre' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_axe_id' => (int) $axe->id,
                'pas_objectif_id' => (int) $objectif->id,
                'direction_id' => $dafDirectionId,
                'service_id' => $dafServiceId,
                'annee' => 2027,
                'echeance' => '2027-12-31',
                'titre' => 'PAO DAF dans PAS global',
                'statut' => 'brouillon',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.direction_id', $dafDirectionId)
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.objectifs_operationnels.0.service_id', $dafServiceId);
    }

    public function test_direction_user_can_create_pao_for_his_own_direction_on_an_accessible_objectif(): void
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
                'pas_axe_id' => (int) $axe->id,
                'pas_objectif_id' => (int) $objectif->id,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'annee' => 2027,
                'echeance' => '2027-12-31',
                'titre' => 'PAO DAF direction autorise',
                'statut' => 'brouillon',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.direction_id', $directionId)
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.objectifs_operationnels.0.service_id', $serviceId);
    }

    public function test_direction_user_can_decline_multiple_operational_objectives_for_the_same_pao_context(): void
    {
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'directeur.daf@anbg.ga',
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-direction-multi-pao',
        ]);

        $token = (string) $loginResponse->json('access_token');
        $directionId = (int) Direction::query()->where('code', 'DAF')->value('id');
        $serviceId = (int) Service::query()->where('direction_id', $directionId)->orderBy('id')->value('id');

        $pas = Pas::query()->create([
            'titre' => 'PAS DAF multi objectifs',
            'periode_debut' => 2027,
            'periode_fin' => 2029,
            'statut' => 'brouillon',
        ]);
        $pas->directions()->sync([$directionId]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-DAF-MULTI',
            'libelle' => 'Axe DAF multiple',
            'ordre' => 1,
        ]);

        $objectifOne = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-DAF-1',
            'libelle' => 'Objectif DAF 1',
            'ordre' => 1,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paos', [
                'pas_axe_id' => (int) $axe->id,
                'pas_objectif_id' => (int) $objectifOne->id,
                'direction_id' => $directionId,
                'annee' => 2027,
                'titre' => 'PAO DAF OS multi',
                'statut' => 'brouillon',
                'objectifs_operationnels' => [
                    [
                        'libelle' => 'Objectif operationnel DAF 1',
                        'service_id' => $serviceId,
                        'echeance' => '2027-09-30',
                        'description' => 'Premiere declinaison operationnelle.',
                    ],
                    [
                        'libelle' => 'Objectif operationnel DAF 2',
                        'service_id' => $serviceId,
                        'echeance' => '2027-12-31',
                        'description' => 'Deuxieme declinaison operationnelle.',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('created_count', 2);

        $this->assertSame(
            1,
            Pao::query()
                ->where('direction_id', $directionId)
                ->where('annee', 2027)
                ->where('pas_objectif_id', (int) $objectifOne->id)
                ->count()
        );

        $paoId = Pao::query()
            ->where('direction_id', $directionId)
            ->where('annee', 2027)
            ->where('pas_objectif_id', (int) $objectifOne->id)
            ->value('id');

        $this->assertSame(
            2,
            ObjectifOperationnel::query()
                ->where('pao_id', (int) $paoId)
                ->count()
        );
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
                                'date_echeance' => '2029-12-31',
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
                    'global',
                    'progression',
                ],
                'statuts',
                'alertes',
            ]);

        $reportingResponse->assertJsonPath('kpi_summary.conformite', fn ($value) => is_numeric($value));

        $auditResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/journal-audit');

        $auditResponse->assertOk();
    }

    public function test_admin_can_consult_kpi_and_measure_api_endpoints(): void
    {
        $admin = $this->createAdminUser();

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-kpi-endpoints',
        ]);

        $token = (string) $loginResponse->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/kpis')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/kpi-mesures')
            ->assertOk();
    }

    /**
     * @return array{0: \App\Models\Pta, 1: \App\Models\User}
     */
    private function firstUnlockedPtaAndAgent(): array
    {
        $pta = Pta::query()
            ->where('statut', '!=', 'verrouille')
            ->orderBy('id')
            ->firstOrFail();

        $agent = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('direction_id', (int) $pta->direction_id)
            ->where('service_id', (int) $pta->service_id)
            ->orderBy('id')
            ->firstOrFail();

        return [$pta, $agent];
    }
}
