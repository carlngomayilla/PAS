<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_objectifs') || Schema::hasColumn('pas_objectifs', 'date_echeance')) {
            return;
        }

        Schema::table('pas_objectifs', function (Blueprint $table): void {
            $table->date('date_echeance')->nullable()->after('libelle');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_objectifs') || ! Schema::hasColumn('pas_objectifs', 'date_echeance')) {
            return;
        }

        Schema::table('pas_objectifs', function (Blueprint $table): void {
            $table->dropColumn('date_echeance');
        });
    }
};
