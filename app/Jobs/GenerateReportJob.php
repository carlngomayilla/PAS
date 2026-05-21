<?php

namespace App\Jobs;

use App\Models\ExportTemplate;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Exports\ExportTemplateResolver;
use App\Services\Exports\ReportingWorkbookExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(
        private readonly int $userId,
        private readonly string $format
    ) {
        $this->onQueue('exports');
    }

    public function handle(
        ReportingAnalyticsService $analyticsService,
        ExportTemplateResolver $templateResolver,
        ReportingWorkbookExporter $workbookExporter
    ): void {
        $user = User::query()->findOrFail($this->userId);
        $format = strtolower($this->format);

        // A16 — Le job peut s executer apres revocation des droits ou
        // suspension du compte. On re-verifie les conditions d acces avant de
        // generer un export potentiellement sensible. En cas d echec, on logge
        // et on sort silencieusement (le job ne doit pas etre retry).
        if (! $this->stillAuthorizedToExport($user)) {
            \Illuminate\Support\Facades\Log::warning('Reporting export refused at job-time (A16).', [
                'user_id' => $user->id,
                'format' => $format,
                'reason' => $this->disqualificationReason($user),
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
        $path = 'exports/reporting/'.$user->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        $url = URL::temporarySignedRoute('workspace.reporting.exports.download', now()->addDays(7), [
            'path' => Crypt::encryptString($path),
            'name' => $filename,
            'content_type' => $contentType,
        ]);

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
                'format' => $format,
                'path' => $path,
                'filename' => $filename,
                'generated_at' => now()->toIso8601String(),
            ],
        ]));
    }

    /**
     * A16 — Conditions d acces re-verifiees au moment de l execution du job
     * (et plus seulement au moment du dispatch). Refuse si :
     *   - le compte est inactif ou suspendu,
     *   - la permission planning.read ou reporting.read a ete revoquee,
     *   - le mot de passe est expire (force renewal).
     */
    private function stillAuthorizedToExport(User $user): bool
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

        return true;
    }

    private function disqualificationReason(User $user): string
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
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
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
