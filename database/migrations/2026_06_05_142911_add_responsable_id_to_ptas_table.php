<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptas', function (Blueprint $table) {
            if (! Schema::hasColumn('ptas', 'responsable_id')) {
                $table->foreignId('responsable_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ptas', function (Blueprint $table) {
            if (Schema::hasColumn('ptas', 'responsable_id')) {
                $table->dropColumn('responsable_id');
            }
        });
    }
};
