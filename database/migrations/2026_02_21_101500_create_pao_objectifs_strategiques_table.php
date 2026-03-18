<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pao_objectifs_strategiques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pao_axe_id')->constrained('pao_axes')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->date('echeance')->nullable();
            $table->timestamps();

            $table->unique(['pao_axe_id', 'code'], 'pao_obj_strat_axe_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pao_objectifs_strategiques');
    }
};

