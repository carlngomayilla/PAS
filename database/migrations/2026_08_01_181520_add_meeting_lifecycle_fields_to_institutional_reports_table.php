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
        Schema::table('institutional_reports', function (Blueprint $table): void {
            $table->string('meeting_type', 20)->nullable()->after('report_type');
            $table->foreignId('responsible_id')->nullable()->after('service_id')->constrained('users')->nullOnDelete();
            $table->timestamp('original_scheduled_at')->nullable()->after('scheduled_at');
            $table->string('location')->nullable()->after('original_scheduled_at');
            $table->json('participant_ids')->nullable()->after('location');
            $table->timestamp('postponed_at')->nullable()->after('held_at');
            $table->foreignId('postponed_by')->nullable()->after('postponed_at')->constrained('users')->nullOnDelete();
            $table->text('postponement_reason')->nullable()->after('postponed_by');
            $table->unsignedSmallInteger('postponement_count')->default(0)->after('postponement_reason');
            $table->timestamp('cancelled_at')->nullable()->after('postponement_count');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->text('actual_agenda')->nullable()->after('summary');
            $table->text('decisions')->nullable()->after('actual_agenda');
            $table->text('recommendations')->nullable()->after('decisions');
            $table->text('difficulties')->nullable()->after('recommendations');
            $table->text('observations')->nullable()->after('difficulties');
            $table->timestamp('minutes_published_at')->nullable()->after('submitted_at');

            $table->index(['report_type', 'scheduled_at'], 'institutional_reports_meeting_schedule_index');
            $table->index(['direction_id', 'service_id', 'scheduled_at'], 'institutional_reports_meeting_scope_schedule_index');
            $table->index(['meeting_type', 'responsible_id'], 'institutional_reports_meeting_responsible_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutional_reports', function (Blueprint $table): void {
            $table->dropIndex('institutional_reports_meeting_schedule_index');
            $table->dropIndex('institutional_reports_meeting_scope_schedule_index');
            $table->dropIndex('institutional_reports_meeting_responsible_index');
            $table->dropConstrainedForeignId('responsible_id');
            $table->dropConstrainedForeignId('postponed_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'meeting_type',
                'location',
                'participant_ids',
                'original_scheduled_at',
                'postponed_at',
                'postponement_reason',
                'postponement_count',
                'cancelled_at',
                'cancellation_reason',
                'actual_agenda',
                'decisions',
                'recommendations',
                'difficulties',
                'observations',
                'minutes_published_at',
            ]);
        });
    }
};
