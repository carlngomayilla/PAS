<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paos', function (Blueprint $table): void {
            $table->date('echeance')->nullable()->after('titre');
        });
    }

    public function down(): void
    {
        Schema::table('paos', function (Blueprint $table): void {
            $table->dropColumn('echeance');
        });
    }
};

