<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute l'etape de validation planification au circuit de suivi.
 *
 * Circuit cible : chef de service -> controleur SCIQ -> planification (cloture).
 * Le controleur ne cloture plus : il transmet a la planification (soumise_planification),
 * qui valide (validee_planification = cloture) ou renvoie en correction
 * (correction_planification).
 *
 * Migration NON destructrice : on etend uniquement la contrainte CHECK
 * `actions_statut_validation_check` (meme pattern que la migration de controle
 * 2026_07_13). Aucune donnee existante n'est modifiee a la montee.
 */
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
            'rejetee_direction',
            'validee_direction',
        ]);
    }

    public function down(): void
    {
        if (! $this->supportsCheckReplacement()) {
            return;
        }

        // Rétablit les actions en attente/correction planification vers le niveau controle.
        DB::table('actions')
            ->where('statut_validation', 'soumise_planification')
            ->update(['statut_validation' => 'validee_controle']);
        DB::table('actions')
            ->where('statut_validation', 'correction_planification')
            ->update(['statut_validation' => 'correction_controle']);
        DB::table('actions')
            ->where('statut_validation', 'validee_planification')
            ->update(['statut_validation' => 'validee_controle']);

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
