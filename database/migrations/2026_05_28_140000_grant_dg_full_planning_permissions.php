<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alignement DB des permissions DG sur la regle metier ANBG :
 * le DG pilote l'agence et doit pouvoir creer / modifier / supprimer
 * PAS / PAO / PTA / Actions sans restriction de perimetre.
 *
 * Auparavant la table platform_settings contenait un override historique
 * limitant le DG aux droits de lecture uniquement (scope.global.read,
 * planning.read, reporting.read, alerts.read, referentiel.read, audit.read,
 * messagerie.read), ce qui empechait toute action d'ecriture.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $newPerms = [
            'scope.global.read',
            'scope.global.write',
            'planning.read',
            'planning.write.global',
            'planning.strategic.manage',
            'reporting.read',
            'alerts.read',
            'referentiel.read',
            'audit.read',
        ];

        DB::table('platform_settings')->updateOrInsert(
            ['group' => 'role_permissions', 'key' => 'role_permissions_'.User::ROLE_DG],
            [
                'value' => json_encode($newPerms),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $oldPerms = [
            'scope.global.read',
            'planning.read',
            'reporting.read',
            'alerts.read',
            'referentiel.read',
            'audit.read',
        ];

        DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', 'role_permissions_'.User::ROLE_DG)
            ->update([
                'value' => json_encode($oldPerms),
                'updated_at' => now(),
            ]);
    }
};
