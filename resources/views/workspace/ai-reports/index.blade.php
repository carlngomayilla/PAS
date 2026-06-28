@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Rapports IA</h1>
                <p class="text-sm text-slate-500">Rapports PAS, PAO et PTA generes depuis les metriques Laravel.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('workspace.ai-reports.create') }}">Nouveau rapport</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Titre</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Auteur</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($reports as $report)
                        <tr>
                            <td class="px-3 py-2">{{ $report->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 font-semibold">{{ $report->title }}</td>
                            <td class="px-3 py-2">{{ \App\Models\AiGeneratedReport::reportTypes()[$report->report_type] ?? $report->report_type }}</td>
                            <td class="px-3 py-2">{{ $report->status }}</td>
                            <td class="px-3 py-2">{{ $report->user?->name ?? '-' }}</td>
                            <td class="px-3 py-2"><a class="btn btn-outline" href="{{ route('workspace.ai-reports.show', $report) }}">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="6">Aucun rapport IA.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $reports->links() }}</div>
    </section>
</div>
@endsection
