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
        Schema::table('meeting_notifications', function (Blueprint $table): void {
            $table->foreignId('meeting_plan_id')
                ->nullable()
                ->after('meeting_id')
                ->constrained('meeting_plans')
                ->nullOnDelete();
            $table->index(
                ['meeting_plan_id', 'user_id', 'notification_type'],
                'meeting_notifications_plan_user_type_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_notifications', function (Blueprint $table): void {
            $table->dropIndex('meeting_notifications_plan_user_type_index');
            $table->dropConstrainedForeignId('meeting_plan_id');
        });
    }
};
