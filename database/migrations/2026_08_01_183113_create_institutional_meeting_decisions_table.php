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
        Schema::create('institutional_meeting_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institutional_report_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('normal');
            $table->date('due_at')->nullable();
            $table->string('status', 30)->default('to_do');
            $table->text('follow_up_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['institutional_report_id', 'status'], 'meeting_decisions_report_status_index');
            $table->index(['responsible_id', 'status', 'due_at'], 'meeting_decisions_responsible_status_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutional_meeting_decisions', function (Blueprint $table): void {
            $table->dropIndex('meeting_decisions_report_status_index');
            $table->dropIndex('meeting_decisions_responsible_status_due_index');
        });

        Schema::dropIfExists('institutional_meeting_decisions');
    }
};
