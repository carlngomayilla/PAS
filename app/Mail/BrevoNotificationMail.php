<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable institutionnel utilisé pour le canal email Brevo.
 *
 * - Le contenu est rendu par la vue Blade emails.brevo.notification.
 * - Le sujet est dérivé du titre métier de la notification.
 * - L'expéditeur par défaut suit MAIL_FROM_ADDRESS (config/mail.php).
 */
class BrevoNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly string $message,
        public readonly string $ctaUrl = '',
        public readonly string $recipientName = '',
        public readonly array $template = []
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->title !== ''
            ? '[ANBG] '.$this->title
            : '[ANBG] Notification PAS';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.brevo.notification',
            with: [
                'event' => $this->event,
                'title' => $this->title,
                'notificationMessage' => $this->message,
                'ctaUrl' => $this->ctaUrl,
                'recipientName' => $this->recipientName,
                'appName' => (string) config('app.name', 'ANBG'),
                'template' => $this->template,
            ]
        );
    }
}
