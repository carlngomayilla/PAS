<?php

namespace App\Services\Alerting;

use App\Models\AlertRead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AlertReadService
{
    /**
     * @return array<int, string>
     */
    public function readFingerprintsForUser(User $user): array
    {
        return AlertRead::query()
            ->where('user_id', $user->id)
            ->pluck('fingerprint')
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $fingerprints
     */
    public function markFingerprintsAsRead(User $user, array $fingerprints, ?string $sourceType = null, ?int $sourceId = null): void
    {
        $items = collect($fingerprints)
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        foreach ($items as $fingerprint) {
            $payload = [
                'read_at' => now(),
            ];

            if ($sourceType !== null) {
                $payload['source_type'] = $sourceType;
            }

            if ($sourceId !== null) {
                $payload['source_id'] = $sourceId;
            }

            AlertRead::query()->updateOrCreate(
                [
                    'user_id' => (int) $user->id,
                    'fingerprint' => (string) $fingerprint,
                ],
                $payload
            );
        }
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $alerts
     */
    public function markAlertsAsRead(User $user, iterable $alerts): int
    {
        $timestamp = now();
        $rows = collect($alerts)
            ->filter(static fn ($alert): bool => is_array($alert) && trim((string) ($alert['fingerprint'] ?? '')) !== '')
            ->unique(static fn (array $alert): string => (string) $alert['fingerprint'])
            ->map(function (array $alert) use ($user, $timestamp): array {
                $sourceType = trim((string) ($alert['source_type'] ?? ''));
                $snapshot = $this->snapshotPayload($alert);
                $snapshot['metadata'] = json_encode($snapshot['metadata'], JSON_THROW_ON_ERROR);

                return array_merge($snapshot, [
                    'user_id' => (int) $user->id,
                    'fingerprint' => (string) $alert['fingerprint'],
                    'source_type' => $sourceType !== '' ? $sourceType : null,
                    'source_id' => isset($alert['source_id']) ? (int) $alert['source_id'] : null,
                    'read_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            })
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($rows): void {
            $rows->chunk(500)->each(function (Collection $chunk): void {
                AlertRead::query()->upsert(
                    $chunk->all(),
                    ['user_id', 'fingerprint'],
                    ['source_type', 'source_id', 'read_at', 'niveau', 'titre', 'message', 'target_url', 'metadata', 'updated_at']
                );
            });
        });

        return $rows->count();
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public function markAlertAsRead(User $user, array $alert): void
    {
        $fingerprint = (string) ($alert['fingerprint'] ?? '');
        if ($fingerprint === '') {
            return;
        }

        $sourceType = (string) ($alert['source_type'] ?? '');
        $sourceId = isset($alert['source_id']) ? (int) $alert['source_id'] : null;

        AlertRead::query()->updateOrCreate(
            [
                'user_id' => (int) $user->id,
                'fingerprint' => $fingerprint,
            ],
            array_merge($this->snapshotPayload($alert), [
                'source_type' => $sourceType !== '' ? $sourceType : null,
                'source_id' => $sourceId,
                'read_at' => now(),
            ])
        );
    }

    /**
     * @param  array{niveau?:?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function historyForUser(User $user, array $filters = [], ?int $limit = 50): Collection
    {
        $limit = $limit !== null ? max(1, min(100, $limit)) : null;
        $level = strtolower(trim((string) ($filters['niveau'] ?? '')));

        return AlertRead::query()
            ->where('user_id', $user->id)
            ->whereNotNull('read_at')
            ->when(
                in_array($level, ['urgence', 'critical', 'warning', 'conforme', 'info'], true),
                fn ($query) => $query->where('niveau', $level)
            )
            ->latest('read_at')
            ->latest('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (AlertRead $read): array => $this->historyItem($read))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function snapshotPayload(array $alert): array
    {
        $metadata = array_filter([
            'niveau_label' => $alert['niveau_label'] ?? null,
            'type_label' => $alert['type_label'] ?? null,
            'type' => $alert['type'] ?? null,
            'module' => $alert['module'] ?? null,
            'date_label' => $alert['date_label'] ?? null,
            'direction' => $alert['direction'] ?? null,
            'service' => $alert['service'] ?? null,
            'section_label' => $alert['section_label'] ?? null,
            'action' => $alert['action'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return [
            'niveau' => $this->normalizedLevel((string) ($alert['niveau'] ?? 'info')),
            'titre' => Str::limit((string) ($alert['titre'] ?? 'Alerte'), 250, ''),
            'message' => (string) ($alert['message'] ?? ''),
            'target_url' => (string) ($alert['target_url'] ?? route('workspace.notifications.index', ['tab' => 'alertes'])),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyItem(AlertRead $read): array
    {
        $metadata = is_array($read->metadata) ? $read->metadata : [];
        $level = $this->normalizedLevel((string) ($read->niveau ?: 'info'));
        $readAt = $read->read_at;

        return [
            'fingerprint' => (string) $read->fingerprint,
            'source_type' => (string) ($read->source_type ?? ''),
            'source_id' => (int) ($read->source_id ?? 0),
            'niveau' => $level,
            'niveau_label' => (string) ($metadata['niveau_label'] ?? $this->levelLabel($level)),
            'type_label' => (string) ($metadata['type_label'] ?? $this->sourceTypeLabel((string) ($read->source_type ?? ''))),
            'titre' => (string) ($read->titre ?: 'Alerte lue'),
            'message' => (string) ($read->message ?: 'Cette alerte a ete marquee comme lue.'),
            'target_url' => (string) ($read->target_url ?: route('workspace.notifications.index', ['tab' => 'alertes', 'vue' => 'historique'])),
            'direction' => (string) ($metadata['direction'] ?? '-'),
            'service' => (string) ($metadata['service'] ?? '-'),
            'date_label' => (string) ($metadata['date_label'] ?? ''),
            'read_at_label' => $readAt !== null ? $readAt->format('d/m/Y H:i') : '',
            'section_label' => (string) ($metadata['section_label'] ?? ''),
        ];
    }

    private function normalizedLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return in_array($level, ['urgence', 'critical', 'warning', 'conforme', 'info'], true) ? $level : 'info';
    }

    private function levelLabel(string $level): string
    {
        return match ($this->normalizedLevel($level)) {
            'urgence' => 'Urgence',
            'critical' => 'Critique',
            'warning' => 'Vigilance',
            'conforme' => 'Conforme',
            default => 'Information',
        };
    }

    private function sourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'action_overdue' => 'Retard action',
            'action_pending_setup' => 'Parametrage PTA',
            'kpi_breach' => 'Indicateur',
            'action_log' => 'Journal action',
            'missing_pao_coverage' => 'Couverture PAO',
            'delegation_expiring' => 'Delegation',
            default => 'Alerte',
        };
    }
}
