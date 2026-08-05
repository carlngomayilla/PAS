<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectifs de reunions definis par le SCIQ : combien de reunions sont attendues
 * chaque mois, pour chaque structure et chaque type de reunion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions')->cascadeOnDelete();
            // Nul pour une reunion de direction.
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('meeting_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('expected_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un seul objectif par structure, type et mois.
            $table->unique(
                ['direction_id', 'service_id', 'meeting_type', 'year', 'month'],
                'meeting_plans_structure_period_unique'
            );
            $table->index(['year', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_plans');
    }
};
