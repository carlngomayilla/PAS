@extends('layouts.workspace')

@section('title', 'Référentiel - Services')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $exportFilters = collect($filters)->except('page')->filter(static fn ($value): bool => $value !== null && $value !== '')->all();
        $metrics = [
            ['label' => 'Services', 'value' => (int) ($summary['total'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-200 dark:text-sky-100'],
            ['label' => 'Actifs', 'value' => (int) ($summary['actifs'] ?? 0), 'tone' => 'border-[#5D8E25] text-[#4B741E] dark:border-lime-400 dark:text-lime-200'],
            ['label' => 'Inactifs', 'value' => (int) ($summary['inactifs'] ?? 0), 'tone' => 'border-[#B42318] text-[#9B1C13] dark:border-red-400 dark:text-red-200'],
            ['label' => 'Directions couvertes', 'value' => (int) ($summary['directions_total'] ?? 0), 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200'],
            ['label' => 'Utilisateurs', 'value' => (int) ($summary['users_total'] ?? 0), 'tone' => 'border-violet-400 text-violet-700 dark:border-violet-400 dark:text-violet-200'],
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title class="mb-4 app-screen-block" eyebrow="Administration organisationnelle" title="Référentiel - Services" subtitle="Unités opérationnelles, rattachements et capacité de suivi.">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.referentiel.services.export.csv', $exportFilters) }}">Exporter CSV</a>
                @if ($canWrite)
                    <a class="btn btn-primary min-h-10 px-4" href="{{ route('workspace.referentiel.services.create') }}">Nouveau service</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        @include('workspace.referentiel.partials.navigation', ['active' => 'services'])

        <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse des services">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(145px,1fr))]">
                @foreach ($metrics as $metric)
                    <div class="border-l-4 pl-3 {{ $metric['tone'] }}">
                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                        <strong class="mt-1 block text-2xl">{{ number_format($metric['value'], 0, ',', ' ') }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-screen-block" aria-labelledby="services-list-title">
            <form method="GET" action="{{ route('workspace.referentiel.services.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-6 dark:border-slate-700 dark:bg-slate-900/70" data-auto-filter-form>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 md:col-span-2 dark:text-slate-400">
                    Recherche
                    <input name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Code ou libellé..." class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Direction
                    <select name="direction_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    État
                    <select name="actif" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="" @selected(($filters['actif'] ?? '') === '')>Tous</option>
                        <option value="1" @selected(($filters['actif'] ?? '') === '1')>Actifs</option>
                        <option value="0" @selected(($filters['actif'] ?? '') === '0')>Inactifs</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Tri
                    <select name="sort" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="code" @selected(($filters['sort'] ?? 'code') === 'code')>Direction et code</option>
                        <option value="libelle" @selected(($filters['sort'] ?? '') === 'libelle')>Libellé</option>
                        <option value="size" @selected(($filters['sort'] ?? '') === 'size')>Effectif</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Par page
                    <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        @foreach ([15, 30, 50, 100] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 30) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-6">
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.referentiel.services.index') }}">Réinitialiser</a>
                </div>
            </form>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                <div><h2 id="services-list-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">Services visibles</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ number_format($rows->total(), 0, ',', ' ') }} résultat(s) dans votre périmètre.</p></div>
                @if ($rows->total() > 0)<span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $rows->firstItem() }}-{{ $rows->lastItem() }} sur {{ $rows->total() }}</span>@endif
            </div>

            <div class="app-table-wrapper mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <table class="app-table data-table min-w-[900px]">
                    <thead><tr><th>Service</th><th>Direction</th><th>État</th><th>Utilisateurs</th><th>PTA</th>@if ($canWrite)<th>Actions</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><div class="min-w-[240px]"><div class="flex items-center gap-2"><span class="anbg-badge anbg-badge-neutral">{{ $row->code }}</span><strong class="text-slate-900 dark:text-slate-100">{{ $row->libelle }}</strong></div><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Identifiant #{{ $row->id }}</span></div></td>
                                <td><div class="min-w-[210px]"><strong class="text-slate-800 dark:text-slate-200">{{ $row->direction?->code ?? '-' }}</strong><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $row->direction?->libelle ?? 'Direction non définie' }}</span></div></td>
                                <td><span class="anbg-badge {{ $row->actif ? 'anbg-badge-success' : 'anbg-badge-danger' }}">{{ $row->actif ? 'Actif' : 'Inactif' }}</span></td>
                                <td>{{ number_format($row->users_count, 0, ',', ' ') }}</td>
                                <td>{{ number_format($row->ptas_count, 0, ',', ' ') }}</td>
                                @if ($canWrite)
                                    <td><div class="flex flex-wrap gap-2"><a class="btn btn-secondary btn-sm" href="{{ route('workspace.referentiel.services.edit', $row) }}">Modifier</a><form method="POST" action="{{ route('workspace.referentiel.services.destroy', $row) }}" data-confirm-message="Supprimer ce service ?" data-confirm-tone="danger" data-confirm-label="Supprimer">@csrf @method('DELETE')<button class="btn btn-danger btn-sm" type="submit">Supprimer</button></form></div></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canWrite ? 6 : 5 }}"><x-ui.empty-state title="Aucun service trouvé" message="Aucun service ne correspond aux filtres courants." icon="filter" tone="info" class="my-4" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pagination mt-4">{{ $rows->links() }}</div>
        </section>
    </div>
@endsection
