<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class WorkspaceModuleSettings
{
    private const GROUP_PUBLISHED = 'workspace_modules';
    private const GROUP_DRAFT = 'workspace_modules_draft';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $resolved = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $draftResolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->readGroup(self::GROUP_PUBLISHED);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function configuredModules(): array
    {
        return array_values($this->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function editable(): array
    {
        return $this->hasDraft() ? $this->draft() : $this->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function draft(): array
    {
        if ($this->draftResolved !== null) {
            return $this->draftResolved;
        }

        return $this->draftResolved = $this->readGroup(self::GROUP_DRAFT, $this->all());
    }

    public function hasDraft(): bool
    {
        if (! $this->hasSettingsTable()) {
            return false;
        }

        return PlatformSetting::query()
            ->where('group', self::GROUP_DRAFT)
            ->exists();
    }

    public function draftUpdatedAt(): ?string
    {
        if (! $this->hasDraft()) {
            return null;
        }

        return PlatformSetting::query()
            ->where('group', self::GROUP_DRAFT)
            ->max('updated_at');
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    public function applyToModules(array $modules): array
    {
        $settings = $this->all();
        $configured = [];

        foreach ($modules as $module) {
            $code = (string) ($module['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if ($code === 'alertes') {
                continue;
            }

            $config = $settings[$code] ?? [
                'enabled' => true,
                'label' => (string) ($module['label'] ?? strtoupper($code)),
                'description' => (string) ($module['description'] ?? ''),
                'order' => 999,
                'section' => 'autres',
            ];

            if ($code !== 'super_admin' && ! (bool) ($config['enabled'] ?? true)) {
                continue;
            }

            $module['label'] = (string) ($config['label'] ?? $module['label'] ?? strtoupper($code));
            $module['description'] = (string) ($config['description'] ?? $module['description'] ?? '');
            $module['display_order'] = (int) ($config['order'] ?? 999);
            $module['navigation_section'] = (string) ($config['section'] ?? 'autres');
            $module['is_globally_enabled'] = (bool) ($config['enabled'] ?? true);

            $configured[] = $module;
        }

        usort($configured, static function (array $left, array $right): int {
            $leftOrder = (int) ($left['display_order'] ?? 999);
            $rightOrder = (int) ($right['display_order'] ?? 999);

            if ($leftOrder === $rightOrder) {
                return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
            }

            return $leftOrder <=> $rightOrder;
        });

        return $configured;
    }

    public function activeCount(): int
    {
        return collect($this->all())
            ->filter(static fn (array $module): bool => (bool) ($module['enabled'] ?? false))
            ->count();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function updateModules(array $payload, ?User $actor = null): array
    {
        $this->writeGroup(self::GROUP_PUBLISHED, $payload, $actor);
        $this->flush();

        return $this->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function updateDraftModules(array $payload, ?User $actor = null): array
    {
        $this->writeGroup(self::GROUP_DRAFT, $payload, $actor);
        $this->flush();

        return $this->draft();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function publishDraft(?User $actor = null): array
    {
        if (! $this->hasDraft()) {
            return $this->all();
        }

        $this->writeGroup(self::GROUP_PUBLISHED, $this->draft(), $actor);
        PlatformSetting::query()->where('group', self::GROUP_DRAFT)->delete();
        $this->flush();

        return $this->all();
    }

    public function discardDraft(): void
    {
        if (! $this->hasSettingsTable()) {
            return;
        }

        PlatformSetting::query()->where('group', self::GROUP_DRAFT)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        \App\Support\SchemaIntrospectionCache::flush();

        $this->resolved = null;
        $this->draftResolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaults(): array
    {
        return [
            'pilotage' => ['code' => 'pilotage', 'label' => 'Pilotage', 'description' => 'Tableau de bord et synthèse de pilotage', 'enabled' => true, 'order' => 20, 'section' => 'pilotage'],
            'pas' => ['code' => 'pas', 'label' => 'PAS', 'description' => 'Vision stratégique pluriannuelle', 'enabled' => true, 'order' => 30, 'section' => 'planification'],
            'pao' => ['code' => 'pao', 'label' => 'PAO', 'description' => 'Déclinaison annuelle par direction', 'enabled' => true, 'order' => 40, 'section' => 'planification'],
            'pta' => ['code' => 'pta', 'label' => 'PTA', 'description' => 'Planification opérationnelle par service', 'enabled' => true, 'order' => 50, 'section' => 'planification'],
            'imports_excel' => ['code' => 'imports_excel', 'label' => 'Imports Excel', 'description' => 'Chargement Excel global PAS, PAO, PTA et actions', 'enabled' => true, 'order' => 55, 'section' => 'planification'],
            'execution' => ['code' => 'execution', 'label' => 'Actions', 'description' => 'Exécution des tâches et suivi de progression', 'enabled' => true, 'order' => 60, 'section' => 'execution'],
            'reporting' => ['code' => 'reporting', 'label' => 'Reporting', 'description' => 'Reporting consolidé, exports et diffusion', 'enabled' => true, 'order' => 70, 'section' => 'pilotage'],
            'referentiel' => ['code' => 'referentiel', 'label' => 'Référentiels', 'description' => 'Directions, services, utilisateurs', 'enabled' => true, 'order' => 80, 'section' => 'gouvernance'],
            'delegations' => ['code' => 'delegations', 'label' => 'Délégations', 'description' => 'Suppléance temporaire de validation', 'enabled' => true, 'order' => 90, 'section' => 'gouvernance'],
            'retention' => ['code' => 'retention', 'label' => 'Rétention', 'description' => 'Archivage et gouvernance des données', 'enabled' => true, 'order' => 100, 'section' => 'gouvernance'],
            'api_docs' => ['code' => 'api_docs', 'label' => 'Documentation API', 'description' => 'Contrats OpenAPI et Swagger UI', 'enabled' => true, 'order' => 110, 'section' => 'gouvernance'],
            'audit' => ['code' => 'audit', 'label' => 'Journal Audit', 'description' => 'Traçabilité des actions utilisateurs', 'enabled' => true, 'order' => 120, 'section' => 'gouvernance'],
            'super_admin' => ['code' => 'super_admin', 'label' => 'Super Administration', 'description' => 'Paramétrage profond, templates d’export et gouvernance de plateforme', 'enabled' => true, 'order' => 130, 'section' => 'plateforme'],
        ];
    }

    private function hasSettingsTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = \App\Support\SchemaIntrospectionCache::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $seed
     * @return array<string, array<string, mixed>>
     */
    private function readGroup(string $group, ?array $seed = null): array
    {
        $settings = $seed ?? $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', $group)
                ->pluck('value', 'key')
                ->all();

            foreach ($stored as $key => $value) {
                $code = str_replace($this->storagePrefix($group), '', (string) $key);
                $decoded = json_decode((string) $value, true);

                if (! is_array($decoded) || ! isset($settings[$code])) {
                    continue;
                }

                $settings[$code] = array_merge($settings[$code], $decoded);
            }
        }

        return $settings;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $payload
     */
    private function writeGroup(string $group, array $payload, ?User $actor = null): void
    {
        foreach ($this->defaults() as $code => $defaultConfig) {
            $input = is_array($payload[$code] ?? null) ? $payload[$code] : [];
            $enabled = $code === 'super_admin'
                ? true
                : filter_var($input['enabled'] ?? $defaultConfig['enabled'], FILTER_VALIDATE_BOOLEAN);

            $label = trim((string) ($input['label'] ?? $defaultConfig['label']));
            $description = trim((string) ($input['description'] ?? $defaultConfig['description']));
            $order = max(1, (int) ($input['order'] ?? $defaultConfig['order']));

            PlatformSetting::query()->updateOrCreate(
                ['group' => $group, 'key' => $this->storagePrefix($group).$code],
                [
                    'value' => json_encode([
                        'code' => $code,
                        'label' => $label !== '' ? $label : (string) $defaultConfig['label'],
                        'description' => $description !== '' ? $description : (string) $defaultConfig['description'],
                        'enabled' => $enabled,
                        'order' => $order,
                        'section' => (string) $defaultConfig['section'],
                    ], JSON_UNESCAPED_SLASHES),
                    'updated_by' => $actor?->id,
                ]
            );
        }
    }

    private function storagePrefix(string $group): string
    {
        return $group === self::GROUP_DRAFT ? 'workspace_module_draft_' : 'workspace_module_';
    }
}
