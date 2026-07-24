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
        Schema::table('unites_dg', function (Blueprint $table) {
            $table->unique('chef_user_id', 'unites_dg_chef_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unites_dg', function (Blueprint $table) {
            $table->dropUnique('unites_dg_chef_user_unique');
        });
    }
};
