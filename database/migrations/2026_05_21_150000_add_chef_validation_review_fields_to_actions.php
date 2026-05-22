<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'taux_valide_chef')) {
                $table->decimal('taux_valide_chef', 5, 2)->nullable()->after('evaluation_note');
            }

            if (! Schema::hasColumn('actions', 'conformite_chef')) {
                $table->string('conformite_chef', 40)->nullable()->after('taux_valide_chef');
            }

            if (! Schema::hasColumn('actions', 'observation_qualite_chef')) {
                $table->text('observation_qualite_chef')->nullable()->after('conformite_chef');
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_statut_validation_check');
            DB::statement(
                "ALTER TABLE actions ADD CONSTRAINT actions_statut_validation_check "
                ."CHECK (statut_validation IS NULL OR statut_validation IN "
                ."('non_soumise','soumise_chef','rejetee_chef','correction_demandee','validee_chef','rejetee_direction','validee_direction'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_statut_validation_check');
            DB::statement(
                "ALTER TABLE actions ADD CONSTRAINT actions_statut_validation_check "
                ."CHECK (statut_validation IS NULL OR statut_validation IN "
                ."('non_soumise','soumise_chef','rejetee_chef','validee_chef','rejetee_direction','validee_direction'))"
            );
        }

        Schema::table('actions', function (Blueprint $table): void {
            foreach (['observation_qualite_chef', 'conformite_chef', 'taux_valide_chef'] as $column) {
                if (Schema::hasColumn('actions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
