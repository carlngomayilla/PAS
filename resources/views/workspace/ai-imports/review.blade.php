@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Revue des donnees extraites</h1>
                <p class="text-sm text-slate-500">{{ $session->file_name }} - {{ $session->status }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-outline" href="{{ route('workspace.ai-imports.index') }}">Retour</a>
                @if ($session->generated_excel_path)
                    <a class="btn btn-outline" href="{{ route('workspace.ai-imports.excel', $session) }}">Excel</a>
                @endif
                <form method="POST" action="{{ route('workspace.ai-imports.validate', $session) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Valider</button>
                </form>
                <form method="POST" action="{{ route('workspace.ai-imports.import', $session) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Importer</button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mb-5 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Detectees</p>
                <p class="mt-2 text-3xl font-extrabold text-[#1c203d]">{{ $session->total_rows_detected }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Pretes</p>
                <p class="mt-2 text-3xl font-extrabold text-[#3996d3]">{{ $session->total_rows_validated }}</p>
            </div>
            <div class="rounded-lg border border-[#fde7c3] bg-[#fffaf2] p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Controles</p>
                <p class="mt-2 text-3xl font-extrabold text-[#c77700]">{{ $session->total_errors }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Tokens</p>
                <p class="mt-2 text-3xl font-extrabold text-[#1c203d]">{{ $session->input_tokens + $session->output_tokens }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Action</th>
                        <th class="px-3 py-2">Rattachement</th>
                        <th class="px-3 py-2">Parametrage</th>
                        <th class="px-3 py-2">Dates</th>
                        <th class="px-3 py-2">Correction</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="align-top">
                            <td class="px-3 py-3 font-semibold">{{ $row->statut_import }}</td>
                            <td class="px-3 py-3">
                                <p class="font-semibold text-[#1c203d]">{{ $row->action ?: '-' }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $row->objectif_operationnel ?: $row->objectif_strategique ?: $row->axe }}</p>
                                @if ($row->errors_json)
                                    <ul class="mt-2 text-xs font-semibold text-red-700">
                                        @foreach ($row->errors_json as $issue)
                                            <li>{{ $issue['message'] ?? '' }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="px-3 py-3">{{ $row->direction ?: '-' }}<br><span class="text-xs text-slate-500">{{ $row->service ?: '-' }}</span></td>
                            <td class="px-3 py-3">{{ $row->type_indicateur ?: '-' }}<br><span class="text-xs text-slate-500">{{ $row->quantite_a_realiser ?: $row->livrable_attendu ?: $row->cible }}</span></td>
                            <td class="px-3 py-3">{{ $row->date_debut?->format('d/m/Y') ?: '-' }}<br><span class="text-xs text-slate-500">{{ $row->date_fin?->format('d/m/Y') ?: '-' }}</span></td>
                            <td class="px-3 py-3">
                                <form method="POST" action="{{ route('workspace.ai-imports.rows.update', [$session, $row]) }}" class="grid min-w-[520px] gap-2 lg:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <input name="libelle_action" value="{{ old('libelle_action', $row->action) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Action">
                                    <input name="direction" value="{{ old('direction', $row->direction) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Direction">
                                    <input name="service" value="{{ old('service', $row->service) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Service">
                                    <select name="type_indicateur" class="rounded border border-[#d8ecf8] p-2 text-xs">
                                        @foreach (['quantitatif', 'non_quantitatif', 'mixte'] as $type)
                                            <option value="{{ $type }}" @selected($row->type_indicateur === $type)>{{ $type }}</option>
                                        @endforeach
                                    </select>
                                    <input name="quantite_a_realiser" value="{{ old('quantite_a_realiser', $row->quantite_a_realiser) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Quantite">
                                    <input name="unite_mesure" value="{{ old('unite_mesure', $row->unite_mesure) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Unite">
                                    <input name="livrable_attendu" value="{{ old('livrable_attendu', $row->livrable_attendu) }}" class="rounded border border-[#d8ecf8] p-2 text-xs" placeholder="Livrable">
                                    <input name="date_fin" type="date" value="{{ old('date_fin', $row->date_fin?->toDateString()) }}" class="rounded border border-[#d8ecf8] p-2 text-xs">
                                    <button class="btn btn-secondary lg:col-span-1" type="submit" name="action" value="save">Enregistrer</button>
                                    <button class="btn btn-outline lg:col-span-1" type="submit" name="action" value="reject">Rejeter</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="6">Aucune ligne detectee.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    </section>
</div>
@endsection
