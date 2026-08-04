<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matricule obligatoire pour TOUS les profils, sans exception.
 *
 * Backfill : attribue un matricule automatique (`MAT-{id}`) a chaque utilisateur
 * existant qui n'en a pas encore (les agents ont deja ete traites par la migration
 * 2026_07_31 ; ici on couvre tous les autres roles). Non destructif : n'ecrase
 * jamais un matricule deja renseigne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'agent_matricule')) {
            return;
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('agent_matricule')
                    ->orWhereRaw("TRIM(agent_matricule) = ''");
            })
            ->orderBy('id')
            ->each(function ($user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['agent_matricule' => 'MAT-'.$user->id]);
            });
    }

    public function down(): void
    {
        // Non reversible : on ne supprime pas les matricules attribues (ils sont
        // desormais obligatoires). Aucune action.
    }
};
