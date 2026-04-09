<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $user->loadMissing([
            'direction:id,libelle',
            'service:id,libelle',
        ]);

        $modules = collect($user->workspaceModules())->map(function (array $module): array {
            $webRoute = match ($module['code']) {
                'messagerie' => route('workspace.messaging.index'),
                'pas' => route('workspace.pas.index'),
                'pao' => route('workspace.pao.index'),
                'pta' => route('workspace.pta.index'),
                'execution' => route('workspace.actions.index'),
                'referentiel' => route('workspace.referentiel.directions.index'),
                'reporting' => route('workspace.reporting'),
                'alertes' => route('workspace.alertes'),
                'super_admin' => route('workspace.super-admin.index'),
                'audit' => route('workspace.audit.index'),
                'api_docs' => route('workspace.api-docs.index'),
                'retention' => route('workspace.retention.index'),
                'delegations' => route('workspace.delegations.index'),
                default => $module['endpoint'],
            };

            $module['web_route'] = $webRoute;

            return $module;
        })->all();

        return view('workspace.index', [
            'user' => $user,
            'profil' => $user->profileInteractions(),
            'modules' => $modules,
        ]);
    }
}
