<?php

namespace App\Console\Commands;

use App\Mail\AlertDigestMail;
use App\Notifications\WorkspaceModuleNotification;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertDigestBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendAlertDigestCommand extends Command
{
    protected $signature = 'alertes:notifier
        {--limit=20 : Nombre maximum d alertes par categorie}
        {--without-db : Desactiver la creation de notifications in-app}
        {--refresh-metrics : Recalculer les statuts/KPI avant envoi}
        {--dry-run : Simuler sans envoi d email}';

    protected $description = 'Envoie automatiquement les alertes (retards/KPI sous seuil) aux profils concernes';

    public function __construct(
        private readonly AlertDigestBuilder $digestBuilder,
        private readonly ActionTrackingService $trackingService
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $withDbNotifications = ! (bool) $this->option('without-db');
        $shouldRefreshMetrics = (bool) $this->option('refresh-metrics');

        if ($shouldRefreshMetrics) {
            $this->refreshMetricsForOpenActions();
        }

        $users = User::query()
            ->whereNotNull('email')
            ->orderBy('id')
            ->get();

        $processed = 0;
        $withAlerts = 0;
        $sent = 0;
        $skipped = 0;
        $dbNotified = 0;

        foreach ($users as $user) {
            if (! $this->digestBuilder->supportsUser($user)) {
                continue;
            }

            $processed++;
            $digest = $this->digestBuilder->buildForUser($user, $limit);
            $totalAlerts = (int) ($digest['totals']['total_alertes'] ?? 0);

            if ($totalAlerts <= 0) {
                $skipped++;
                continue;
            }

            $withAlerts++;

            if ($dryRun) {
                $this->line("DRY-RUN {$user->email} : {$totalAlerts} alertes");
                continue;
            }

            Mail::to($user->email)->send(new AlertDigestMail($user, $digest));
            $sent++;

            if ($withDbNotifications && ! $this->alreadyNotifiedToday($user)) {
                $user->notify(new WorkspaceModuleNotification([
                    'title' => 'Alertes de pilotage',
                    'message' => sprintf(
                        '%d alerte(s): %d action(s) en retard, %d KPI sous seuil, %d incident(s) de suivi.',
                        $totalAlerts,
                        (int) ($digest['totals']['actions_retard'] ?? 0),
                        (int) ($digest['totals']['kpi_sous_seuil'] ?? 0),
                        (int) ($digest['totals']['action_logs'] ?? 0),
                    ),
                    'module' => 'alertes',
                    'entity_type' => 'alert_digest',
                    'entity_id' => null,
                    'url' => route('workspace.alertes', ['limit' => $limit]),
                    'icon' => 'alert-triangle',
                    'status' => ((int) ($digest['totals']['actions_retard'] ?? 0) > 0
                        || (int) ($digest['totals']['action_logs'] ?? 0) > 0) ? 'warning' : 'info',
                    'priority' => ((int) ($digest['totals']['actions_retard'] ?? 0) > 0) ? 'high' : 'normal',
                    'meta' => [
                        'event' => 'alert_digest',
                        'generated_at' => Carbon::now()->toIso8601String(),
                        'totals' => [
                            'actions_retard' => (int) ($digest['totals']['actions_retard'] ?? 0),
                            'kpi_sous_seuil' => (int) ($digest['totals']['kpi_sous_seuil'] ?? 0),
                            'action_logs' => (int) ($digest['totals']['action_logs'] ?? 0),
                            'total_alertes' => $totalAlerts,
                        ],
                    ],
                ]));
                $dbNotified++;
            }
        }

        $this->info("Utilisateurs analyses: {$processed}");
        $this->info("Utilisateurs avec alertes: {$withAlerts}");
        $this->info("Utilisateurs sans alerte: {$skipped}");

        if ($dryRun) {
            $this->warn('Mode simulation: aucun email envoye.');
        } else {
            $this->info("Emails envoyes: {$sent}");
            if ($withDbNotifications) {
                $this->info("Notifications in-app creees: {$dbNotified}");
            }
        }

        return self::SUCCESS;
    }

    private function refreshMetricsForOpenActions(): void
    {
        $actions = \App\Models\Action::query()
            ->whereNotIn('statut_dynamique', [
                ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ])
            ->get(['id']);

        foreach ($actions as $actionRef) {
            $action = \App\Models\Action::query()->find($actionRef->id);
            if ($action === null) {
                continue;
            }
            $this->trackingService->refreshActionMetrics($action);
        }
    }

    private function alreadyNotifiedToday(User $user): bool
    {
        return $user->notifications()
            ->where('type', WorkspaceModuleNotification::class)
            ->whereDate('created_at', today()->toDateString())
            ->get()
            ->contains(static function ($notification): bool {
                return (string) ($notification->data['module'] ?? '') === 'alertes'
                    && (string) ($notification->data['meta']['event'] ?? '') === 'alert_digest';
            });
    }
}
