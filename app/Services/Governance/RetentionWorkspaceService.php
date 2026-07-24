<?php

namespace App\Services\Governance;

use App\Models\DataArchive;
use App\Models\RetentionRun;
use App\Models\User;
use App\Services\PlanningAutoArchiveService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RetentionWorkspaceService
{
    /** @var list<string> */
    private const SECRET_KEY_FRAGMENTS = [
        'password',
        'mot_de_passe',
        'token',
        'secret',
        'api_key',
        'authorization',
        'cookie',
    ];

    public function __construct(
        private readonly RetentionService $retentionService,
        private readonly PlanningAutoArchiveService $planningAutoArchiveService
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     filters:array<string, mixed>,archives:LengthAwarePaginator<int, array<string, mixed>>,
     *     archiveSummary:array<string, int>,options:array<string, mixed>,runs:mixed,
     *     retentionSummary:array<string, mixed>,planningArchiveSummary:array<string, mixed>
     * }
     */
    public function workspace(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $query = $this->filteredQuery($filters);
        $archives = (clone $query)
            ->with('archivedBy:id,name,email')
            ->orderBy('id', $filters['sort'] === 'oldest' ? 'asc' : 'desc')
            ->paginate($filters['per_page'])
            ->withQueryString();
        $archives->setCollection(
            $archives->getCollection()->map(fn (DataArchive $archive): array => $this->present($archive))
        );

        return [
            'filters' => $filters,
            'archives' => $archives,
            'archiveSummary' => [
                'total' => (clone $query)->count(),
                'batches' => (clone $query)->whereNotNull('batch_key')->distinct('batch_key')->count('batch_key'),
                'sources' => (clone $query)->distinct('source_table')->count('source_table'),
                'actors' => (clone $query)->whereNotNull('archived_by')->distinct('archived_by')->count('archived_by'),
            ],
            'options' => $this->filterOptions(),
            'runs' => RetentionRun::query()
                ->with('initiatedBy:id,name,email')
                ->latest('id')
                ->limit(20)
                ->get(),
            'retentionSummary' => $this->retentionService->summary(),
            'planningArchiveSummary' => $this->planningAutoArchiveService->summary(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,source:string,batch:string,actor_id:?int,date_from:string,date_to:string,sort:string,per_page:int}
     */
    public function normalizeFilters(array $input): array
    {
        $sort = $this->scalar($input, 'sort') === 'oldest' ? 'oldest' : 'recent';
        $perPage = $this->positiveInteger($input['per_page'] ?? null) ?? 20;

        return [
            'q' => Str::limit($this->scalar($input, 'q'), 120, ''),
            'source' => Str::limit($this->scalar($input, 'source'), 80, ''),
            'batch' => Str::limit($this->scalar($input, 'batch'), 80, ''),
            'actor_id' => $this->positiveInteger($input['actor_id'] ?? null),
            'date_from' => $this->date($input['date_from'] ?? null),
            'date_to' => $this->date($input['date_to'] ?? null),
            'sort' => $sort,
            'per_page' => in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DataArchive>
     */
    public function filteredQuery(array $filters): Builder
    {
        return DataArchive::query()
            ->when(
                ($filters['source'] ?? '') !== '',
                fn (Builder $query): Builder => $query->where('source_table', (string) $filters['source'])
            )
            ->when(
                ($filters['batch'] ?? '') !== '',
                fn (Builder $query): Builder => $query->where('batch_key', (string) $filters['batch'])
            )
            ->when(
                ($filters['actor_id'] ?? null) !== null,
                fn (Builder $query): Builder => $query->where('archived_by', (int) $filters['actor_id'])
            )
            ->when(
                ($filters['date_from'] ?? '') !== '',
                fn (Builder $query): Builder => $query->where('archived_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay())
            )
            ->when(
                ($filters['date_to'] ?? '') !== '',
                fn (Builder $query): Builder => $query->where('archived_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay())
            )
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $search = '%'.trim((string) $filters['q']).'%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->whereLike('scope_label', $search)
                        ->orWhereLike('source_table', $search)
                        ->orWhereLike('entity_type', $search)
                        ->orWhereLike('batch_key', $search)
                        ->orWhereHas('archivedBy', fn (Builder $actorQuery): Builder => $actorQuery->whereLike('name', $search)->orWhereLike('email', $search));
                });
            });
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
            'Date d’archivage',
            'Source',
            'Type d’entité',
            'ID entité',
            'Périmètre',
            'Lot',
            'Opérateur',
            'Email opérateur',
        ], ';', '"', '');

        $query = $this->filteredQuery($filters)->with('archivedBy:id,name,email');
        $rows = $filters['sort'] === 'oldest' ? $query->lazyById(500) : $query->lazyByIdDesc(500);

        foreach ($rows as $archive) {
            $cells = [
                $archive->id,
                optional($archive->archived_at)->format('d/m/Y H:i:s'),
                $archive->source_table,
                $archive->entity_type,
                $archive->entity_id,
                $archive->scope_label,
                $archive->batch_key,
                $archive->archivedBy?->name ?? 'Système',
                $archive->archivedBy?->email ?? '',
            ];

            fputcsv(
                $stream,
                array_map(fn (mixed $value): string => $this->csvCell($value), $cells),
                ';',
                '"',
                ''
            );
        }
    }

    public function archiveJson(DataArchive $archive): string
    {
        $archive->loadMissing('archivedBy:id,name,email');

        return (string) json_encode([
            'archive' => [
                'id' => (int) $archive->id,
                'archived_at' => optional($archive->archived_at)->toIso8601String(),
                'source_table' => (string) $archive->source_table,
                'entity_type' => (string) $archive->entity_type,
                'entity_id' => $archive->entity_id !== null ? (int) $archive->entity_id : null,
                'scope_label' => $archive->scope_label,
                'batch_key' => $archive->batch_key,
                'archived_by' => $archive->archivedBy === null ? null : [
                    'id' => (int) $archive->archivedBy->id,
                    'name' => (string) $archive->archivedBy->name,
                    'email' => (string) $archive->archivedBy->email,
                ],
            ],
            'payload' => $this->redactValue($archive->payload),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function present(DataArchive $archive): array
    {
        $payload = $this->redactValue($archive->payload);

        return [
            'id' => (int) $archive->id,
            'archived_at' => $archive->archived_at,
            'source_table' => (string) $archive->source_table,
            'entity_type' => (string) $archive->entity_type,
            'entity_id' => $archive->entity_id !== null ? (int) $archive->entity_id : null,
            'scope_label' => (string) ($archive->scope_label ?? ''),
            'batch_key' => (string) ($archive->batch_key ?? ''),
            'actor' => $archive->archivedBy,
            'payload_json' => $payload === null ? '' : (string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'sources' => DataArchive::query()->whereNotNull('source_table')->distinct()->orderBy('source_table')->pluck('source_table'),
            'batches' => DataArchive::query()->whereNotNull('batch_key')->latest('id')->limit(100)->pluck('batch_key')->unique()->values(),
            'actors' => User::query()
                ->whereIn('id', DataArchive::query()->select('archived_by')->whereNotNull('archived_by'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ];
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && Str::contains((string) Str::of($key)->ascii()->lower(), self::SECRET_KEY_FRAGMENTS)) {
            return '[MASQUÉ]';
        }

        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $this->redactValue($decoded, $key) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->map(fn (mixed $child, string|int $childKey): mixed => $this->redactValue($child, (string) $childKey))
            ->all();
    }

    /** @param array<string, mixed> $input */
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
