<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardOverviewRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardAccessService;
use App\Services\Dashboard\DashboardOverviewReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class DashboardResponsibleOptionsController extends Controller
{
    public function __construct(
        private readonly DashboardAccessService $dashboardAccess,
        private readonly DashboardOverviewReadModel $dashboardOverview,
    ) {}

    public function __invoke(DashboardOverviewRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $filters = $request->filters();
        $this->dashboardAccess->authorizeFilterScope($user, $filters);
        if (! $this->dashboardAccess->responsableIsRelevant($user, $filters)) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Aucune affectation pertinente ne correspond à ce responsable dans le périmètre sélectionné.',
            ]);
        }

        $request->restoreAllSentinelsForDashboardContext();
        $this->dashboardOverview->useRequest($request);

        $response = response()->json($this->dashboardOverview->responsibleOptions($user));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Vary', 'Accept, Cookie');

        return $response;
    }
}
