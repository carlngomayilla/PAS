<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardOverviewRequest;
use App\Http\Resources\DashboardOverviewResource;
use App\Models\User;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\Dashboard\DashboardAccessService;
use App\Services\Dashboard\DashboardFilterData;
use App\Services\Dashboard\DashboardOverviewReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class DashboardController extends Controller
{
    private const CACHE_SECONDS = 30;

    public function __construct(
        private readonly DashboardAccessService $dashboardAccess,
        private readonly DashboardOverviewReadModel $dashboardOverview,
        private readonly AnalyticsCacheVersionService $cacheVersion,
    ) {}

    public function overview(DashboardOverviewRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $filters = $request->filters();
        $this->dashboardAccess->authorizeFilterScope($user, $filters);
        if (! $this->dashboardAccess->responsableIsRelevant($user, $filters)) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Aucune affectation pertinente ne correspond à ce responsable dans le périmètre sélectionné.',
            ]);
        }

        $request->restoreAllSentinelsForDashboardContext();
        $this->dashboardOverview->useRequest($request);
        $payload = $this->cachedPayload($request, $user, $filters);

        return $this->httpResponse($request, $payload, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedPayload(
        DashboardOverviewRequest $request,
        User $user,
        DashboardFilterData $filters
    ): array {
        $cacheKey = 'dashboard-api:v1:'.sha1(json_encode([
            'dashboard_version' => $this->cacheVersion->dashboardVersion(),
            'alerts_version' => $this->cacheVersion->alertsVersion(),
            'user_id' => (int) $user->id,
            'role' => (string) $user->role,
            'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
            'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
            'filters' => $filters->toArray(),
            'scope' => $this->dashboardOverview->cacheDimensions($user),
        ], JSON_THROW_ON_ERROR));

        try {
            return Cache::remember(
                $cacheKey,
                now()->addSeconds(self::CACHE_SECONDS),
                fn (): array => $this->freshPayload($request, $user),
            );
        } catch (Throwable) {
            return $this->freshPayload($request, $user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function freshPayload(DashboardOverviewRequest $request, User $user): array
    {
        $snapshot = $this->dashboardOverview->read($user);

        return (new DashboardOverviewResource($snapshot))->resolve($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function httpResponse(DashboardOverviewRequest $request, array $payload, User $user): JsonResponse
    {
        $etagPayload = $payload;
        unset($etagPayload['generated_at']);
        $etag = hash('sha256', json_encode([
            'user_id' => (int) $user->id,
            'payload' => $etagPayload,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $response = response()->json($payload);
        $response->setPrivate();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->set('Vary', 'Accept, Authorization, Cookie');
        $response->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }
}
