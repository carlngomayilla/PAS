<?php

namespace App\Services\Notifications;

use App\Mail\BrevoNotificationMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Canal email Brevo (complémentaire au canal in_app).
 *
 * Règles métier (v1.1 - Section Brevo) :
 *   - L'envoi email est complémentaire : la notification interne est créée AVANT.
 *   - Tout échec Brevo est journalisé (table brevo_email_log) mais NE BLOQUE PAS
 *     l'action métier.
 *   - Tout envoi (queued | sent | failed) laisse une trace en BDD.
 *   - Le service utilise le mailer Laravel configuré sur 'brevo' (SMTP Brevo).
 *
 * Le service est conçu pour être appelé par WorkspaceNotificationService::dispatchEvent
 * lorsque la liste de canaux d'un événement contient 'email'.
 */
class BrevoMailService
{
    public function __construct(
        private readonly BrevoEmailTemplateFactory $templateFactory
    ) {
    }

    /**
     * @param  Collection<int, User>|EloquentCollection<int, User>  $recipients
     * @param  array<string, mixed>  $payload Notification rendue (title, message, url, module, entity_*).
     */
    public function dispatch(
        string $event,
        Collection|EloquentCollection $recipients,
        array $payload
    ): void {
        if (! $this->canSendEmails()) {
            return;
        }

        $targets = $recipients
            ->filter(static fn ($user): bool => $user instanceof User
                && (string) ($user->email ?? '') !== ''
            )
            ->unique('id')
            ->values();

        if ($targets->isEmpty()) {
            return;
        }

        foreach ($targets as $user) {
            $this->sendOne($event, $user, $payload);
        }
    }

    /**
     * Envoi best-effort à un destinataire unique.
     * Toute exception est attrapée et journalisée — JAMAIS propagée.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendOne(string $event, User $user, array $payload): void
    {
        $logId = null;

        try {
            $logId = $this->logEntry($event, $user, $payload, 'queued');
        } catch (Throwable $exception) {
            // Si la journalisation échoue, on log dans les fichiers Laravel mais
            // on continue : l'envoi mail reste tenté.
            Log::warning('Brevo email log queue failed.', [
                'event' => $event,
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $mailable = new BrevoNotificationMail(
                event: $event,
                title: (string) ($payload['title'] ?? ''),
                message: (string) ($payload['message'] ?? ''),
                ctaUrl: (string) ($payload['url'] ?? ''),
                recipientName: (string) ($user->name ?? ''),
                template: $this->templateFactory->make($event, $payload)
            );

            Mail::mailer($this->mailerName())->to($user->email)->send($mailable);

            $this->updateLog($logId, [
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // FAIL-SAFE absolu : on n'interrompt jamais le workflow métier.
            $this->updateLog($logId, [
                'status' => 'failed',
                'error_message' => $this->truncate($exception->getMessage()),
            ]);

            Log::warning('Brevo email send failed (non-blocking).', [
                'event' => $event,
                'user_id' => $user->id,
                'recipient_email' => $user->email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logEntry(string $event, User $user, array $payload, string $status): ?int
    {
        if (! Schema::hasTable('brevo_email_log')) {
            return null;
        }

        $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : null;
        $module = isset($payload['module']) ? (string) $payload['module'] : null;
        $entityType = isset($payload['entity_type']) ? (string) $payload['entity_type'] : null;
        $url = isset($payload['url']) ? (string) $payload['url'] : null;
        $title = (string) ($payload['title'] ?? '');

        $id = DB::table('brevo_email_log')->insertGetId([
            'user_id' => $user->id,
            'event_type' => $event,
            'recipient_email' => (string) $user->email,
            'subject' => $this->truncate($title, 255),
            'related_module' => $module,
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId,
            'cta_url' => $url !== null ? $this->truncate($url, 1024) : null,
            'payload' => json_encode([
                'title' => $title,
                'message' => (string) ($payload['message'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateLog(?int $logId, array $values): void
    {
        if ($logId === null || ! Schema::hasTable('brevo_email_log')) {
            return;
        }

        try {
            $values['updated_at'] = now();
            DB::table('brevo_email_log')->where('id', $logId)->update($values);
        } catch (Throwable $exception) {
            Log::warning('Brevo email log update failed.', [
                'log_id' => $logId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Email actif uniquement si le mailer 'brevo' est configuré ET activé via
     * BREVO_ENABLED. En testing par défaut on n'envoie pas (sauf override).
     */
    private function canSendEmails(): bool
    {
        if (! config('mail.mailers.'.$this->mailerName())) {
            return false;
        }

        return (bool) config('services.brevo.enabled', false);
    }

    private function mailerName(): string
    {
        return (string) config('services.brevo.mailer', 'brevo');
    }

    private function truncate(string $value, int $max = 500): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
