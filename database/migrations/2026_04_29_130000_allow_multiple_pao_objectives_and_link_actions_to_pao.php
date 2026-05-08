<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paos')) {
            if ($this->indexExists('paos', 'paos_objectif_annee_direction_service_unique')) {
                Schema::table('paos', function (Blueprint $table): void {
                    $table->dropUnique('paos_objectif_annee_direction_service_unique');
                });
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE paos MODIFY statut ENUM('brouillon','soumis','valide','verrouille','fin') NOT NULL DEFAULT 'brouillon'");
            }

            if (! $this->indexExists('paos', 'paos_objectif_annee_direction_service_index')) {
                Schema::table('paos', function (Blueprint $table): void {
                    $table->index(
                        ['pas_objectif_id', 'annee', 'direction_id', 'service_id'],
                        'paos_objectif_annee_direction_service_index'
                    );
                });
            }
        }

        if (Schema::hasTable('actions') && ! Schema::hasColumn('actions', 'pao_id')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->foreignId('pao_id')
                    ->nullable()
                    ->after('pta_id')
                    ->constrained('paos')
                    ->nullOnDelete();
                $table->index(['pao_id'], 'actions_pao_id_index');
            });

        }

        if (
            Schema::hasTable('actions')
            && Schema::hasColumn('actions', 'pao_id')
            && Schema::hasTable('ptas')
            && Schema::hasColumn('ptas', 'pao_id')
        ) {
            DB::statement('UPDATE actions SET pao_id = (SELECT ptas.pao_id FROM ptas WHERE ptas.id = actions.pta_id) WHERE pao_id IS NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('actions') && Schema::hasColumn('actions', 'pao_id')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropForeign(['pao_id']);
                $table->dropIndex('actions_pao_id_index');
                $table->dropColumn('pao_id');
            });
        }

        if (Schema::hasTable('paos')) {
            if ($this->indexExists('paos', 'paos_objectif_annee_direction_service_index')) {
                Schema::table('paos', function (Blueprint $table): void {
                    $table->dropIndex('paos_objectif_annee_direction_service_index');
                });
            }

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE paos MODIFY statut ENUM('brouillon','soumis','valide','verrouille') NOT NULL DEFAULT 'brouillon'");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $indexes = DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$index]);

            return $indexes !== [];
        }

        return false;
    }
};
