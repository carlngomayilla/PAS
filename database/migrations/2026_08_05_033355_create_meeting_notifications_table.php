<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications ciblees du module, adressees aux seuls utilisateurs concernes
 * par la structure et le role.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('meeting_report_id')->nullable()->constrained('meeting_reports')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notification_type', 50);
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_notifications');
    }
};
