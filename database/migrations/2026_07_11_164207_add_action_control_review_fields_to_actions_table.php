<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->decimal('chef_progress_percent', 5, 2)->nullable()->after('official_progress_percent');
            $table->text('chef_adjustment_reason')->nullable()->after('chef_progress_percent');
            $table->string('controle_decision', 30)->nullable()->after('motif_validation_chef');
            $table->text('controle_comment')->nullable()->after('controle_decision');
            $table->foreignId('controle_reviewed_by')
                ->nullable()
                ->after('controle_comment')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('controle_reviewed_at')->nullable()->after('controle_reviewed_by');
            $table->index(['statut_validation', 'evalue_le'], 'actions_validation_review_queue_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('controle_reviewed_by');
            $table->dropIndex('actions_validation_review_queue_index');
            $table->dropColumn([
                'chef_progress_percent',
                'chef_adjustment_reason',
                'controle_decision',
                'controle_comment',
                'controle_reviewed_at',
            ]);
        });
    }
};
