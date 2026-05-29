<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
                DB::statement("ALTER TABLE pas MODIFY statut ENUM('brouillon','soumis','actif','valide','cloture','archive','verrouille') NOT NULL DEFAULT 'actif'");
            }

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck(
            'pas',
            'pas_statut_check',
            "statut IN ('brouillon','soumis','actif','valide','cloture','archive','verrouille')"
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
            DB::table('pas')
                ->where('statut', 'actif')
                ->update(['statut' => 'valide']);
            DB::table('pas')
                ->whereIn('statut', ['cloture', 'archive'])
                ->update(['statut' => 'verrouille']);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
                DB::statement("ALTER TABLE pas MODIFY statut ENUM('brouillon','soumis','valide','verrouille') NOT NULL DEFAULT 'brouillon'");
            }

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck(
            'pas',
            'pas_statut_check',
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
