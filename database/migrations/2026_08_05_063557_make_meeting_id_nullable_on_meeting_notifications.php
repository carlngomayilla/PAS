<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La notification de publication du programme trimestriel n'est rattachee a
 * aucune reunion : `meeting_id` doit accepter la valeur nulle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_notifications', function (Blueprint $table): void {
            $table->foreignId('meeting_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_notifications', function (Blueprint $table): void {
            $table->foreignId('meeting_id')->nullable(false)->change();
        });
    }
};
