<?php

namespace Tests\Feature;

use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Services\PasStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class PasStructureSyncTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    public function test_sync_reuses_soft_deleted_generated_axis_codes_instead_of_inserting_duplicates(): void
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS test synchronisation',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
            'statut' => Pas::STATUS_ACTIF,
        ]);

        $service = app(PasStructureService::class);
        $service->sync($pas, [[
            'libelle' => 'Axe initial',
            'objectifs' => [[
                'libelle' => 'Objectif initial',
                'date_echeance' => '2026-12-31',
            ]],
        ]]);

        $initialAxe = PasAxe::query()->where('pas_id', $pas->id)->firstOrFail();
        $initialObjectif = PasObjectif::query()->where('pas_axe_id', $initialAxe->id)->firstOrFail();

        $initialAxe->objectifs()->delete();
        $initialAxe->delete();

        $service->sync($pas, [[
            'libelle' => 'Axe corrige',
            'objectifs' => [[
                'libelle' => 'Objectif corrige',
                'date_echeance' => '2026-12-31',
            ]],
        ]]);

        $syncedAxe = PasAxe::query()->where('pas_id', $pas->id)->firstOrFail();
        $syncedObjectif = PasObjectif::query()->where('pas_axe_id', $syncedAxe->id)->firstOrFail();

        $this->assertSame($initialAxe->id, $syncedAxe->id);
        $this->assertSame('AXE-1', $syncedAxe->code);
        $this->assertSame('Axe corrige', $syncedAxe->libelle);
        $this->assertSame($initialObjectif->id, $syncedObjectif->id);
        $this->assertSame('OS1-1', $syncedObjectif->code);
        $this->assertSame('Objectif corrige', $syncedObjectif->libelle);
        $this->assertSame(1, PasAxe::withTrashed()->where('pas_id', $pas->id)->where('code', 'AXE-1')->count());
    }

    public function test_web_update_keeps_existing_axis_code_without_unique_constraint_violation(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $pas = Pas::query()->create([
            'titre' => 'PAS test formulaire',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
            'statut' => Pas::STATUS_ACTIF,
        ]);

        app(PasStructureService::class)->sync($pas, [[
            'libelle' => 'Axe formulaire',
            'objectifs' => [[
                'libelle' => 'Objectif formulaire',
                'date_echeance' => '2026-12-31',
            ]],
        ]], $superAdmin->id);

        $axe = PasAxe::query()->where('pas_id', $pas->id)->firstOrFail();
        $objectif = PasObjectif::query()->where('pas_axe_id', $axe->id)->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('workspace.pas.update', $pas), [
                'titre' => 'PAS test formulaire',
                'periode_debut' => 2026,
                'periode_fin' => 2026,
                'axes' => [[
                    'id' => $axe->id,
                    'code' => $axe->code,
                    'libelle' => 'Axe formulaire modifie',
                    'objectifs' => [[
                        'id' => $objectif->id,
                        'code' => $objectif->code,
                        'libelle' => 'Objectif formulaire modifie',
                        'date_echeance' => '2026-12-31',
                    ]],
                ]],
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertSame(1, PasAxe::query()->where('pas_id', $pas->id)->count());
        $this->assertDatabaseHas('pas_axes', [
            'id' => $axe->id,
            'code' => 'AXE-1',
            'libelle' => 'Axe formulaire modifie',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('pas_objectifs', [
            'id' => $objectif->id,
            'code' => 'OS1-1',
            'libelle' => 'Objectif formulaire modifie',
            'deleted_at' => null,
        ]);
    }
}
