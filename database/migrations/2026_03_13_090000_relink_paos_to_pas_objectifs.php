<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_objectifs', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_objectifs', 'ordre')) {
                $table->unsignedInteger('ordre')->default(0)->after('description');
            }
        });

        Schema::table('paos', function (Blueprint $table): void {
            if (! Schema::hasColumn('paos', 'pas_objectif_id')) {
                $table->foreignId('pas_objectif_id')
                    ->nullable()
                    ->after('pas_id')
                    ->constrained('pas_objectifs')
                    ->nullOnDelete();
            }
        });

        $this->backfillPasObjectifs();

        Schema::table('paos', function (Blueprint $table): void {
            $table->dropUnique('paos_pas_annee_direction_unique');
            $table->unique(
                ['pas_objectif_id', 'annee', 'direction_id'],
                'paos_objectif_annee_direction_unique'
            );
            $table->index(['pas_id', 'pas_objectif_id'], 'paos_pas_objectif_index');
        });
    }

    public function down(): void
    {
        Schema::table('paos', function (Blueprint $table): void {
            $table->dropIndex('paos_pas_objectif_index');
            $table->dropUnique('paos_objectif_annee_direction_unique');
            $table->unique(['pas_id', 'annee', 'direction_id'], 'paos_pas_annee_direction_unique');
            $table->dropConstrainedForeignId('pas_objectif_id');
        });

        Schema::table('pas_objectifs', function (Blueprint $table): void {
            $table->dropColumn('ordre');
        });
    }

    private function backfillPasObjectifs(): void
    {
        $paos = DB::table('paos')
            ->select(['id', 'pas_id', 'direction_id'])
            ->whereNull('pas_objectif_id')
            ->orderBy('id')
            ->get();

        foreach ($paos as $row) {
            $directionId = (int) ($row->direction_id ?? 0);
            $pasId = (int) $row->pas_id;

            $query = DB::table('pas_objectifs')
                ->join('pas_axes', 'pas_axes.id', '=', 'pas_objectifs.pas_axe_id')
                ->where('pas_axes.pas_id', $pasId);

            if ($directionId > 0) {
                $query->orderByRaw(
                    'CASE WHEN pas_axes.direction_id = '.$directionId.' THEN 0 WHEN pas_axes.direction_id IS NULL THEN 1 ELSE 2 END'
                );
            }

            $objectifId = $query
                ->orderBy('pas_axes.ordre')
                ->orderBy('pas_axes.id')
                ->orderBy('pas_objectifs.ordre')
                ->orderBy('pas_objectifs.id')
                ->value('pas_objectifs.id');

            if ($objectifId === null) {
                continue;
            }

            DB::table('paos')
                ->where('id', (int) $row->id)
                ->update(['pas_objectif_id' => (int) $objectifId]);
        }
    }
};
