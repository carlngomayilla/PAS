<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->date('date_debut_reelle')->nullable()->after('date_debut');
            $table->timestamp('historical_execution_recorded_at')->nullable()->after('date_debut_reelle');
            $table->foreignId('historical_execution_recorded_by')->nullable()->after('historical_execution_recorded_at')->constrained('users')->nullOnDelete();
            $table->text('historical_execution_comment')->nullable()->after('historical_execution_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('historical_execution_recorded_by');
            $table->dropColumn(['date_debut_reelle', 'historical_execution_recorded_at', 'historical_execution_comment']);
        });
    }
};
