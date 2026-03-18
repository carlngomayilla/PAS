<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlerteController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly AlertCenterService $alertCenter,
        private readonly AlertReadService $alertReadService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $limit = max(1, min(100, (int) $request->integer('limit', 20)));
        $readFingerprints = $this->alertReadService->readFingerprintsForUser($user);

        $items = $this->alertCenter
            ->buildForUser($user, $limit)
            ->map(function (array $item) use ($readFingerprints, $limit): array {
                $item['is_unread'] = ! in_array((string) $item['fingerprint'], $readFingerprints, true);
                $item['read_endpoint'] = route('api.alertes.read', [
                    'type' => $item['source_type'],
                    'id' => $item['source_id'],
                ]);
                $item['limit'] = $limit;

                return $item;
            })
            ->values();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => $items->count(),
                'unread' => $items->where('is_unread', true)->count(),
                'critical' => $items->where('niveau', 'critical')->count(),
                'warning' => $items->where('niveau', 'warning')->count(),
                'info' => $items->where('niveau', 'info')->count(),
            ],
            'level_unread_counts' => [
                'critical' => $items->where('niveau', 'critical')->where('is_unread', true)->count(),
                'warning' => $items->where('niveau', 'warning')->where('is_unread', true)->count(),
                'info' => $items->where('niveau', 'info')->where('is_unread', true)->count(),
            ],
            'items' => $items,
            'alerts' => [
                'actions_retard' => $items->where('source_type', 'action_overdue')->values(),
                'kpi_sous_seuil' => $items->where('source_type', 'kpi_breach')->values(),
                'action_logs' => $items->where('source_type', 'action_log')->values(),
                'delegations' => $items->where('source_type', 'delegation_expiring')->values(),
            ],
        ]);
    }

    public function read(Request $request, string $type, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $alert = $this->alertCenter->findForUser($user, $type, $id);
        if ($alert === null) {
            abort(404);
        }

        $this->alertReadService->markAlertAsRead($user, $alert);
        $this->markAlertNotificationsAsRead($user);

        return response()->json([
            'message' => 'Alerte marquee comme lue.',
            'target_url' => (string) ($alert['target_url'] ?? route('workspace.alertes')),
            'fingerprint' => (string) ($alert['fingerprint'] ?? ''),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $limit = max(1, min(100, (int) $request->integer('limit', 20)));
        $fingerprints = $this->alertCenter
            ->buildForUser($user, $limit)
            ->pluck('fingerprint')
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        $this->alertReadService->markFingerprintsAsRead($user, $fingerprints);
        $this->markAlertNotificationsAsRead($user);

        return response()->json([
            'message' => 'Alertes visibles marquees comme lues.',
            'count' => count($fingerprints),
        ]);
    }

    private function markAlertNotificationsAsRead(User $user): void
    {
        $user->unreadNotifications()
            ->get()
            ->filter(static fn ($notification): bool => strtolower((string) ($notification->data['module'] ?? '')) === 'alertes')
            ->each
            ->markAsRead();
    }
}
