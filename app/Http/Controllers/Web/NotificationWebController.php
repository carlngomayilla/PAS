<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

        $notificationItems = $user->notifications()
            ->latest()
            ->get()
            ->reject(fn ($notification): bool => $this->isAlertNotification($notification))
            ->values();
        $notifications = $this->paginateNotifications($notificationItems, $request, 30);
        $unreadCount = $notificationItems
            ->filter(static fn ($notification): bool => $notification->read_at === null)
            ->count();

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
            'unreadCount' => $unreadCount,
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

        $targetUrl = $this->safeTargetUrl($record->data['url'] ?? null, $request);

        return redirect()->to($targetUrl);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $user->unreadNotifications()
            ->get()
            ->reject(fn ($notification): bool => $this->isAlertNotification($notification))
            ->each
            ->markAsRead();

        return back()->with('success', 'Toutes les notifications ont été marquées comme lues.');
    }

    private function isAlertNotification(mixed $notification): bool
    {
        $data = is_array($notification->data ?? null) ? $notification->data : [];

        return strtolower((string) ($data['module'] ?? '')) === 'alertes';
    }

    private function safeTargetUrl(mixed $target, Request $request): string
    {
        $fallback = route('dashboard');
        $url = trim((string) $target);

        if ($url === '') {
            return $fallback;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }

        $host = $parts['host'] ?? null;
        if ($host !== null && strcasecmp($host, $request->getHost()) !== 0) {
            return $fallback;
        }

        if (isset($parts['scheme']) && ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return $fallback;
        }

        $path .= isset($parts['query']) ? '?'.$parts['query'] : '';
        $path .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return url($path);
    }

    /**
     * @param Collection<int, mixed> $items
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginateNotifications(Collection $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
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
