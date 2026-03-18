<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptas', function (Blueprint $table): void {
            $table->dropUnique('ptas_pao_service_unique');
            $table->unique(['pao_id'], 'ptas_pao_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ptas', function (Blueprint $table): void {
            $table->dropUnique('ptas_pao_unique');
            $table->unique(['pao_id', 'service_id'], 'ptas_pao_service_unique');
        });
    }
};
