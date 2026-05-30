<?php

namespace App\Services\Notifications;

use App\Mail\BrevoNotificationMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Canal email Brevo (complémentaire au canal in_app).
 *
 * Règles métier (v1.1 - Section Brevo) :
 *   - L'envoi email est complémentaire : la notification interne est créée AVANT.
 *   - Tout échec Brevo est journalisé (table brevo_email_log) mais NE BLOQUE PAS
 *     l'action métier.
 *   - Tout envoi (queued | sent | failed) laisse une trace en BDD.
 *
 * Deux transports supportés (configurés via `services.brevo.transport`) :
 *   - 'api'  : POST https://api.brevo.com/v3/smtp/email avec header api-key.
 *              Pas de restriction d'IP, plus fiable, recommandé en production.
 *   - 'smtp' : Mailer Laravel 'brevo' (relais SMTP). Soumis aux IPs autorisées.
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
            if ($this->transport() === 'api') {
                $this->sendViaApi($event, $user, $payload);
            } else {
                $this->sendViaSmtp($event, $user, $payload);
            }

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
                'transport' => $this->transport(),
                'user_id' => $user->id,
                'recipient_email' => $user->email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Envoi via l'API HTTP Brevo. Pas de restriction d'IP.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \RuntimeException Si l'API retourne un code != 2xx.
     */
    private function sendViaApi(string $event, User $user, array $payload): void
    {
        $title = (string) ($payload['title'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $ctaUrl = (string) ($payload['url'] ?? '');
        $recipientName = (string) ($user->name ?? '');
        $template = $this->templateFactory->make($event, $payload);

        // Le HTML est rendu via la même vue Blade que pour le canal SMTP, pour
        // garantir un rendu identique entre les deux transports.
        $htmlContent = View::make('emails.brevo.notification', [
            'event' => $event,
            'title' => $title,
            'notificationMessage' => $message,
            'ctaUrl' => $ctaUrl,
            'recipientName' => $recipientName,
            'appName' => (string) config('app.name', 'ANBG'),
            'template' => $template,
        ])->render();

        $textContent = trim(strip_tags($message)) ?: $title;
        $subject = $title !== '' ? '[ANBG] '.$title : '[ANBG] Notification PAS';

        $fromAddress = (string) config('services.brevo.from.address', 'no-reply@anbg.ga');
        $fromName = (string) config('services.brevo.from.name', 'ANBG');

        $body = [
            'sender' => ['name' => $fromName, 'email' => $fromAddress],
            'to' => [['email' => (string) $user->email, 'name' => $recipientName !== '' ? $recipientName : (string) $user->email]],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
            'textContent' => $textContent,
            'tags' => [$event],
        ];

        $endpoint = (string) config('services.brevo.api_endpoint', 'https://api.brevo.com/v3/smtp/email');
        $timeout = (int) config('services.brevo.api_timeout', 10);
        $verifySsl = (bool) config('services.brevo.api_verify_ssl', true);

        $client = Http::withHeaders([
            'api-key' => (string) config('services.brevo.api_key'),
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->timeout($timeout);

        if (! $verifySsl) {
            // Dev only (Windows PHP sans bundle CA). Loggué pour traçabilité.
            $client = $client->withoutVerifying();
        }

        $response = $client->post($endpoint, $body);

        if (! $response->successful()) {
            $errorBody = $response->body();
            throw new \RuntimeException(sprintf(
                'Brevo API responded with HTTP %d: %s',
                $response->status(),
                $this->truncate($errorBody, 350)
            ));
        }
    }

    /**
     * Envoi via le mailer Laravel 'brevo' (transport SMTP).
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendViaSmtp(string $event, User $user, array $payload): void
    {
        $mailable = new BrevoNotificationMail(
            event: $event,
            title: (string) ($payload['title'] ?? ''),
            message: (string) ($payload['message'] ?? ''),
            ctaUrl: (string) ($payload['url'] ?? ''),
            recipientName: (string) ($user->name ?? ''),
            template: $this->templateFactory->make($event, $payload)
        );

        Mail::mailer($this->mailerName())->to($user->email)->send($mailable);
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
                'transport' => $this->transport(),
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
     * Email actif uniquement si Brevo est explicitement activé ET que les
     * credentials du transport sélectionné sont présents.
     *
     *   - transport=api  : services.brevo.api_key doit être défini.
     *   - transport=smtp : mail.mailers.brevo doit exister + credentials SMTP.
     */
    private function canSendEmails(): bool
    {
        if (! (bool) config('services.brevo.enabled', false)) {
            return false;
        }

        if ($this->transport() === 'api') {
            return (string) config('services.brevo.api_key', '') !== '';
        }

        return (bool) config('mail.mailers.'.$this->mailerName());
    }

    private function transport(): string
    {
        $value = strtolower((string) config('services.brevo.transport', 'api'));

        return in_array($value, ['api', 'smtp'], true) ? $value : 'api';
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
