<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class DashboardProfileSettings
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'dashboard_profiles')
                ->pluck('value', 'key')
                ->all();

            foreach ($stored as $key => $value) {
                $role = str_replace('dashboard_profile_', '', (string) $key);
                $decoded = json_decode((string) $value, true);

                if (! is_array($decoded) || ! isset($settings[$role])) {
                    continue;
                }

                $settings[$role] = array_merge($settings[$role], Arr::except($decoded, ['cards']));
                $settings[$role]['cards'] = $this->mergeCards(
                    $settings[$role]['cards'] ?? [],
                    is_array($decoded['cards'] ?? null) ? $decoded['cards'] : []
                );
                $settings[$role] = $this->normalizeChartFlags($settings[$role]);
            }
        }

        foreach ($settings as $role => $config) {
            $settings[$role] = $this->normalizeChartFlags($config);
        }

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, string>
     */
    public function roleOptions(): array
    {
        return [
            'agent' => 'Agent',
            'service' => 'Chef de service',
            'direction' => 'Direction',
            'planification' => 'Planification',
            'dg' => 'DG',
            'cabinet' => 'Cabinet',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forRole(string $role): array
    {
        return $this->all()[$role] ?? [
            'overview_enabled' => true,
            'comparison_chart_enabled' => true,
            'status_chart_enabled' => true,
            'trend_chart_enabled' => true,
            'support_chart_enabled' => true,
            'cards' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    public function applyToDashboard(string $role, array $dashboard): array
    {
        $settings = $this->forRole($role);
        $summaryCards = is_array($dashboard['summary_cards'] ?? null)
            ? $dashboard['summary_cards']
            : [];
        $cardsConfig = collect($settings['cards'] ?? [])
            ->keyBy(fn (array $card): string => (string) ($card['code'] ?? ''));

        $dashboard['summary_cards'] = collect($summaryCards)
            ->filter(function (array $card) use ($cardsConfig): bool {
                $code = (string) ($card['code'] ?? '');
                if ($code === '') {
                    return true;
                }

                return (bool) ($cardsConfig[$code]['enabled'] ?? true);
            })
            ->map(function (array $card) use ($cardsConfig): array {
                $code = (string) ($card['code'] ?? '');
                $config = is_array($cardsConfig[$code] ?? null) ? $cardsConfig[$code] : [];

                return $this->applyCardConfiguration($card, $config);
            })
            ->sortBy(function (array $card) use ($cardsConfig): int {
                $code = (string) ($card['code'] ?? '');

                return (int) ($cardsConfig[$code]['order'] ?? 999);
            })
            ->values()
            ->all();

        if (is_array($dashboard['summary_groups'] ?? null)) {
            $dashboard['summary_groups'] = collect($dashboard['summary_groups'])
                ->map(function (array $group) use ($cardsConfig): array {
                    $cards = collect($group['cards'] ?? [])
                        ->filter(function (array $card) use ($cardsConfig): bool {
                            $code = (string) ($card['code'] ?? '');
                            if ($code === '') {
                                return true;
                            }

                            return (bool) ($cardsConfig[$code]['enabled'] ?? true);
                        })
                        ->map(function (array $card) use ($cardsConfig): array {
                            $code = (string) ($card['code'] ?? '');
                            $config = is_array($cardsConfig[$code] ?? null) ? $cardsConfig[$code] : [];

                            return $this->applyCardConfiguration($card, $config);
                        })
                        ->sortBy(function (array $card) use ($cardsConfig): int {
                            $code = (string) ($card['code'] ?? '');

                            return (int) ($cardsConfig[$code]['order'] ?? 999);
                        })
                        ->values()
                        ->all();

                    $group['cards'] = $cards;

                    return $group;
                })
                ->filter(fn (array $group): bool => ($group['cards'] ?? []) !== [])
                ->values()
                ->all();
        }

        $settings = $this->normalizeChartFlags($settings);
        $dashboard['overview_enabled'] = (bool) ($settings['overview_enabled'] ?? true);
        $dashboard['comparison_chart_enabled'] = (bool) ($settings['comparison_chart_enabled'] ?? true);
        $dashboard['status_chart_enabled'] = (bool) ($settings['status_chart_enabled'] ?? true);
        $dashboard['trend_chart_enabled'] = (bool) ($settings['trend_chart_enabled'] ?? true);
        $dashboard['support_chart_enabled'] = (bool) ($settings['support_chart_enabled'] ?? true);

        return $dashboard;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        foreach ($this->defaults() as $role => $config) {
            $hasRolePayload = is_array($payload[$role] ?? null);
            $rolePayload = $hasRolePayload ? $payload[$role] : [];
            $cards = [];

            foreach ($config['cards'] as $card) {
                $code = (string) $card['code'];
                $cardPayload = is_array($rolePayload['cards'][$code] ?? null) ? $rolePayload['cards'][$code] : [];
                $cards[] = [
                    'code' => $code,
                    'label' => (string) $card['label'],
                    'enabled' => $hasRolePayload
                        ? array_key_exists('enabled', $cardPayload)
                        : (bool) $card['enabled'],
                    'order' => max(1, (int) ($cardPayload['order'] ?? $card['order'])),
                    'size' => $this->normalizeCardSize($cardPayload['size'] ?? $card['size'] ?? 'md'),
                    'tone' => $this->normalizeCardTone($cardPayload['tone'] ?? $card['tone'] ?? 'auto'),
                    'target_route' => $this->normalizeTargetRoute($cardPayload['target_route'] ?? $card['target_route'] ?? ''),
                    'target_filters' => trim((string) ($cardPayload['target_filters'] ?? $card['target_filters'] ?? '')),
                ];
            }

            $chartFlags = $this->normalizeChartFlags([
                'overview_enabled' => $hasRolePayload
                    ? array_key_exists('overview_enabled', $rolePayload)
                    : (bool) $config['overview_enabled'],
                'comparison_chart_enabled' => $hasRolePayload
                    ? array_key_exists('comparison_chart_enabled', $rolePayload)
                    : (bool) $config['comparison_chart_enabled'],
                'status_chart_enabled' => $hasRolePayload
                    ? array_key_exists('status_chart_enabled', $rolePayload)
                    : (bool) $config['status_chart_enabled'],
                'trend_chart_enabled' => $hasRolePayload
                    ? array_key_exists('trend_chart_enabled', $rolePayload)
                    : (bool) $config['trend_chart_enabled'],
                'support_chart_enabled' => $hasRolePayload
                    ? array_key_exists('support_chart_enabled', $rolePayload)
                    : (bool) $config['support_chart_enabled'],
            ]);

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'dashboard_profiles', 'key' => 'dashboard_profile_'.$role],
                [
                    'value' => json_encode([
                        'overview_enabled' => $chartFlags['overview_enabled'],
                        'comparison_chart_enabled' => $chartFlags['comparison_chart_enabled'],
                        'status_chart_enabled' => $chartFlags['status_chart_enabled'],
                        'trend_chart_enabled' => $chartFlags['trend_chart_enabled'],
                        'support_chart_enabled' => $chartFlags['support_chart_enabled'],
                        'cards' => $cards,
                    ], JSON_UNESCAPED_SLASHES),
                    'updated_by' => $actor?->id,
                ]
            );
        }

        $this->flush();

        return $this->all();
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            'agent' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'Mes actions',
                    'Mes actions en cours',
                    'Mes actions achevees',
                    'Mes actions en retard',
                    'Mes alertes actives',
                    'Actions a mettre a jour',
                ]),
            ],
            'service' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'Actions du service',
                    'Actions en cours',
                    'Actions achevees',
                    'Actions en retard',
                    'Actions a valider',
                    'Actions validees service',
                    'Alertes actives',
                    'Taux execution service',
                ]),
            ],
            'direction' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'Actions direction',
                    'Actions en cours',
                    'Actions achevees',
                    'Actions en retard',
                    'Actions validees service',
                    'Actions validees',
                    'En attente validation',
                    'Alertes critiques',
                    'Taux execution direction',
                    'Respect des delais',
                    'Score global direction',
                ]),
            ],
            'planification' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'PAS actifs',
                    'PAO actifs',
                    'PTA actifs',
                    'Actions validees',
                    'Actions en retard',
                    'Indicateur global',
                    'Alertes critiques',
                    'Directions en difficulte',
                ]),
            ],
            'dg' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'Directions actives',
                    'Services actifs',
                    'Actions totales',
                    'Actions validees',
                    'Taux validation',
                    'Execution globale',
                    'Score global',
                    'Alertes critiques',
                    'Directions en difficulte',
                ]),
            ],
            'cabinet' => [
                'overview_enabled' => true,
                'comparison_chart_enabled' => true,
                'status_chart_enabled' => true,
                'trend_chart_enabled' => true,
                'support_chart_enabled' => true,
                'cards' => $this->cards([
                    'Actions sensibles',
                    'Alertes critiques',
                    'Actions en retard',
                    'Actions validees',
                    'Validations en attente',
                    'Directions en difficulte',
                ]),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $labels): array
    {
        return collect($labels)
            ->values()
            ->map(fn (string $label, int $index): array => [
                'code' => str($label)->slug('_')->toString(),
                'label' => $label,
                'enabled' => true,
                'order' => ($index + 1) * 10,
                'size' => 'md',
                'tone' => 'auto',
                'target_route' => '',
                'target_filters' => '',
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $defaultCards
     * @param  array<int, array<string, mixed>>  $storedCards
     * @return array<int, array<string, mixed>>
     */
    private function mergeCards(array $defaultCards, array $storedCards): array
    {
        $storedByCode = collect($storedCards)
            ->filter(fn ($card): bool => is_array($card) && isset($card['code']))
            ->keyBy(fn (array $card): string => (string) $card['code']);

        return collect($defaultCards)
            ->map(function (array $card) use ($storedByCode): array {
                $code = (string) $card['code'];
                $stored = $storedByCode->get($code, []);

                return [
                    'code' => $code,
                    'label' => (string) ($card['label'] ?? $stored['label'] ?? $code),
                    'enabled' => (bool) ($stored['enabled'] ?? $card['enabled'] ?? true),
                    'order' => (int) ($stored['order'] ?? $card['order'] ?? 999),
                    'size' => $this->normalizeCardSize($stored['size'] ?? $card['size'] ?? 'md'),
                    'tone' => $this->normalizeCardTone($stored['tone'] ?? $card['tone'] ?? 'auto'),
                    'target_route' => $this->normalizeTargetRoute($stored['target_route'] ?? $card['target_route'] ?? ''),
                    'target_filters' => trim((string) ($stored['target_filters'] ?? $card['target_filters'] ?? '')),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyCardConfiguration(array $card, array $config): array
    {
        $size = $this->normalizeCardSize($config['size'] ?? 'md');
        $tone = $this->normalizeCardTone($config['tone'] ?? 'auto');
        $targetRoute = $this->normalizeTargetRoute($config['target_route'] ?? '');
        $targetFilters = trim((string) ($config['target_filters'] ?? ''));

        $card['dashboard_size'] = $size;
        if ($tone !== 'auto') {
            $card['tone'] = $tone;
        }

        if ($targetRoute !== '') {
            $card['href'] = $this->resolveCardRoute($targetRoute, $targetFilters);
        }

        return $card;
    }

    private function normalizeCardSize(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array($value, ['sm', 'md', 'lg'], true) ? $value : 'md';
    }

    private function normalizeCardTone(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array($value, ['auto', 'neutral', 'info', 'success', 'warning', 'danger'], true)
            ? $value
            : 'auto';
    }

    private function normalizeTargetRoute(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array($value, ['dashboard', 'actions', 'alertes', 'reporting', 'pilotage'], true)
            ? $value
            : '';
    }

    private function resolveCardRoute(string $targetRoute, string $targetFilters): string
    {
        parse_str(ltrim($targetFilters, '?'), $filters);
        $filters = array_filter(
            is_array($filters) ? $filters : [],
            static fn ($value): bool => $value !== null && $value !== ''
        );

        return match ($targetRoute) {
            'dashboard' => route('dashboard', $filters),
            'actions' => route('workspace.actions.index', $filters),
            'alertes' => route('workspace.alertes', $filters),
            'reporting' => route('workspace.reporting', $filters),
            'pilotage' => route('workspace.pilotage', $filters),
            default => route('workspace.actions.index', $filters),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalizeChartFlags(array $settings): array
    {
        $settings['overview_enabled'] = (bool) ($settings['overview_enabled'] ?? true);
        $settings['comparison_chart_enabled'] = (bool) ($settings['comparison_chart_enabled'] ?? true);
        $settings['status_chart_enabled'] = (bool) ($settings['status_chart_enabled'] ?? true);
        $settings['trend_chart_enabled'] = (bool) ($settings['trend_chart_enabled'] ?? true);
        $settings['support_chart_enabled'] = (bool) ($settings['support_chart_enabled'] ?? true);

        $hasAnyChart = $settings['comparison_chart_enabled']
            || $settings['status_chart_enabled']
            || $settings['trend_chart_enabled']
            || $settings['support_chart_enabled'];

        if (! $hasAnyChart) {
            $settings['status_chart_enabled'] = true;
            $settings['trend_chart_enabled'] = true;
        }

        return $settings;
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



