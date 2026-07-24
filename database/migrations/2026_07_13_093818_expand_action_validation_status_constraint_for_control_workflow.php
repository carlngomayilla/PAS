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
            ->where('statut_validation', 'soumise_controle')
            ->update(['statut_validation' => 'validee_chef']);
        DB::table('actions')
            ->where('statut_validation', 'correction_controle')
            ->update(['statut_validation' => 'correction_demandee']);
        DB::table('actions')
            ->where('statut_validation', 'validee_controle')
            ->update(['statut_validation' => 'validee_direction']);

        $this->replaceConstraint([
            'non_soumise',
            'soumise',
            'soumise_chef',
            'rejetee',
            'rejetee_chef',
            'correction_demandee',
            'validee',
            'validee_chef',
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
