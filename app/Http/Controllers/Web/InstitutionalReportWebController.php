<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResubmitInstitutionalReportRequest;
use App\Http\Requests\ReviewInstitutionalReportRequest;
use App\Http\Requests\StoreInstitutionalReportRequest;
use App\Models\Direction;
use App\Models\InstitutionalReport;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\User;
use App\Services\InstitutionalReportingService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InstitutionalReportWebController extends Controller
{
    use RecordsAuditTrail;

    public function index(Request $request, InstitutionalReportingService $reports): View
    {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', InstitutionalReport::class);

        $activeTab = in_array((string) $request->query('tab'), ['register', 'review'], true)
            ? (string) $request->query('tab')
            : 'register';
        $filters = $this->reportFilters($request);
        $query = $reports->filteredVisibleQuery($user, $filters)->with([
            'direction:id,code,libelle',
            'service:id,code,libelle',
            'submittedBy:id,name,email',
            'justificatifs:id,justifiable_type,justifiable_id,nom_original,description,ajoute_par,created_at',
        ])->where('report_type', '!=', InstitutionalReport::TYPE_MEETING);

        if ($activeTab === 'review') {
            $query->whereIn('status', [
                InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            ]);
        }

        $otherReports = $reports->filteredVisibleQuery($user, $filters)
            ->where('report_type', '!=', InstitutionalReport::TYPE_MEETING);

        return view('workspace.reports.index', [
            'reports' => $query->latest('scheduled_at')->latest('id')->paginate(20)->withQueryString(),
            'summary' => [
                'total' => (clone $otherReports)->count(),
                'pending' => (clone $otherReports)->whereIn('status', [
                    InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                    InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                    InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                    InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
                ])->count(),
                'verified' => (clone $otherReports)->where('status', InstitutionalReport::STATUS_VERIFIED)->count(),
            ],
            'filters' => $filters,
            'activeTab' => $activeTab,
            'canSubmit' => $reports->canSubmit($user),
            'canReview' => $reports->canReviewAnything($user),
            'directionOptions' => Direction::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
            'reportService' => $reports,
        ]);
    }

    public function store(StoreInstitutionalReportRequest $request, InstitutionalReportingService $reports, SecureJustificatifStorage $storage): RedirectResponse
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
                $description = 'Pièce jointe du rapport institutionnel - version '.($index + 1).'.';
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

        return redirect()->route('workspace.reports.index', ['tab' => 'register'])
            ->with('success', 'Rapport créé. Vous pouvez maintenant le soumettre au circuit de vérification.');
    }

    public function submit(Request $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): RedirectResponse
    {
        $this->rejectRetiredMeeting($institutionalReport);
        $user = $this->authenticatedUser($request);
        $this->authorize('update', $institutionalReport);
        $before = $institutionalReport->toArray();
        $report = $reports->submit($institutionalReport, $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_submit', $report, $before, $report->toArray());

        return redirect()->route('workspace.reports.index', ['tab' => 'review'])->with('success', 'Rapport transmis au SCIQ pour verification.');
    }

    public function resubmit(ResubmitInstitutionalReportRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports, SecureJustificatifStorage $storage): RedirectResponse
    {
        $this->rejectRetiredMeeting($institutionalReport);
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
                $description = 'Pièce jointe de correction - version '.($nextVersion + $index).'.';
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

        return redirect()->route('workspace.reports.show', $report)->with('success', 'Correction soumise au SCIQ.');
    }

    public function review(ReviewInstitutionalReportRequest $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): RedirectResponse
    {
        $this->rejectRetiredMeeting($institutionalReport);
        $user = $this->authenticatedUser($request);
        $before = $institutionalReport->toArray();
        $validated = $request->validated();
        $report = $reports->review($institutionalReport, (string) $validated['decision'], (string) $validated['note'], $user);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_review', $report, $before, $report->toArray());

        return redirect()->route('workspace.reports.show', $report)->with('success', (string) $validated['decision'] === 'approve'
            ? 'Verification enregistree et rapport transmis.'
            : 'Correction demandee au deposant.');
    }

    public function show(Request $request, InstitutionalReport $institutionalReport, InstitutionalReportingService $reports): View
    {
        $user = $this->authenticatedUser($request);
        $this->rejectRetiredMeeting($institutionalReport);
        $this->authorize('view', $institutionalReport);
        $this->recordAudit($request, 'institutional_reports', 'institutional_report_view', $institutionalReport, null, [
            'report_id' => $institutionalReport->id,
        ]);

        return view('workspace.reports.show', [
            'report' => $institutionalReport->load([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'submittedBy:id,name,email',
                'justificatifs.ajoutePar:id,name,email',
            ]),
            'reportService' => $reports,
            'canReview' => $reports->canReview($user, $institutionalReport),
            'canAmend' => $reports->canAmend($user, $institutionalReport),
            'currentUser' => $user,
        ]);
    }

    public function download(Request $request, InstitutionalReport $institutionalReport, Justificatif $justificatif, InstitutionalReportingService $reports, SecureJustificatifStorage $storage): StreamedResponse
    {
        $this->rejectRetiredMeeting($institutionalReport);
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

    private function rejectRetiredMeeting(InstitutionalReport $report): void
    {
        abort_if($report->report_type === InstitutionalReport::TYPE_MEETING, 410, 'Le module Réunions et PV a été retiré.');
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
            'status',
        ])->mapWithKeys(fn (string $key): array => [$key => trim((string) $request->query($key, ''))])->all();
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
