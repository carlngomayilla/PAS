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
        Schema::table('sous_actions', function (Blueprint $table) {
            $table->decimal('seuil_minimum', 5, 2)->default(80)->after('quantite_a_realiser');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sous_actions', function (Blueprint $table) {
            $table->dropColumn('seuil_minimum');
        });
    }
};
