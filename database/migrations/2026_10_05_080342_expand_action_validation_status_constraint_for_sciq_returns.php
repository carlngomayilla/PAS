<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (! $this->supportsCheckReplacement()) {
            return;
        }

        $this->replaceConstraint([
            'non_soumise',
            'soumise',
            'soumise_chef',
            'rejetee',
            'rejetee_chef',
            'correction_demandee',
            'validee',
            'validee_chef',
            'soumise_controle',
            'correction_controle',
            'validee_controle',
            'soumise_planification',
            'correction_planification',
            'validee_planification',
            'retour_sciq',
            'retour_planification',
            'reexamen_sciq',
            'rejetee_direction',
            'validee_direction',
        ]);
    }

    public function down(): void
    {
        if (! $this->supportsCheckReplacement()) {
            return;
        }

        DB::table('actions')
            ->whereIn('statut_validation', ['retour_sciq', 'retour_planification'])
            ->update(['statut_validation' => 'correction_demandee']);
        DB::table('actions')
            ->where('statut_validation', 'reexamen_sciq')
            ->update(['statut_validation' => 'soumise_controle']);

        $this->replaceConstraint([
            'non_soumise',
            'soumise',
            'soumise_chef',
            'rejetee',
            'rejetee_chef',
            'correction_demandee',
            'validee',
            'validee_chef',
            'soumise_controle',
            'correction_controle',
            'validee_controle',
            'soumise_planification',
            'correction_planification',
            'validee_planification',
            'rejetee_direction',
            'validee_direction',
        ]);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function replaceConstraint(array $allowedStatuses): void
    {
        $allowedValues = implode(', ', array_map(
            static fn (string $status): string => DB::connection()->getPdo()->quote($status),
            $allowedStatuses
        ));

        DB::statement('ALTER TABLE "actions" DROP CONSTRAINT IF EXISTS "actions_statut_validation_check"');
        DB::statement(
            'ALTER TABLE "actions" ADD CONSTRAINT "actions_statut_validation_check" '
            .'CHECK ("statut_validation" IS NULL OR "statut_validation" IN ('.$allowedValues.'))'
        );
    }

    private function supportsCheckReplacement(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable('actions')
            && Schema::hasColumn('actions', 'statut_validation');
    }
};
