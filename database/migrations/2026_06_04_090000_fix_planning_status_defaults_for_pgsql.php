<?php

use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->syncMysql();

            return;
        }

        if ($driver === 'pgsql') {
            $this->syncPgsql();

            return;
        }

        $this->normalizeRows();
    }

    public function down(): void
    {
        // Forward-only production correction: keep canonical defaults.
    }

    private function syncPgsql(): void
    {
        $this->replaceCheck('pas', 'pas_statut_check', "statut IN ('brouillon','soumis','actif','valide','cloture','archive','verrouille')");
        $this->replaceCheck('paos', 'paos_statut_check', "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille','fin')");
        $this->replaceCheck('ptas', 'ptas_statut_check', "statut IN ('brouillon','soumis','en_cours','valide','cloture','archive','verrouille')");

        $this->normalizeRows();

        if (Schema::hasColumn('pas', 'statut')) {
            DB::statement("ALTER TABLE pas ALTER COLUMN statut SET DEFAULT 'actif'");
        }
        if (Schema::hasColumn('paos', 'statut')) {
            DB::statement("ALTER TABLE paos ALTER COLUMN statut SET DEFAULT 'en_cours'");
        }
        if (Schema::hasColumn('ptas', 'statut')) {
            DB::statement("ALTER TABLE ptas ALTER COLUMN statut SET DEFAULT 'en_cours'");
        }

        $this->replaceCheck('pas', 'pas_statut_check', "statut IN ('actif','cloture','archive')");
        $this->replaceCheck('paos', 'paos_statut_check', "statut IN ('en_cours','valide','cloture','archive')");
        $this->replaceCheck('ptas', 'ptas_statut_check', "statut IN ('en_cours','cloture','archive')");
    }

    private function syncMysql(): void
    {
        if (Schema::hasColumn('pas', 'statut')) {
            DB::statement("ALTER TABLE pas MODIFY statut ENUM('brouillon','soumis','actif','valide','cloture','archive','verrouille') NOT NULL DEFAULT 'actif'");
        }
        if (Schema::hasColumn('paos', 'statut')) {
            DB::statement("ALTER TABLE paos MODIFY statut ENUM('brouillon','soumis','en_cours','valide','cloture','archive','verrouille','fin') NOT NULL DEFAULT 'en_cours'");
        }
        if (Schema::hasColumn('ptas', 'statut')) {
            DB::statement("ALTER TABLE ptas MODIFY statut ENUM('brouillon','soumis','en_cours','valide','cloture','archive','verrouille') NOT NULL DEFAULT 'en_cours'");
        }

        $this->normalizeRows();

        if (Schema::hasColumn('pas', 'statut')) {
            DB::statement("ALTER TABLE pas MODIFY statut ENUM('actif','cloture','archive') NOT NULL DEFAULT 'actif'");
        }
        if (Schema::hasColumn('paos', 'statut')) {
            DB::statement("ALTER TABLE paos MODIFY statut ENUM('en_cours','valide','cloture','archive') NOT NULL DEFAULT 'en_cours'");
        }
        if (Schema::hasColumn('ptas', 'statut')) {
            DB::statement("ALTER TABLE ptas MODIFY statut ENUM('en_cours','cloture','archive') NOT NULL DEFAULT 'en_cours'");
        }
    }

    private function normalizeRows(): void
    {
        if (Schema::hasTable('pas') && Schema::hasColumn('pas', 'statut')) {
            DB::table('pas')
                ->whereNull('statut')
                ->orWhereIn('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])
                ->update(['statut' => Pas::STATUS_ACTIF]);
        }

        if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'statut')) {
            DB::table('paos')
                ->whereNull('statut')
                ->orWhereIn('statut', ['brouillon', 'soumis', 'fin'])
                ->update(['statut' => Pao::STATUS_EN_COURS]);

            DB::table('paos')
                ->where('statut', 'verrouille')
                ->update(['statut' => Pao::STATUS_VALIDE]);
        }

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'statut')) {
            DB::table('ptas')
                ->whereNull('statut')
                ->orWhereIn('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])
                ->update(['statut' => Pta::STATUS_EN_COURS]);
        }
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
            // Non bloquant pour les environnements dont le schema diverge deja.
        }
    }
};
