<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array{
     *     generated_at: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     actions_retard: \Illuminate\Support\Collection<int, \App\Models\Action>,
     *     kpi_sous_seuil: \Illuminate\Support\Collection<int, \App\Models\KpiMesure>,
     *     action_logs: \Illuminate\Support\Collection<int, \App\Models\ActionLog>,
     *     totals: array{actions_retard: int, kpi_sous_seuil: int, action_logs: int, total_alertes: int}
     * } $digest
     */
    public function __construct(
        public User $user,
        public array $digest
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ANBG - Alertes automatiques de suivi PAS/PAO/PTA'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alert-digest'
        );
    }
}
