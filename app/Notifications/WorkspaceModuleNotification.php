<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WorkspaceModuleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => (string) ($this->payload['title'] ?? 'Notification'),
            'message' => (string) ($this->payload['message'] ?? ''),
            'module' => (string) ($this->payload['module'] ?? 'autres'),
            'entity_type' => $this->payload['entity_type'] ?? null,
            'entity_id' => $this->payload['entity_id'] ?? null,
            'url' => (string) ($this->payload['url'] ?? route('dashboard')),
            'icon' => (string) ($this->payload['icon'] ?? 'bell'),
            'status' => (string) ($this->payload['status'] ?? 'info'),
            'priority' => (string) ($this->payload['priority'] ?? 'normal'),
            'meta' => is_array($this->payload['meta'] ?? null) ? $this->payload['meta'] : [],
        ];
    }
}
