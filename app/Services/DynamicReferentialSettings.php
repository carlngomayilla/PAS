<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DynamicReferentialSettings
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
                ->where('group', 'dynamic_referentials')
                ->pluck('value', 'key')
                ->all();

            foreach (array_keys($settings) as $key) {
                if (! array_key_exists($key, $stored)) {
                    continue;
                }

                $decoded = json_decode((string) $stored[$key], true);
                if ($decoded !== null) {
                    $settings[$key] = $decoded;
                }
            }
        }

        $settings['action_target_type_labels'] = $this->sanitizeTargetTypeLabels(
            is_array($settings['action_target_type_labels'] ?? null)
                ? $settings['action_target_type_labels']
                : []
        );
        $settings['action_unit_suggestions'] = $this->sanitizeStringList(
            $settings['action_unit_suggestions'] ?? [],
            24
        );
        $settings['pao_operational_priorities'] = $this->sanitizeStringList(
            $settings['pao_operational_priorities'] ?? [],
            12
        );
        $settings['kpi_unit_suggestions'] = $this->sanitizeStringList(
            $settings['kpi_unit_suggestions'] ?? [],
            24
        );
        $settings['justificatif_category_labels'] = $this->sanitizeKeyValueLabels(
            is_array($settings['justificatif_category_labels'] ?? null) ? $settings['justificatif_category_labels'] : [],
            [
                'hebdomadaire' => 'Justificatif hebdomadaire',
                'final' => 'Justificatif final',
                'evaluation_chef' => 'Evaluation chef',
                'evaluation_direction' => 'Evaluation direction',
                'financement' => 'Piece financement',
            ]
        );
        $settings['alert_level_labels'] = $this->sanitizeKeyValueLabels(
            is_array($settings['alert_level_labels'] ?? null) ? $settings['alert_level_labels'] : [],
            [
                'warning' => 'Attention',
                'critical' => 'Critique',
                'urgence' => 'Urgence',
                'info' => 'Info',
            ]
        );
        $settings['validation_status_labels'] = $this->sanitizeKeyValueLabels(
            is_array($settings['validation_status_labels'] ?? null) ? $settings['validation_status_labels'] : [],
            [
                'non_soumise' => 'Non soumise',
                'soumise_chef' => 'Soumise au chef',
                'rejetee_chef' => 'Rejetee par le chef',
                'validee_chef' => 'Validee chef',
                'rejetee_direction' => 'Rejetee direction',
                'validee_direction' => 'Validee direction',
            ]
        );

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'action_target_type_labels' => [
                'quantitative' => 'Quantitative',
                'qualitative' => 'Qualitative',
            ],
            'action_unit_suggestions' => [
                'dossiers',
                'formations',
                'missions',
                'ateliers',
                'rapports',
                'beneficiaires',
            ],
            'pao_operational_priorities' => [
                'basse',
                'moyenne',
                'haute',
                'critique',
            ],
            'kpi_unit_suggestions' => [
                '%',
                'points',
                'jours',
                'dossiers',
                'rapports',
                'beneficiaires',
            ],
            'justificatif_category_labels' => [
                'hebdomadaire' => 'Justificatif hebdomadaire',
                'final' => 'Justificatif final',
                'evaluation_chef' => 'Evaluation chef',
                'evaluation_direction' => 'Evaluation direction',
                'financement' => 'Piece financement',
            ],
            'alert_level_labels' => [
                'warning' => 'Attention',
                'critical' => 'Critique',
                'urgence' => 'Urgence',
                'info' => 'Info',
            ],
            'validation_status_labels' => [
                'non_soumise' => 'Non soumise',
                'soumise_chef' => 'Soumise au chef',
                'rejetee_chef' => 'Rejetee par le chef',
                'validee_chef' => 'Validee chef',
                'rejetee_direction' => 'Rejetee direction',
                'validee_direction' => 'Validee direction',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function actionTargetTypeLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = $this->all()['action_target_type_labels'];

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function actionTargetTypeCodes(): array
    {
        return array_keys($this->actionTargetTypeLabels());
    }

    /**
     * @return list<string>
     */
    public function actionUnitSuggestions(): array
    {
        /** @var list<string> $units */
        $units = $this->all()['action_unit_suggestions'];

        return $units;
    }

    /**
     * @return list<string>
     */
    public function paoOperationalPriorities(): array
    {
        /** @var list<string> $priorities */
        $priorities = $this->all()['pao_operational_priorities'];

        return $priorities;
    }

    /**
     * @return list<string>
     */
    public function kpiUnitSuggestions(): array
    {
        /** @var list<string> $units */
        $units = $this->all()['kpi_unit_suggestions'];

        return $units;
    }

    /**
     * @return array<string, string>
     */
    public function justificatifCategoryLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = $this->all()['justificatif_category_labels'];

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function alertLevelLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = $this->all()['alert_level_labels'];

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function validationStatusLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = $this->all()['validation_status_labels'];

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $settings = $this->normalizePayload($payload);

        foreach ($settings as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'dynamic_referentials', 'key' => $key],
                [
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_by' => $actor?->id,
                ]
            );
        }

        $this->flush();

        return $this->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $labels = $this->sanitizeTargetTypeLabels([
            'quantitative' => Arr::get($payload, 'action_target_type_label_quantitative'),
            'qualitative' => Arr::get($payload, 'action_target_type_label_qualitative'),
        ]);

        $units = $this->sanitizeStringList(
            $this->lines((string) Arr::get($payload, 'action_unit_suggestions')),
            24
        );
        $priorities = $this->sanitizeStringList(
            $this->lines((string) Arr::get($payload, 'pao_operational_priorities')),
            12
        );
        $kpiUnits = $this->sanitizeStringList(
            $this->lines((string) Arr::get($payload, 'kpi_unit_suggestions')),
            24
        );

        if ($units === []) {
            throw ValidationException::withMessages([
                'action_unit_suggestions' => 'Renseignez au moins une suggestion d unite pour les actions quantitatives.',
            ]);
        }

        if ($priorities === []) {
            throw ValidationException::withMessages([
                'pao_operational_priorities' => 'Renseignez au moins une priorite pour les objectifs operationnels.',
            ]);
        }

        if ($kpiUnits === []) {
            throw ValidationException::withMessages([
                'kpi_unit_suggestions' => 'Renseignez au moins une suggestion d unite pour les KPI.',
            ]);
        }

        return [
            'action_target_type_labels' => $labels,
            'action_unit_suggestions' => $units,
            'pao_operational_priorities' => $priorities,
            'kpi_unit_suggestions' => $kpiUnits,
            'justificatif_category_labels' => $this->sanitizeKeyValueLabels([
                'hebdomadaire' => Arr::get($payload, 'justificatif_category_label_hebdomadaire'),
                'final' => Arr::get($payload, 'justificatif_category_label_final'),
                'evaluation_chef' => Arr::get($payload, 'justificatif_category_label_evaluation_chef'),
                'evaluation_direction' => Arr::get($payload, 'justificatif_category_label_evaluation_direction'),
                'financement' => Arr::get($payload, 'justificatif_category_label_financement'),
            ], $this->defaults()['justificatif_category_labels']),
            'alert_level_labels' => $this->sanitizeKeyValueLabels([
                'warning' => Arr::get($payload, 'alert_level_label_warning'),
                'critical' => Arr::get($payload, 'alert_level_label_critical'),
                'urgence' => Arr::get($payload, 'alert_level_label_urgence'),
                'info' => Arr::get($payload, 'alert_level_label_info'),
            ], $this->defaults()['alert_level_labels']),
            'validation_status_labels' => $this->sanitizeKeyValueLabels([
                'non_soumise' => Arr::get($payload, 'validation_status_label_non_soumise'),
                'soumise_chef' => Arr::get($payload, 'validation_status_label_soumise_chef'),
                'rejetee_chef' => Arr::get($payload, 'validation_status_label_rejetee_chef'),
                'validee_chef' => Arr::get($payload, 'validation_status_label_validee_chef'),
                'rejetee_direction' => Arr::get($payload, 'validation_status_label_rejetee_direction'),
                'validee_direction' => Arr::get($payload, 'validation_status_label_validee_direction'),
            ], $this->defaults()['validation_status_labels']),
        ];
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, string>
     */
    private function sanitizeTargetTypeLabels(array $labels): array
    {
        $resolved = [];

        foreach (['quantitative', 'qualitative'] as $code) {
            $label = trim((string) ($labels[$code] ?? ''));
            $resolved[$code] = $label !== ''
                ? Str::limit($label, 40, '')
                : Str::headline($code);
        }

        return $resolved;
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function sanitizeStringList(iterable $values, int $limit): array
    {
        $items = collect($values)
            ->map(function ($value): string {
                $normalized = Str::of((string) $value)
                    ->squish()
                    ->replace('|', ' ')
                    ->trim()
                    ->value();

                return Str::limit($normalized, 80, '');
            })
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();

        /** @var list<string> $items */
        return $items;
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values($lines);
    }

    /**
     * @param  array<string, mixed>  $labels
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private function sanitizeKeyValueLabels(array $labels, array $defaults): array
    {
        $resolved = [];
        foreach ($defaults as $code => $defaultLabel) {
            $label = trim((string) ($labels[$code] ?? ''));
            $resolved[$code] = Str::limit($label !== '' ? $label : $defaultLabel, 60, '');
        }

        return $resolved;
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



