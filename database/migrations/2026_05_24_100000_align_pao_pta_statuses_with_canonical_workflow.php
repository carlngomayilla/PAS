<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
                DB::statement("ALTER TABLE paos MODIFY statut ENUM('brouillon','soumis','en_cours','valide','cloture','archive','verrouille','fin') NOT NULL DEFAULT 'en_cours'");
            }

            if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
                DB::statement("ALTER TABLE ptas MODIFY statut ENUM('brouillon','soumis','en_cours','valide','cloture','archive','verrouille') NOT NULL DEFAULT 'en_cours'");
            }

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck(
            'paos',
            'paos_statut_check',
            "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille','fin')"
        );

        $this->replaceCheck(
            'ptas',
            'ptas_statut_check',
            "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille')"
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
            DB::table('paos')
                ->whereIn('statut', ['en_cours'])
                ->update(['statut' => 'brouillon']);
            DB::table('paos')
                ->whereIn('statut', ['cloture', 'archive'])
                ->update(['statut' => 'verrouille']);
        }

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
            DB::table('ptas')
                ->whereIn('statut', ['en_cours'])
                ->update(['statut' => 'brouillon']);
            DB::table('ptas')
                ->whereIn('statut', ['cloture', 'archive'])
                ->update(['statut' => 'verrouille']);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
                DB::statement("ALTER TABLE paos MODIFY statut ENUM('brouillon','soumis','valide','verrouille','fin') NOT NULL DEFAULT 'brouillon'");
            }

            if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
                DB::statement("ALTER TABLE ptas MODIFY statut ENUM('brouillon','soumis','valide','verrouille') NOT NULL DEFAULT 'brouillon'");
            }

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck(
            'paos',
            'paos_statut_check',
            "statut IN ('brouillon','soumis','valide','verrouille','fin')"
        );

        $this->replaceCheck(
            'ptas',
            'ptas_statut_check',
            "statut IN ('brouillon','soumis','valide','verrouille')"
        );
    }

    private function replaceCheck(string $table, string $constraintName, string $expression): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraintName}");
        } catch (Throwable) {
            return;
        }

        try {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$expression})");
        } catch (Throwable) {
            // Non bloquant en migration idempotente.
        }
    }
};
