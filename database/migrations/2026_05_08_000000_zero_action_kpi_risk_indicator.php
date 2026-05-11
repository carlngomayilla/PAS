<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('action_kpis') && Schema::hasColumn('action_kpis', 'kpi_risque')) {
            DB::table('action_kpis')->update(['kpi_risque' => 0]);
        }
    }

    public function down(): void
    {
        // Intentionally left empty: the previous risk indicator values were legacy KPI data.
    }
};
