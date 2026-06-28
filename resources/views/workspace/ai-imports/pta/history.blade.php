@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Historique imports IA</h1>
                <p class="text-sm text-slate-500">Traçabilite des fichiers PTA analyses et valides.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('workspace.ai-imports.pta.index') }}">Nouvel import</a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Utilisateur</th>
                        <th class="px-3 py-2">Fichier</th>
                        <th class="px-3 py-2">Exercice</th>
                        <th class="px-3 py-2">Direction</th>
                        <th class="px-3 py-2">Service</th>
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Score</th>
                        <th class="px-3 py-2">Lignes</th>
                        <th class="px-3 py-2">Erreurs</th>
                        <th class="px-3 py-2">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($batches as $batch)
                        <tr>
                            <td class="px-3 py-2">{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $batch->user?->name ?? '-' }}</td>
                            <td class="px-3 py-2 font-semibold">{{ $batch->original_filename }}</td>
                            <td class="px-3 py-2">{{ $batch->detected_year ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $batch->detected_direction ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $batch->detected_service ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $batch->status }}</td>
                            <td class="px-3 py-2">{{ $batch->confidence_score ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $batch->rows_count }}</td>
                            <td class="px-3 py-2">{{ $batch->blocking_rows_count }}</td>
                            <td class="px-3 py-2"><a class="btn btn-outline" href="{{ route('workspace.ai-imports.pta.preview', $batch) }}">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="11">Aucun historique.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $batches->links() }}</div>
    </section>
</div>
@endsection
