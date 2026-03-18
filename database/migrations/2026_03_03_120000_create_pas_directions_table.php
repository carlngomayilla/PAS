<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_directions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pas_id')->constrained('pas')->cascadeOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pas_id', 'direction_id'], 'pas_directions_pas_direction_unique');
        });

        $now = now();

        $rows = DB::table('paos')
            ->select('pas_id', 'direction_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            DB::table('pas_directions')->updateOrInsert(
                [
                    'pas_id' => (int) $row->pas_id,
                    'direction_id' => (int) $row->direction_id,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_directions');
    }
};
