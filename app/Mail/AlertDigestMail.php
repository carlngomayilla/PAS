<?php

namespace App\Mail;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\KpiMesure;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AlertDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array{
     *     generated_at: Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     actions_retard: Collection<int, Action>,
     *     kpi_sous_seuil: Collection<int, KpiMesure>,
     *     action_logs: Collection<int, ActionLog>,
     *     totals: array{actions_retard: int, kpi_sous_seuil: int, action_logs: int, total_alertes: int}
     * } $digest
     */
    public function __construct(
        public User $user,
        public array $digest
    ) {}

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
