<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpis', function (Blueprint $table): void {
            $table->boolean('est_a_renseigner')
                ->default(true)
                ->after('periodicite');
        });
    }

    public function down(): void
    {
        Schema::table('kpis', function (Blueprint $table): void {
            $table->dropColumn('est_a_renseigner');
        });
    }
};
