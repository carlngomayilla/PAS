<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->enum('frequence_execution', [
                'instantanee',
                'journaliere',
                'hebdomadaire',
                'mensuelle',
                'annuelle',
            ])->default('hebdomadaire')->after('date_fin');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropColumn('frequence_execution');
        });
    }
};
