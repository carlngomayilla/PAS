<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // foreignId().constrained() crée automatiquement un index sur la colonne.
            $table->foreignId('unite_dg_id')
                ->nullable()
                ->after('service_id')
                ->constrained('unites_dg')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unite_dg_id');
        });
    }
};
