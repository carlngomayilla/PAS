<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Couvre A22 / A24 / A30 : la migration `strengthen_db_constraints` doit
 *   - ajouter une FK ptas.pao_id → paos.id et ptas.service_id → services.id
 *   - (PG only) ajouter les CHECK constraints d enums et la consistance
 *     role_scope vs direction_id / service_id sur les delegations.
 *
 * En SQLite les FK sont posees mais ne sont pas enforcees sans
 * `pragma foreign_keys=on`. On verifie surtout la presence structurelle.
 */
class DatabaseConstraintsCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a22_ptas_has_foreign_keys_on_pao_and_service(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $fks = DB::table('information_schema.table_constraints AS tc')
                ->join('information_schema.key_column_usage AS kcu', 'kcu.constraint_name', '=', 'tc.constraint_name')
                ->where('tc.table_name', 'ptas')
                ->where('tc.constraint_type', 'FOREIGN KEY')
                ->pluck('kcu.column_name')
                ->all();

            $this->assertContains('pao_id', $fks);
            $this->assertContains('service_id', $fks);

            return;
        }

        if ($driver === 'sqlite') {
            $fkInfo = DB::select('PRAGMA foreign_key_list(ptas)');
            $columns = collect($fkInfo)->pluck('from')->all();

            $this->assertContains('pao_id', $columns, 'FK ptas.pao_id manquante.');
            $this->assertContains('service_id', $columns, 'FK ptas.service_id manquante.');

            return;
        }

        $this->markTestSkipped('Driver non couvert : '.$driver);
    }

    public function test_a24_check_constraints_present_on_pgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints sur enums : couvert uniquement par le job CI PostgreSQL.');
        }

        $constraints = DB::table('information_schema.check_constraints')
            ->pluck('constraint_name')
            ->all();

        $this->assertContains('pas_statut_check', $constraints);
        $this->assertContains('paos_statut_check', $constraints);
        $this->assertContains('ptas_statut_check', $constraints);
        $this->assertContains('actions_contexte_action_check', $constraints);
        $this->assertContains('actions_statut_validation_check', $constraints);
        $this->assertContains('delegations_role_scope_check', $constraints);
        $this->assertContains('delegations_scope_consistency_check', $constraints);
    }

    public function test_a30_delegation_scope_consistency_enforced_in_pgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK delegations : couvert uniquement par le job CI PostgreSQL.');
        }

        // Tentative d insertion incoherente : role_scope=service mais service_id NULL.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('delegations')->insert([
            'delegant_id' => 1,
            'delegue_id' => 2,
            'role_scope' => 'service',
            'direction_id' => 1,
            'service_id' => null, // INCOHERENT : doit etre bloque par le CHECK
            'permissions' => json_encode([]),
            'date_debut' => now(),
            'date_fin' => now()->addDays(5),
            'statut' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
