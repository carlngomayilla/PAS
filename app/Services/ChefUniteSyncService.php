<?php

namespace App\Services;

use App\Models\UniteDg;
use App\Models\User;

/**
 * Maintient la cohérence entre les rôles `chef_unite_*` et la colonne
 * `unites_dg.chef_user_id`.
 *
 * Règles appliquées à chaque création / mise à jour d'utilisateur :
 *
 *  - Si l'utilisateur a le rôle `chef_unite_sciq` / `_dga` / `_cabinet` /
 *    `_ucas`, on le désigne automatiquement comme chef de l'unité
 *    correspondante (`unites_dg.chef_user_id`) et on aligne son
 *    `unite_dg_id` sur cette même unité.
 *  - Si un autre utilisateur était déjà chef de la même unité, il est
 *    automatiquement délogé (la désignation est écrasée). Son rôle est
 *    conservé : il reste habilité, simplement il n'est plus LE chef.
 *  - Si on retire le rôle de chef d'unité à un utilisateur, toute unité
 *    qui le désignait comme chef voit son `chef_user_id` remis à null.
 */
class ChefUniteSyncService
{
    /**
     * @return array<string, string>
     */
    private function roleToUniteCodeMap(): array
    {
        return [
            User::ROLE_CHEF_UNITE_SCIQ => UniteDg::CODE_SCIQ,
            User::ROLE_CHEF_UNITE_DGA => UniteDg::CODE_DGA,
            User::ROLE_CHEF_UNITE_CABINET => UniteDg::CODE_CABINET,
            User::ROLE_CHEF_UNITE_UCAS => UniteDg::CODE_UCAS,
        ];
    }

    public function sync(User $user): void
    {
        // 1) Retirer ce user de toute unité où il était chef.
        //    Couvre le passage chef_unite_X → autre rôle et le changement
        //    chef_unite_X → chef_unite_Y.
        UniteDg::query()
            ->where('chef_user_id', $user->id)
            ->update(['chef_user_id' => null]);

        // 2) Si le user a un rôle de chef d'unité, le désigner sur la
        //    bonne unité et aligner son périmètre.
        $map = $this->roleToUniteCodeMap();
        if (! isset($map[$user->role])) {
            return;
        }

        $unite = UniteDg::query()
            ->where('code', $map[$user->role])
            ->first();

        if (! $unite instanceof UniteDg) {
            return;
        }

        if ((int) ($user->unite_dg_id ?? 0) !== (int) $unite->id) {
            $user->forceFill(['unite_dg_id' => $unite->id])->save();
        }

        $unite->forceFill(['chef_user_id' => $user->id])->save();
    }
}
