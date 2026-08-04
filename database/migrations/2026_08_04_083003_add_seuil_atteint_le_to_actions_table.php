<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date a laquelle une action a atteint son seuil de completude.
 *
 * Sans cette date, le statut delai etait fausse : une action ayant atteint son
 * seuil APRES l'echeance (mais pas encore cloturee) etait affichee « dans les
 * delais », car le calcul renvoyait ce statut des que `taux >= seuil` sans
 * jamais comparer de date. Cette colonne permet de comparer la date reelle
 * d'atteinte du seuil a l'echeance.
 *
 * Migration additive : colonne nullable, aucune donnee existante modifiee.
 * Les actions deja au seuil restent a `null` et retombent sur les dates
 * existantes (fin reelle, cloture, soumission).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('actions') || Schema::hasColumn('actions', 'seuil_atteint_le')) {
            return;
        }

        Schema::table('actions', function (Blueprint $table): void {
            $table->timestamp('seuil_atteint_le')->nullable()->after('date_fin_reelle');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('actions') || ! Schema::hasColumn('actions', 'seuil_atteint_le')) {
            return;
        }

        Schema::table('actions', function (Blueprint $table): void {
            $table->dropColumn('seuil_atteint_le');
        });
    }
};
