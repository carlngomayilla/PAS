<?php

namespace App\Services;

use App\Models\ActionLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NotificationPolicySettings
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'notification_policy')
                ->pluck('value', 'key')
                ->all();

            foreach ($this->eventDefinitions() as $event => $definition) {
                $key = 'event_'.$event.'_enabled';
                if (array_key_exists($key, $stored)) {
                    $settings[$key] = $this->normalizeBooleanString($stored[$key]);
                }

                foreach (['title', 'message', 'channels'] as $suffix) {
                    $templateKey = 'event_'.$event.'_'.$suffix;
                    if (! array_key_exists($templateKey, $stored)) {
                        continue;
                    }

                    if ($suffix === 'channels') {
                        $decoded = json_decode((string) $stored[$templateKey], true);
                        if (is_array($decoded)) {
                            $settings[$templateKey] = $this->sanitizeChannelList($decoded);
                        }

                        continue;
                    }

                    $settings[$templateKey] = Str::limit(trim((string) $stored[$templateKey]), $suffix === 'title' ? 120 : 255, '');
                }
            }

            foreach ($this->alertLevelDefinitions() as $level => $definition) {
                $enabledKey = 'alert_'.$level.'_enabled';
                $rolesKey = 'alert_'.$level.'_roles';

                if (array_key_exists($enabledKey, $stored)) {
                    $settings[$enabledKey] = $this->normalizeBooleanString($stored[$enabledKey]);
                }

                if (array_key_exists($rolesKey, $stored)) {
                    $decoded = json_decode((string) $stored[$rolesKey], true);
                    if (is_array($decoded)) {
                        $settings[$rolesKey] = $this->sanitizeRoleList($decoded);
                    }
                }
            }

            if (array_key_exists('alert_escalation_rules', $stored)) {
                $decoded = json_decode((string) $stored['alert_escalation_rules'], true);
                if (is_array($decoded)) {
                    $settings['alert_escalation_rules'] = $this->sanitizeEscalationRules($decoded);
                }
            }

            if (array_key_exists('alert_timeline_rules', $stored)) {
                $decoded = json_decode((string) $stored['alert_timeline_rules'], true);
                if (is_array($decoded)) {
                    $settings['alert_timeline_rules'] = $this->sanitizeTimelineRules($decoded);
                }
            }
        }

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach (array_keys($this->eventDefinitions()) as $event) {
            $defaults['event_'.$event.'_enabled'] = '1';
            $template = $this->eventTemplateDefaults()[$event] ?? ['title' => Str::headline($event), 'message' => '', 'channels' => ['in_app']];
            $defaults['event_'.$event.'_title'] = (string) ($template['title'] ?? Str::headline($event));
            $defaults['event_'.$event.'_message'] = (string) ($template['message'] ?? '');
            $defaults['event_'.$event.'_channels'] = $this->sanitizeChannelList($template['channels'] ?? ['in_app']);
        }

        foreach (array_keys($this->alertLevelDefinitions()) as $level) {
            $defaults['alert_'.$level.'_enabled'] = '1';
            $defaults['alert_'.$level.'_roles'] = [];
        }

        $defaults['alert_escalation_rules'] = [];
        $defaults['alert_timeline_rules'] = [
            [
                'code' => 'j_minus_3',
                'offset_days' => -3,
                'level' => 'warning',
                'target_role' => 'service',
                'message_template' => 'J-3 avant echeance pour {action_label}. Acceleration demandee.',
                'active' => true,
            ],
            [
                'code' => 'j_plus_7',
                'offset_days' => 7,
                'level' => 'critical',
                'target_role' => 'direction',
                'message_template' => 'J+7 apres echeance pour {action_label}. Escalade direction requise.',
                'active' => true,
            ],
        ];

        return $defaults;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function eventDefinitions(): array
    {
        return [
            'action_assigned' => ['group' => 'Actions', 'label' => 'Attribution d action', 'description' => 'Notification envoyee a l agent responsable lors de l attribution ou du changement de responsable.'],
            'action_submitted_to_chef' => ['group' => 'Actions', 'label' => 'Soumission au chef', 'description' => 'Notification envoyee au service lors d une demande de validation.'],
            'action_submitted_to_direction' => ['group' => 'Actions', 'label' => 'Soumission directe a la direction', 'description' => 'Notification envoyee a la direction quand l etape service est sautee.'],
            'action_reviewed_by_chef' => ['group' => 'Actions', 'label' => 'Decision chef', 'description' => 'Notification apres validation ou rejet par le chef de service.'],
            'action_reviewed_by_direction' => ['group' => 'Actions', 'label' => 'Decision direction', 'description' => 'Notification apres validation ou rejet par la direction.'],
            'action_finalized_by_chef' => ['group' => 'Actions', 'label' => 'Finalisation par le chef', 'description' => 'Notification finale lorsque le chef devient la derniere etape du circuit.'],
            'action_finalized_without_workflow' => ['group' => 'Actions', 'label' => 'Cloture sans workflow', 'description' => 'Notification finale quand aucune validation supplementaire n est active.'],
            'action_alert_escalation' => ['group' => 'Alertes', 'label' => 'Escalade d alerte action', 'description' => 'Notification issue des journaux d action en warning, critique ou urgence.'],
            'pas_status' => ['group' => 'Planification', 'label' => 'Statuts PAS', 'description' => 'Notifications de soumission, validation, verrouillage et retour brouillon des PAS.'],
            'pao_status' => ['group' => 'Planification', 'label' => 'Statuts PAO', 'description' => 'Notifications de soumission, validation, verrouillage et retour brouillon des PAO.'],
            'pta_status' => ['group' => 'Planification', 'label' => 'Statuts PTA', 'description' => 'Notifications de soumission, validation, verrouillage et retour brouillon des PTA.'],
            'delegation_created' => ['group' => 'Gouvernance', 'label' => 'Nouvelle delegation', 'description' => 'Notification envoyee au delegue lors de la creation d une delegation.'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function alertLevelDefinitions(): array
    {
        return [
            'warning' => ['label' => 'Warning', 'description' => 'Escalade faible mais visible.'],
            'critical' => ['label' => 'Critique', 'description' => 'Escalade forte sur les cas critiques.'],
            'urgence' => ['label' => 'Urgence', 'description' => 'Escalade maximale sur les situations les plus sensibles.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function escalationRoleOptions(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Super Admin',
            User::ROLE_ADMIN => 'Administrateur',
            User::ROLE_PLANIFICATION => 'Planification',
            User::ROLE_DG => 'DG',
            User::ROLE_CABINET => 'Cabinet',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function escalationTargetRoleOptions(): array
    {
        return [
            'responsable' => 'Responsable action',
            'service' => 'Chef de service',
            'direction' => 'Direction',
            'planification' => 'Planification',
            'dg' => 'DG',
            User::ROLE_ADMIN => 'Administrateur',
            User::ROLE_SUPER_ADMIN => 'Super Admin',
            User::ROLE_CABINET => 'Cabinet',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function eventChannelOptions(): array
    {
        return [
            'in_app' => 'Notification applicative',
            'audit' => 'Trace audit supplementaire',
        ];
    }

    public function eventEnabled(string $event): bool
    {
        return ($this->all()['event_'.$event.'_enabled'] ?? '0') === '1';
    }

    /**
     * @return array{enabled:bool,title:string,message:string,channels:list<string>}
     */
    public function eventTemplate(string $event): array
    {
        $settings = $this->all();

        return [
            'enabled' => $this->eventEnabled($event),
            'title' => (string) ($settings['event_'.$event.'_title'] ?? ($this->eventTemplateDefaults()[$event]['title'] ?? Str::headline($event))),
            'message' => (string) ($settings['event_'.$event.'_message'] ?? ($this->eventTemplateDefaults()[$event]['message'] ?? '')),
            'channels' => $this->sanitizeChannelList($settings['event_'.$event.'_channels'] ?? ($this->eventTemplateDefaults()[$event]['channels'] ?? ['in_app'])),
        ];
    }

    public function alertLevelEnabled(string $level): bool
    {
        return ($this->all()['alert_'.$this->normalizeLevel($level).'_enabled'] ?? '0') === '1';
    }

    /**
     * @return list<string>
     */
    public function alertOversightRoles(string $level): array
    {
        $value = $this->all()['alert_'.$this->normalizeLevel($level).'_roles'] ?? [];

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->sanitizeRoleList($decoded);
            }
        }

        if (is_array($value)) {
            return $this->sanitizeRoleList($value);
        }

        return [];
    }

    /**
     * @return list<array{code:string,level:string,target_role:string,message_template:string,active:bool}>
     */
    public function escalationRules(): array
    {
        $rules = $this->all()['alert_escalation_rules'] ?? [];

        return is_array($rules) ? $this->sanitizeEscalationRules($rules) : [];
    }

    /**
     * @return list<array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}>
     */
    public function timelineRules(): array
    {
        $rules = $this->all()['alert_timeline_rules'] ?? [];

        return is_array($rules) ? $this->sanitizeTimelineRules($rules) : [];
    }

    /**
     * @return list<array{code:string,level:string,target_role:string,message_template:string,active:bool}>
     */
    public function matchingEscalationRules(string $level): array
    {
        $normalizedLevel = $this->normalizeLevel($level);

        /** @var list<array{code:string,level:string,target_role:string,message_template:string,active:bool}> $items */
        $items = collect($this->escalationRules())
            ->filter(fn (array $rule): bool => $rule['active'] && $rule['level'] === $normalizedLevel)
            ->values()
            ->all();

        return $items;
    }

    /**
     * @return list<array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}>
     */
    public function matchingTimelineRules(int $offsetDays): array
    {
        /** @var list<array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}> $items */
        $items = collect($this->timelineRules())
            ->filter(fn (array $rule): bool => $rule['active'] && $rule['offset_days'] === $offsetDays)
            ->values()
            ->all();

        return $items;
    }

    public function renderActionAlertMessage(ActionLog $log): string
    {
        $template = collect($this->matchingEscalationRules((string) $log->niveau))
            ->pluck('message_template')
            ->map(fn ($value): string => trim((string) $value))
            ->first(fn (string $value): bool => $value !== '');

        if (! is_string($template) || trim($template) === '') {
            return (string) $log->message;
        }

        $log->loadMissing('action:id,libelle');

        return strtr($template, [
            '{level}' => $this->alertLevelDefinitions()[$this->normalizeLevel((string) $log->niveau)]['label'] ?? strtoupper((string) $log->niveau),
            '{event}' => (string) ($log->type_evenement ?? ''),
            '{message}' => (string) $log->message,
            '{action_label}' => (string) ($log->action?->libelle ?? 'Action'),
            '{offset_days}' => (string) ((int) ($log->details['offset_days'] ?? 0)),
        ]);
    }

    /**
     * @param  array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}  $rule
     */
    public function renderTimelineRuleMessage(array $rule, ActionLog $log): string
    {
        $template = trim((string) ($rule['message_template'] ?? ''));

        if ($template === '') {
            return (string) $log->message;
        }

        $log->loadMissing('action:id,libelle');

        return strtr($template, [
            '{level}' => $this->alertLevelDefinitions()[$this->normalizeLevel((string) ($rule['level'] ?? $log->niveau))]['label'] ?? strtoupper((string) $log->niveau),
            '{event}' => (string) ($log->type_evenement ?? ''),
            '{message}' => (string) $log->message,
            '{action_label}' => (string) ($log->action?->libelle ?? 'Action'),
            '{offset_days}' => (string) ((int) ($rule['offset_days'] ?? 0)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $settings = [];

        foreach (array_keys($this->eventDefinitions()) as $event) {
            $settings['event_'.$event.'_enabled'] = ! empty($payload['event_'.$event.'_enabled']) ? '1' : '0';
            $settings['event_'.$event.'_title'] = Str::limit(trim((string) ($payload['event_'.$event.'_title'] ?? ($this->eventTemplateDefaults()[$event]['title'] ?? ''))), 120, '');
            $settings['event_'.$event.'_message'] = Str::limit(trim((string) ($payload['event_'.$event.'_message'] ?? ($this->eventTemplateDefaults()[$event]['message'] ?? ''))), 255, '');
            $settings['event_'.$event.'_channels'] = json_encode(
                $this->sanitizeChannelList($payload['event_'.$event.'_channels'] ?? ($this->eventTemplateDefaults()[$event]['channels'] ?? ['in_app'])),
                JSON_UNESCAPED_SLASHES
            );
        }

        foreach (array_keys($this->alertLevelDefinitions()) as $level) {
            $settings['alert_'.$level.'_enabled'] = ! empty($payload['alert_'.$level.'_enabled']) ? '1' : '0';
            $settings['alert_'.$level.'_roles'] = json_encode(
                $this->sanitizeRoleList($payload['alert_'.$level.'_roles'] ?? []),
                JSON_UNESCAPED_SLASHES
            );
        }

        $settings['alert_escalation_rules'] = json_encode(
            $this->sanitizeEscalationRules($payload['escalation_rules'] ?? []),
            JSON_UNESCAPED_SLASHES
        );
        $settings['alert_timeline_rules'] = json_encode(
            $this->sanitizeTimelineRules($payload['timeline_rules'] ?? []),
            JSON_UNESCAPED_SLASHES
        );

        foreach ($settings as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'notification_policy', 'key' => $key],
                ['value' => $value, 'updated_by' => $actor?->id]
            );
        }

        $this->flush();

        return $this->all();
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'events_enabled' => collect(array_keys($this->eventDefinitions()))
                ->filter(fn (string $event): bool => $this->eventEnabled($event))
                ->count(),
            'levels_enabled' => collect(array_keys($this->alertLevelDefinitions()))
                ->filter(fn (string $level): bool => $this->alertLevelEnabled($level))
                ->count(),
            'oversight_roles' => collect(array_keys($this->alertLevelDefinitions()))
                ->flatMap(fn (string $level): array => $this->alertOversightRoles($level))
                ->unique()
                ->count(),
            'rules_enabled' => collect($this->escalationRules())
                ->where('active', true)
                ->count(),
            'timeline_rules_enabled' => collect($this->timelineRules())
                ->where('active', true)
                ->count(),
        ];
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, scalar|null>  $replacements
     * @return array<string, mixed>
     */
    public function renderEventPayload(string $event, array $defaults, array $replacements = []): array
    {
        $template = $this->eventTemplate($event);
        $title = trim((string) ($template['title'] ?: ($defaults['title'] ?? '')));
        $message = trim((string) ($template['message'] ?: ($defaults['message'] ?? '')));

        $renderedTitle = $this->renderTemplateString($title, $replacements);
        $renderedMessage = $this->renderTemplateString($message, $replacements);

        return [
            ...$defaults,
            'title' => $renderedTitle !== '' ? $renderedTitle : (string) ($defaults['title'] ?? ''),
            'message' => $renderedMessage !== '' ? $renderedMessage : (string) ($defaults['message'] ?? ''),
            'channels' => $template['channels'],
        ];
    }

    private function normalizeLevel(string $level): string
    {
        $normalized = strtolower(trim($level));

        return match ($normalized) {
            'urgence' => 'urgence',
            'critical', 'critique' => 'critical',
            default => 'warning',
        };
    }

    private function normalizeBooleanString(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    /**
     * @return array<string, array{title:string,message:string,channels:list<string>}>
     */
    private function eventTemplateDefaults(): array
    {
        return [
            'action_assigned' => ['title' => 'Nouvelle action attribuee', 'message' => 'L action \"{action_label}\" vous a ete attribuee.', 'channels' => ['in_app']],
            'action_submitted_to_chef' => ['title' => 'Action soumise pour validation', 'message' => 'L action \"{action_label}\" attend votre evaluation.', 'channels' => ['in_app']],
            'action_submitted_to_direction' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'action_reviewed_by_chef' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'action_reviewed_by_direction' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'action_finalized_by_chef' => ['title' => 'Action validee par le chef', 'message' => 'L action \"{action_label}\" est finalisee sans etape direction supplementaire.', 'channels' => ['in_app']],
            'action_finalized_without_workflow' => ['title' => 'Action cloturee', 'message' => 'L action \"{action_label}\" a ete cloturee sans circuit de validation supplementaire.', 'channels' => ['in_app']],
            'action_alert_escalation' => ['title' => '', 'message' => '', 'channels' => ['in_app', 'audit']],
            'pas_status' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'pao_status' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'pta_status' => ['title' => '', 'message' => '', 'channels' => ['in_app']],
            'delegation_created' => ['title' => 'Nouvelle delegation recue', 'message' => 'Une delegation de {actor_name} vous a ete attribuee sur le perimetre {scope_label}.', 'channels' => ['in_app']],
        ];
    }

    /**
     * @param  iterable<int, mixed>  $channels
     * @return list<string>
     */
    private function sanitizeChannelList(iterable $channels): array
    {
        $allowed = array_keys($this->eventChannelOptions());

        /** @var list<string> $items */
        $items = collect($channels)
            ->map(fn ($channel): string => trim((string) $channel))
            ->filter(fn (string $channel): bool => in_array($channel, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $items === [] ? ['in_app'] : $items;
    }

    /**
     * @param  array<string, scalar|null>  $replacements
     */
    private function renderTemplateString(string $template, array $replacements): string
    {
        if ($template === '') {
            return '';
        }

        $normalized = [];
        foreach ($replacements as $key => $value) {
            $normalized['{'.$key.'}'] = (string) ($value ?? '');
        }

        return strtr($template, $normalized);
    }

    /**
     * @param  iterable<int, mixed>  $roles
     * @return list<string>
     */
    private function sanitizeRoleList(iterable $roles): array
    {
        $allowed = array_keys($this->escalationRoleOptions());

        $items = collect($roles)
            ->map(fn ($role): string => trim((string) $role))
            ->filter(fn (string $role): bool => in_array($role, $allowed, true))
            ->unique()
            ->values()
            ->all();

        /** @var list<string> $items */
        return $items;
    }

    /**
     * @param  iterable<int, mixed>  $rules
     * @return list<array{code:string,level:string,target_role:string,message_template:string,active:bool}>
     */
    private function sanitizeEscalationRules(iterable $rules): array
    {
        $allowedLevels = array_keys($this->alertLevelDefinitions());
        $allowedTargets = array_keys($this->escalationTargetRoleOptions());

        $items = collect($rules)
            ->map(function ($rule, int $index) use ($allowedLevels, $allowedTargets): ?array {
                if (! is_array($rule)) {
                    return null;
                }

                $targetRole = trim((string) ($rule['target_role'] ?? ''));
                $messageTemplate = trim((string) ($rule['message_template'] ?? ''));

                if ($targetRole === '' && $messageTemplate === '') {
                    return null;
                }

                if (! in_array($targetRole, $allowedTargets, true)) {
                    return null;
                }

                $level = $this->normalizeLevel((string) ($rule['level'] ?? 'warning'));
                if (! in_array($level, $allowedLevels, true)) {
                    $level = 'warning';
                }

                return [
                    'code' => trim((string) ($rule['code'] ?? '')) !== ''
                        ? Str::slug((string) $rule['code'])
                        : 'rule-'.($index + 1).'-'.$level.'-'.$targetRole,
                    'level' => $level,
                    'target_role' => $targetRole,
                    'message_template' => Str::limit($messageTemplate, 255, ''),
                    'active' => filter_var($rule['active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->filter()
            ->values()
            ->all();

        /** @var list<array{code:string,level:string,target_role:string,message_template:string,active:bool}> $items */
        return $items;
    }

    /**
     * @param  iterable<int, mixed>  $rules
     * @return list<array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}>
     */
    private function sanitizeTimelineRules(iterable $rules): array
    {
        $allowedTargets = array_keys($this->escalationTargetRoleOptions());

        $items = collect($rules)
            ->map(function ($rule, int $index) use ($allowedTargets): ?array {
                if (! is_array($rule)) {
                    return null;
                }

                $targetRole = trim((string) ($rule['target_role'] ?? ''));
                $messageTemplate = trim((string) ($rule['message_template'] ?? ''));
                $offsetDays = (int) ($rule['offset_days'] ?? 0);

                if ($targetRole === '' && $messageTemplate === '' && $offsetDays === 0) {
                    return null;
                }

                if (! in_array($targetRole, $allowedTargets, true)) {
                    return null;
                }

                return [
                    'code' => trim((string) ($rule['code'] ?? '')) !== ''
                        ? Str::slug((string) $rule['code'], '_')
                        : 'timeline_'.($index + 1).'_'.$offsetDays.'_'.$targetRole,
                    'offset_days' => max(-365, min(365, $offsetDays)),
                    'level' => $this->normalizeLevel((string) ($rule['level'] ?? 'warning')),
                    'target_role' => $targetRole,
                    'message_template' => Str::limit($messageTemplate, 255, ''),
                    'active' => filter_var($rule['active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->filter()
            ->unique(fn (array $rule): string => $rule['code'].':'.$rule['offset_days'])
            ->values()
            ->all();

        /** @var list<array{code:string,offset_days:int,level:string,target_role:string,message_template:string,active:bool}> $items */
        return $items;
    }
    private function hasSettingsTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}



