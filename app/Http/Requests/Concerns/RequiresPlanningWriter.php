<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * A14 — Defense en profondeur sur les FormRequests de planification (PAS/PAO/
 * PTA/Action/KPI/KpiMesure).
 *
 * Tous ces endpoints sont deja proteges par :
 *   1. middleware `auth` (utilisateur authentifie),
 *   2. middleware `EnsureActiveAccount` (compte actif, non suspendu),
 *   3. middleware `EnsurePasswordIsFresh`,
 *   4. controleur appelant `denyUnless*` qui verifie le scope fin
 *      (direction / service / unite + delegations).
 *
 * Ce trait ajoute une 5e barriere AU NIVEAU DU FORM REQUEST :
 *   - rejette explicitement les agents simples (ROLE_AGENT) qui n ont aucun
 *     droit d ecriture sur la chaine planning.
 *   - rejette tout user non authentifie (cas defensif si un middleware saute).
 *
 * Les checks plus fins restent dans les controleurs. Ce trait garantit qu en cas
 * d oubli d un `denyUnless*` dans un controleur, l agent reste bloque a l entree
 * (la 403 vient du FormRequest, pas du controleur).
 */
trait RequiresPlanningWriter
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        // Les agents n ont AUCUN droit d ecriture sur PAS / PAO / PTA / Actions
        // metier (ils renseignent leur suivi via les endpoints dedies du tracking
        // qui ne passent PAS par ces FormRequests).
        return ! $user->isAgent();
    }
}
