@extends('layouts.workspace')

@section('content')
@php
    $totalImports = $imports->total();
    $lastImport = $imports->first();
    $successfulImports = \App\Models\PlanningImport::query()->where('status', 'imported')->count();
    $importsWithErrors = \App\Models\PlanningImport::query()->where('error_rows', '>', 0)->count();
@endphp
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Imports Excel</h1>
                <p class="text-sm text-slate-500">Chargez en une operation le PAS, les axes, objectifs, PAO, PTA et actions planifiees.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-secondary" href="{{ route('workspace.imports.template') }}">Telecharger le modele Excel</a>
                <a class="btn btn-primary" href="{{ route('workspace.imports.create') }}">Nouvel import</a>
            </div>
        </div>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-[#d8ecf8] bg-[#f8fbfe] p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Imports lances</p>
                <p class="mt-2 text-3xl font-extrabold text-[#1c203d]">{{ $totalImports }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Imports finalises</p>
                <p class="mt-2 text-3xl font-extrabold text-[#3996d3]">{{ $successfulImports }}</p>
            </div>
            <div class="rounded-lg border border-[#fde7c3] bg-[#fffaf2] p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Avec erreurs</p>
                <p class="mt-2 text-3xl font-extrabold text-[#c77700]">{{ $importsWithErrors }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Format attendu</p>
                <p class="mt-2 text-lg font-extrabold text-[#1c203d]">1 feuille / 1 action par ligne</p>
            </div>
        </div>

        <div class="mb-5 grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <h2 class="mb-3 text-base font-extrabold text-[#1c203d]">Importer un fichier</h2>

                @if ($errors->any())
                    <div class="mb-3 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.imports.preview') }}" class="mb-4 rounded-lg border-2 border-dashed border-[#3996d3]/40 bg-[#f8fbfe] p-4">
                    @csrf
                    <label for="file" class="mb-2 block text-sm font-extrabold text-[#1c203d]">Fichier d'importation Excel</label>
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                        <input id="file" name="file" type="file" accept=".xlsx,.csv" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <button class="btn btn-primary shrink-0" type="submit">Verifier le fichier</button>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Formats acceptes : .xlsx ou .csv. La verification affiche une previsualisation avant toute insertion.</p>
                    @error('file') <p class="field-error">{{ $message }}</p> @enderror
                </form>

                <div class="grid gap-3 sm:grid-cols-3">
                    <a href="{{ route('workspace.imports.template') }}" class="rounded-lg border border-[#d8ecf8] p-3 transition hover:border-[#3996d3] hover:bg-[#f8fbfe]">
                        <span class="block text-sm font-extrabold text-[#3996d3]">1. Modele</span>
                        <span class="mt-1 block text-xs text-slate-500">Telecharger le fichier avec les 27 colonnes attendues.</span>
                    </a>
                    <label for="file" class="cursor-pointer rounded-lg border border-[#d8ecf8] p-3 transition hover:border-[#3996d3] hover:bg-[#f8fbfe]">
                        <span class="block text-sm font-extrabold text-[#3996d3]">2. Verification</span>
                        <span class="mt-1 block text-xs text-slate-500">Choisir le fichier ci-dessus et controler les lignes.</span>
                    </label>
                    <div class="rounded-lg border border-[#d8ecf8] p-3">
                        <span class="block text-sm font-extrabold text-[#3996d3]">3. Confirmation</span>
                        <span class="mt-1 block text-xs text-slate-500">Choisir le mode puis inserer en transaction.</span>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[#d8ecf8] bg-[#f8fbfe] p-4">
                <h2 class="mb-3 text-base font-extrabold text-[#1c203d]">Dernier import</h2>
                @if ($lastImport)
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs font-bold uppercase text-slate-500">Fichier</dt>
                            <dd class="font-semibold text-[#1c203d]">{{ $lastImport->filename }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase text-slate-500">Statut</dt>
                            <dd class="font-semibold text-[#1c203d]">{{ str_replace('_', ' ', $lastImport->status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase text-slate-500">Lignes</dt>
                            <dd class="font-semibold text-[#1c203d]">{{ $lastImport->valid_rows }} valides / {{ $lastImport->error_rows }} erreurs</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase text-slate-500">Date</dt>
                            <dd class="font-semibold text-[#1c203d]">{{ $lastImport->created_at?->format('d/m/Y H:i') }}</dd>
                        </div>
                    </dl>
                    <a class="btn btn-outline mt-4" href="{{ route('workspace.imports.show', $lastImport) }}">Ouvrir</a>
                @else
                    <p class="text-sm text-slate-600">Aucun fichier n'a encore ete importe.</p>
                    <a class="btn btn-primary mt-4" href="{{ route('workspace.imports.create') }}">Choisir un fichier</a>
                @endif
            </div>
        </div>

        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Fichier</th>
                        <th>Importateur</th>
                        <th>Mode</th>
                        <th>Lignes</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($imports as $import)
                        <tr>
                            <td>{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $import->filename }}</td>
                            <td>{{ $import->user?->name ?? '-' }}</td>
                            <td>{{ str_replace('_', ' ', $import->mode) }}</td>
                            <td>{{ $import->valid_rows }} valides / {{ $import->error_rows }} erreurs</td>
                            <td><span class="anbg-badge anbg-badge-info px-2 py-0.5 text-xs">{{ str_replace('_', ' ', $import->status) }}</span></td>
                            <td class="text-right">
                                <a class="btn btn-outline" href="{{ route('workspace.imports.show', $import) }}">Voir</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="py-6 text-center">
                                    <p class="font-semibold text-[#1c203d]">Aucun import enregistre.</p>
                                    <p class="mt-1 text-sm text-slate-500">Commencez par telecharger le modele, puis lancez un nouvel import.</p>
                                    <div class="mt-3 flex justify-center gap-2">
                                        <a class="btn btn-secondary" href="{{ route('workspace.imports.template') }}">Telecharger le modele</a>
                                        <a class="btn btn-primary" href="{{ route('workspace.imports.create') }}">Nouvel import</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $imports->links() }}</div>
    </section>
</div>
@endsection
