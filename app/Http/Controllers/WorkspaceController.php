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
            $module['web_route'] = $this->webRouteForModule($module);

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

        $target = $this->webRouteForModule($moduleData);
        $current = $request->fullUrl();
        if ($target !== $current && $target !== $request->url()) {
            return redirect()->to($target);
        }

        return view('workspace.module-placeholder', [
            'user' => $user,
            'module' => $moduleData,
        ]);
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function webRouteForModule(array $module): string
    {
        return match ((string) ($module['code'] ?? '')) {
            'pilotage' => route('dashboard'),
            'messagerie' => route('workspace.messaging.index'),
            'mes_taches' => route('workspace.tasks.index'),
            'mes_actions' => route('workspace.actions.index', ['vue' => 'mes_actions']),
            'corrections' => route('workspace.actions.index', ['vue' => 'mes_actions', 'statut' => 'a_corriger']),
            'pas' => route('workspace.pas.index'),
            'pao' => route('workspace.pao.index'),
            'pta' => route('workspace.pta.index'),
            'imports_excel' => route('workspace.imports.index'),
            'execution' => route('workspace.actions.index'),
            'validations' => route('workspace.actions.index', ['vue' => 'pilotage', 'statut_validation' => 'soumise_chef']),
            'agents', 'services_agents' => route('workspace.referentiel.utilisateurs.index'),
            'controle' => route('workspace.actions.index', ['vue' => 'pilotage', 'statut_validation' => 'soumise_chef']),
            'financement', 'financements_critiques' => route('workspace.daf.financements.index'),
            'notifications' => route('workspace.notifications.index'),
            'synthese_agence', 'rapports_consolides', 'supervision' => route('workspace.reporting'),
            'arbitrages', 'deverrouillages' => route('workspace.planning-unlocks.index'),
            'referentiel' => route('workspace.referentiel.directions.index'),
            'reporting' => route('workspace.reporting'),
            'alertes' => route('workspace.alertes'),
            'super_admin' => route('workspace.super-admin.index'),
            'roles_permissions' => route('workspace.super-admin.roles.edit'),
            'organisation' => route('workspace.super-admin.organization.index'),
            'exercices' => route('workspace.super-admin.exercises.index'),
            'workflows' => route('workspace.super-admin.workflow.edit'),
            'audit' => route('workspace.audit.index'),
            'api_docs' => route('workspace.api-docs.index'),
            'retention' => route('workspace.retention.index'),
            'delegations' => route('workspace.delegations.index'),
            default => url((string) ($module['endpoint'] ?? '/workspace')),
        };
    }
}
