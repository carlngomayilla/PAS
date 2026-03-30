<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_kpis', function (Blueprint $table): void {
            $table->decimal('kpi_qualite', 7, 2)->default(0)->after('kpi_conformite');
            $table->decimal('kpi_risque', 7, 2)->default(0)->after('kpi_qualite');
        });
    }

    public function down(): void
    {
        Schema::table('action_kpis', function (Blueprint $table): void {
            $table->dropColumn(['kpi_qualite', 'kpi_risque']);
        });
    }
};
