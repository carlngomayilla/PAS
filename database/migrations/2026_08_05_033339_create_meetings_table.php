<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reunions programmees par les chefs de service et les directeurs.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            // Nul pour une reunion supplementaire hors objectif.
            $table->foreignId('meeting_plan_id')->nullable()->constrained('meeting_plans')->nullOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('meeting_type', 20);
            $table->string('label');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedTinyInteger('month');
            $table->date('original_scheduled_date');
            $table->date('current_scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->string('status', 40);
            $table->boolean('is_extra')->default(false);
            $table->boolean('was_postponed')->default(false);
            $table->unsignedSmallInteger('postponement_count')->default(0);
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['direction_id', 'service_id']);
            $table->index(['year', 'quarter', 'month']);
            $table->index('status');
            $table->index('current_scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
