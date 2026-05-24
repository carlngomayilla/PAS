<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * @deprecated Le suivi hebdomadaire des actions a ete supprime.
 *
 * Stub conserve uniquement pour eviter de casser des references anciennes
 * (autoload PSR-4). Toutes les routes qui pointaient ici renvoient
 * desormais 410 / sont retirees.
 */
final class ActionWeekController extends Controller
{
    public function __call(string $name, array $arguments)
    {
        abort(410, 'Le suivi hebdomadaire a ete supprime.');
    }
}
