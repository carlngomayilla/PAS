<?php

namespace App\Http\Controllers\Web;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelMeetingRequest;
use App\Http\Requests\ListMeetingsRequest;
use App\Http\Requests\PostponeMeetingRequest;
use App\Http\Requests\ReviewMeetingReportRequest;
use App\Http\Requests\StoreMeetingPlanRequest;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\SubmitMeetingReportRequest;
use App\Models\Direction;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Service;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use App\Services\Meetings\MeetingKpiService;
use App\Services\Meetings\MeetingWorkflowService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingWebController extends Controller
{
    use RecordsAuditTrail;

    public function index(
        ListMeetingsRequest $request,
        MeetingKpiService $kpis,
        MeetingAccessService $access
    ): View {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', Meeting::class);
        $filters = $this->normalizedFilters($request->validated());
        $view = (string) ($filters['view'] ?? 'all');
        $query = $kpis->meetingQuery($filters, $user)
            ->with([
                'direction:id,code,libelle',
                'service:id,code,libelle,direction_id',
                'responsible:id,name,email',
                'createdBy:id,name,email',
                'currentReport',
                'currentReport.uploadedBy:id,name,email',
            ]);

        $this->applyWorkspaceView($query, $view, $access, $user);
        if (($filters['q'] ?? '') !== '') {
            $query->where(function (Builder $search) use ($filters): void {
                $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
                $search->where('label', 'like', $needle)
                    ->orWhere('location', 'like', $needle)
                    ->orWhereHas('responsible', fn (Builder $responsible) => $responsible->where('name', 'like', $needle));
            });
        }

        return view('workspace.meetings.index', [
            'meetings' => $query->latest('current_scheduled_date')->latest('id')->paginate(20)->withQueryString(),
            'summary' => $kpis->summary($filters, $user),
            'planProgress' => $view === 'plans' ? $kpis->planProgress($filters, $user) : [],
            'filters' => $filters,
            'activeView' => $view,
            'canDefinePlans' => $access->canDefinePlans($user),
            'canSchedule' => $access->canScheduleAny($user),
            'directionOptions' => $this->directionOptions($user, $access),
            'serviceOptions' => $this->serviceOptions($user, $access),
            'userOptions' => $this->userOptions($user, $access),
            'responsibleOptions' => $this->responsibleOptions($user, $access),
            'meetingTypes' => MeetingType::options(),
            'meetingStatuses' => MeetingStatus::options(),
        ]);
    }

    public function storePlan(StoreMeetingPlanRequest $request, MeetingWorkflowService $workflow): RedirectResponse
    {
        return $this->runWorkflow($request, function () use ($request, $workflow): void {
            $plan = $workflow->definePlan($request->validated(), $this->authenticatedUser($request));
            $this->recordAudit($request, 'meetings', 'meeting_plan_define', $plan, null, $plan->toArray());
        }, 'Objectif mensuel enregistré et responsables concernés notifiés.');
    }

    public function store(StoreMeetingRequest $request, MeetingWorkflowService $workflow): RedirectResponse
    {
        try {
            $meeting = $workflow->scheduleMeeting($request->validated(), $this->authenticatedUser($request));
            $this->recordAudit($request, 'meetings', 'meeting_schedule', $meeting, null, $meeting->toArray());

            return redirect()->route('workspace.meetings.show', $meeting)
                ->with('success', 'Réunion programmée et participants notifiés.');
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }
    }

    public function show(Request $request, Meeting $meeting, MeetingAccessService $access): View
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('view', $meeting);
        $meeting->load([
            'direction:id,code,libelle',
            'service:id,code,libelle,direction_id',
            'responsible:id,name,email',
            'createdBy:id,name,email',
            'cancelledBy:id,name,email',
            'plan:id,direction_id,service_id,expected_count,year,month',
            'reports.uploadedBy:id,name,email',
            'reports.approvals.reviewer:id,name,email',
            'statusHistories.changedBy:id,name,email',
        ]);
        $currentReport = $meeting->reports->first();

        $this->recordAudit($request, 'meetings', 'meeting_view', $meeting, null, ['meeting_id' => $meeting->id]);

        return view('workspace.meetings.show', [
            'meeting' => $meeting,
            'currentReport' => $currentReport,
            'participantUsers' => User::query()
                ->whereIn('id', collect($meeting->participant_ids ?? [])->map(fn ($id): int => (int) $id)->all())
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'canPostpone' => $access->canPostpone($user, $meeting) && ! $meeting->hasOccurred(),
            'canCancel' => $access->canCancel($user, $meeting) && ! $meeting->hasOccurred(),
            'canSubmitReport' => $access->canSubmitReport($user, $meeting) && $meeting->hasOccurred(),
            'canReview' => $currentReport instanceof MeetingReport && $access->canReviewReport($user, $currentReport),
            'canDownloadReport' => $currentReport instanceof MeetingReport && $access->canDownloadReport($user, $currentReport),
        ]);
    }

    public function postpone(
        PostponeMeetingRequest $request,
        Meeting $meeting,
        MeetingWorkflowService $workflow
    ): RedirectResponse {
        return $this->runWorkflow($request, function () use ($request, $meeting, $workflow): void {
            $before = $meeting->toArray();
            $updated = $workflow->postponeMeeting(
                $meeting,
                (string) $request->validated('scheduled_date'),
                (string) $request->validated('scheduled_time'),
                (string) $request->validated('reason'),
                $this->authenticatedUser($request)
            );
            $this->recordAudit($request, 'meetings', 'meeting_postpone', $updated, $before, $updated->toArray());
        }, 'Réunion reportée et destinataires notifiés.');
    }

    public function cancel(
        CancelMeetingRequest $request,
        Meeting $meeting,
        MeetingWorkflowService $workflow
    ): RedirectResponse {
        return $this->runWorkflow($request, function () use ($request, $meeting, $workflow): void {
            $before = $meeting->toArray();
            $updated = $workflow->cancelMeeting(
                $meeting,
                (string) $request->validated('reason'),
                $this->authenticatedUser($request)
            );
            $this->recordAudit($request, 'meetings', 'meeting_cancel', $updated, $before, $updated->toArray());
        }, 'Réunion annulée et destinataires notifiés.');
    }

    public function submitReport(
        SubmitMeetingReportRequest $request,
        Meeting $meeting,
        MeetingWorkflowService $workflow
    ): RedirectResponse {
        return $this->runWorkflow($request, function () use ($request, $meeting, $workflow): void {
            $report = $workflow->submitReport(
                $meeting,
                $request->file('report'),
                $request->safe()->except('report'),
                $this->authenticatedUser($request)
            );
            $this->recordAudit($request, 'meetings', 'meeting_report_submit', $report, null, $report->toArray());
        }, 'PV transmis au SCIQ pour contrôle.');
    }

    public function reviewReport(
        ReviewMeetingReportRequest $request,
        Meeting $meeting,
        MeetingReport $meetingReport,
        MeetingWorkflowService $workflow
    ): RedirectResponse {
        return $this->runWorkflow($request, function () use ($request, $meetingReport, $workflow): void {
            $before = $meetingReport->toArray();
            $updated = $workflow->review(
                $meetingReport,
                MeetingApprovalDecision::from((string) $request->validated('decision')),
                $request->validated('comment'),
                $this->authenticatedUser($request)
            );
            $this->recordAudit($request, 'meetings', 'meeting_report_review', $updated, $before, $updated->toArray());
        }, 'Décision enregistrée et prochaine étape notifiée.');
    }

    public function downloadReport(
        Request $request,
        Meeting $meeting,
        MeetingReport $meetingReport,
        SecureJustificatifStorage $storage
    ): StreamedResponse {
        $this->authorize('downloadReport', [$meeting, $meetingReport]);
        $this->recordAudit($request, 'meetings', 'meeting_report_download', $meetingReport, null, [
            'meeting_id' => $meeting->id,
            'report_id' => $meetingReport->id,
            'version' => $meetingReport->version,
        ]);

        return $storage->downloadStoredFile(
            (string) $meetingReport->file_path,
            (string) $meetingReport->original_file_name,
            (bool) $meetingReport->is_encrypted,
            $meetingReport->mime_type,
            (int) $meetingReport->file_size
        );
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function normalizedFilters(array $validated): array
    {
        return collect($validated)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }

    private function applyWorkspaceView(Builder $query, string $view, MeetingAccessService $access, User $user): void
    {
        if ($view === 'corrections') {
            $query->where('status', MeetingStatus::ACorriger->value);

            return;
        }

        if ($view !== 'reviews') {
            return;
        }

        $statuses = [];
        if ($access->isSciq($user) || $access->isAdministrator($user)) {
            $statuses[] = MeetingStatus::EnValidationSciq->value;
        }
        if ($access->isPlanification($user) || $access->isAdministrator($user)) {
            $statuses[] = MeetingStatus::EnValidationPlanification->value;
        }

        $query->whereIn('status', $statuses !== [] ? $statuses : ['__forbidden__']);
    }

    /** @return Collection<int, Direction> */
    private function directionOptions(User $user, MeetingAccessService $access): Collection
    {
        return Direction::query()
            ->where('actif', true)
            ->when(! $access->canViewAllMeetings($user), fn (Builder $query) => $query->whereKey($user->direction_id))
            ->orderBy('code')
            ->get(['id', 'code', 'libelle']);
    }

    /** @return Collection<int, Service> */
    private function serviceOptions(User $user, MeetingAccessService $access): Collection
    {
        return Service::query()
            ->where('actif', true)
            ->when(! $access->canViewAllMeetings($user), fn (Builder $query) => $query->where('direction_id', $user->direction_id))
            ->when(
                ! $access->canViewAllMeetings($user) && ! $user->hasRole(User::ROLE_DIRECTION) && $user->service_id !== null,
                fn (Builder $query) => $query->whereKey($user->service_id)
            )
            ->orderBy('code')
            ->get(['id', 'direction_id', 'code', 'libelle']);
    }

    /** @return Collection<int, User> */
    private function userOptions(User $user, MeetingAccessService $access): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when(! $access->canViewAllMeetings($user), fn (Builder $query) => $query->where('direction_id', $user->direction_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'direction_id', 'service_id', 'role']);
    }

    /** @return Collection<int, User> */
    private function responsibleOptions(User $user, MeetingAccessService $access): Collection
    {
        return $this->userOptions($user, $access)
            ->filter(fn (User $candidate): bool => $access->canScheduleAny($candidate))
            ->values();
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function runWorkflow(Request $request, callable $operation, string $successMessage): RedirectResponse
    {
        try {
            $operation();

            return back()->with('success', $successMessage);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }
    }
}
