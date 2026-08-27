<?php

namespace Tests\Feature;

use App\Services\Exports\ReportingExportRetentionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ReportingExportRetentionTest extends TestCase
{
    public function test_command_dry_run_detects_expired_exports_without_deleting_files(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        config(['retention.reporting_exports_days' => 7]);
        Storage::fake('local');

        $paths = $this->prepareStoredExports();

        $this->artisan('anbg:prune-reporting-exports')
            ->expectsOutputToContain('2 export(s) reporting expire(s) detecte(s).')
            ->expectsOutputToContain('Simulation uniquement : aucun fichier supprime.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists(array_values($paths));
    }

    public function test_command_execute_deletes_only_expired_reporting_exports_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        config(['retention.reporting_exports_days' => 7]);
        Storage::fake('local');

        $paths = $this->prepareStoredExports();

        $this->artisan('anbg:prune-reporting-exports', ['--execute' => true])
            ->expectsOutputToContain('2 export(s) reporting expire(s) supprime(s).')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing([$paths['expired'], $paths['boundary']]);
        Storage::disk('local')->assertExists([$paths['fresh'], $paths['unrelated']]);

        $this->artisan('anbg:prune-reporting-exports', ['--execute' => true])
            ->expectsOutputToContain('0 export(s) reporting expire(s) supprime(s).')
            ->assertSuccessful();

        Storage::disk('local')->assertExists([$paths['fresh'], $paths['unrelated']]);
    }

    public function test_service_reports_deletion_failures_without_counting_them_as_deleted(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        config(['retention.reporting_exports_days' => 7]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('allFiles')
            ->once()
            ->with('exports/reporting')
            ->andReturn(['exports/reporting/10/expired.xlsx']);
        $disk->shouldReceive('lastModified')
            ->once()
            ->with('exports/reporting/10/expired.xlsx')
            ->andReturn(now()->subDays(8)->getTimestamp());
        $disk->shouldReceive('delete')
            ->once()
            ->with('exports/reporting/10/expired.xlsx')
            ->andReturnFalse();

        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        Log::shouldReceive('warning')->once();

        $result = app(ReportingExportRetentionService::class)->enforceRetention(true);

        $this->assertSame([
            'examined' => 1,
            'expired' => 1,
            'deleted' => 0,
            'failed' => 1,
        ], $result);
    }

    /**
     * @return array{expired: string, boundary: string, fresh: string, unrelated: string}
     */
    private function prepareStoredExports(): array
    {
        $paths = [
            'expired' => 'exports/reporting/10/expired.xlsx',
            'boundary' => 'exports/reporting/10/boundary.pdf',
            'fresh' => 'exports/reporting/20/fresh.xlsx',
            'unrelated' => 'exports/other/private.xlsx',
        ];

        foreach ($paths as $path) {
            Storage::disk('local')->put($path, $path);
        }

        touch(Storage::disk('local')->path($paths['expired']), now()->subDays(8)->getTimestamp());
        touch(Storage::disk('local')->path($paths['boundary']), now()->subDays(7)->getTimestamp());
        touch(Storage::disk('local')->path($paths['fresh']), now()->subDays(6)->getTimestamp());
        touch(Storage::disk('local')->path($paths['unrelated']), now()->subDays(30)->getTimestamp());

        return $paths;
    }
}
