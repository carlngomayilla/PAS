<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_axes', function (Blueprint $table): void {
            $table->foreignId('direction_id')
                ->nullable()
                ->after('pas_id')
                ->constrained('directions')
                ->nullOnDelete();

            $table->index(['pas_id', 'direction_id'], 'pas_axes_pas_direction_index');
        });

        DB::table('pas_axes')
            ->orderBy('id')
            ->get(['id', 'pas_id'])
            ->each(function ($row): void {
                $directionId = DB::table('pas_directions')
                    ->where('pas_id', (int) $row->pas_id)
                    ->orderBy('id')
                    ->value('direction_id');

                if ($directionId !== null) {
                    DB::table('pas_axes')
                        ->where('id', (int) $row->id)
                        ->update(['direction_id' => (int) $directionId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pas_axes', function (Blueprint $table): void {
            $table->dropIndex('pas_axes_pas_direction_index');
            $table->dropConstrainedForeignId('direction_id');
        });
    }
};

