<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->string('risque_lie')->nullable()->after('seuil_alerte_progression');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropColumn('risque_lie');
        });
    }
};
