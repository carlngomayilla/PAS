@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Reprise des executions historiques</h1>
                <p class="text-sm text-slate-500">Mettez a jour les actions deja executees, en cours ou non executees sans recreer le PTA.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-secondary" href="{{ route('workspace.imports.historical.template') }}">Telecharger le modele</a>
                <a class="btn btn-outline" href="{{ route('workspace.imports.historical.template', ['prefill' => 1]) }}">Pre-remplir avec les actions</a>
                <a class="btn btn-outline" href="{{ route('workspace.imports.index') }}">Imports de planification</a>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mb-5 grid gap-3 lg:grid-cols-3">
            <div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4">
                <p class="text-xs font-bold uppercase text-indigo-700">1. Identifier</p>
                <p class="mt-1 text-sm text-indigo-950">Utilisez le <strong>code_action</strong> existant. Le libelle ne sert pas de clé.</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4">
                <p class="text-xs font-bold uppercase text-emerald-700">2. Qualifier</p>
                <p class="mt-1 text-sm text-emerald-950">Renseignez le statut, la progression et les dates réelles du trimestre passé.</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50/60 p-4">
                <p class="text-xs font-bold uppercase text-amber-700">3. Justifier</p>
                <p class="mt-1 text-sm text-amber-950">Après import, déposez les pièces dans l’onglet Justificatifs de chaque action.</p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.imports.historical.preview') }}" class="mb-6 rounded-lg border-2 border-dashed border-indigo-300/60 bg-indigo-50/30 p-4">
            @csrf
            <label for="historical-file" class="mb-2 block text-sm font-extrabold text-[#1c203d]">Fichier Excel de reprise</label>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <input id="historical-file" name="file" type="file" accept=".xlsx,.csv" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                <button class="btn btn-primary shrink-0" type="submit">Verifier le fichier</button>
            </div>
            <p class="mt-2 text-xs text-slate-500">Une ligne par action existante. Pour la production, utilisez le modele pre-rempli afin de conserver les vrais codes. Aucun justificatif n’est envoyé dans Excel.</p>
        </form>

        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Fichier</th>
                        <th>Importateur</th>
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
                            <td>{{ $import->valid_rows }} valides / {{ $import->error_rows }} erreurs</td>
                            <td><span class="anbg-badge anbg-badge-info px-2 py-0.5 text-xs">{{ str_replace('_', ' ', $import->status) }}</span></td>
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a class="btn btn-outline" href="{{ route('workspace.imports.historical.show', $import) }}">Voir</a>
                                    <form method="POST" action="{{ route('workspace.imports.historical.destroy', $import) }}" data-confirm-message="Supprimer cet import ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">Aucune reprise historique enregistree.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $imports->links() }}</div>
    </section>
</div>
@endsection
