<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\PasObjectif;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasObjectifWebController extends Controller
{
    use AuthorizesPlanningScope;

    public function index(Request $request): RedirectResponse
    {
        return $this->redirectToPasWizard($request);
    }

    public function create(Request $request): RedirectResponse
    {
        return $this->redirectToPasWizard($request, null, true);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->redirectToPasWizard($request, null, true);
    }

    public function edit(Request $request, PasObjectif $pasObjectif): RedirectResponse
    {
        return $this->redirectToPasWizard($request, (int) $pasObjectif->pasAxe?->pas_id, true);
    }

    public function update(Request $request, PasObjectif $pasObjectif): RedirectResponse
    {
        return $this->redirectToPasWizard($request, (int) $pasObjectif->pasAxe?->pas_id, true);
    }

    public function destroy(Request $request, PasObjectif $pasObjectif): RedirectResponse
    {
        return $this->redirectToPasWizard($request, (int) $pasObjectif->pasAxe?->pas_id, true);
    }

    private function redirectToPasWizard(Request $request, ?int $pasId = null, bool $writeIntent = false): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($writeIntent) {
            $this->denyUnlessStrategicWriter($user);
        } else {
            $this->denyUnlessPlanningReader($user);
        }

        $route = $pasId !== null ? route('workspace.pas.edit', $pasId) : route('workspace.pas.index');

        return redirect($route)->with(
            'warning',
            'Les objectifs strategiques du PAS se gerent desormais directement dans le wizard PAS.'
        );
    }
}
