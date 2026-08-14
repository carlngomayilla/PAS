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
            // Les PV créés avant le stockage chiffré sont des fichiers clairs.
            // Les nouveaux dépôts renseignent explicitement cette valeur.
            $table->boolean('is_encrypted')->default(false)->after('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_reports', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};
