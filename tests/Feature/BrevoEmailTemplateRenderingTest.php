<?php

namespace Tests\Feature;

use App\Mail\BrevoNotificationMail;
use App\Services\Notifications\BrevoEmailTemplateFactory;
use Tests\TestCase;

class BrevoEmailTemplateRenderingTest extends TestCase
{
    public function test_all_brevo_notification_email_templates_render(): void
    {
        $events = [
            'action_assigned',
            'action_submitted_to_chef',
            'action_alert_escalation',
            'action_financing_requested',
            'action_financing_reviewed_by_daf',
            'action_financing_reviewed_by_dg',
            'sub_action_created',
            'test_notification',
            'unknown_event',
        ];

        $factory = app(BrevoEmailTemplateFactory::class);

        foreach ($events as $event) {
            $payload = [
                'title' => 'Titre '.$event,
                'message' => 'Message personnalise pour '.$event,
                'url' => 'https://pas.anbg.test/workspace/actions/1/suivi',
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => 1,
                'status' => 'info',
                'priority' => 'normal',
            ];

            $html = (new BrevoNotificationMail(
                event: $event,
                title: (string) $payload['title'],
                message: (string) $payload['message'],
                ctaUrl: (string) $payload['url'],
                recipientName: 'Administrateur fonctionnel',
                template: $factory->make($event, $payload)
            ))->render();

            $this->assertStringContainsString('e-Pilotage PAS', $html, 'Template '.$event);
            $this->assertStringContainsString('Message personnalise pour '.$event, $html, 'Template '.$event);
            $this->assertStringContainsString('Administrateur fonctionnel', $html, 'Template '.$event);
            $this->assertStringContainsString('https://pas.anbg.test/workspace/actions/1/suivi', $html, 'Template '.$event);
        }
    }
}
