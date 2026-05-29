<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Services\PlanningAutoArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class PlanningAutoArchiveWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    public function test_super_admin_configures_and_runs_automatic_pao_pta_archiving(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->planningFixture();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.exercises.index'))
            ->assertOk()
            ->assertSee('Archivage automatique PAO / PTA');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.exercises.archive-settings.update'), [
                'planning_auto_archive_enabled' => '1',
                'planning_pao_archive_after_days' => 10,
                'planning_pta_archive_after_days' => 10,
            ])
            ->assertRedirect(route('workspace.super-admin.exercises.index'));

        $summary = app(PlanningAutoArchiveService::class)->summary();
        $this->assertSame(1, (int) $summary['counts']['paos']);
        $this->assertSame(1, (int) $summary['counts']['ptas']);

        $dryRun = app(PlanningAutoArchiveService::class)->run(false, $superAdmin);
        $this->assertSame('dry-run', $dryRun['mode']);
        $this->assertSame(Pao::STATUS_CLOTURE, $fixture['oldPao']->fresh()->statut);
        $this->assertSame(Pta::STATUS_CLOTURE, $fixture['oldPta']->fresh()->statut);

        $this->actingAs($superAdmin)
            ->post(route('workspace.retention.run'), [
                'scope' => 'planning',
                'mode' => 'execute',
            ])
            ->assertRedirect(route('workspace.retention.index'));

        $this->assertSame(Pao::STATUS_ARCHIVE, $fixture['oldPao']->fresh()->statut);
        $this->assertSame(Pta::STATUS_ARCHIVE, $fixture['oldPta']->fresh()->statut);
        $this->assertSame(Pao::STATUS_CLOTURE, $fixture['recentPao']->fresh()->statut);
        $this->assertSame(Pta::STATUS_CLOTURE, $fixture['recentPta']->fresh()->statut);

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'pao',
            'action' => 'auto_archive',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'pta',
            'action' => 'auto_archive',
        ]);

        $audit = JournalAudit::query()->where('action', 'auto_archive')->latest('id')->firstOrFail();
        $after = is_array($audit->nouvelle_valeur) ? $audit->nouvelle_valeur : [];
        $this->assertSame('Archivage automatique apres duree parametree.', $after['motif'] ?? null);
    }

    public function test_planning_auto_archive_command_honors_execute_flag(): void
    {
        $fixture = $this->planningFixture();

        $this->artisan('anbg:planning-auto-archive')
            ->assertExitCode(0);

        $this->assertSame(Pao::STATUS_CLOTURE, $fixture['oldPao']->fresh()->statut);

        $this->artisan('anbg:planning-auto-archive --execute')
            ->assertExitCode(0);

        $this->assertSame(Pao::STATUS_ARCHIVE, $fixture['oldPao']->fresh()->statut);
    }

    /**
     * @return array{oldPao: Pao, recentPao: Pao, oldPta: Pta, recentPta: Pta}
     */
    private function planningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-AUTO-ARCH',
            'libelle' => 'Direction auto archive',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-AUTO-ARCH',
            'libelle' => 'Service auto archive',
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS auto archive',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
        ]);
        $pas->forceFill(['statut' => Pas::STATUS_ACTIF])->save();

        $oldPao = $this->makePao($pas, $direction, 2026, now()->subDays(40));
        $recentPao = $this->makePao($pas, $direction, 2027, now()->subDays(2));

        $oldPta = $this->makePta($oldPao, $direction, $service, now()->subDays(40));
        $recentPta = $this->makePta($recentPao, $direction, $service, now()->subDays(2));

        return compact('oldPao', 'recentPao', 'oldPta', 'recentPta');
    }

    private function makePao(Pas $pas, Direction $direction, int $year, mixed $closedAt): Pao
    {
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => $year,
            'titre' => 'PAO auto archive '.$year,
        ]);
        $pao->forceFill([
            'statut' => Pao::STATUS_CLOTURE,
            'valide_le' => $closedAt,
        ])->save();

        return $pao;
    }

    private function makePta(Pao $pao, Direction $direction, Service $service, mixed $closedAt): Pta
    {
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA auto archive '.$pao->annee,
        ]);
        $pta->forceFill([
            'statut' => Pta::STATUS_CLOTURE,
            'valide_le' => $closedAt,
        ])->save();

        return $pta;
    }
}
