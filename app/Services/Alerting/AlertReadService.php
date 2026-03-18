<?php

namespace App\Services\Alerting;

use App\Models\AlertRead;
use App\Models\User;

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
     * @param array<int, string> $fingerprints
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
     * @param array<string, mixed> $alert
     */
    public function markAlertAsRead(User $user, array $alert): void
    {
        $fingerprint = (string) ($alert['fingerprint'] ?? '');
        if ($fingerprint === '') {
            return;
        }

        $sourceType = (string) ($alert['source_type'] ?? '');
        $sourceId = isset($alert['source_id']) ? (int) $alert['source_id'] : null;

        $this->markFingerprintsAsRead($user, [$fingerprint], $sourceType !== '' ? $sourceType : null, $sourceId);
    }
}
