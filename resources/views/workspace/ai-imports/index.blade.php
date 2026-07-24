@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Import intelligent PAS / PAO / PTA</h1>
                <p class="text-sm text-slate-500">Depot, analyse IA, revue humaine et fichier Excel institutionnel.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('workspace.ai-usage.index') }}">Consommation IA</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.ai-imports.upload') }}" class="mb-6 rounded-lg border border-[#d8ecf8] bg-white p-4">
            @csrf
            <div class="grid gap-4 lg:grid-cols-[1.5fr_0.6fr_auto] lg:items-end">
                <div>
                    <label class="form-label" for="file">Fichier source</label>
                    <input id="file" name="file" type="file" accept=".pdf,.xlsx,.csv" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                </div>
                <div>
                    <label class="form-label" for="document_type">Type</label>
                    <select id="document_type" name="document_type" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Auto</option>
                        <option value="PAS">PAS</option>
                        <option value="PAO">PAO</option>
                        <option value="PTA">PTA</option>
                        <option value="MIXTE">Mixte</option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">Analyser</button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Fichier</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Lignes</th>
                        <th class="px-3 py-2">Cout</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($sessions as $session)
                        <tr>
                            <td class="px-3 py-2">{{ $session->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 font-semibold text-[#1c203d]">{{ $session->file_name }}</td>
                            <td class="px-3 py-2">{{ $session->document_type ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $session->status }}</td>
                            <td class="px-3 py-2">{{ $session->total_rows_validated }} / {{ $session->total_rows_detected }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $session->total_cost_usd, 4) }} USD</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-outline" href="{{ route('workspace.ai-imports.review', $session) }}">Revoir</a>
                                    <form method="POST" action="{{ route('workspace.ai-imports.analyze', $session) }}">
                                        @csrf
                                        <button class="btn btn-secondary" type="submit">Relancer</button>
                                    </form>
                                    @if ($session->generated_excel_path)
                                        <a class="btn btn-outline" href="{{ route('workspace.ai-imports.excel', $session) }}">Excel</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="7">Aucune session IA.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $sessions->links() }}</div>
    </section>
</div>
@endsection
