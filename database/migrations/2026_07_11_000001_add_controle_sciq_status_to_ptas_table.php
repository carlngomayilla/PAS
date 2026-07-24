<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ptas') || ! Schema::hasColumn('ptas', 'statut')) {
            return;
        }

        $this->normalizePtaStatuses();

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ptas MODIFY statut ENUM('en_cours','controle_sciq','cloture','archive') NOT NULL DEFAULT 'en_cours'");

            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceCheck("statut IN ('en_cours','controle_sciq','cloture','archive')");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ptas') || ! Schema::hasColumn('ptas', 'statut')) {
            return;
        }

        DB::table('ptas')
            ->where('statut', 'controle_sciq')
            ->update(['statut' => 'en_cours']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ptas MODIFY statut ENUM('en_cours','cloture','archive') NOT NULL DEFAULT 'en_cours'");

            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceCheck("statut IN ('en_cours','cloture','archive')");
        }
    }

    private function replaceCheck(string $expression): void
    {
        try {
            DB::statement('ALTER TABLE ptas DROP CONSTRAINT IF EXISTS ptas_statut_check');
            DB::statement("ALTER TABLE ptas ADD CONSTRAINT ptas_statut_check CHECK ({$expression})");
        } catch (Throwable) {
            // SQLite and some existing local schemas do not expose this check.
        }
    }

    private function normalizePtaStatuses(): void
    {
        DB::table('ptas')
            ->whereNull('statut')
            ->orWhereNotIn('statut', ['en_cours', 'controle_sciq', 'cloture', 'archive'])
            ->update(['statut' => 'en_cours']);
    }
};
