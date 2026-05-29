<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sous_actions') || ! Schema::hasColumn('sous_actions', 'statut')) {
            return;
        }

        DB::table('sous_actions')
            ->where('statut', 'a_faire')
            ->update(['statut' => 'non_demarre']);

        $this->setDefaultStatus('non_demarre');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sous_actions') || ! Schema::hasColumn('sous_actions', 'statut')) {
            return;
        }

        DB::table('sous_actions')
            ->where('statut', 'non_demarre')
            ->where('est_effectuee', false)
            ->update(['statut' => 'a_faire']);

        $this->setDefaultStatus('a_faire');
    }

    private function setDefaultStatus(string $status): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sous_actions ALTER COLUMN statut SET DEFAULT '{$status}'");
        }
    }
};
