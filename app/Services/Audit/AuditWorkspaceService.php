<?php

namespace App\Services\Audit;

use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditWorkspaceService
{
    /**
     * @var list<string>
     */
    private const INTERVENTION_MODULES = [
        'planning_unlock',
    ];

    /**
     * @var list<string>
     */
    private const INTERVENTION_ACTIONS = [
        'submit_validation_chef',
        'submit_sub_action_validation_chef',
        'review_action_validate',
        'review_action_reject',
        'review_sub_action_validate',
        'review_sub_action_reject',
        'review_control_validate',
        'review_control_reject',
        'submit_control',
        'controller_review',
        'director_review',
        'deadline_applied',
        'deadline_extension_director_reviewed',
        'deadline_extension_dg_approved_and_applied',
        'deadline_extension_final_decided',
        'deadline_extension_legacy_applied_by_dg',
        'submit_financing_daf',
        'review_financing_daf',
        'review_financing_dg',
        'deletion_request_create',
        'deletion_request_decision',
    ];

    /**
     * @var list<string>
     */
    private const SECRET_KEY_FRAGMENTS = [
        'password',
        'mot_de_passe',
        'token',
        'secret',
        'api_key',
        'authorization',
        'remember_token',
        'cookie',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     q:string,module:string,action:string,user_id:?int,entite_type:string,entite_id:?int,
     *     date_from:string,date_to:string,operation_scope:string,sort:string,per_page:int,page:int
     * }
     */
    public function normalizeFilters(array $input): array
    {
        $operationScope = strtolower($this->scalar($input, 'operation_scope'));
        $sort = strtolower($this->scalar($input, 'sort', 'recent'));
        $perPage = $this->positiveInteger($input['per_page'] ?? null) ?? 30;

        return [
            'q' => Str::limit($this->scalar($input, 'q'), 100, ''),
            'module' => Str::limit(strtolower($this->scalar($input, 'module')), 50, ''),
            'action' => Str::limit(strtolower($this->scalar($input, 'action')), 50, ''),
            'user_id' => $this->positiveInteger($input['user_id'] ?? null),
            'entite_type' => Str::limit($this->scalar($input, 'entite_type'), 100, ''),
            'entite_id' => $this->positiveInteger($input['entite_id'] ?? null),
            'date_from' => $this->date($input['date_from'] ?? null),
            'date_to' => $this->date($input['date_to'] ?? null),
            'operation_scope' => in_array($operationScope, ['recent', 'execution', 'reports', 'interventions', 'sensitive', 'organization'], true)
                ? $operationScope
                : '',
            'sort' => $sort === 'oldest' ? 'oldest' : 'recent',
            'per_page' => max(1, min(100, $perPage)),
            'page' => $this->positiveInteger($input['page'] ?? null) ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     logs:LengthAwarePaginator<int, array<string, mixed>>,
     *     summary:array<string, mixed>,
     *     options:array<string, mixed>
     * }
     */
    public function workspace(array $filters): array
    {
        $contextFilters = $filters;
        $contextFilters['operation_scope'] = '';
        $contextQuery = $this->filteredQuery($contextFilters);
        $filteredQuery = $this->filteredQuery($filters);
        $logs = $this->paginate($filteredQuery, $filters);
        $logs->setCollection(
            $logs->getCollection()
                ->map(fn (JournalAudit $audit): array => $this->present($audit))
        );

        return [
            'logs' => $logs,
            'summary' => $this->summary($filteredQuery, $contextQuery),
            'options' => $this->filterOptions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalAudit>
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = JournalAudit::query();

        $query->when(
            ($filters['module'] ?? '') !== '',
            fn (Builder $builder): Builder => $builder->where('module', (string) $filters['module'])
        );
        $query->when(
            ($filters['action'] ?? '') !== '',
            fn (Builder $builder): Builder => $builder->where('action', (string) $filters['action'])
        );
        $query->when(
            ($filters['user_id'] ?? null) !== null,
            fn (Builder $builder): Builder => $builder->where('user_id', (int) $filters['user_id'])
        );
        $query->when(
            ($filters['entite_type'] ?? '') !== '',
            fn (Builder $builder): Builder => $builder->where('entite_type', (string) $filters['entite_type'])
        );
        $query->when(
            ($filters['entite_id'] ?? null) !== null,
            fn (Builder $builder): Builder => $builder->where('entite_id', (int) $filters['entite_id'])
        );
        $query->when(
            ($filters['date_from'] ?? '') !== '',
            fn (Builder $builder): Builder => $builder->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay())
        );
        $query->when(
            ($filters['date_to'] ?? '') !== '',
            fn (Builder $builder): Builder => $builder->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay())
        );
        $query->when(($filters['q'] ?? '') !== '', function (Builder $builder) use ($filters): void {
            $search = '%'.trim((string) $filters['q']).'%';

            $builder->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->whereLike('module', $search)
                    ->orWhereLike('action', $search)
                    ->orWhereLike('entite_type', $search)
                    ->orWhereLike('adresse_ip', $search)
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->whereLike('name', $search)
                            ->orWhereLike('email', $search);
                    });
            });
        });

        $this->applyOperationScope($query, (string) ($filters['operation_scope'] ?? ''));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JournalAudit>
     */
    public function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        $sort = (string) ($filters['sort'] ?? 'recent');

        return $query
            ->with('user:id,name,email')
            ->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate(
                (int) ($filters['per_page'] ?? 30),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1)
            )
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(JournalAudit $audit): array
    {
        $before = $this->redactValue($audit->ancienne_valeur);
        $after = $this->redactValue($audit->nouvelle_valeur);
        $changedFields = $this->changedFields($before, $after);

        return [
            'id' => (int) $audit->id,
            'created_at' => $audit->created_at,
            'user' => $audit->user,
            'module' => (string) $audit->module,
            'module_label' => Str::headline((string) $audit->module),
            'action' => (string) $audit->action,
            'action_label' => Str::headline((string) $audit->action),
            'entite_type' => (string) $audit->entite_type,
            'entite_label' => $this->entityLabel((string) $audit->entite_type),
            'entite_id' => (int) $audit->entite_id,
            'entity_url' => $this->entityUrl($audit),
            'before' => $before,
            'after' => $after,
            'before_json' => $this->json($before),
            'after_json' => $this->json($after),
            'changed_fields' => $changedFields,
            'change_count' => count($changedFields),
            'adresse_ip' => (string) ($audit->adresse_ip ?? ''),
            'user_agent' => (string) ($audit->user_agent ?? ''),
            'category' => $this->category($audit),
            'category_label' => $this->categoryLabel($audit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiPayload(JournalAudit $audit): array
    {
        $payload = $audit->toArray();
        $payload['ancienne_valeur'] = $this->redactValue($audit->ancienne_valeur);
        $payload['nouvelle_valeur'] = $this->redactValue($audit->nouvelle_valeur);
        $payload['changed_fields'] = $this->changedFields(
            $payload['ancienne_valeur'],
            $payload['nouvelle_valeur']
        );
        $payload['entity_url'] = $this->entityUrl($audit);

        return $payload;
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $filters
     */
    public function writeCsv($stream, array $filters): void
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Le flux CSV est invalide.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'ID',
            'Date',
            'Utilisateur',
            'Email',
            'Module',
            'Action',
            'Entité',
            'ID entité',
            'Champs modifiés',
            'Ancienne valeur',
            'Nouvelle valeur',
            'Adresse IP',
        ], ';', '"', '');

        $query = $this->filteredQuery($filters)->with('user:id,name,email');
        $logs = ($filters['sort'] ?? 'recent') === 'oldest'
            ? $query->lazyById(500)
            : $query->lazyByIdDesc(500);

        foreach ($logs as $audit) {
            $item = $this->present($audit);
            $fields = [
                $item['id'],
                optional($item['created_at'])->format('d/m/Y H:i:s'),
                $item['user']?->name ?? 'Système',
                $item['user']?->email ?? '',
                $item['module'],
                $item['action'],
                $item['entite_label'],
                $item['entite_id'],
                implode(', ', $item['changed_fields']),
                $item['before_json'],
                $item['after_json'],
                $item['adresse_ip'],
            ];

            fputcsv(
                $stream,
                array_map(fn (mixed $value): string => $this->csvCell($value), $fields),
                ';',
                '"',
                ''
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Builder $filteredQuery, Builder $contextQuery): array
    {
        return [
            'total' => (clone $filteredQuery)->count(),
            'distinct_users' => (clone $filteredQuery)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id'),
            'with_changes' => (clone $filteredQuery)
                ->where(function (Builder $query): void {
                    $query->whereNotNull('ancienne_valeur')->orWhereNotNull('nouvelle_valeur');
                })
                ->count(),
            'anonymous' => (clone $filteredQuery)->whereNull('user_id')->count(),
            'modules_touched' => (clone $filteredQuery)->distinct('module')->count('module'),
            'scope_counts' => [
                'all' => (clone $contextQuery)->count(),
                'recent' => $this->countForScope($contextQuery, 'recent'),
                'execution' => $this->countForScope($contextQuery, 'execution'),
                'reports' => $this->countForScope($contextQuery, 'reports'),
                'interventions' => $this->countForScope($contextQuery, 'interventions'),
                'sensitive' => $this->countForScope($contextQuery, 'sensitive'),
                'organization' => $this->countForScope($contextQuery, 'organization'),
            ],
        ];
    }

    /**
     * @return array{
     *     modules:array<int, array{code:string,label:string}>,
     *     actions:array<int, array{code:string,label:string}>,
     *     entity_types:array<int, array{code:string,label:string}>,
     *     users:array<int, array{id:int,name:string,email:string}>
     * }
     */
    private function filterOptions(): array
    {
        $modules = JournalAudit::query()
            ->select('module')
            ->whereNotNull('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->map(fn (string $module): array => ['code' => $module, 'label' => Str::headline($module)])
            ->values()
            ->all();
        $actions = JournalAudit::query()
            ->select('action')
            ->whereNotNull('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn (string $action): array => ['code' => $action, 'label' => Str::headline($action)])
            ->values()
            ->all();
        $entityTypes = JournalAudit::query()
            ->select('entite_type')
            ->whereNotNull('entite_type')
            ->distinct()
            ->orderBy('entite_type')
            ->pluck('entite_type')
            ->map(fn (string $type): array => ['code' => $type, 'label' => $this->entityLabel($type)])
            ->values()
            ->all();
        $users = User::query()
            ->whereIn('id', JournalAudit::query()->select('user_id')->whereNotNull('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->all();

        return [
            'modules' => $modules,
            'actions' => $actions,
            'entity_types' => $entityTypes,
            'users' => $users,
        ];
    }

    private function applyOperationScope(Builder $query, string $scope): void
    {
        match ($scope) {
            'recent' => $query->where('created_at', '>=', now()->subDay()),
            'execution' => $this->applyExecutionScope($query),
            'reports' => $query->where('module', 'institutional_reports'),
            'interventions' => $this->applyInterventionScope($query),
            'sensitive' => $this->applySensitiveScope($query),
            'organization' => $query->where('action', 'like', 'organization_%'),
            default => null,
        };
    }

    private function applyExecutionScope(Builder $query): Builder
    {
        return $query->where(function (Builder $executionQuery): void {
            $executionQuery
                ->whereIn('module', [
                    'action',
                    'actions',
                    'pta',
                    'pao',
                    'pas',
                    'planning',
                    'planning_unlock',
                    'deadline_extension',
                    'reports_echeance',
                ])
                ->orWhereIn('entite_type', [
                    'action',
                    'sous_action',
                    'pta',
                    'pao',
                    'pas',
                    'deadline_extension_request',
                    'planning_unlock_request',
                ])
                ->orWhere('action', 'like', '%controle%')
                ->orWhere('action', 'like', '%review%')
                ->orWhere('action', 'like', '%validation%')
                ->orWhere('action', 'like', '%progress%')
                ->orWhere('action', 'like', '%execution%')
                ->orWhere('action', 'like', '%justificatif%');
        });
    }

    private function applyInterventionScope(Builder $query): Builder
    {
        return $query->where(function (Builder $interventionQuery): void {
            $interventionQuery
                ->whereIn('module', self::INTERVENTION_MODULES)
                ->orWhereIn('action', self::INTERVENTION_ACTIONS)
                ->orWhere('action', 'like', '%deletion_request%');
        });
    }

    private function applySensitiveScope(Builder $query): Builder
    {
        return $query->where(function (Builder $sensitiveQuery): void {
            $sensitiveQuery
                ->where('module', 'super_admin')
                ->orWhere('module', 'retention')
                ->orWhere('action', 'like', 'maintenance_%')
                ->orWhere('action', 'like', '%permission%')
                ->orWhere('action', 'like', '%workflow%');
        });
    }

    private function countForScope(Builder $query, string $scope): int
    {
        $scopedQuery = clone $query;
        $this->applyOperationScope($scopedQuery, $scope);

        return $scopedQuery->count();
    }

    private function category(JournalAudit $audit): string
    {
        if ($this->matchesIntervention($audit)) {
            return 'intervention';
        }

        if ($this->matchesSensitive($audit)) {
            return 'sensitive';
        }

        if (str_starts_with((string) $audit->action, 'organization_')) {
            return 'organization';
        }

        return $audit->user_id === null ? 'system' : 'standard';
    }

    private function categoryLabel(JournalAudit $audit): string
    {
        return match ($this->category($audit)) {
            'intervention' => 'Intervention',
            'sensitive' => 'Sensible',
            'organization' => 'Organisation',
            'system' => 'Système',
            default => 'Traçabilité',
        };
    }

    private function matchesIntervention(JournalAudit $audit): bool
    {
        return in_array((string) $audit->module, self::INTERVENTION_MODULES, true)
            || in_array((string) $audit->action, self::INTERVENTION_ACTIONS, true)
            || str_contains((string) $audit->action, 'deletion_request');
    }

    private function matchesSensitive(JournalAudit $audit): bool
    {
        $action = (string) $audit->action;

        return (string) $audit->module === 'super_admin'
            || (string) $audit->module === 'retention'
            || str_starts_with($action, 'maintenance_')
            || str_contains($action, 'permission')
            || str_contains($action, 'workflow');
    }

    private function entityLabel(string $type): string
    {
        $className = class_basename($type);

        return Str::headline($className !== '' ? $className : $type);
    }

    private function entityUrl(JournalAudit $audit): ?string
    {
        $action = strtolower((string) $audit->action);
        if (str_contains($action, 'delete') || str_contains($action, 'archive')) {
            return null;
        }

        $type = strtolower(class_basename((string) $audit->entite_type));
        $id = (int) $audit->entite_id;

        return match ($type) {
            'action' => route('workspace.actions.suivi', $id),
            'pta' => route('workspace.pta.show', $id),
            'pao' => route('workspace.pao.show', $id),
            'pas' => route('workspace.pas.show', $id),
            'deadlineextensionrequest' => route('workspace.deadline-extension.show', $id),
            'planningunlockrequest' => route('workspace.planning-unlocks.index'),
            'retentionrun' => route('workspace.retention.index').'#run-'.$id,
            default => null,
        };
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSecretKey($key)) {
            return '[MASQUÉ]';
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = $this->redactValue($childValue, (string) $childKey);
        }

        return $redacted;
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = (string) Str::of($key)->ascii()->lower();

        return Str::contains($normalized, self::SECRET_KEY_FRAGMENTS);
    }

    /**
     * @return list<string>
     */
    private function changedFields(mixed $before, mixed $after): array
    {
        if (! is_array($before) && ! is_array($after)) {
            return [];
        }

        $beforeValues = is_array($before) ? $before : [];
        $afterValues = is_array($after) ? $after : [];
        $keys = array_unique(array_merge(array_keys($beforeValues), array_keys($afterValues)));

        return collect($keys)
            ->filter(fn (string|int $key): bool => ($beforeValues[$key] ?? null) !== ($afterValues[$key] ?? null))
            ->map(static fn (string|int $key): string => Str::headline((string) $key))
            ->values()
            ->all();
    }

    private function json(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function scalar(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function date(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $date = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : '';
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) $value;

        return preg_match('/^[=+\-@]/', $cell) === 1 ? "'".$cell : $cell;
    }
}
