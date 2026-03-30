<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Message $message
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('messaging.conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->message->id,
            'conversation_id' => (int) $this->message->conversation_id,
            'sender_id' => (int) $this->message->sender_id,
            'sender_name' => (string) ($this->message->sender?->name ?? 'Collaborateur'),
            'body' => (string) $this->message->body,
            'sent_at_iso' => optional($this->message->sent_at)->toIso8601String(),
            'sent_at_label' => optional($this->message->sent_at)->format('d/m/Y H:i'),
            'attachment' => $this->message->hasAttachment() ? [
                'name' => $this->message->attachment_original_name,
                'size_label' => $this->formatBytes((int) $this->message->attachment_size_bytes),
                'download_url' => route('workspace.messaging.attachment.download', [
                    'conversation' => $this->message->conversation_id,
                    'message' => $this->message->id,
                ]),
            ] : null,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ').' Ko';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', ' ').' Mo';
    }
}
