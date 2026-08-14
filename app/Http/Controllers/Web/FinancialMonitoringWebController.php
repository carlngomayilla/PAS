<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewBudgetOverrunRequest;
use App\Http\Requests\StoreBudgetOverrunRequest;
use App\Http\Requests\StoreFinancialTransactionRequest;
use App\Models\Action;
use App\Models\BudgetOverrunRequest;
use App\Models\Direction;
use App\Models\FinancialTransaction;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\User;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\FinancialMonitoringService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FinancialMonitoringWebController extends Controller
{
    use RecordsAuditTrail;

    public function __construct(private readonly AnalyticsCacheVersionService $cacheVersionService) {}

    public function index(Request $request, FinancialMonitoringService $finance): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        if (! $finance->canView($user)) {
            abort(403, 'Acces non autorise.');
        }

        $actionsQuery = $finance->scopedActions($user)
            ->with(['pta:id,titre,direction_id,service_id', 'pta.direction:id,code,libelle', 'pta.service:id,code,libelle'])
            ->withSum(['financialTransactions as engaged_total' => fn ($query) => $query->where('operation_type', FinancialTransaction::TYPE_COMMITMENT)], 'amount')
            ->withSum(['financialTransactions as disbursed_total' => fn ($query) => $query->where('operation_type', FinancialTransaction::TYPE_DISBURSEMENT)], 'amount');

        if ($request->filled('direction_id')) {
            $actionsQuery->whereHas('pta', fn ($query) => $query->where('direction_id', $request->integer('direction_id')));
        }
        if ($request->filled('service_id')) {
            $actionsQuery->whereHas('pta', fn ($query) => $query->where('service_id', $request->integer('service_id')));
        }
        if ($request->filled('q')) {
            $search = trim((string) $request->string('q'));
            $actionsQuery->where('libelle', 'like', "%{$search}%");
        }

        $summaryQuery = clone $actionsQuery;
        $actionIds = (clone $summaryQuery)->pluck('id');
        $operationTotals = FinancialTransaction::query()
            ->whereIn('action_id', $actionIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'engagement' THEN amount ELSE 0 END), 0) as engaged")
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'decaissement' THEN amount ELSE 0 END), 0) as disbursed")
            ->first();
        $budget = (float) (clone $summaryQuery)->sum('montant_estime');
        $canSeeGlobalFinance = $finance->canRecord($user) || $user->hasGlobalReadAccess() || $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_DG
        );
        $approvedExtra = $canSeeGlobalFinance
            ? (float) BudgetOverrunRequest::query()->where('status', BudgetOverrunRequest::STATUS_APPROVED)->sum('requested_extra')
            : 0.0;

        $selectedAction = null;
        if ($request->filled('action_id')) {
            $selectedAction = $finance->scopedActions($user)
                ->with(['pta:id,titre,direction_id,service_id', 'pta.direction:id,code,libelle', 'pta.service:id,code,libelle'])
                ->findOrFail($request->integer('action_id'));
        }

        $transactions = $selectedAction instanceof Action
            ? $selectedAction->financialTransactions()->with(['recordedBy:id,name,email', 'justificatifs'])->latest('operated_on')->latest('id')->get()
            : collect();
        $overrunQuery = BudgetOverrunRequest::query()
            ->with(['requestedBy:id,name,email', 'dafDirector:id,name,email', 'dgDecidedBy:id,name,email', 'justificatifs'])
            ->latest();
        if (! $finance->canRecord($user) && ! $user->hasGlobalReadAccess() && ! $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_DG
        )) {
            $overrunQuery->where(function ($query) use ($actionIds): void {
                $query->where(function ($scopeQuery) use ($actionIds): void {
                    $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_ACTION)->whereIn('scope_id', $actionIds);
                });
            });
        }
        $overruns = $overrunQuery->limit(20)->get();

        $actionOptions = $finance->canRecord($user)
            ? $finance->scopedActions($user)->with('pta:id,direction_id,service_id')->orderBy('libelle')->limit(300)->get(['id', 'pta_id', 'libelle', 'montant_estime'])
            : collect();

        return view('workspace.finance.index', [
            'actions' => $actionsQuery->orderByDesc('id')->paginate(20)->withQueryString(),
            'selectedAction' => $selectedAction,
            'selectedSummary' => $selectedAction instanceof Action ? $finance->actionSummary($selectedAction) : null,
            'transactions' => $transactions,
            'overruns' => $overruns,
            'canRecord' => $finance->canRecord($user),
            'isDafDirector' => $finance->isDafDirector($user),
            'isDg' => $user->hasRole(User::ROLE_DG),
            'canViewFinancingRequests' => $finance->isDafDirector($user)
                || $user->hasRole(User::ROLE_DG)
                || $user->hasGlobalReadAccess(),
            'summary' => [
                'budget' => $budget,
                'approved_extra' => $approvedExtra,
                'engaged' => (float) ($operationTotals?->engaged ?? 0),
                'disbursed' => (float) ($operationTotals?->disbursed ?? 0),
            ],
            'directionOptions' => Direction::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
            'actionOptions' => $actionOptions,
        ]);
    }

    public function storeTransaction(StoreFinancialTransactionRequest $request, Action $action, FinancialMonitoringService $finance, SecureJustificatifStorage $storage): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        unset($validated['proof']);
        $storedFile = $request->hasFile('proof') ? $storage->store($request->file('proof'), 'justificatifs/finances/'.date('Y/m')) : null;
        try {
            $transaction = $finance->record($action, $validated, $user);
            if ($storedFile !== null) {
                $transaction->justificatifs()->create([
                    'categorie' => 'financement',
                    'nom_original' => $storedFile['nom_original'],
                    'chemin_stockage' => $storedFile['path'],
                    'est_chiffre' => $storedFile['est_chiffre'],
                    'mime_type' => $storedFile['mime_type'],
                    'taille_octets' => $storedFile['taille_octets'],
                    'description' => 'Piece justificative de '.$transaction->operation_type,
                    'ajoute_par' => $user->id,
                ]);
            }
        } catch (Throwable $exception) {
            $storage->deleteByPath($storedFile['path'] ?? null);
            throw $exception;
        }

        $this->recordAudit($request, 'finance', 'financial_transaction_create', $transaction, null, $transaction->toArray());
        $this->cacheVersionService->bumpAll();

        return redirect()->route('workspace.daf.financements.index', ['action_id' => $action->id])->with('success', 'Operation financiere enregistree.');
    }

    public function storeOverrun(StoreBudgetOverrunRequest $request, FinancialMonitoringService $finance, SecureJustificatifStorage $storage): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $storedFile = $request->hasFile('proof') ? $storage->store($request->file('proof'), 'justificatifs/finances/'.date('Y/m')) : null;
        try {
            $overrun = $finance->requestOverrun((string) $validated['scope_type'], (int) $validated['scope_id'], (float) $validated['requested_extra'], (string) $validated['reason'], $user);
            if ($storedFile !== null) {
                $overrun->justificatifs()->create([
                    'categorie' => 'financement',
                    'nom_original' => $storedFile['nom_original'],
                    'chemin_stockage' => $storedFile['path'],
                    'est_chiffre' => $storedFile['est_chiffre'],
                    'mime_type' => $storedFile['mime_type'],
                    'taille_octets' => $storedFile['taille_octets'],
                    'description' => 'Piece justificative de demande de depassement budgetaire',
                    'ajoute_par' => $user->id,
                ]);
            }
        } catch (Throwable $exception) {
            $storage->deleteByPath($storedFile['path'] ?? null);
            throw $exception;
        }

        $this->recordAudit($request, 'finance', 'budget_overrun_request_create', $overrun, null, $overrun->toArray());
        $this->cacheVersionService->bumpAll();

        return redirect()->route('workspace.daf.financements.index')->with('success', 'Demande de depassement enregistree.');
    }

    public function reviewOverrun(ReviewBudgetOverrunRequest $request, BudgetOverrunRequest $budgetOverrunRequest, FinancialMonitoringService $finance): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $budgetOverrunRequest->toArray();
        $validated = $request->validated();
        $overrun = $finance->reviewOverrun($budgetOverrunRequest, (string) $validated['decision'], (string) $validated['note'], $user);
        $this->recordAudit($request, 'finance', 'budget_overrun_request_review', $overrun, $before, $overrun->toArray());
        $this->cacheVersionService->bumpAll();

        return redirect()->route('workspace.daf.financements.index')->with('success', 'Decision de depassement enregistree.');
    }

    public function downloadTransactionProof(Request $request, FinancialTransaction $financialTransaction, Justificatif $justificatif, FinancialMonitoringService $finance, SecureJustificatifStorage $storage): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $finance->canView($user)) {
            abort(403, 'Acces non autorise.');
        }
        if ((string) $justificatif->justifiable_type !== FinancialTransaction::class || (int) $justificatif->justifiable_id !== (int) $financialTransaction->id) {
            abort(404);
        }

        $allowed = $finance->scopedActions($user)->whereKey($financialTransaction->action_id)->exists();
        if (! $allowed) {
            abort(403, 'Acces hors de votre perimetre.');
        }

        return $storage->download($justificatif);
    }

    public function downloadOverrunProof(Request $request, BudgetOverrunRequest $budgetOverrunRequest, Justificatif $justificatif, FinancialMonitoringService $finance, SecureJustificatifStorage $storage): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $finance->canView($user)) {
            abort(403, 'Acces non autorise.');
        }
        if ((string) $justificatif->justifiable_type !== BudgetOverrunRequest::class || (int) $justificatif->justifiable_id !== (int) $budgetOverrunRequest->id) {
            abort(404);
        }

        if (! $finance->canRecord($user) && ! $user->hasGlobalReadAccess() && ! $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_DG
        )) {
            $allowed = $budgetOverrunRequest->scope_type === BudgetOverrunRequest::SCOPE_ACTION
                && $finance->scopedActions($user)->whereKey($budgetOverrunRequest->scope_id)->exists();
            if (! $allowed) {
                abort(403, 'Acces hors de votre perimetre.');
            }
        }

        return $storage->download($justificatif);
    }
}
