<?php

/*
|--------------------------------------------------------------------------
| Configuration des KPI metier (spec v2 PAS ANBG, 28/05/2026)
|--------------------------------------------------------------------------
|
| Centralise les choix d'implementation des indicateurs metier conserves
| par la spec v2 (KPI Performance et KPI Delai). Le KPI conformite et la
| note du chef ont ete supprimes par la migration
| 2026_05_28_120000_drop_chef_quality_note_and_conformite_kpi.
|
*/

return [

    'delay' => [

        /*
        |----------------------------------------------------------------------
        | Mode de calcul du KPI Delai
        |----------------------------------------------------------------------
        |
        | - 'graduated' (recommande spec v2) : formule progressive
        |     KPI_delai = max(0, 100 - (retard_jours / duree_prevue × 100))
        |   Permet de distinguer un retard d'1 jour d'un retard de 60 jours.
        |
        | - 'binary' (heritage v1) : KPI = 100 si soumis avant l'echeance,
        |   sinon 0. Conserve derriere le flag pour rollback rapide en
        |   production si la nouvelle formule pose probleme.
        |
        */

        'mode' => env('KPI_DELAY_MODE', 'graduated'),

        /*
        |----------------------------------------------------------------------
        | Penalite quotidienne par defaut (mode graduated, fallback)
        |----------------------------------------------------------------------
        |
        | Quand date_debut est absente, on ne peut pas calculer la duree
        | prevue de l'action. On retombe alors sur une penalite forfaitaire
        | exprimee en pourcentage par jour de retard (5% par defaut -> 0 en
        | 20 jours de retard). Plage : 0 a 100.
        |
        */

        'fallback_daily_penalty' => (float) env('KPI_DELAY_FALLBACK_PENALTY', 5.0),

    ],

];
