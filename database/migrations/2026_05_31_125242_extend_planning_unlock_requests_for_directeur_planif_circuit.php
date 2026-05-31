<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit de modification d'action V2 (cf. docs/WORKFLOW-SUIVI-V2.md §11) :
 * Chef → Directeur (transfère) → Planification (avis consultatif) → DG (décide).
 *
 * Étend planning_unlock_requests pour tracer l'étape directeur et l'avis planif,
 * en plus de la décision DG déjà existante (reviewed_by / decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_unlock_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('planning_unlock_requests', 'transferred_by')) {
                $table->unsignedBigInteger('transferred_by')->nullable()->after('requested_by');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'transferred_at')) {
                $table->timestamp('transferred_at')->nullable()->after('transferred_by');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'transfer_comment')) {
                $table->text('transfer_comment')->nullable()->after('transferred_at');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'planif_avis')) {
                $table->string('planif_avis')->nullable()->after('transfer_comment');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'planif_avis_by')) {
                $table->unsignedBigInteger('planif_avis_by')->nullable()->after('planif_avis');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'planif_avis_at')) {
                $table->timestamp('planif_avis_at')->nullable()->after('planif_avis_by');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'planif_comment')) {
                $table->text('planif_comment')->nullable()->after('planif_avis_at');
            }
            if (! Schema::hasColumn('planning_unlock_requests', 'justificatif_path')) {
                $table->string('justificatif_path')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('planning_unlock_requests', function (Blueprint $table): void {
            foreach ([
                'transferred_by', 'transferred_at', 'transfer_comment',
                'planif_avis', 'planif_avis_by', 'planif_avis_at', 'planif_comment',
                'justificatif_path',
            ] as $col) {
                if (Schema::hasColumn('planning_unlock_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
