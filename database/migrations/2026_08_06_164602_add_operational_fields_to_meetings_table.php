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
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('responsible_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('location')->nullable()->after('label');
            $table->text('agenda')->nullable()->after('location');
            $table->json('participant_ids')->nullable()->after('agenda');
            $table->timestamp('held_at')->nullable()->after('scheduled_time');

            $table->index(['responsible_id', 'status'], 'meetings_responsible_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_responsible_status_index');
            $table->dropConstrainedForeignId('responsible_id');
            $table->dropColumn(['location', 'agenda', 'participant_ids', 'held_at']);
        });
    }
};
