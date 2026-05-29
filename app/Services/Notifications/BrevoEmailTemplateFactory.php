<?php

namespace App\Services\Notifications;

class BrevoEmailTemplateFactory
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function make(string $event, array $payload): array
    {
        $definition = $this->definitions()[$event] ?? $this->genericDefinition($event);

        return [
            'eyebrow' => $definition['eyebrow'],
            'headline' => (string) ($payload['title'] ?? $definition['headline']),
            'intro' => $definition['intro'],
            'message' => (string) ($payload['message'] ?? ''),
            'cta_label' => $definition['cta_label'],
            'badge_label' => $definition['badge_label'],
            'tone' => $definition['tone'],
            'accent' => $definition['accent'],
            'details' => $this->details($event, $payload, $definition['details']),
            'footer_note' => $definition['footer_note'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            'action_assigned' => [
                'eyebrow' => 'Attribution action',
                'headline' => 'Nouvelle action attribuee',
                'intro' => 'Une action vient de vous etre confiee dans le dispositif PAS / PAO / PTA.',
                'cta_label' => 'Consulter l action',
                'badge_label' => 'Action',
                'tone' => 'Information',
                'accent' => '#3996d3',
                'details' => ['module', 'entity_type', 'entity_id'],
                'footer_note' => 'Merci de consulter la fiche action afin de verifier les echeances et les attendus.',
            ],
            'action_submitted_to_chef' => [
                'eyebrow' => 'Validation chef de service',
                'headline' => 'Action soumise pour validation',
                'intro' => 'Une action attend votre evaluation et votre decision de cloture.',
                'cta_label' => 'Examiner la demande',
                'badge_label' => 'Validation',
                'tone' => 'Decision requise',
                'accent' => '#f59e0b',
                'details' => ['module', 'entity_type', 'entity_id', 'priority'],
                'footer_note' => 'Votre traitement permet de maintenir la chaine de validation a jour.',
            ],
            'action_alert_escalation' => [
                'eyebrow' => 'Alerte de pilotage',
                'headline' => 'Alerte action',
                'intro' => 'Une situation necessite une attention particuliere sur une action suivie.',
                'cta_label' => 'Analyser l alerte',
                'badge_label' => 'Alerte',
                'tone' => 'Prioritaire',
                'accent' => '#dc2626',
                'details' => ['module', 'entity_type', 'entity_id', 'status', 'priority'],
                'footer_note' => 'Cette alerte est generee automatiquement a partir des regles de suivi.',
            ],
            'action_financing_requested' => [
                'eyebrow' => 'Financement action',
                'headline' => 'Financement a traiter',
                'intro' => 'Une demande de financement requiert une analyse par les acteurs habilites.',
                'cta_label' => 'Ouvrir le dossier financement',
                'badge_label' => 'Financement',
                'tone' => 'Traitement DAF',
                'accent' => '#0f766e',
                'details' => ['module', 'entity_type', 'entity_id', 'priority'],
                'footer_note' => 'Les pieces et commentaires associes sont disponibles dans la fiche action.',
            ],
            'action_financing_reviewed_by_daf' => [
                'eyebrow' => 'Avis DAF',
                'headline' => 'Financement examine par la DAF',
                'intro' => 'La DAF a mis a jour son avis sur une demande de financement.',
                'cta_label' => 'Voir la decision DAF',
                'badge_label' => 'DAF',
                'tone' => 'Suivi financement',
                'accent' => '#2563eb',
                'details' => ['module', 'entity_type', 'entity_id', 'status', 'priority'],
                'footer_note' => 'Consultez le dossier pour connaitre les suites attendues.',
            ],
            'action_financing_reviewed_by_dg' => [
                'eyebrow' => 'Decision DG',
                'headline' => 'Financement examine par la DG',
                'intro' => 'La Direction Generale a statue sur une demande de financement.',
                'cta_label' => 'Voir la decision DG',
                'badge_label' => 'DG',
                'tone' => 'Decision',
                'accent' => '#1c203d',
                'details' => ['module', 'entity_type', 'entity_id', 'status', 'priority'],
                'footer_note' => 'Cette decision est conservee dans le journal de suivi de l action.',
            ],
            'sub_action_created' => [
                'eyebrow' => 'Sous-action',
                'headline' => 'Nouvelle sous-action creee',
                'intro' => 'Une sous-action a ete ajoutee a une action que vous suivez.',
                'cta_label' => 'Consulter la sous-action',
                'badge_label' => 'Sous-action',
                'tone' => 'Mise a jour',
                'accent' => '#7c3aed',
                'details' => ['module', 'entity_type', 'entity_id'],
                'footer_note' => 'Cette notification vous aide a suivre les contributions operationnelles.',
            ],
            'test_notification' => [
                'eyebrow' => 'Test canal email',
                'headline' => 'Test notification PAS',
                'intro' => 'Ce message confirme que le canal email Brevo est operationnel.',
                'cta_label' => 'Ouvrir l application',
                'badge_label' => 'Test',
                'tone' => 'Verification',
                'accent' => '#3996d3',
                'details' => ['module', 'entity_type', 'entity_id'],
                'footer_note' => 'Aucune action metier n est attendue pour cette notification de test.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function genericDefinition(string $event): array
    {
        return [
            'eyebrow' => 'Notification PAS',
            'headline' => 'Nouvelle notification',
            'intro' => 'Une mise a jour vous concerne dans le systeme de pilotage strategique.',
            'cta_label' => 'Ouvrir l application',
            'badge_label' => 'Notification',
            'tone' => 'Information',
            'accent' => '#3996d3',
            'details' => ['module', 'entity_type', 'entity_id'],
            'footer_note' => 'Reference evenement : '.$event,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<array{label:string,value:string}>
     */
    private function details(string $event, array $payload, array $keys): array
    {
        $labels = [
            'module' => 'Module',
            'entity_type' => 'Type',
            'entity_id' => 'Identifiant',
            'status' => 'Statut',
            'priority' => 'Priorite',
        ];

        $details = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $details[] = [
                'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)),
                'value' => (string) $value,
            ];
        }

        $details[] = [
            'label' => 'Evenement',
            'value' => $event,
        ];

        return $details;
    }
}
