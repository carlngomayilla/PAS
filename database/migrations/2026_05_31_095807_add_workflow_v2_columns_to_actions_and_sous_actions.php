<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow de suivi V2 — colonnes de définition + performance officielle.
 *
 * Voir docs/WORKFLOW-SUIVI-V2.md pour la spécification complète.
 *
 * - actions : type_action (pivot) + requires_comment + allows_difficulty
 *             + official_progress_percent.
 * - sous_actions : sub_action_type + weight + requires_proof + requires_comment
 *             + allows_difficulty + official_progress_percent + validation_status.
 *
 * Mapping auto des données existantes (66 actions) :
 *   type_action = composee  si l'action a des sous-actions
 *               = quantitative   si type_cible = 'quantitative'
 *               = non_quantitative sinon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'type_action')) {
                $table->string('type_action')->nullable()->after('mode_evaluation');
            }
            if (! Schema::hasColumn('actions', 'requires_comment')) {
                $table->boolean('requires_comment')->default(false)->after('justificatif_obligatoire');
            }
            if (! Schema::hasColumn('actions', 'allows_difficulty')) {
                $table->boolean('allows_difficulty')->default(true)->after('requires_comment');
            }
            if (! Schema::hasColumn('actions', 'official_progress_percent')) {
                $table->decimal('official_progress_percent', 8, 2)->default(0)->after('progression_reelle');
            }
        });

        Schema::table('sous_actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('sous_actions', 'sub_action_type')) {
                $table->string('sub_action_type')->nullable()->after('libelle');
            }
            if (! Schema::hasColumn('sous_actions', 'weight')) {
                $table->decimal('weight', 6, 2)->nullable()->after('cible_prevue');
            }
            if (! Schema::hasColumn('sous_actions', 'requires_proof')) {
                $table->boolean('requires_proof')->default(true)->after('weight');
            }
            if (! Schema::hasColumn('sous_actions', 'requires_comment')) {
                $table->boolean('requires_comment')->default(false)->after('requires_proof');
            }
            if (! Schema::hasColumn('sous_actions', 'allows_difficulty')) {
                $table->boolean('allows_difficulty')->default(true)->after('requires_comment');
            }
            if (! Schema::hasColumn('sous_actions', 'official_progress_percent')) {
                $table->decimal('official_progress_percent', 8, 2)->default(0)->after('taux_realisation');
            }
            if (! Schema::hasColumn('sous_actions', 'validation_status')) {
                $table->string('validation_status')->default('non_soumise')->after('statut');
            }
        });

        $this->mapExistingActions();
        $this->mapExistingSubActions();
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            foreach (['type_action', 'requires_comment', 'allows_difficulty', 'official_progress_percent'] as $col) {
                if (Schema::hasColumn('actions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('sous_actions', function (Blueprint $table): void {
            foreach ([
                'sub_action_type', 'weight', 'requires_proof', 'requires_comment',
                'allows_difficulty', 'official_progress_percent', 'validation_status',
            ] as $col) {
                if (Schema::hasColumn('sous_actions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * Mappe les actions existantes vers `type_action` selon la règle métier V2.
     */
    private function mapExistingActions(): void
    {
        // IDs des actions ayant au moins une sous-action → composee.
        $composeeIds = DB::table('sous_actions')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('action_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($composeeIds !== []) {
            DB::table('actions')->whereIn('id', $composeeIds)->update(['type_action' => 'composee']);
        }

        // Actions quantitatives restantes.
        DB::table('actions')
            ->whereNull('type_action')
            ->where('type_cible', 'quantitative')
            ->update(['type_action' => 'quantitative']);

        // Tout le reste → non quantitative.
        DB::table('actions')
            ->whereNull('type_action')
            ->update(['type_action' => 'non_quantitative']);

        // official_progress_percent initialisé sur la progression officielle
        // uniquement pour les actions déjà validées par le chef (statut_validation).
        DB::table('actions')
            ->where('statut_validation', 'validee_chef')
            ->update(['official_progress_percent' => DB::raw('COALESCE(progression_reelle, 0)')]);
    }

    /**
     * Initialise sub_action_type pour les sous-actions existantes selon cible_prevue.
     */
    private function mapExistingSubActions(): void
    {
        // Avec cible quantitative > 0 → quantitative.
        DB::table('sous_actions')
            ->whereNull('sub_action_type')
            ->where('cible_prevue', '>', 0)
            ->update(['sub_action_type' => 'quantitative']);

        // Sinon → non quantitative.
        DB::table('sous_actions')
            ->whereNull('sub_action_type')
            ->update(['sub_action_type' => 'non_quantitative']);
    }
};
