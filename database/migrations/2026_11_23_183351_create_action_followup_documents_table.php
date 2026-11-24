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
        Schema::create('action_followup_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('justificatif_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('stage', 40);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rmo_signature_receipt_id')->nullable()->constrained('action_signature_receipts')->restrictOnDelete();
            $table->foreignId('chef_signature_receipt_id')->nullable()->constrained('action_signature_receipts')->restrictOnDelete();
            $table->foreignId('planification_signature_receipt_id')->nullable()->constrained('action_signature_receipts')->restrictOnDelete();
            $table->foreignId('sciq_signature_receipt_id')->nullable()->constrained('action_signature_receipts')->restrictOnDelete();
            $table->string('document_sha256', 64);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['action_id', 'version'], 'action_followup_documents_action_version_unique');
            $table->index(['action_id', 'stage'], 'action_followup_documents_stage_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_followup_documents');
    }
};
