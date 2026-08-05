<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit du module : chaque changement de statut est trace.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('meeting_report_id')->nullable()->constrained('meeting_reports')->nullOnDelete();
            $table->string('old_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->text('comment')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['meeting_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_status_histories');
    }
};
