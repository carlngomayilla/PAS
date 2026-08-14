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
        Schema::table('meeting_reports', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('observation');
            $table->text('actual_agenda')->nullable()->after('summary');
            $table->text('decisions')->nullable()->after('actual_agenda');
            $table->text('recommendations')->nullable()->after('decisions');
            $table->text('difficulties')->nullable()->after('recommendations');
            $table->text('observations')->nullable()->after('difficulties');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_reports', function (Blueprint $table) {
            $table->dropColumn([
                'summary',
                'actual_agenda',
                'decisions',
                'recommendations',
                'difficulties',
                'observations',
            ]);
        });
    }
};
