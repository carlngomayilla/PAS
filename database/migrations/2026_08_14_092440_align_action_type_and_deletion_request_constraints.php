<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE ptas DROP CONSTRAINT IF EXISTS ptas_statut_check');
                DB::statement(<<<'SQL'
                    ALTER TABLE ptas
                    ADD CONSTRAINT ptas_statut_check
                    CHECK (statut IN ('brouillon', 'en_cours', 'controle_sciq', 'cloture', 'archive'))
                    SQL);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE ptas MODIFY statut ENUM('brouillon','en_cours','controle_sciq','cloture','archive') NOT NULL DEFAULT 'en_cours'");
            }
        }

        if (Schema::hasTable('actions') && Schema::hasColumn('actions', 'type_cible')) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_type_cible_check');
                DB::statement(<<<'SQL'
                    ALTER TABLE actions
                    ADD CONSTRAINT actions_type_cible_check
                    CHECK (type_cible IN ('quantitative', 'qualitative', 'sans_quantite', 'mixte'))
                    SQL);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE actions MODIFY type_cible ENUM('quantitative','qualitative','sans_quantite','mixte') NOT NULL DEFAULT 'quantitative'");
            }
        }

        if (
            DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable('deletion_requests')
            && Schema::hasColumn('deletion_requests', 'status')
        ) {
            DB::statement('ALTER TABLE deletion_requests DROP CONSTRAINT IF EXISTS deletion_requests_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE deletion_requests
                ADD CONSTRAINT deletion_requests_status_check
                CHECK (status IS NULL OR status IN (
                    'pending',
                    'approved',
                    'deleted',
                    'disabled',
                    'archived',
                    'rejected',
                    'complement_requested',
                    'corrected'
                ))
                SQL);
        }
    }

    public function down(): void
    {
        $this->assertRollbackIsSafe();

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE ptas DROP CONSTRAINT IF EXISTS ptas_statut_check');
                DB::statement(<<<'SQL'
                    ALTER TABLE ptas
                    ADD CONSTRAINT ptas_statut_check
                    CHECK (statut IN ('en_cours', 'controle_sciq', 'cloture', 'archive'))
                    SQL);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE ptas MODIFY statut ENUM('en_cours','controle_sciq','cloture','archive') NOT NULL DEFAULT 'en_cours'");
            }
        }

        if (Schema::hasTable('actions') && Schema::hasColumn('actions', 'type_cible')) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_type_cible_check');
                DB::statement(<<<'SQL'
                    ALTER TABLE actions
                    ADD CONSTRAINT actions_type_cible_check
                    CHECK (type_cible IN ('quantitative', 'qualitative'))
                    SQL);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE actions MODIFY type_cible ENUM('quantitative','qualitative') NOT NULL DEFAULT 'quantitative'");
            }
        }

        if (
            DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable('deletion_requests')
            && Schema::hasColumn('deletion_requests', 'status')
        ) {
            DB::statement('ALTER TABLE deletion_requests DROP CONSTRAINT IF EXISTS deletion_requests_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE deletion_requests
                ADD CONSTRAINT deletion_requests_status_check
                CHECK (status IS NULL OR status IN (
                    'pending',
                    'deleted',
                    'disabled',
                    'archived',
                    'rejected',
                    'complement_requested',
                    'corrected'
                ))
                SQL);
        }
    }

    private function assertRollbackIsSafe(): void
    {
        $driver = DB::connection()->getDriverName();

        if (
            in_array($driver, ['pgsql', 'mysql'], true)
            && Schema::hasTable('ptas')
            && Schema::hasColumn('ptas', 'statut')
            && DB::table('ptas')->where('statut', 'brouillon')->exists()
        ) {
            throw new RuntimeException('Rollback impossible : des PTA importes sont encore en cours de parametrage.');
        }

        if (
            in_array($driver, ['pgsql', 'mysql'], true)
            && Schema::hasTable('actions')
            && Schema::hasColumn('actions', 'type_cible')
            && DB::table('actions')->whereIn('type_cible', ['sans_quantite', 'mixte'])->exists()
        ) {
            throw new RuntimeException('Rollback impossible : des actions utilisent un type de cible ajoute par cette migration.');
        }

        if (
            $driver === 'pgsql'
            && Schema::hasTable('deletion_requests')
            && Schema::hasColumn('deletion_requests', 'status')
            && DB::table('deletion_requests')->where('status', 'approved')->exists()
        ) {
            throw new RuntimeException('Rollback impossible : des demandes de suppression approuvees attendent leur execution.');
        }
    }
};
