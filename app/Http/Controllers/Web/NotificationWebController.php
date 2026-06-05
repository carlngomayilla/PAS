<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class NotificationWebController extends Controller
{
    public function index(
        Request $request,
        AlertCenterService $alertCenter,
        AlertReadService $alertReadService
    ): View {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $activeTab = (string) $request->query('tab') === 'alertes'
            ? 'alertes'
            : 'notifications';

        $notifications = $user->notifications()
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $canReadAlerts = $user->hasPermission('planning.read') && $user->hasPermission('alerts.read');
        $alertItems = collect();
        $alertSummary = $this->emptyAlertSummary();

        if ($canReadAlerts) {
            $readFingerprints = $alertReadService->readFingerprintsForUser($user);
            $alertItems = $alertCenter
                ->buildForUser($user, 50)
                ->map(function (array $item) use ($readFingerprints): array {
                    $item['is_unread'] = ! in_array((string) ($item['fingerprint'] ?? ''), $readFingerprints, true);
                    $item['read_url'] = route('workspace.alertes.read', [
                        'type' => $item['source_type'],
                        'id' => $item['source_id'],
                    ]);

                    return $item;
                })
                ->values();
            $alertSummary = $alertCenter->summaryForUser($user, $readFingerprints);
        }

        return view('workspace.notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'activeTab' => $activeTab,
            'canReadAlerts' => $canReadAlerts,
            'alertItems' => $alertItems,
            'alertSummary' => $alertSummary,
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $record = $user->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        $targetUrl = (string) ($record->data['url'] ?? route('dashboard'));

        return redirect()->to($targetUrl);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Toutes les notifications ont été marquées comme lues.');
    }

    /**
     * @return array{
     *     total:int,
     *     unread:int,
     *     urgence:int,
     *     critical:int,
     *     warning:int,
     *     info:int,
     *     level_unread_counts:array{urgence:int,critical:int,warning:int,info:int}
     * }
     */
    private function emptyAlertSummary(): array
    {
        return [
            'total' => 0,
            'unread' => 0,
            'urgence' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'level_unread_counts' => [
                'urgence' => 0,
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
            ],
        ];
    }
}
