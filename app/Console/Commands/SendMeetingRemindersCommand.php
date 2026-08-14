<?php

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Services\InstitutionalReportingService;
use App\Services\Meetings\MeetingNotificationService;
use App\Services\Meetings\MeetingWorkflowService;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('meetings:send-reminders {--days=7,1 : Jours avant la réunion, séparés par des virgules} {--dry-run : Simuler sans envoyer}')]
#[Description('Envoie les rappels automatiques des réunions programmées.')]
class SendMeetingRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        MeetingWorkflowService $workflow,
        MeetingNotificationService $notifications,
        InstitutionalReportingService $legacyReports,
        WorkspaceNotificationService $legacyNotifications
    ): int {
        $days = collect(explode(',', (string) $this->option('days')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter(fn (int $value): bool => $value >= 0 && $value <= 30)
            ->unique()
            ->sort()
            ->values();
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        if (! $dryRun) {
            $movedToAwaitingReport = $workflow->markDueMeetingsAsAwaitingReport();
            if ($movedToAwaitingReport > 0) {
                $this->line(sprintf('%d réunion(s) passée(s) au statut « PV attendu ».', $movedToAwaitingReport));
            }
        }

        foreach ($days as $daysBefore) {
            $meetings = Meeting::query()
                ->whereIn('status', [MeetingStatus::Programmee->value, MeetingStatus::Reportee->value])
                ->whereDate('current_scheduled_date', now()->addDays($daysBefore)->toDateString())
                ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
                ->orderBy('current_scheduled_date')
                ->orderBy('scheduled_time')
                ->get();
            foreach ($meetings as $meeting) {
                if (! $dryRun) {
                    $notifications->reminder($meeting);
                }
                $sent++;
                $this->line(sprintf('%s : %s (J-%d)', $dryRun ? 'SIMULATION' : 'Rappel', $meeting->label, $daysBefore));
            }

            foreach ($legacyReports->meetingReminderCandidates($daysBefore) as $legacyMeeting) {
                if (! $dryRun) {
                    $legacyNotifications->notifyMeetingReminder($legacyMeeting, $daysBefore);
                }
                $sent++;
                $this->line(sprintf(
                    '%s (archive) : %s (J-%d)',
                    $dryRun ? 'SIMULATION' : 'Rappel',
                    $legacyMeeting->title,
                    $daysBefore
                ));
            }
        }

        $this->info(sprintf('%d rappel(s) %s.', $sent, $dryRun ? 'simulé(s)' : 'envoyé(s)'));

        return self::SUCCESS;
    }
}
