<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalAuditController extends Controller
{
    use AuthorizesPlanningScope;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Acces non autorise.');
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

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
            $request->filled('date_debut'),
            fn ($q) => $q->whereDate('created_at', '>=', (string) $request->string('date_debut'))
        );

        $query->when(
            $request->filled('date_fin'),
            fn ($q) => $q->whereDate('created_at', '<=', (string) $request->string('date_fin'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));

            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('entite_type', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function show(Request $request, JournalAudit $journalAudit): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $journalAudit->load('user:id,name,email'),
        ]);
    }
}
