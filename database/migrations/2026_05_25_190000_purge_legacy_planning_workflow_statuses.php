<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
            DB::table('pas')
                ->whereIn('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])
                ->update(['statut' => 'actif']);
        }

        if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
            DB::table('paos')
                ->whereIn('statut', ['brouillon', 'soumis', 'fin'])
                ->update(['statut' => 'en_cours']);

            DB::table('paos')
                ->whereIn('statut', ['verrouille'])
                ->update(['statut' => 'valide']);
        }

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
            DB::table('ptas')
                ->whereIn('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])
                ->update(['statut' => 'en_cours']);
        }

        $this->tightenStatusConstraints();
    }

    public function down(): void
    {
        $this->loosenStatusConstraints();
    }

    private function tightenStatusConstraints(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
                DB::statement("ALTER TABLE pas MODIFY statut ENUM('actif','cloture','archive') NOT NULL DEFAULT 'actif'");
            }

            if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
                DB::statement("ALTER TABLE paos MODIFY statut ENUM('en_cours','valide','cloture','archive') NOT NULL DEFAULT 'en_cours'");
            }

            if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
                DB::statement("ALTER TABLE ptas MODIFY statut ENUM('en_cours','cloture','archive') NOT NULL DEFAULT 'en_cours'");
            }

            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck('pas', 'pas_statut_check', "statut IN ('actif','cloture','archive')");
        $this->replaceCheck('paos', 'paos_statut_check', "statut IN ('en_cours','valide','cloture','archive')");
        $this->replaceCheck('ptas', 'ptas_statut_check', "statut IN ('en_cours','cloture','archive')");
    }

    private function loosenStatusConstraints(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
                DB::statement("ALTER TABLE pas MODIFY statut ENUM('brouillon','soumis','actif','valide','cloture','archive','verrouille') NOT NULL DEFAULT 'actif'");
            }

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

        $this->replaceCheck('pas', 'pas_statut_check', "statut IN ('brouillon','soumis','actif','valide','cloture','archive','verrouille')");
        $this->replaceCheck('paos', 'paos_statut_check', "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille','fin')");
        $this->replaceCheck('ptas', 'ptas_statut_check', "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille')");
    }

    private function replaceCheck(string $table, string $constraintName, string $expression): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraintName}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$expression})");
        } catch (Throwable) {
            // SQLite et certains schemas existants ne permettent pas ce resserrage sans rebuild.
        }
    }
};
