<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationWebController extends Controller
{
    public function index(
        Request $request,
        AlertCenterService $alertCenter,
        AlertReadService $alertReadService,
        NotificationInboxService $notificationInboxService
    ): View {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $canReadAlerts = $user->hasPermission('planning.read') && $user->hasPermission('alerts.read');
        $activeTab = $canReadAlerts && $this->scalarQuery($request, 'tab') === 'alertes'
            ? 'alertes'
            : 'notifications';
        $notificationFilters = [
            'q' => Str::limit($this->scalarQuery($request, 'q'), 100, ''),
            'etat' => $this->stateFilter($request),
            'niveau' => $this->levelFilter($request),
            'module' => Str::limit(strtolower($this->scalarQuery($request, 'module')), 50, ''),
            'per_page' => $this->perPageFilter($request),
        ];
        $notificationWorkspace = $notificationInboxService->workspaceForUser($user, $notificationFilters);
        $notifications = $this->paginate(
            $notificationWorkspace['items'],
            $request,
            $notificationFilters['per_page']
        );

        $alertFilters = [
            'q' => Str::limit($this->scalarQuery($request, 'q'), 100, ''),
            'niveau' => $this->levelFilter($request),
            'etat' => $this->stateFilter($request),
            'type' => $this->alertTypeFilter($request),
            'per_page' => $this->perPageFilter($request),
            'vue' => $this->alertViewFilter($request),
        ];
        $alertSummary = $this->emptyAlertSummary();
        $alertFilteredSummary = ['total' => 0, 'unread' => 0];
        $alertTypeOptions = [];
        $alertHistoryTotal = 0;
        $alertPaginator = $this->paginate(collect(), $request, $alertFilters['per_page']);

        if ($canReadAlerts) {
            $readFingerprints = $alertReadService->readFingerprintsForUser($user);
            $activeAlertItems = $alertCenter
                ->allForUser($user)
                ->map(function (array $item) use ($readFingerprints): array {
                    $item['is_unread'] = ! in_array((string) ($item['fingerprint'] ?? ''), $readFingerprints, true);
                    $item['read_url'] = route('workspace.alertes.read', [
                        'type' => $item['source_type'],
                        'id' => $item['source_id'],
                    ]);

                    return $item;
                })
                ->values();
            $historyAlertItems = $alertReadService->historyForUser($user, [], null);
            $alertSummary = $alertCenter->summaryForUser($user, $readFingerprints);
            $alertHistoryTotal = $historyAlertItems->count();

            $sourceItems = $alertFilters['vue'] === 'historique' ? $historyAlertItems : $activeAlertItems;
            $alertTypeOptions = $this->alertTypeOptions($sourceItems);
            $filteredAlertItems = $this->filterAlertItems(
                $sourceItems,
                $alertFilters,
                $alertFilters['vue'] === 'actives'
            );
            $alertFilteredSummary = [
                'total' => $filteredAlertItems->count(),
                'unread' => $filteredAlertItems->where('is_unread', true)->count(),
            ];
            $alertPaginator = $this->paginate($filteredAlertItems, $request, $alertFilters['per_page']);
        }

        return view('workspace.notifications.index', [
            'notifications' => $notifications,
            'notificationSummary' => $notificationWorkspace['summary'],
            'notificationFilteredSummary' => $notificationWorkspace['filtered_summary'],
            'notificationModuleOptions' => $notificationWorkspace['module_options'],
            'notificationFilters' => $notificationFilters,
            'unreadCount' => (int) $notificationWorkspace['summary']['unread'],
            'activeTab' => $activeTab,
            'canReadAlerts' => $canReadAlerts,
            'alertPaginator' => $alertPaginator,
            'alertSummary' => $alertSummary,
            'alertFilteredSummary' => $alertFilteredSummary,
            'alertTypeOptions' => $alertTypeOptions,
            'alertHistoryTotal' => $alertHistoryTotal,
            'alertFilters' => $alertFilters,
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

    public function readAll(Request $request, NotificationInboxService $notificationInboxService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $notificationInboxService
            ->notificationsForUser($user, true)
            ->each
            ->markAsRead();

        return back()->with('success', 'Toutes les notifications ont été marquées comme lues.');
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
     * @param  Collection<int, mixed>  $items
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginate(Collection $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $pageValue = $request->query('page', 1);
        $page = is_scalar($pageValue) ? max(1, (int) $pageValue) : 1;
        $query = array_filter(
            $request->query(),
            static fn ($value): bool => is_scalar($value) && $value !== ''
        );

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $query,
            ]
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array{q:string,niveau:?string,etat:?string,type:?string,per_page:int,vue:string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterAlertItems(Collection $items, array $filters, bool $filterState): Collection
    {
        return $items
            ->when(
                $filters['niveau'] !== null,
                fn (Collection $alerts): Collection => $alerts->where('niveau', $filters['niveau'])
            )
            ->when(
                $filters['type'] !== null,
                fn (Collection $alerts): Collection => $alerts->where('source_type', $filters['type'])
            )
            ->when(
                $filterState && $filters['etat'] === 'unread',
                fn (Collection $alerts): Collection => $alerts->where('is_unread', true)
            )
            ->when(
                $filterState && $filters['etat'] === 'read',
                fn (Collection $alerts): Collection => $alerts->where('is_unread', false)
            )
            ->when(
                $filters['q'] !== '',
                function (Collection $alerts) use ($filters): Collection {
                    $needle = $this->searchableText($filters['q']);

                    return $alerts->filter(function (array $alert) use ($needle): bool {
                        $action = is_array($alert['action'] ?? null) ? $alert['action'] : [];
                        $haystack = $this->searchableText(implode(' ', [
                            (string) ($alert['titre'] ?? ''),
                            (string) ($alert['message'] ?? ''),
                            (string) ($alert['type_label'] ?? ''),
                            (string) ($alert['section_label'] ?? ''),
                            (string) ($alert['direction'] ?? ''),
                            (string) ($alert['service'] ?? ''),
                            (string) ($action['libelle'] ?? ''),
                            (string) ($action['pta'] ?? ''),
                        ]));

                        return str_contains($haystack, $needle);
                    });
                }
            )
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array{code:string,label:string,count:int}>
     */
    private function alertTypeOptions(Collection $items): array
    {
        return $items
            ->pluck('source_type')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->countBy()
            ->map(fn (int $count, string $sourceType): array => [
                'code' => $sourceType,
                'label' => $this->alertSourceLabel($sourceType),
                'count' => $count,
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    private function alertSourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'action_overdue' => 'Retards d’action',
            'action_pending_setup' => 'Paramétrage PTA',
            'kpi_breach' => 'Indicateurs',
            'action_log' => 'Journal des actions',
            'missing_pao_coverage' => 'Couverture PAO',
            'delegation_expiring' => 'Délégations',
            default => Str::headline($sourceType),
        };
    }

    private function levelFilter(Request $request): ?string
    {
        $level = strtolower($this->scalarQuery($request, 'niveau'));

        return in_array($level, ['urgence', 'critical', 'warning', 'conforme', 'info'], true) ? $level : null;
    }

    private function stateFilter(Request $request): ?string
    {
        $state = strtolower($this->scalarQuery($request, 'etat'));

        return in_array($state, ['read', 'unread'], true) ? $state : null;
    }

    private function alertViewFilter(Request $request): string
    {
        return strtolower($this->scalarQuery($request, 'vue', 'actives')) === 'historique'
            ? 'historique'
            : 'actives';
    }

    private function alertTypeFilter(Request $request): ?string
    {
        $type = strtolower($this->scalarQuery($request, 'type'));

        return in_array($type, [
            'action_overdue',
            'action_pending_setup',
            'kpi_breach',
            'action_log',
            'missing_pao_coverage',
            'delegation_expiring',
        ], true) ? $type : null;
    }

    private function perPageFilter(Request $request): int
    {
        $value = $request->query('per_page', 15);
        $perPage = is_scalar($value) ? (int) $value : 15;

        return in_array($perPage, [15, 25, 50], true) ? $perPage : 15;
    }

    private function scalarQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function searchableText(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->lower()
            ->squish();
    }

    /**
     * @return array{
     *     total:int,
     *     unread:int,
     *     urgence:int,
     *     critical:int,
     *     warning:int,
     *     conforme:int,
     *     info:int,
     *     level_unread_counts:array{urgence:int,critical:int,warning:int,conforme:int,info:int}
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
            'conforme' => 0,
            'info' => 0,
            'level_unread_counts' => [
                'urgence' => 0,
                'critical' => 0,
                'warning' => 0,
                'conforme' => 0,
                'info' => 0,
            ],
        ];
    }
}
