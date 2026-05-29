<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deletion_requests')) {
            return;
        }

        Schema::create('deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 80);
            $table->string('entity_type', 160);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label')->nullable();
            $table->string('requested_action', 40)->default('delete');
            $table->string('status', 40)->default('pending')->index();
            $table->text('reason');
            $table->text('reviewer_note')->nullable();
            $table->json('impact_summary')->nullable();
            $table->string('decision', 40)->nullable();
            $table->timestamp('decided_at')->nullable()->index();
            $table->timestamp('executed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'deletion_requests_entity_index');
            $table->index(['requested_by', 'status'], 'deletion_requests_requester_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_requests');
    }
};
