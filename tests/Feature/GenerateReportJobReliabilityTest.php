<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\User;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Exports\ExportTemplateResolver;
use App\Services\Exports\ReportingWorkbookExporter;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenerateReportJobReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retry_reuses_the_same_file_and_does_not_duplicate_the_notification(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $job = new GenerateReportJob((int) $user->id, 'excel');
        $passwordPolicy = app(PasswordPolicyService::class);

        $analytics = Mockery::mock(ReportingAnalyticsService::class);
        $analytics->shouldReceive('buildPayload')
            ->twice()
            ->with(Mockery::on(fn (User $candidate): bool => $candidate->is($user)), true, true)
            ->andReturn(['generatedAt' => now()]);

        $templates = Mockery::mock(ExportTemplateResolver::class);
        $templates->shouldReceive('resolve')->twice()->andReturnNull();

        $sourcePaths = [];
        $workbook = Mockery::mock(ReportingWorkbookExporter::class);
        $workbook->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function () use (&$sourcePaths): string {
                $path = tempnam(sys_get_temp_dir(), 'pas-report-');
                if ($path === false || file_put_contents($path, 'workbook') === false) {
                    throw new RuntimeException('Unable to prepare the temporary workbook fixture.');
                }

                $sourcePaths[] = $path;

                return $path;
            });

        $job->handle($analytics, $templates, $workbook, $passwordPolicy);
        $job->handle($analytics, $templates, $workbook, $passwordPolicy);

        $files = Storage::disk('local')->allFiles('exports/reporting/'.$user->id);

        $this->assertCount(1, $files);
        $this->assertSame(
            'exports/reporting/'.$user->id.'/'.$job->exportId.'.xlsx',
            $files[0]
        );
        $this->assertCount(1, $user->refresh()->notifications);
        $this->assertSame(
            $job->exportId,
            data_get($user->notifications->first()?->data, 'meta.export_id')
        );
        foreach ($sourcePaths as $sourcePath) {
            $this->assertFileDoesNotExist($sourcePath);
        }
    }

    public function test_a_failed_storage_write_throws_for_retry_without_a_premature_success_notification(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $job = new GenerateReportJob((int) $user->id, 'excel');
        $passwordPolicy = app(PasswordPolicyService::class);

        $analytics = Mockery::mock(ReportingAnalyticsService::class);
        $analytics->shouldReceive('buildPayload')
            ->twice()
            ->with(Mockery::on(fn (User $candidate): bool => $candidate->is($user)), true, true)
            ->andReturn(['generatedAt' => now()]);

        $templates = Mockery::mock(ExportTemplateResolver::class);
        $templates->shouldReceive('resolve')->twice()->andReturnNull();

        $workbook = Mockery::mock(ReportingWorkbookExporter::class);
        $workbook->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function (): string {
                $path = tempnam(sys_get_temp_dir(), 'pas-report-');
                if ($path === false || file_put_contents($path, 'workbook') === false) {
                    throw new RuntimeException('Unable to prepare the temporary workbook fixture.');
                }

                return $path;
            });

        $expectedPath = 'exports/reporting/'.$user->id.'/'.$job->exportId.'.xlsx';
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')
            ->twice()
            ->with($expectedPath, 'workbook')
            ->andReturn(false, true);
        Storage::shouldReceive('disk')
            ->twice()
            ->with('local')
            ->andReturn($disk);

        $storageException = null;

        try {
            $job->handle($analytics, $templates, $workbook, $passwordPolicy);
        } catch (RuntimeException $exception) {
            $storageException = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $storageException);
        $this->assertSame('Reporting export could not be stored.', $storageException->getMessage());
        $this->assertSame(2, $job->tries);
        $this->assertCount(0, $user->refresh()->notifications);

        $job->handle($analytics, $templates, $workbook, $passwordPolicy);

        $notifications = $user->refresh()->notifications;

        $this->assertCount(1, $notifications);
        $this->assertSame('reporting_export_ready', data_get($notifications->first()?->data, 'meta.event'));
        $this->assertSame($job->exportId, data_get($notifications->first()?->data, 'meta.export_id'));
    }

    public function test_a_missing_workbook_source_throws_without_writing_or_notifying_success(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $job = new GenerateReportJob((int) $user->id, 'excel');
        $passwordPolicy = app(PasswordPolicyService::class);
        $missingPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pas-report-missing-'.$job->exportId.'.xlsx';

        $analytics = Mockery::mock(ReportingAnalyticsService::class);
        $analytics->shouldReceive('buildPayload')->once()->andReturn(['generatedAt' => now()]);

        $templates = Mockery::mock(ExportTemplateResolver::class);
        $templates->shouldReceive('resolve')->once()->andReturnNull();

        $workbook = Mockery::mock(ReportingWorkbookExporter::class);
        $workbook->shouldReceive('create')->once()->andReturn($missingPath);

        Storage::shouldReceive('disk')->never();

        $readException = null;

        try {
            $job->handle($analytics, $templates, $workbook, $passwordPolicy);
        } catch (RuntimeException $exception) {
            $readException = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $readException);
        $this->assertSame('Temporary reporting workbook could not be read.', $readException->getMessage());
        $this->assertFileDoesNotExist($missingPath);
        $this->assertCount(0, $user->refresh()->notifications);
    }

    public function test_an_unreadable_workbook_source_is_not_deleted_or_reported_as_ready(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $job = new GenerateReportJob((int) $user->id, 'excel');
        $passwordPolicy = app(PasswordPolicyService::class);
        $sourceDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pas-report-source-'.$job->exportId;

        $this->assertTrue(mkdir($sourceDirectory));

        try {
            $analytics = Mockery::mock(ReportingAnalyticsService::class);
            $analytics->shouldReceive('buildPayload')->once()->andReturn(['generatedAt' => now()]);

            $templates = Mockery::mock(ExportTemplateResolver::class);
            $templates->shouldReceive('resolve')->once()->andReturnNull();

            $workbook = Mockery::mock(ReportingWorkbookExporter::class);
            $workbook->shouldReceive('create')->once()->andReturn($sourceDirectory);

            Storage::shouldReceive('disk')->never();

            $readException = null;

            try {
                $job->handle($analytics, $templates, $workbook, $passwordPolicy);
            } catch (RuntimeException $exception) {
                $readException = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $readException);
            $this->assertSame('Temporary reporting workbook could not be read.', $readException->getMessage());
            $this->assertDirectoryExists($sourceDirectory);
            $this->assertCount(0, $user->refresh()->notifications);
        } finally {
            rmdir($sourceDirectory);
        }
    }

    public function test_failed_notification_is_idempotent_and_does_not_expose_the_exception_message(): void
    {
        $user = User::factory()->create();
        $job = new GenerateReportJob((int) $user->id, 'pdf');
        $exception = new RuntimeException('storage-password=super-secret');

        $job->failed($exception);
        $job->failed($exception);

        $notifications = $user->refresh()->notifications;
        $payload = $notifications->first()?->data ?? [];

        $this->assertCount(1, $notifications);
        $this->assertSame('reporting_export_failed', data_get($payload, 'meta.event'));
        $this->assertSame($job->exportId, data_get($payload, 'meta.export_id'));
        $this->assertStringNotContainsString('super-secret', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_job_refuses_an_export_when_the_password_expires_after_dispatch(): void
    {
        config(['security.passwords.expire_days' => 90]);
        Storage::fake('local');
        Log::spy();

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $job = new GenerateReportJob((int) $user->id, 'excel');

        $user->forceFill([
            'password_changed_at' => now()->subDays(91),
        ])->save();

        $analytics = Mockery::mock(ReportingAnalyticsService::class);
        $analytics->shouldNotReceive('buildPayload');
        $templates = Mockery::mock(ExportTemplateResolver::class);
        $templates->shouldNotReceive('resolve');
        $workbook = Mockery::mock(ReportingWorkbookExporter::class);
        $workbook->shouldNotReceive('create');

        $job->handle(
            $analytics,
            $templates,
            $workbook,
            app(PasswordPolicyService::class)
        );

        $this->assertSame([], Storage::disk('local')->allFiles('exports/reporting'));
        $this->assertCount(0, $user->refresh()->notifications);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Reporting export refused at job-time (A16).',
                Mockery::on(static fn (array $context): bool => ($context['user_id'] ?? null) === $user->id
                    && ($context['format'] ?? null) === 'excel'
                    && ($context['reason'] ?? null) === 'password_expired')
            );
    }

    public function test_export_identifier_survives_queue_serialization(): void
    {
        $job = new GenerateReportJob(123, 'pdf');
        $serializedJob = unserialize(serialize($job));

        $this->assertInstanceOf(GenerateReportJob::class, $serializedJob);
        $this->assertSame($job->exportId, $serializedJob->exportId);
    }
}
