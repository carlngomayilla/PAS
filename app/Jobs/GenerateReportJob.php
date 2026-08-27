<?php

namespace App\Jobs;

use App\Models\ExportTemplate;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Exports\ExportTemplateResolver;
use App\Services\Exports\ReportingWorkbookExporter;
use App\Services\Security\PasswordPolicyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public readonly string $exportId;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $userId,
        private readonly string $format
    ) {
        $this->exportId = (string) Str::uuid();
        $this->onQueue('exports');
    }

    public function handle(
        ReportingAnalyticsService $analyticsService,
        ExportTemplateResolver $templateResolver,
        ReportingWorkbookExporter $workbookExporter,
        PasswordPolicyService $passwordPolicy
    ): void {
        $user = User::query()->findOrFail($this->userId);
        $format = strtolower($this->format);
        $passwordExpired = $passwordPolicy->isExpired($user);

        // A16 — Le job peut s executer apres revocation des droits ou
        // suspension du compte. On re-verifie les conditions d acces avant de
        // generer un export potentiellement sensible. En cas d echec, on logge
        // et on sort silencieusement (le job ne doit pas etre retry).
        if (! $this->stillAuthorizedToExport($user, $passwordExpired)) {
            Log::warning('Reporting export refused at job-time (A16).', [
                'user_id' => $user->id,
                'format' => $format,
                'reason' => $this->disqualificationReason($user, $passwordExpired),
            ]);

            return;
        }

        $template = $templateResolver->resolve($user, 'reporting', 'consolidated_reporting', $format, 'officiel');
        $payload = $analyticsService->buildPayload($user, true, true);
        $this->injectTemplate($payload, $template, $format);

        if ($format === 'pdf') {
            @ini_set('memory_limit', '512M');
        }

        [$contents, $extension, $contentType] = match ($format) {
            'excel' => [$this->readAndDelete($workbookExporter->create($payload)), 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [Pdf::loadView('workspace.monitoring.reporting-pdf', $payload)
                ->setPaper($template?->paperSize() ?? 'a4', $template?->orientation() ?? 'landscape')
                ->output(), 'pdf', 'application/pdf'],
            default => throw new \InvalidArgumentException('Format export non supporte: '.$format),
        };

        $filename = $this->filename($payload, $extension, $template?->filenamePrefix() ?? 'reporting_anbg');
        $path = 'exports/reporting/'.$user->id.'/'.$this->exportId.'.'.$extension;
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Reporting export could not be stored.');
        }

        $retentionDays = max(1, (int) config('retention.reporting_exports_days', 7));
        $url = URL::temporarySignedRoute('workspace.reporting.exports.download', now()->addDays($retentionDays), [
            'path' => Crypt::encryptString($path),
            'name' => $filename,
            'content_type' => $contentType,
        ]);

        if ($this->hasExportNotification($user, 'reporting_export_ready')) {
            return;
        }

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Export reporting disponible',
            'message' => 'Votre export '.$format.' est pret au telechargement.',
            'module' => 'reporting',
            'entity_type' => 'reporting_export',
            'entity_id' => null,
            'url' => $url,
            'icon' => 'download',
            'status' => 'success',
            'priority' => 'normal',
            'meta' => [
                'event' => 'reporting_export_ready',
                'export_id' => $this->exportId,
                'format' => $format,
                'path' => $path,
                'filename' => $filename,
                'generated_at' => now()->toIso8601String(),
            ],
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Reporting export job failed.', [
            'user_id' => $this->userId,
            'format' => strtolower($this->format),
            'export_id' => $this->exportId,
            'exception_type' => get_debug_type($exception),
        ]);

        $user = User::query()->find($this->userId);
        if ($user === null
            || $this->hasExportNotification($user, 'reporting_export_ready')
            || $this->hasExportNotification($user, 'reporting_export_failed')) {
            return;
        }

        try {
            $user->notify(new WorkspaceModuleNotification([
                'title' => 'Echec de l export reporting',
                'message' => 'Votre export n a pas pu etre genere. Vous pouvez relancer la demande.',
                'module' => 'reporting',
                'entity_type' => 'reporting_export',
                'entity_id' => null,
                'url' => route('workspace.reporting'),
                'icon' => 'alert-triangle',
                'status' => 'error',
                'priority' => 'high',
                'meta' => [
                    'event' => 'reporting_export_failed',
                    'export_id' => $this->exportId,
                    'format' => strtolower($this->format),
                    'failed_at' => now()->toIso8601String(),
                ],
            ]));
        } catch (Throwable $notificationException) {
            Log::error('Reporting export failure notification could not be stored.', [
                'user_id' => $this->userId,
                'export_id' => $this->exportId,
                'exception_type' => $notificationException::class,
            ]);
        }
    }

    /**
     * A16 — Conditions d acces re-verifiees au moment de l execution du job
     * (et plus seulement au moment du dispatch). Refuse si :
     *   - le compte est inactif ou suspendu,
     *   - la permission planning.read ou reporting.read a ete revoquee,
     *   - le mot de passe est expire (force renewal).
     */
    private function stillAuthorizedToExport(User $user, bool $passwordExpired): bool
    {
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return false;
        }

        if (! (bool) ($user->is_active ?? false)) {
            return false;
        }

        if (! $user->hasPermission('planning.read') || ! $user->hasPermission('reporting.read')) {
            return false;
        }

        return ! $passwordExpired;
    }

    private function disqualificationReason(User $user, bool $passwordExpired): string
    {
        if (! (bool) ($user->is_active ?? false)) {
            return 'account_inactive';
        }
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return 'account_suspended';
        }
        if (! $user->hasPermission('planning.read')) {
            return 'permission_revoked_planning_read';
        }
        if (! $user->hasPermission('reporting.read')) {
            return 'permission_revoked_reporting_read';
        }
        if ($passwordExpired) {
            return 'password_expired';
        }

        return 'unknown';
    }

    private function injectTemplate(array &$payload, ?ExportTemplate $template, string $format): void
    {
        if ($template === null) {
            return;
        }

        if ($format === 'excel') {
            $payload['export_template'] = [
                'name' => $template->name,
                'title' => $template->documentTitle(),
                'subtitle' => $template->documentSubtitle(),
                'filename_prefix' => $template->filenamePrefix(),
                'layout' => $template->layout_config ?? [],
                'blocks' => $template->blocks_config ?? [],
            ];

            return;
        }

        $payload['exportTemplate'] = $template;
    }

    private function readAndDelete(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Temporary reporting workbook could not be read.');
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Temporary reporting workbook could not be read.');
        }

        @unlink($path);

        return $contents;
    }

    private function hasExportNotification(User $user, string $event): bool
    {
        return $user->notifications()
            ->where('type', WorkspaceModuleNotification::class)
            ->latest('created_at')
            ->limit(100)
            ->get(['id', 'data'])
            ->contains(fn ($notification): bool => data_get($notification->data, 'meta.event') === $event
                && data_get($notification->data, 'meta.export_id') === $this->exportId);
    }

    private function filename(array $payload, string $extension, string $prefix): string
    {
        $generatedAt = $payload['generatedAt'] instanceof Carbon ? $payload['generatedAt'] : now();
        $prefixToken = $this->token($prefix, 'reporting_anbg');

        return implode('_', array_filter([
            'RAPPORT',
            'REPORTING',
            $prefixToken !== 'reporting_anbg' ? $prefixToken : null,
            $generatedAt->format('Ymd_His'),
        ])).'.'.$this->token($extension, 'dat');
    }

    private function token(string $value, string $fallback): string
    {
        $token = (string) Str::of($value)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_');

        return $token !== '' ? $token : $fallback;
    }
}
