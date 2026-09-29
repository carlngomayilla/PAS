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
        Schema::table('actions', function (Blueprint $table): void {
            $table->timestamp('historical_execution_confirmed_at')->nullable()->after('historical_execution_comment');
            $table->foreignId('historical_execution_confirmed_by')->nullable()->after('historical_execution_confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('historical_execution_confirmed_by');
            $table->dropColumn('historical_execution_confirmed_at');
        });
    }
};
