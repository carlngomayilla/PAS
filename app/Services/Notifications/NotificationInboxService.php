<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationInboxService
{
    /**
     * @param  array{q:string,etat:?string,niveau:?string,module:string}  $filters
     * @return array{
     *     items:Collection<int, mixed>,
     *     summary:array{total:int,unread:int,read:int,critical:int},
     *     filtered_summary:array{total:int,unread:int},
     *     module_options:array<int, array{code:string,label:string,count:int}>
     * }
     */
    public function workspaceForUser(User $user, array $filters): array
    {
        $items = $this->notificationsForUser($user);
        $summary = [
            'total' => $items->count(),
            'unread' => $items->whereNull('read_at')->count(),
            'read' => $items->whereNotNull('read_at')->count(),
            'critical' => $items->filter(fn ($notification): bool => in_array(
                $this->notificationLevel($notification),
                ['urgence', 'critical'],
                true
            ))->count(),
        ];
        $moduleOptions = $items
            ->map(fn ($notification): string => $this->notificationModule($notification))
            ->filter()
            ->countBy()
            ->map(fn (int $count, string $module): array => [
                'code' => $module,
                'label' => Str::headline($module),
                'count' => $count,
            ])
            ->sortBy('label')
            ->values()
            ->all();

        $filteredItems = $items
            ->when(
                $filters['etat'] === 'unread',
                fn (Collection $notifications): Collection => $notifications->whereNull('read_at')
            )
            ->when(
                $filters['etat'] === 'read',
                fn (Collection $notifications): Collection => $notifications->whereNotNull('read_at')
            )
            ->when(
                $filters['niveau'] !== null,
                fn (Collection $notifications): Collection => $notifications->filter(
                    fn ($notification): bool => $this->notificationLevel($notification) === $filters['niveau']
                )
            )
            ->when(
                $filters['module'] !== '',
                fn (Collection $notifications): Collection => $notifications->filter(
                    fn ($notification): bool => $this->notificationModule($notification) === $filters['module']
                )
            )
            ->when(
                $filters['q'] !== '',
                function (Collection $notifications) use ($filters): Collection {
                    $needle = $this->searchableText($filters['q']);

                    return $notifications->filter(function ($notification) use ($needle): bool {
                        $data = $this->notificationData($notification);
                        $haystack = $this->searchableText(implode(' ', [
                            (string) ($data['title'] ?? $data['titre'] ?? ''),
                            (string) ($data['message'] ?? $data['body'] ?? ''),
                            (string) ($data['module'] ?? ''),
                            (string) ($data['level'] ?? $data['niveau'] ?? ''),
                        ]));

                        return str_contains($haystack, $needle);
                    });
                }
            )
            ->values();

        return [
            'items' => $filteredItems,
            'summary' => $summary,
            'filtered_summary' => [
                'total' => $filteredItems->count(),
                'unread' => $filteredItems->whereNull('read_at')->count(),
            ],
            'module_options' => $moduleOptions,
        ];
    }

    /**
     * @return Collection<int, mixed>
     */
    public function notificationsForUser(User $user, bool $unreadOnly = false): Collection
    {
        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        return $query
            ->latest()
            ->get()
            ->reject(fn ($notification): bool => $this->isAlertNotification($notification))
            ->values();
    }

    private function isAlertNotification(mixed $notification): bool
    {
        return $this->notificationModule($notification) === 'alertes';
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationData(mixed $notification): array
    {
        return is_array($notification->data ?? null) ? $notification->data : [];
    }

    private function notificationModule(mixed $notification): string
    {
        $data = $this->notificationData($notification);

        return strtolower(trim((string) ($data['module'] ?? '')));
    }

    private function notificationLevel(mixed $notification): string
    {
        $data = $this->notificationData($notification);
        $level = strtolower(trim((string) ($data['level'] ?? $data['niveau'] ?? 'info')));

        return match ($level) {
            'urgent', 'urgente', 'urgence' => 'urgence',
            'critical', 'critique', 'danger', 'error', 'erreur' => 'critical',
            'warning', 'avertissement', 'vigilance' => 'warning',
            'conforme', 'success', 'succes', 'validée', 'validee' => 'conforme',
            default => 'info',
        };
    }

    private function searchableText(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->lower()
            ->squish();
    }
}
