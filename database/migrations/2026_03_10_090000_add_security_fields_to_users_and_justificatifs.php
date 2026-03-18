<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });

        Schema::table('justificatifs', function (Blueprint $table): void {
            $table->boolean('est_chiffre')->default(false)->after('chemin_stockage');
        });

        DB::table('users')
            ->whereNull('password_changed_at')
            ->update(['password_changed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('justificatifs', function (Blueprint $table): void {
            $table->dropColumn('est_chiffre');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
