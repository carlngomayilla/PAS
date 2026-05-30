<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\Governance\RetentionService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\PlanningAutoArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class GovernanceWebController extends Controller
{
    public function apiDocumentation(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('api_docs.read')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.api-docs', [
            'specUrl' => route('workspace.api-docs.spec'),
        ]);
    }

    public function apiSpec(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('api_docs.read')) {
            abort(403, 'Acces non autorise.');
        }

        $path = base_path('docs/openapi.yaml');
        if (! File::exists($path)) {
            abort(404);
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }

    public function retentionIndex(Request $request, RetentionService $retentionService, PlanningAutoArchiveService $planningAutoArchiveService): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasAnyPermission('retention.read', 'retention.manage')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.retention', [
            'summary' => $retentionService->summary(),
            'planningArchiveSummary' => $planningAutoArchiveService->summary(),
            'canRun' => $user->hasPermission('retention.manage'),
        ]);
    }

    public function retentionRun(Request $request, RetentionService $retentionService, PlanningAutoArchiveService $planningAutoArchiveService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('retention.manage')) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array{mode:string,scope?:string} $validated */
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['dry-run', 'execute'])],
            'scope' => ['nullable', Rule::in(['data', 'planning'])],
        ]);

        $scope = (string) ($validated['scope'] ?? 'data');
        if ($scope === 'planning') {
            $result = $planningAutoArchiveService->run($validated['mode'] === 'execute', $user);
            $archived = is_array($result['archived'] ?? null) ? $result['archived'] : [];
            $message = $validated['mode'] === 'execute'
                ? sprintf('Archivage PAO/PTA execute : %d PAO, %d PTA.', (int) ($archived['paos'] ?? 0), (int) ($archived['ptas'] ?? 0))
                : 'Dry-run archivage PAO/PTA effectue.';
        } else {
            $result = $retentionService->archive($validated['mode'] === 'execute', $user);
            $message = $validated['mode'] === 'execute'
                ? 'Archive de retention enregistree sous le batch '.($result['batch_key'] ?? 'N/A').'.'
                : 'Dry-run de retention effectue.';
        }

        return redirect()
            ->route('workspace.retention.index')
            ->with('success', $message);
    }

    public function delegationsIndex(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        $delegations = Delegation::query()
            ->with([
                'delegant:id,name,role,direction_id,service_id',
                'delegue:id,name,role,direction_id,service_id',
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'createdBy:id,name',
                'cancelledBy:id,name',
            ]);

        return view('workspace.governance.delegations.index', [
            'rows' => $delegations->latest('id')->paginate(20),
            'summary' => [
                'total' => (clone $delegations)->count(),
                'active' => (clone $delegations)->active()->count(),
                'expires_soon' => (clone $delegations)
                    ->where('statut', 'active')
                    ->whereBetween('date_fin', [now(), now()->addDays(7)])
                    ->count(),
                'cancelled' => (clone $delegations)->where('statut', 'cancelled')->count(),
                'direction_scope' => (clone $delegations)->where('role_scope', Delegation::SCOPE_DIRECTION)->count(),
                'service_scope' => (clone $delegations)->where('role_scope', Delegation::SCOPE_SERVICE)->count(),
            ],
        ]);
    }

    public function delegationsCreate(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.delegations.form', [
            'delegation' => new Delegation([
                'role_scope' => Delegation::SCOPE_SERVICE,
                'permissions' => ['planning_read', 'action_review'],
                'date_debut' => now()->format('Y-m-d\TH:i'),
                'date_fin' => now()->addDays(15)->format('Y-m-d\TH:i'),
            ]),
            'delegantOptions' => $this->delegationEligibleUsers(),
            'delegateOptions' => $this->delegateReceivers(),
            'directionOptions' => Direction::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
        ]);
    }

    public function delegationsStore(Request $request, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'delegant_id' => ['required', 'integer', 'exists:users,id'],
            'delegue_id' => ['required', 'integer', 'different:delegant_id', 'exists:users,id'],
            'role_scope' => ['required', Rule::in([Delegation::SCOPE_DIRECTION, Delegation::SCOPE_SERVICE])],
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', Rule::in(['planning_read', 'planning_write', 'action_review'])],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'motif' => ['required', 'string', 'min:5'],
        ]);

        $delegant = User::query()->findOrFail((int) $validated['delegant_id']);
        $delegate = User::query()->findOrFail((int) $validated['delegue_id']);

        if ($validated['role_scope'] === Delegation::SCOPE_DIRECTION) {
            if (! $delegant->hasRole(User::ROLE_DIRECTION) || (int) $delegant->direction_id !== (int) $validated['direction_id']) {
                return back()->withInput()->withErrors([
                    'delegant_id' => 'Le delegant doit etre un directeur de la direction selectionnee.',
                ]);
            }
            $validated['service_id'] = null;
        } else {
            $service = Service::query()->findOrFail((int) $validated['service_id']);
            if ((int) $service->direction_id !== (int) $validated['direction_id']) {
                return back()->withInput()->withErrors([
                    'service_id' => 'Le service selectionne ne correspond pas a la direction choisie.',
                ]);
            }
            if (! $delegant->hasRole(User::ROLE_SERVICE)
                || (int) $delegant->direction_id !== (int) $validated['direction_id']
                || (int) $delegant->service_id !== (int) $validated['service_id']
            ) {
                return back()->withInput()->withErrors([
                    'delegant_id' => 'Le delegant doit etre un chef de service du perimetre selectionne.',
                ]);
            }
        }

        if ($delegate->isAgent()) {
            return back()->withInput()->withErrors([
                'delegue_id' => 'Le delegue doit etre un profil d encadrement ou de pilotage.',
            ]);
        }

        $delegation = Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegate->id,
            'role_scope' => $validated['role_scope'],
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => $validated['service_id'] !== null ? (int) $validated['service_id'] : null,
            'permissions' => array_values(array_unique($validated['permissions'])),
            'motif' => (string) $validated['motif'],
            'date_debut' => $validated['date_debut'],
            'date_fin' => $validated['date_fin'],
            'statut' => 'active',
            'cree_par' => $user->id,
        ]);

        $notificationService->notifyDelegationCreated($delegation, $user);

        return redirect()
            ->route('workspace.delegations.index')
            ->with('success', 'Delegation enregistree avec succès.');
    }

    public function delegationsCancel(Request $request, Delegation $delegation): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array{motif_annulation:string} $validated */
        $validated = $request->validate([
            'motif_annulation' => ['required', 'string', 'min:5'],
        ]);

        $delegation->update([
            'statut' => 'cancelled',
            'annule_par' => $user->id,
            'annule_le' => now(),
            'motif_annulation' => $validated['motif_annulation'],
        ]);

        return redirect()
            ->route('workspace.delegations.index')
            ->with('success', 'Délégation annulée.');
    }

    private function delegationEligibleUsers()
    {
        return User::query()
            ->whereIn('role', [User::ROLE_DIRECTION, User::ROLE_SERVICE])
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'direction_id', 'service_id']);
    }

    private function delegateReceivers()
    {
        return User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_DIRECTION, User::ROLE_SERVICE])
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'direction_id', 'service_id']);
    }
}
