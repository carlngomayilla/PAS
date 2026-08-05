<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visas poses sur un PV : d'abord le SCIQ, puis la Planification.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_report_id')->constrained('meeting_reports')->cascadeOnDelete();
            $table->string('approval_level', 20);
            $table->string('decision', 30);
            $table->text('comment')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_report_id', 'approval_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_approvals');
    }
};
