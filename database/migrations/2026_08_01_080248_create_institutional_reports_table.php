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
        Schema::create('institutional_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 40)->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->foreignId('direction_id')->nullable()->constrained('directions')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('held_at')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->json('review_history')->nullable();
            $table->timestamps();

            $table->index(['direction_id', 'service_id', 'status'], 'institutional_reports_scope_status_index');
            $table->index(['report_type', 'status'], 'institutional_reports_type_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institutional_reports');
    }
};
