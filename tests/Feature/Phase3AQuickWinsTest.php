<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Pas;
use App\Models\User;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\RolePermissionSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Couvre la sous-phase 3.A :
 *   - A34 : la vue preview templates retire les balises interactives.
 *   - A37 : scope.global.write est restreint aux profils admin purs.
 *   - A38 : ActionObserver invalide le cache sur seuil_minimum / taux_realisation_global
 *           (evaluation_note retire par la spec v2 du 28/05/2026).
 *   - A39 : AnalyticsCacheVersionService expose bumpAlerts().
 *   - A40 : pas_directions impose UNIQUE (pas_id, direction_id).
 */
class Phase3AQuickWinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a34_preview_view_filters_dangerous_html(): void
    {
        $blade = file_get_contents(base_path('resources/views/workspace/super_admin/templates/preview.blade.php'));
        $this->assertStringContainsString(
            'strip_tags((string) ($preview[\'html\']',
            $blade,
            'A34 — Le rendu HTML preview doit etre filtre via strip_tags.'
        );
        $this->assertStringNotContainsString(
            '{!! $preview[\'html\'] ?? \'\' !!}',
            $blade,
            'A34 — Le rendu non escape historique doit avoir disparu.'
        );
    }

    public function test_a37_scope_global_write_is_restricted_to_admin_roles(): void
    {
        /** @var RolePermissionSettings $registry */
        $registry = app(RolePermissionSettings::class);

        // Roles purement administratifs : ils conservent scope.global.write.
        $this->assertContains('scope.global.write', $registry->forRole(User::ROLE_SUPER_ADMIN));
        $this->assertContains('scope.global.write', $registry->forRole(User::ROLE_ADMIN));
        $this->assertContains('scope.global.write', $registry->forRole(User::ROLE_ADMIN_FONCTIONNEL));

        // Roles metier : doivent l avoir perdu.
        $this->assertNotContains('scope.global.write', $registry->forRole(User::ROLE_PLANIFICATION));
        $this->assertNotContains('scope.global.write', $registry->forRole(User::ROLE_SCIQ));
        $this->assertNotContains('scope.global.write', $registry->forRole(User::ROLE_SCIQ_SUIVI_GLOBAL));
        $this->assertNotContains('scope.global.write', $registry->forRole(User::ROLE_CHEF_UNITE_SCIQ));
        // Note : DG dispose desormais de scope.global.write (regle metier ANBG
        // 2026-05-28). Le DG pilote l'agence avec droit d'ecriture / suppression
        // global sur PAS / PAO / PTA / Actions, c'est volontaire et controle.
        $this->assertContains('scope.global.write', $registry->forRole(User::ROLE_DG));
    }

    public function test_a38_action_observer_bumps_cache_on_seuil_minimum(): void
    {
        // Spec v2 (2026-05-28) : evaluation_note retire de REPORTING_FIELDS apres
        // la suppression de la note du chef. On valide que les autres champs
        // sensibles (seuil_minimum, taux_realisation_global) sont toujours suivis.
        $observerCode = file_get_contents(base_path('app/Observers/ActionObserver.php'));
        $this->assertStringContainsString("'seuil_minimum'", $observerCode);
        $this->assertStringContainsString("'taux_realisation_global'", $observerCode);
        $this->assertStringNotContainsString("'evaluation_note'", $observerCode);
    }

    public function test_a39_analytics_cache_exposes_bump_alerts(): void
    {
        /** @var AnalyticsCacheVersionService $service */
        $service = app(AnalyticsCacheVersionService::class);

        $before = $service->alertsVersion();
        $service->bumpAlerts();
        $after = $service->alertsVersion();

        $this->assertGreaterThan($before, $after);
    }

    public function test_a40_pas_directions_unique_constraint_prevents_duplicates(): void
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-A40',
            'libelle' => 'Direction A40',
            'actif' => true,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS A40',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);

        DB::table('pas_directions')->insert([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('pas_directions')->insert([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
