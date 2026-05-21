<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A40 — Ajoute une contrainte UNIQUE sur (pas_id, direction_id) dans la table
 * pivot `pas_directions` pour empecher qu une meme direction soit liee deux
 * fois au meme PAS (doublons M2M silencieux).
 *
 * Migration idempotente : si l index existe deja (rerun), on ignore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_directions')) {
            return;
        }

        // Nettoyage prealable des doublons potentiels avant de poser l UNIQUE.
        $this->deduplicate();

        try {
            Schema::table('pas_directions', function (Blueprint $blueprint): void {
                $blueprint->unique(['pas_id', 'direction_id'], 'pas_directions_pas_id_direction_id_unique');
            });
        } catch (\Throwable) {
            // Index probablement deja present : non bloquant.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_directions')) {
            return;
        }

        try {
            Schema::table('pas_directions', function (Blueprint $blueprint): void {
                $blueprint->dropUnique('pas_directions_pas_id_direction_id_unique');
            });
        } catch (\Throwable) {
            // Index inexistant : non bloquant.
        }
    }

    /**
     * Supprime les doublons (pas_id, direction_id) en conservant l id le plus
     * petit (le plus ancien). Compatible PG / SQLite.
     */
    private function deduplicate(): void
    {
        $duplicates = DB::table('pas_directions')
            ->select('pas_id', 'direction_id')
            ->groupBy('pas_id', 'direction_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $pair) {
            $ids = DB::table('pas_directions')
                ->where('pas_id', $pair->pas_id)
                ->where('direction_id', $pair->direction_id)
                ->orderBy('id')
                ->pluck('id');

            // On garde le premier, on supprime les autres.
            $toDelete = $ids->slice(1)->all();
            if ($toDelete !== []) {
                DB::table('pas_directions')->whereIn('id', $toDelete)->delete();
            }
        }
    }
};
