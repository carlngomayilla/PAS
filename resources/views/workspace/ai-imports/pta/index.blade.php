@extends('layouts.workspace')

@section('content')
@php
    $total = $batches->total();
    $imported = \App\Models\AiImportBatch::query()->where('status', 'imported')->count();
    $failed = \App\Models\AiImportBatch::query()->where('status', 'failed')->count();
@endphp
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">IA & Imports PTA</h1>
                <p class="text-sm text-slate-500">Extraction, controle humain et import final des fichiers PTA.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('workspace.ai-imports.history') }}">Historique</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mb-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Imports IA</p>
                <p class="mt-2 text-3xl font-extrabold text-[#1c203d]">{{ $total }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Imports finalises</p>
                <p class="mt-2 text-3xl font-extrabold text-[#3996d3]">{{ $imported }}</p>
            </div>
            <div class="rounded-lg border border-[#fde7c3] bg-[#fffaf2] p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Echecs</p>
                <p class="mt-2 text-3xl font-extrabold text-[#c77700]">{{ $failed }}</p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.ai-imports.pta.upload') }}" class="mb-6 rounded-lg border border-[#d8ecf8] bg-white p-4">
            @csrf
            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr_0.8fr_0.8fr_auto] lg:items-end">
                <div>
                    <label class="form-label" for="file">Fichier PTA</label>
                    <input id="file" name="file" type="file" accept=".pdf,.doc,.docx,.xlsx,.csv,.png,.jpg,.jpeg" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                </div>
                <div>
                    <label class="form-label" for="detected_year">Exercice</label>
                    <select id="detected_year" name="detected_year" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Auto</option>
                        @foreach ($exercices as $exercice)
                            <option value="{{ $exercice->annee }}">{{ $exercice->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="detected_direction">Direction</label>
                    <select id="detected_direction" name="detected_direction" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Auto</option>
                        @foreach ($directions as $direction)
                            <option value="{{ $direction->libelle }}">{{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="detected_service">Service</label>
                    <select id="detected_service" name="detected_service" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Auto</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->libelle }}">{{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">Charger</button>
            </div>
            <p class="mt-3 text-xs font-semibold text-slate-500">Validation humaine obligatoire avant tout import final.</p>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Fichier</th>
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Score</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($batches as $batch)
                        <tr>
                            <td class="px-3 py-2">{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 font-semibold text-[#1c203d]">{{ $batch->original_filename }}</td>
                            <td class="px-3 py-2">
                                <span>{{ $batch->status }}</span>
                                @if ($batch->error_message)
                                    <span class="mt-1 block max-w-xs text-xs font-semibold text-amber-700">{{ $batch->error_message }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $batch->confidence_score ?? '-' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-outline" href="{{ route('workspace.ai-imports.pta.preview', $batch) }}">Ouvrir</a>
                                    @if (in_array($batch->status, ['uploaded', 'failed'], true))
                                        <form method="POST" action="{{ route('workspace.ai-imports.pta.analyze', $batch) }}">
                                            @csrf
                                            <button class="btn btn-secondary" type="submit">Analyser</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="5">Aucun import IA PTA.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $batches->links() }}</div>
    </section>
</div>
@endsection
