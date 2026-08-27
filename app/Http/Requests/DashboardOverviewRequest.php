<?php

namespace App\Http\Requests;

use App\Models\Service;
use App\Models\User;
use App\Services\Dashboard\DashboardFilterContext;
use App\Services\Dashboard\DashboardFilterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardOverviewRequest extends FormRequest
{
    /** @var list<string> */
    private const FILTER_KEYS = [
        'exercice',
        'periode',
        'direction_id',
        'service_id',
        'responsable_id',
        'statut_action',
        'statut_suivi',
        'statut_delai',
        'alerte_echeance',
    ];

    /** @var list<string> */
    private array $normalizedAllFilters = [];

    /** @var list<string> */
    private const PERIODS = [
        'q1', 'q2', 'q3', 'q4',
        's1', 's2',
        'm1', 'm2', 'm3', 'm4', 'm5', 'm6',
        'm7', 'm8', 'm9', 'm10', 'm11', 'm12',
    ];

    public function authorize(): bool
    {
        return $this->user() instanceof User && Gate::allows('dashboard.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'exercice' => ['nullable', 'integer', 'digits:4', 'between:2000,2100'],
            'periode' => ['nullable', 'string', Rule::in(self::PERIODS)],
            'direction_id' => [
                'nullable',
                'integer',
                Rule::exists('directions', 'id')->where('actif', true),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('actif', true),
            ],
            'responsable_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query): void {
                    $query->where('is_active', true)
                        ->where(function ($activeQuery): void {
                            $activeQuery->whereNull('suspended_until')
                                ->orWhere('suspended_until', '<=', now());
                        });
                }),
            ],
            'statut_action' => ['nullable', 'string', Rule::in(array_keys(DashboardFilterContext::ACTION_STATUS_OPTIONS))],
            'statut_suivi' => ['nullable', 'string', Rule::in(array_keys(DashboardFilterContext::TRACKING_STATUS_OPTIONS))],
            'statut_delai' => ['nullable', 'string', Rule::in(array_keys(DashboardFilterContext::DELAY_STATUS_OPTIONS))],
            'alerte_echeance' => ['nullable', 'string', Rule::in(array_keys(DashboardFilterContext::DEADLINE_ALERT_OPTIONS))],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query->all()), self::FILTER_KEYS) as $unknownKey) {
                $validator->errors()->add(
                    (string) $unknownKey,
                    'Ce paramètre de filtre n’est pas pris en charge.',
                );
            }

            if ($validator->errors()->hasAny(['direction_id', 'service_id'])) {
                return;
            }

            $directionId = $this->nullableInteger('direction_id');
            $serviceId = $this->nullableInteger('service_id');
            if ($serviceId !== null && $directionId === null) {
                $validator->errors()->add('direction_id', 'La direction est obligatoire avec un service.');

                return;
            }

            if ($serviceId !== null) {
                $serviceDirectionId = Service::query()->whereKey($serviceId)->value('direction_id');
                if ($serviceDirectionId === null || (int) $serviceDirectionId !== $directionId) {
                    $validator->errors()->add('service_id', 'Le service ne dépend pas de la direction sélectionnée.');
                }
            }

        }];
    }

    public function filters(): DashboardFilterData
    {
        return DashboardFilterData::fromValidated($this->validated());
    }

    public function restoreAllSentinelsForDashboardContext(): void
    {
        foreach ($this->normalizedAllFilters as $key) {
            $this->query->set($key, 'all');
        }
    }

    protected function prepareForValidation(): void
    {
        $queryInput = $this->query->all();

        foreach (self::FILTER_KEYS as $key) {
            if (! $this->query->has($key)) {
                continue;
            }

            $value = $queryInput[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized === '' || Str::lower($normalized) === 'all') {
                $this->normalizedAllFilters[] = $key;
                $this->query->set($key, null);

                continue;
            }

            $this->query->set($key, in_array($key, [
                'periode',
                'statut_action',
                'statut_suivi',
                'statut_delai',
                'alerte_echeance',
            ], true) ? Str::lower($normalized) : $normalized);
        }
    }

    private function nullableInteger(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }
}
