<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaoMultiStrategicObjectiveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_creates_one_annual_pao_covering_multiple_strategic_objectives(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->post(route('workspace.pao.store'), $this->validPayload($fixture))
            ->assertRedirect(route('workspace.pao.index'))
            ->assertSessionHasNoErrors();

        $pao = Pao::query()->firstOrFail();
        $this->assertSame(1, Pao::query()->count());
        $this->assertSame($fixture['primary_objective']->id, $pao->pas_objectif_id);
        $this->assertSame(2, $pao->objectifsOperationnels()->count());
        $this->assertEqualsCanonicalizing(
            [$fixture['primary_objective']->id, $fixture['secondary_objective']->id],
            $pao->objectifsOperationnels()->pluck('pas_objectif_id')->all()
        );

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.pao.index', ['pas_objectif_id' => $fixture['secondary_objective']->id]))
            ->assertOk()
            ->assertSee($pao->titre)
            ->assertSee('Couverture stratégique')
            ->assertSee($fixture['primary_objective']->libelle)
            ->assertSee($fixture['secondary_objective']->libelle)
            ->assertSee('Services couverts');
    }

    public function test_creation_rejects_an_operational_objective_from_another_pas(): void
    {
        $fixture = $this->createFixture();
        $payload = $this->validPayload($fixture);
        $payload['objectifs_operationnels'][1]['pas_objectif_id'] = $fixture['foreign_objective']->id;

        $this->actingAs($fixture['admin'])
            ->from(route('workspace.pao.create'))
            ->post(route('workspace.pao.store'), $payload)
            ->assertRedirect(route('workspace.pao.create'))
            ->assertSessionHasErrors('objectifs_operationnels.1.pas_objectif_id');

        $this->assertSame(0, Pao::query()->count());
        $this->assertSame(0, ObjectifOperationnel::query()->count());
    }

    public function test_api_persists_each_operational_objective_strategic_link(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);

        $this->postJson('/api/v1/paos', $this->validPayload($fixture))
            ->assertCreated()
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('data.objectifs_operationnels.0.pas_objectif_id', $fixture['primary_objective']->id)
            ->assertJsonPath('data.objectifs_operationnels.1.pas_objectif_id', $fixture['secondary_objective']->id);

        $this->assertEqualsCanonicalizing(
            [$fixture['primary_objective']->id, $fixture['secondary_objective']->id],
            ObjectifOperationnel::query()->pluck('pas_objectif_id')->all()
        );
    }

    public function test_creation_uses_each_strategic_objective_deadline(): void
    {
        $fixture = $this->createFixture();
        $fixture['secondary_objective']->update(['date_echeance' => '2026-06-30']);
        $payload = $this->validPayload($fixture);
        $payload['objectifs_operationnels'][1]['echeance'] = '2026-07-01';

        $this->actingAs($fixture['admin'])
            ->from(route('workspace.pao.create'))
            ->post(route('workspace.pao.store'), $payload)
            ->assertRedirect(route('workspace.pao.create'))
            ->assertSessionHasErrors('objectifs_operationnels.1.echeance');

        $this->assertSame(0, Pao::query()->count());
    }

    public function test_update_cannot_remove_an_objective_already_used_by_a_pta(): void
    {
        $fixture = $this->createFixture();
        $this->actingAs($fixture['admin'])->post(route('workspace.pao.store'), $this->validPayload($fixture));

        $pao = Pao::query()->firstOrFail();
        $objectives = $pao->objectifsOperationnels()->orderBy('id')->get();
        $protectedObjective = $objectives->last();
        Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $protectedObjective->id,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['secondary_service']->id,
            'titre' => 'PTA lie a l objectif protege',
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        $payload = $this->validPayload($fixture);
        $payload['objectifs_operationnels'] = [[
            'id' => $objectives->first()->id,
            'pas_objectif_id' => $fixture['primary_objective']->id,
            'libelle' => 'Objectif operationnel principal actualise',
            'service_id' => $fixture['primary_service']->id,
            'echeance' => '2026-09-30',
        ]];

        $this->actingAs($fixture['admin'])
            ->from(route('workspace.pao.edit', $pao))
            ->put(route('workspace.pao.update', $pao), $payload)
            ->assertRedirect(route('workspace.pao.edit', $pao))
            ->assertSessionHasErrors('objectifs_operationnels');

        $this->assertSame(2, $pao->objectifsOperationnels()->count());
        $this->assertModelExists($protectedObjective->fresh());
    }

    public function test_direction_writer_cannot_create_a_pao_for_another_direction(): void
    {
        $fixture = $this->createFixture();
        $directionWriter = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
            'password_changed_at' => now(),
        ]);
        $payload = $this->validPayload($fixture);
        $payload['direction_id'] = $fixture['foreign_direction']->id;
        $payload['objectifs_operationnels'][0]['service_id'] = $fixture['foreign_service']->id;
        $payload['objectifs_operationnels'][1]['service_id'] = $fixture['foreign_service']->id;

        $this->actingAs($directionWriter)
            ->post(route('workspace.pao.store'), $payload)
            ->assertForbidden();

        $this->assertSame(0, Pao::query()->count());
    }

    public function test_database_guarantees_one_active_pao_per_direction_and_year(): void
    {
        $fixture = $this->createFixture();
        $this->actingAs($fixture['admin'])->post(route('workspace.pao.store'), $this->validPayload($fixture));
        $pao = Pao::query()->firstOrFail();

        try {
            DB::transaction(function () use ($pao): void {
                $duplicate = $pao->replicate();
                $duplicate->save();
            });
            $this->fail('La base a accepte deux PAO actifs pour la meme direction et la meme annee.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0] ?? null, ['23000', '23505']);
            $this->assertTrue(
                str_contains($exception->getMessage(), 'paos_direction_annee_active_unique')
                || str_contains($exception->getMessage(), 'paos.direction_id, paos.annee')
            );
        }

        $pao->delete();
        $replacement = $pao->replicate();
        $replacement->forceFill(['deleted_at' => null]);
        $replacement->save();

        $this->assertModelExists($replacement);
        $this->assertSame(1, Pao::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-MULTI',
            'libelle' => 'Direction multi axes',
            'actif' => true,
        ]);
        $primaryService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-MULTI-1',
            'libelle' => 'Service multi un',
            'actif' => true,
        ]);
        $secondaryService = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-MULTI-2',
            'libelle' => 'Service multi deux',
            'actif' => true,
        ]);
        $foreignDirection = Direction::query()->create([
            'code' => 'DIR-OTHER',
            'libelle' => 'Direction hors perimetre',
            'actif' => true,
        ]);
        $foreignService = Service::query()->create([
            'direction_id' => $foreignDirection->id,
            'code' => 'SER-OTHER',
            'libelle' => 'Service hors perimetre',
            'actif' => true,
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS multi axes 2026-2028',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $primaryAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-MULTI-1',
            'libelle' => 'Premier axe strategique',
            'ordre' => 1,
        ]);
        $secondaryAxis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-MULTI-2',
            'libelle' => 'Deuxieme axe strategique',
            'ordre' => 2,
        ]);
        $primaryObjective = PasObjectif::query()->create([
            'pas_axe_id' => $primaryAxis->id,
            'code' => 'OS-MULTI-1',
            'libelle' => 'Premier objectif strategique',
            'date_echeance' => '2026-12-31',
            'ordre' => 1,
        ]);
        $secondaryObjective = PasObjectif::query()->create([
            'pas_axe_id' => $secondaryAxis->id,
            'code' => 'OS-MULTI-2',
            'libelle' => 'Deuxieme objectif strategique',
            'date_echeance' => '2026-12-31',
            'ordre' => 1,
        ]);

        $foreignPas = Pas::query()->create([
            'titre' => 'PAS etranger 2026-2028',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $foreignAxis = PasAxe::query()->create([
            'pas_id' => $foreignPas->id,
            'code' => 'AXE-FOREIGN',
            'libelle' => 'Axe autre PAS',
            'ordre' => 1,
        ]);
        $foreignObjective = PasObjectif::query()->create([
            'pas_axe_id' => $foreignAxis->id,
            'code' => 'OS-FOREIGN',
            'libelle' => 'Objectif autre PAS',
            'date_echeance' => '2026-12-31',
            'ordre' => 1,
        ]);

        return [
            'direction' => $direction,
            'primary_service' => $primaryService,
            'secondary_service' => $secondaryService,
            'foreign_direction' => $foreignDirection,
            'foreign_service' => $foreignService,
            'admin' => $admin,
            'pas' => $pas,
            'primary_axis' => $primaryAxis,
            'secondary_axis' => $secondaryAxis,
            'primary_objective' => $primaryObjective,
            'secondary_objective' => $secondaryObjective,
            'foreign_pas' => $foreignPas,
            'foreign_axis' => $foreignAxis,
            'foreign_objective' => $foreignObjective,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function validPayload(array $fixture): array
    {
        return [
            'pas_axe_id' => $fixture['primary_axis']->id,
            'pas_objectif_id' => $fixture['primary_objective']->id,
            'direction_id' => $fixture['direction']->id,
            'annee' => 2026,
            'objectifs_operationnels' => [
                [
                    'pas_objectif_id' => $fixture['primary_objective']->id,
                    'libelle' => 'Objectif operationnel du premier axe',
                    'service_id' => $fixture['primary_service']->id,
                    'echeance' => '2026-09-30',
                ],
                [
                    'pas_objectif_id' => $fixture['secondary_objective']->id,
                    'libelle' => 'Objectif operationnel du deuxieme axe',
                    'service_id' => $fixture['secondary_service']->id,
                    'echeance' => '2026-10-31',
                ],
            ],
        ];
    }
}
