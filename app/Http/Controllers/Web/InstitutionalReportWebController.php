<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInstitutionalMeetingRequest;
use App\Http\Requests\PostponeInstitutionalMeetingRequest;
use App\Http\Requests\ResubmitInstitutionalReportRequest;
use App\Http\Requests\ReviewInstitutionalReportRequest;
use App\Http\Requests\StoreInstitutionalMeetingDecisionRequest;
use App\Http\Requests\StoreInstitutionalReportRequest;
use App\Http\Requests\UpdateInstitutionalMeetingDecisionRequest;
use App\Models\Direction;
use App\Models\InstitutionalMeetingDecision;
use App\Models\InstitutionalReport;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\User;
use App\Services\InstitutionalMeetingExportService;
use App\Services\InstitutionalReportingService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InstitutionalReportWebController extends Controller
{
    use RecordsAuditTrail;

    public function index(Request $request, InstitutionalReportingService $reports): View
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', InstitutionalReport::class);

        $activeTab = in_array((string) $request->query('tab'), ['register', 'schedule', 'review'], true)
            ? (string) $request->query('tab')
            : 'register';
        $filters = $this->reportFilters($request);
        $query = $reports->filteredVisibleQuery($user, $filters)->with([
            'direction:id,code,libelle',
            'service:id,code,libelle',
            'submittedBy:id,name,email',
            'responsible:id,name,email',
            'justificatifs:id,justifiable_type,justifiable_id,nom_original,description,ajoute_par,created_at',
        ]);

        if ($activeTab === 'schedule') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING);
        }
        if ($activeTab === 'review') {
            $query->whereIn('status', [
                InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            ]);
        }

        return view('workspace.reports.index', [
            'reports' => $query->latest('scheduled_at')->latest('id')->paginate(20)->withQueryString(),
            'summary' => $reports->summaryFor($user, $filters),
            'filters' => $filters,
            'activeTab' => $activeTab,
            'canSubmit' => $reports->canSubmit($user),
            'canScheduleMeeting' => $reports->canScheduleMeeting($user),
            'canReview' => $reports->canReviewAnything($user),
            'canExportMeetings' => $reports->canExportMeetingReports($user),
            'directionOptions' => Direction::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
            'userOptions' => $this->participantOptionsFor($user),
            'followUpDecisions' => InstitutionalMeetingDecision::query()
                ->whereIn('institutional_report_id', $reports->filteredVisibleQuery($user, $filters)
                    ->where('report_type', InstitutionalReport::TYPE_MEETING)
                    ->select('id'))
                ->where('status', '!=', InstitutionalMeetingDecision::STATUS_COMPLETED)
                ->with([
                    'institutionalReport:id,title,scheduled_at',
                    'responsible:id,name,email',
                ])
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->limit(8)
                ->get(),
            'reportService' => $reports,
        ]);
    }

    public function export(Request $request, string $format, InstitutionalReportingService $reports, InstitutionalMeetingExportService $exports): BinaryFileResponse
    {
        $user = $this->authenticatedUser($request);
        abort_unless($reports->canExportMeetingReports($user), 403);
        abort_unless(in_array($format, ['pdf', 'xlsx', 'docx'], true), 404);

        $filters = $this->reportFilters($request);
        $meetings = $reports->filteredVisibleQuery($user, $filters)
            ->where('report_type', InstitutionalReport::TYPE_MEETING)
            ->with([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'submittedBy:id,name,email',
                'responsible:id,name,email',
            ])
            ->orderBy('scheduled_at')
            ->get();
        abort_if($meetings->isEmpty(), 422, 'Aucune réunion ne correspond aux filtres sélectionnés.');

        $this->recordAudit($request, 'institutional_reports', 'institutional_meeting_export', $meetings->first(), null, [
            'format' => $format,
            'filters' => $filters,
            'meeting_count' => $meetings->count(),
        ]);

        return match ($format) {
            'pdf' => $exports->pdf($meetings, $reports->summaryFor($user, $filters), $filters),
            'xlsx' => $exports->excel($meetings, $reports->summaryFor($user, $filters), $filters),
            default => $exports->word($meetings, $reports->summaryFor($user, $filters), $filters),
        };
    }

    public function store(StoreInstitutionalReportRequest $request, InstitutionalReportingService $reports, SecureJustificatifStorage $storage, WorkspaceNotificationService $notifications): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validated();
        $storedFiles = [];
        $report = null;

        try {
            foreach ($this->uploadedFiles($request) as $uploadedFile) {
                $storedFiles[] = $storage->store($uploadedFile, 'justificatifs/rapports/'.date('Y/m'));
            }
            $report = $reports->create($validated, $user);
            foreach ($storedFiles as $index => $storedFile) {
                $description = $report->report_type === InstitutionalReport::TYPE_MEETING
                    ? 'Pièce jointe de la réunion - version '.($index + 1).'.'
                    : 'Pièce jointe du rapport institutionnel - version '.($index + 1).'.';
                $report->justificatifs()->create($this->justificatifPayload($storedFile, $user, $description));
            }
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                $storage->deleteByPath($storedFile['path'] ?? null);
            }
            $report?->delete();

            throw $exception;
        }

        $this->recordAudit($request, 'institutional_reports', 'institutional_report_create', $report, null, $report->fresh()->toArray());
        if ($report->report_type === InstitutionalReport::TYPE_MEETING && $report->scheduled_at !== null) {
            $notifications->notifyMeetingScheduled($report, $user);
        }

        return redirect()->route('workspace.reports.index', ['tab' => $report->report_type === InstitutionalReport::TYPE_MEETING ? 'schedule' : 'register'])
            ->with('success', $report->report_type === InstitutionalReport::TYPE_MEETING && $report->held_at === null
                ? 'Reunion programmee. Ajoutez le compte rendu puis soumettez-le apres sa tenue.'
                : 'Rapport cree. Vous pouvez maintenant le soumettre au circuit de verification.');
    }

    public function submit(Request $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $institutionalReport);
        $before = $institutionalReport->toArray();
        $report = $reports->submit($institutionalReport, $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_submit', $report, $before, $report->toArray());

        return redirect()->route('workspace.reports.index', ['tab' => 'review'])->with('success', 'Rapport transmis au SCIQ pour verification.');
    }

    public function resubmit(ResubmitInstitutionalReportRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports, SecureJustificatifStorage $storage, WorkspaceNotificationService $notifications): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $before = $institutionalReport->toArray();
        $storedFiles = [];
        $justificatifs = [];

        try {
            foreach ($this->uploadedFiles($request) as $uploadedFile) {
                $storedFiles[] = $storage->store($uploadedFile, 'justificatifs/rapports/'.date('Y/m'));
            }
            $nextVersion = $institutionalReport->justificatifs()->count() + 1;
            foreach ($storedFiles as $index => $storedFile) {
                $description = $institutionalReport->report_type === InstitutionalReport::TYPE_MEETING
                    ? 'PV ou annexe de réunion - version '.($nextVersion + $index).'.'
                    : 'Pièce jointe de correction - version '.($nextVersion + $index).'.';
                $justificatifs[] = $institutionalReport->justificatifs()->create(
                    $this->justificatifPayload($storedFile, $user, $description)
                );
            }
            $report = $reports->resubmit($institutionalReport, $request->validated(), $user);
        } catch (Throwable $exception) {
            foreach ($justificatifs as $justificatif) {
                $justificatif->delete();
            }
            foreach ($storedFiles as $storedFile) {
                $storage->deleteByPath($storedFile['path'] ?? null);
            }

            throw $exception;
        }

        $this->recordAudit($request, 'institutional_reports', 'institutional_report_resubmit', $report, $before, $report->toArray());
        if ($report->report_type === InstitutionalReport::TYPE_MEETING && $report->held_at !== null) {
            $notifications->notifyMeetingMinutesPublished($report, $user);
        }

        return redirect()->route('workspace.reports.show', $report)->with('success', 'Correction soumise au SCIQ.');
    }

    public function postpone(PostponeInstitutionalMeetingRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports, WorkspaceNotificationService $notifications): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $before = $institutionalReport->toArray();
        $report = $reports->postponeMeeting($institutionalReport, $request->validated(), $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_meeting_postpone', $report, $before, $report->toArray());
        $notifications->notifyMeetingPostponed($report, $user);

        return redirect()->route('workspace.reports.show', $report)->with('success', 'Réunion reportée et participants notifiés.');
    }

    public function cancel(CancelInstitutionalMeetingRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports, WorkspaceNotificationService $notifications): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $before = $institutionalReport->toArray();
        $report = $reports->cancelMeeting($institutionalReport, $request->validated(), $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_meeting_cancel', $report, $before, $report->toArray());
        $notifications->notifyMeetingCancelled($report, $user);

        return redirect()->route('workspace.reports.show', $report)->with('success', 'Réunion annulée et participants notifiés.');
    }

    public function review(ReviewInstitutionalReportRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $before = $institutionalReport->toArray();
        $validated = $request->validated();
        $report = $reports->review($institutionalReport, (string) $validated['decision'], (string) $validated['note'], $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_review', $report, $before, $report->toArray());

        return redirect()->route('workspace.reports.show', $report)->with('success', (string) $validated['decision'] === 'approve'
            ? 'Verification enregistree et rapport transmis.'
            : 'Correction demandee au deposant.');
    }

    public function storeDecision(StoreInstitutionalMeetingDecisionRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports, WorkspaceNotificationService $notifications): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $decision = $reports->createMeetingDecision($institutionalReport, $request->validated(), $user);
        $this->recordAudit($request, 'institutional_meeting_decisions', 'institutional_meeting_decision_create', $decision, null, $decision->toArray());
        $notifications->notifyMeetingDecisionAssigned($institutionalReport, $decision, $user);

        return redirect()->route('workspace.reports.show', $institutionalReport)->with('success', 'Décision enregistrée dans le suivi de la réunion.');
    }

    public function updateDecision(UpdateInstitutionalMeetingDecisionRequest $request, InstitutionalReport $institutionalReport, InstitutionalMeetingDecision $decision, InstitutionalReportingService $reports): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $before = $decision->toArray();
        $updatedDecision = $reports->updateMeetingDecision($institutionalReport, $decision, $request->validated(), $user);
        $this->recordAudit($request, 'institutional_meeting_decisions', 'institutional_meeting_decision_update', $updatedDecision, $before, $updatedDecision->toArray());

        return redirect()->route('workspace.reports.show', $institutionalReport)->with('success', 'État de la décision mis à jour.');
    }

    public function show(Request $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): View
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('view', $institutionalReport);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_view', $institutionalReport, null, [
            'report_id' => $institutionalReport->id,
        ]);

        return view('workspace.reports.show', [
            'report' => $institutionalReport->load([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'submittedBy:id,name,email',
                'responsible:id,name,email',
                'justificatifs.ajoutePar:id,name,email',
                'meetingDecisions.responsible:id,name,email',
                'meetingDecisions.createdBy:id,name,email',
            ]),
            'reportService' => $reports,
            'participantUsers' => User::query()
                ->whereIn('id', collect($institutionalReport->participant_ids ?? [])
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all())
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'service_id']),
            'canReview' => $reports->canReview($user, $institutionalReport),
            'canAmend' => $reports->canAmend($user, $institutionalReport),
            'canPostpone' => $reports->canPostponeMeeting($user, $institutionalReport),
            'canPublishMeetingMinutes' => $reports->canPublishMeetingMinutes($user, $institutionalReport),
            'canManageMeetingDecisions' => $reports->canManageMeetingDecisions($user, $institutionalReport),
            'decisionUserOptions' => $this->participantOptionsFor($user),
            'currentUser' => $user,
        ]);
    }

    public function download(Request $request, InstitutionalReport $institutionalReport, Justificatif $justificatif, InstitutionalReportingService $reports, SecureJustificatifStorage $storage): StreamedResponse
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('view', $institutionalReport);
        if ((string) $justificatif->justifiable_type !== InstitutionalReport::class || (int) $justificatif->justifiable_id !== (int) $institutionalReport->id) {
            abort(404);
        }
        if (! $reports->canViewReport($user, $institutionalReport)) {
            abort(403, 'Acces hors de votre perimetre.');
        }

        $this->recordAudit($request, 'institutional_reports', 'institutional_report_attachment_download', $institutionalReport, null, [
            'report_id' => $institutionalReport->id,
            'attachment_id' => $justificatif->id,
            'file_name' => $justificatif->nom_original,
        ]);

        return $storage->download($justificatif);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function reportFilters(Request $request): array
    {
        return collect([
            'q',
            'year',
            'quarter',
            'month',
            'direction_id',
            'service_id',
            'meeting_type',
            'responsible_id',
            'participant_id',
            'status',
        ])->mapWithKeys(fn (string $key): array => [$key => trim((string) $request->query($key, ''))])->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function participantOptionsFor(User $user): Collection
    {
        $users = User::query()->orderBy('name');
        if ($user->direction_id !== null) {
            $users->where('direction_id', $user->direction_id);
        }
        if ($user->hasRole(User::ROLE_SERVICE)
            && ! $user->hasRole(User::ROLE_DIRECTION)
            && $user->service_id !== null) {
            $users->where('service_id', $user->service_id);
        }

        return $users->get(['id', 'name', 'email', 'direction_id', 'service_id', 'role']);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        return collect([$request->file('attachment')])
            ->merge(collect($request->file('attachments', [])))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{path:string,mime_type:?string,taille_octets:int,nom_original:string,est_chiffre:bool}  $storedFile
     * @return array<string, mixed>
     */
    private function justificatifPayload(array $storedFile, User $user, string $description): array
    {
        return [
            'categorie' => 'rapport_institutionnel',
            'nom_original' => $storedFile['nom_original'],
            'chemin_stockage' => $storedFile['path'],
            'est_chiffre' => $storedFile['est_chiffre'],
            'mime_type' => $storedFile['mime_type'],
            'taille_octets' => $storedFile['taille_octets'],
            'description' => $description,
            'ajoute_par' => $user->id,
        ];
    }
}
