@extends('layouts.workspace')

@section('content')
@php
    $visibleFields = collect($importColumns ?? [])->mapWithKeys(fn (string $field): array => [$field => $field])->all();
    $visibleFieldKeys = array_keys($visibleFields);
    $longFields = [
        'libelle_axe',
        'libelle_objectif_strategique',
        'libelle_objectif_operationnel',
        'libelle_action',
        'justificatif_attendu',
        'sous_actions',
        'risque',
        'mesures_preventives',
        'ressources_materielles',
        'main_oeuvre',
        'autres_ressources',
        'nature_financement',
        'champ_difficulte',
    ];
    $tableColspan = count($visibleFields) + 4;
@endphp
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Previsualisation PTA</h1>
                <p class="text-sm text-slate-500">{{ $batch->original_filename }} · statut {{ $batch->status }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-outline" href="{{ route('workspace.ai-imports.pta.index') }}">Retour</a>
                @if ($batch->generated_excel_path)
                    <a class="btn btn-secondary" href="{{ route('workspace.ai-imports.pta.excel', $batch) }}">Excel IMPORT_GLOBAL</a>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif
        @if ($batch->error_message)
            <div class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">{{ $batch->error_message }}</div>
        @endif

        <div class="mb-5 grid gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4"><p class="text-xs font-bold uppercase text-slate-500">Score</p><p class="mt-2 text-2xl font-extrabold">{{ $batch->confidence_score ?? '-' }}</p></div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4"><p class="text-xs font-bold uppercase text-slate-500">Lignes</p><p class="mt-2 text-2xl font-extrabold">{{ $stats['total'] }}</p></div>
            <div class="rounded-lg border border-green-200 bg-green-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Valides</p><p class="mt-2 text-2xl font-extrabold">{{ $stats['valid'] }}</p></div>
            <div class="rounded-lg border border-red-200 bg-red-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Invalides</p><p class="mt-2 text-2xl font-extrabold">{{ $stats['invalid'] }}</p></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Ignorees</p><p class="mt-2 text-2xl font-extrabold">{{ $stats['ignored'] }}</p></div>
        </div>

        <div class="mb-5 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('workspace.ai-imports.pta.analyze', $batch) }}">
                @csrf
                <button class="btn btn-secondary" type="submit">Analyser avec IA</button>
            </form>
            <form method="POST" action="{{ route('workspace.ai-imports.pta.validate', $batch) }}">
                @csrf
                <button class="btn btn-outline" type="submit">Valider</button>
            </form>
            @if ($stats['invalid'] === 0 && $stats['total'] > 0 && $batch->status !== 'imported')
                <form method="POST" action="{{ route('workspace.ai-imports.pta.import', $batch) }}" data-confirm-message="Confirmer l'import final des lignes validees ?" data-confirm-tone="warning" data-confirm-label="Importer">
                    @csrf
                    <button class="btn btn-primary" type="submit">Importer</button>
                </form>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Ligne</th>
                        @foreach ($visibleFields as $label)
                            <th class="px-3 py-2">{{ $label }}</th>
                        @endforeach
                        <th class="px-3 py-2">Statut</th>
                        <th class="px-3 py-2">Erreurs</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($batch->rows as $row)
                        @php($payload = $row->normalized_payload ?? [])
                        <tr>
                            <form method="POST" action="{{ route('workspace.ai-imports.pta.rows.update', [$batch, $row]) }}">
                                @csrf
                                @method('PATCH')
                                <td class="px-3 py-2 font-semibold">{{ $row->row_number }}</td>
                                @foreach ($visibleFields as $field => $label)
                                    @php($fieldValue = old('normalized.'.$field, $payload[$field] ?? ''))
                                    @php($fieldValue = is_array($fieldValue) ? implode(' | ', $fieldValue) : $fieldValue)
                                    <td class="px-3 py-2">
                                        @if ($field === 'type_action')
                                            <select name="normalized[{{ $field }}]" class="w-24 rounded border border-slate-200 px-2 py-1 text-xs">
                                                <option value=""></option>
                                                @foreach (['Q' => 'Q', 'NQ' => 'NQ', 'M' => 'M'] as $value => $optionLabel)
                                                    <option value="{{ $value }}" @selected((string) $fieldValue === $value)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($field === 'seuil_mode')
                                            <select name="normalized[{{ $field }}]" class="w-32 rounded border border-slate-200 px-2 py-1 text-xs">
                                                <option value=""></option>
                                                @foreach (['unique' => 'unique', 'trimestriel' => 'trimestriel'] as $value => $optionLabel)
                                                    <option value="{{ $value }}" @selected((string) $fieldValue === $value)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($field === 'niveau_risque')
                                            <select name="normalized[{{ $field }}]" class="w-28 rounded border border-slate-200 px-2 py-1 text-xs">
                                                <option value=""></option>
                                                @foreach (['faible' => 'faible', 'modere' => 'modere', 'eleve' => 'eleve', 'critique' => 'critique'] as $value => $optionLabel)
                                                    <option value="{{ $value }}" @selected((string) $fieldValue === $value)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @elseif (in_array($field, ['financement', 'commentaire_obligatoire', 'champ_difficulte'], true))
                                            <select name="normalized[{{ $field }}]" class="w-24 rounded border border-slate-200 px-2 py-1 text-xs">
                                                <option value=""></option>
                                                @foreach (['0' => '0', '1' => '1'] as $value => $optionLabel)
                                                    <option value="{{ $value }}" @selected((string) $fieldValue === $value)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @elseif (in_array($field, $longFields, true))
                                            <textarea name="normalized[{{ $field }}]" rows="3" class="w-56 rounded border border-slate-200 px-2 py-1 text-xs">{{ $fieldValue }}</textarea>
                                        @else
                                            <input name="normalized[{{ $field }}]" value="{{ $fieldValue }}" class="w-36 rounded border border-slate-200 px-2 py-1 text-xs">
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2">{{ $row->status }}</td>
                                <td class="px-3 py-2 text-xs text-red-700">
                                    {{ implode(' | ', $row->validation_errors['errors'] ?? []) }}
                                    @if (! empty($row->validation_errors['warnings'] ?? []))
                                        <span class="block text-amber-700">{{ implode(' | ', $row->validation_errors['warnings']) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @foreach ($fields as $field)
                                        @if (! in_array($field, $visibleFieldKeys, true))
                                            @php($hiddenValue = $payload[$field] ?? '')
                                            @php($hiddenValue = is_array($hiddenValue) ? implode(' | ', $hiddenValue) : $hiddenValue)
                                            <input type="hidden" name="normalized[{{ $field }}]" value="{{ $hiddenValue }}">
                                        @endif
                                    @endforeach
                                    <div class="flex flex-wrap gap-2">
                                        <button class="btn btn-outline" name="action" value="save" type="submit">Corriger</button>
                                        <button class="btn btn-secondary" name="action" value="ignore" type="submit">Ignorer</button>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center text-slate-500" colspan="{{ $tableColspan }}">Aucune ligne extraite.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
