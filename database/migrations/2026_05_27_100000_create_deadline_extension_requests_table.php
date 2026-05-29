<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadline_extension_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->foreignId('sous_action_id')->nullable()->constrained('sous_actions')->nullOnDelete();
            $table->string('target_type', 30)->default('action');
            $table->date('old_deadline');
            $table->date('requested_deadline');
            $table->date('approved_deadline')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('motif');
            $table->text('justification');
            $table->string('attachment_path');
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->boolean('is_critical')->default(false);
            $table->string('status', 60)->default('soumise');
            $table->string('sciq_avis', 40)->nullable();
            $table->text('sciq_comment')->nullable();
            $table->foreignId('sciq_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sciq_reviewed_at')->nullable();
            $table->string('dg_decision', 40)->nullable();
            $table->text('dg_comment')->nullable();
            $table->foreignId('dg_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dg_decided_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['action_id', 'status']);
            $table->index(['sous_action_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_extension_requests');
    }
};
