<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
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
                'pilotage' => route('dashboard'),
                'messagerie' => route('workspace.messaging.index'),
                'mes_taches' => route('workspace.tasks.index'),
                'mes_actions' => route('workspace.actions.index', ['vue' => 'mes_actions']),
                'corrections' => route('workspace.module', ['module' => 'corrections']),
                'pas' => route('workspace.pas.index'),
                'pao' => route('workspace.pao.index'),
                'pta' => route('workspace.pta.index'),
                'imports_excel' => route('workspace.imports.index'),
                'execution' => route('workspace.actions.index'),
                'validations' => route('workspace.actions.index', ['statut_validation' => 'soumise_chef']),
                'agents', 'services_agents' => route('workspace.module', ['module' => 'agents']),
                'controle' => route('workspace.module', ['module' => 'controle']),
                'financement' => route('workspace.daf.financements.index'),
                'notifications' => route('workspace.notifications.index'),
                'synthese_agence' => route('workspace.module', ['module' => 'synthese-agence']),
                'arbitrages' => route('workspace.module', ['module' => 'arbitrages']),
                'financements_critiques' => route('workspace.module', ['module' => 'financements-critiques']),
                'rapports_consolides' => route('workspace.module', ['module' => 'rapports-consolides']),
                'supervision' => route('workspace.module', ['module' => 'supervision']),
                'referentiel' => route('workspace.referentiel.directions.index'),
                'reporting' => route('workspace.reporting'),
                'alertes' => route('workspace.alertes'),
                'super_admin' => route('workspace.super-admin.index'),
                'audit' => route('workspace.audit.index'),
                'api_docs' => route('workspace.api-docs.index'),
                'retention' => route('workspace.retention.index'),
                'delegations' => route('workspace.delegations.index'),
                default => url((string) $module['endpoint']),
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

    public function module(Request $request, string $module): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $code = str_replace('-', '_', $module);
        $workspaceModules = collect($user->workspaceModules())->keyBy('code');
        $moduleData = $workspaceModules->get($code);

        if (! is_array($moduleData)) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Acces refuse : ce module ne fait pas partie de votre perimetre.');
        }

        $user->loadMissing([
            'direction:id,libelle',
            'service:id,libelle',
        ]);

        return view('workspace.module-placeholder', [
            'user' => $user,
            'module' => $moduleData,
        ]);
    }
}
