<?php

namespace App\Console\Commands;

use App\Services\InstitutionalReportingService;
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
    public function handle(InstitutionalReportingService $reports, WorkspaceNotificationService $notifications): int
    {
        $days = collect(explode(',', (string) $this->option('days')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter(fn (int $value): bool => $value >= 0 && $value <= 30)
            ->unique()
            ->sort()
            ->values();
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ($days as $daysBefore) {
            $meetings = $reports->meetingReminderCandidates($daysBefore);
            foreach ($meetings as $meeting) {
                if (! $dryRun) {
                    $notifications->notifyMeetingReminder($meeting, $daysBefore);
                }
                $sent++;
                $this->line(sprintf('%s : %s (J-%d)', $dryRun ? 'SIMULATION' : 'Rappel', $meeting->title, $daysBefore));
            }
        }

        $this->info(sprintf('%d rappel(s) %s.', $sent, $dryRun ? 'simulé(s)' : 'envoyé(s)'));

        return self::SUCCESS;
    }
}
