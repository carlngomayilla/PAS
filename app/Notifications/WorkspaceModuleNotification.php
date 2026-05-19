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
        $destinationUserId = $notifiable->id ?? null;
        $triggerUserId = $this->payload['user_id_declencheur'] ?? $this->payload['actor_id'] ?? null;

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
            'user_id_destinataire' => $destinationUserId !== null ? (int) $destinationUserId : null,
            'user_id_declencheur' => $triggerUserId !== null ? (int) $triggerUserId : null,
            'notification_type' => $this->payload['notification_type'] ?? $this->payload['type'] ?? 'evenement',
            'categorie' => $this->payload['categorie'] ?? 'metier',
            'niveau' => $this->payload['niveau'] ?? $this->payload['status'] ?? 'info',
            'direction_id' => $this->payload['direction_id'] ?? null,
            'service_id' => $this->payload['service_id'] ?? null,
            'unite_dg_id' => $this->payload['unite_dg_id'] ?? null,
            'action_id' => $this->payload['action_id'] ?? null,
            'pao_id' => $this->payload['pao_id'] ?? null,
            'pta_id' => $this->payload['pta_id'] ?? null,
            'pas_id' => $this->payload['pas_id'] ?? null,
            'meta' => is_array($this->payload['meta'] ?? null) ? $this->payload['meta'] : [],
        ];
    }
}
