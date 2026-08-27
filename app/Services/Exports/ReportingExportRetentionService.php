<?php

namespace App\Services\Exports;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReportingExportRetentionService
{
    /**
     * @return array{examined: int, expired: int, deleted: int, failed: int}
     */
    public function enforceRetention(bool $execute = false, ?CarbonInterface $referenceTime = null): array
    {
        $disk = Storage::disk('local');
        $cutoffTimestamp = ($referenceTime ?? now())
            ->copy()
            ->subDays($this->retentionDays())
            ->getTimestamp();
        $result = [
            'examined' => 0,
            'expired' => 0,
            'deleted' => 0,
            'failed' => 0,
        ];

        foreach ($disk->allFiles('exports/reporting') as $path) {
            $result['examined']++;

            try {
                if ($disk->lastModified($path) > $cutoffTimestamp) {
                    continue;
                }

                $result['expired']++;

                if (! $execute) {
                    continue;
                }

                if ($disk->delete($path)) {
                    $result['deleted']++;

                    continue;
                }

                $result['failed']++;
                Log::warning('Expired reporting export could not be deleted.', [
                    'path' => $path,
                    'reason' => 'delete_returned_false',
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                Log::warning('Reporting export retention inspection failed.', [
                    'path' => $path,
                    'exception_type' => $exception::class,
                ]);
            }
        }

        return $result;
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('retention.reporting_exports_days', 7));
    }
}
