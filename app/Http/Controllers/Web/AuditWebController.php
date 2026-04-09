<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\PlatformDiagnosticService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditWebController extends Controller
{
    public function __construct(
        private readonly PlatformDiagnosticService $platformDiagnosticService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Acces non autorise.');
        }

        $query = $this->filteredQuery($request);

        return view('workspace.audit.index', [
            'logs' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'summary' => $this->platformDiagnosticService->filteredAuditSummary([
                'module' => (string) $request->string('module'),
                'action' => (string) $request->string('action'),
                'user_id' => $request->filled('user_id') ? (int) $request->integer('user_id') : null,
                'entite_type' => (string) $request->string('entite_type'),
                'entite_id' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
                'date_from' => (string) $request->string('date_from'),
                'date_to' => (string) $request->string('date_to'),
                'q' => (string) $request->string('q'),
            ]),
            'filters' => [
                'module' => (string) $request->string('module'),
                'action' => (string) $request->string('action'),
                'user_id' => $request->filled('user_id') ? (int) $request->integer('user_id') : null,
                'entite_type' => (string) $request->string('entite_type'),
                'entite_id' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
                'date_from' => (string) $request->string('date_from'),
                'date_to' => (string) $request->string('date_to'),
                'q' => (string) $request->string('q'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Acces non autorise.');
        }

        $filename = 'journal_audit_'.now()->format('Ymd_His').'.csv';
        $query = $this->filteredQuery($request)->orderByDesc('id');

        return Response::streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'id',
                'created_at',
                'user_id',
                'user_email',
                'module',
                'action',
                'entite_type',
                'entite_id',
                'adresse_ip',
                'user_agent',
                'ancienne_valeur',
                'nouvelle_valeur',
            ], ';');

            $query->chunk(250, function ($logs) use ($handle): void {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        optional($log->created_at)?->toDateTimeString(),
                        $log->user_id,
                        $log->user?->email,
                        $log->module,
                        $log->action,
                        $log->entite_type,
                        $log->entite_id,
                        $log->adresse_ip,
                        $log->user_agent,
                        is_array($log->ancienne_valeur) ? json_encode($log->ancienne_valeur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $log->ancienne_valeur,
                        is_array($log->nouvelle_valeur) ? json_encode($log->nouvelle_valeur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $log->nouvelle_valeur,
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filteredQuery(Request $request)
    {
        $query = JournalAudit::query()->with('user:id,name,email');

        $query->when(
            $request->filled('module'),
            fn ($q) => $q->where('module', (string) $request->string('module'))
        );

        $query->when(
            $request->filled('action'),
            fn ($q) => $q->where('action', (string) $request->string('action'))
        );

        $query->when(
            $request->filled('user_id'),
            fn ($q) => $q->where('user_id', (int) $request->integer('user_id'))
        );

        $query->when(
            $request->filled('entite_type'),
            fn ($q) => $q->where('entite_type', (string) $request->string('entite_type'))
        );

        $query->when(
            $request->filled('entite_id'),
            fn ($q) => $q->where('entite_id', (int) $request->integer('entite_id'))
        );
        $query->when(
            $request->filled('date_from'),
            fn ($q) => $q->whereDate('created_at', '>=', (string) $request->string('date_from'))
        );
        $query->when(
            $request->filled('date_to'),
            fn ($q) => $q->whereDate('created_at', '<=', (string) $request->string('date_to'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('entite_type', 'like', "%{$search}%");
            });
        });

        return $query;
    }
}
