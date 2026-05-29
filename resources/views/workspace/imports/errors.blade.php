@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h1 class="showcase-panel-title">Historique des erreurs</h1>
            <a class="btn btn-secondary" href="{{ route('workspace.imports.error-report', $import) }}">Telecharger le rapport</a>
        </div>
        <div class="space-y-2">
            @forelse (($import->error_report ?? []) as $row)
                <div class="rounded border border-red-100 bg-red-50 p-3 text-sm text-red-800">
                    Ligne {{ $row['line'] ?? '-' }} : {{ $row['message'] ?? 'Erreur non detaillee.' }}
                </div>
            @empty
                <p class="text-sm text-slate-500">Aucune erreur enregistree pour cet import.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
