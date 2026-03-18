<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditWebController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasGlobalReadAccess()) {
            abort(403, 'Acces non autorise.');
        }

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

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('entite_type', 'like', "%{$search}%");
            });
        });

        return view('workspace.audit.index', [
            'logs' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => [
                'module' => (string) $request->string('module'),
                'action' => (string) $request->string('action'),
                'user_id' => $request->filled('user_id') ? (int) $request->integer('user_id') : null,
                'entite_type' => (string) $request->string('entite_type'),
                'entite_id' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
                'q' => (string) $request->string('q'),
            ],
        ]);
    }
}

