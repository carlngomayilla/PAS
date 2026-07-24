@extends('layouts.workspace')

@section('title', 'Référentiel - Utilisateurs')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $scopeCounts = is_array($summary['scope_counts'] ?? null) ? $summary['scope_counts'] : [];
        $healthByUserId = is_array($healthByUserId ?? null) ? $healthByUserId : [];
        $roleLabels = app(\App\Services\RoleRegistryService::class)->labels();
        $activeState = (string) ($filters['account_state'] ?? '');
        $stateTabs = [
            ['code' => '', 'label' => 'Tous', 'count' => (int) ($scopeCounts['all'] ?? 0)],
            ['code' => 'active', 'label' => 'Actifs', 'count' => (int) ($scopeCounts['active'] ?? 0)],
            ['code' => 'inactive', 'label' => 'Inactifs', 'count' => (int) ($scopeCounts['inactive'] ?? 0)],
            ['code' => 'suspended', 'label' => 'Suspendus', 'count' => (int) ($scopeCounts['suspended'] ?? 0)],
            ['code' => 'renewal', 'label' => 'Mot de passe à renouveler', 'count' => (int) ($scopeCounts['renewal'] ?? 0)],
            ['code' => 'unscoped', 'label' => 'Rattachement incomplet', 'count' => (int) ($scopeCounts['unscoped'] ?? 0)],
        ];
        $exportFilters = collect($filters)->except('page')->filter(static fn ($value): bool => $value !== null && $value !== '')->all();
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title class="mb-4 app-screen-block" eyebrow="Administration organisationnelle" title="Référentiel - Utilisateurs" subtitle="Annuaire, rattachements métier et santé des comptes.">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.referentiel.utilisateurs.export.csv', $exportFilters) }}">Exporter CSV</a>
                @if ($canWrite)
                    <a class="btn btn-primary min-h-10 px-4" href="{{ route('workspace.referentiel.utilisateurs.create') }}">Nouvel utilisateur</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        @include('workspace.referentiel.partials.temporary-credentials')
        @include('workspace.referentiel.partials.navigation', ['active' => 'utilisateurs'])

        <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse des comptes">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(145px,1fr))]">
                @foreach ([
                    ['label' => 'Comptes visibles', 'value' => (int) ($scopeCounts['all'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-200 dark:text-sky-100'],
                    ['label' => 'Actifs', 'value' => (int) ($scopeCounts['active'] ?? 0), 'tone' => 'border-[#5D8E25] text-[#4B741E] dark:border-lime-400 dark:text-lime-200'],
                    ['label' => 'À régulariser', 'value' => (int) (($scopeCounts['renewal'] ?? 0) + ($scopeCounts['unscoped'] ?? 0)), 'tone' => 'border-[#F9B13C] text-[#9A5B00] dark:border-amber-300 dark:text-amber-200'],
                    ['label' => 'Directions', 'value' => (int) ($summary['directions_total'] ?? 0), 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200'],
                    ['label' => 'Services', 'value' => (int) ($summary['services_total'] ?? 0), 'tone' => 'border-violet-400 text-violet-700 dark:border-violet-400 dark:text-violet-200'],
                ] as $metric)
                    <div class="border-l-4 pl-3 {{ $metric['tone'] }}">
                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                        <strong class="mt-1 block text-2xl">{{ number_format($metric['value'], 0, ',', ' ') }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-screen-block" aria-labelledby="users-list-title">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="États des comptes">
                @foreach ($stateTabs as $stateTab)
                    @php
                        $stateUrl = route('workspace.referentiel.utilisateurs.index', array_merge(
                            request()->except(['page', 'account_state', 'is_active']),
                            $stateTab['code'] !== '' ? ['account_state' => $stateTab['code']] : []
                        ));
                    @endphp
                    <a href="{{ $stateUrl }}" @if ($activeState === $stateTab['code']) aria-current="page" @endif class="inline-flex min-h-10 shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition {{ $activeState === $stateTab['code'] ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}">
                        {{ $stateTab['label'] }}
                        <span class="min-w-6 rounded-full bg-slate-100 px-1.5 py-0.5 text-center text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ number_format($stateTab['count'], 0, ',', ' ') }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('workspace.referentiel.utilisateurs.index') }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-6 dark:border-slate-700 dark:bg-slate-900/70">
                @if ($activeState !== '')<input type="hidden" name="account_state" value="{{ $activeState }}">@endif
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 md:col-span-2 dark:text-slate-400">
                    Recherche
                    <input name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Nom, email, matricule ou fonction..." class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Rôle
                    <select name="role" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous</option>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $roleLabels[$role] ?? $role }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Direction
                    <select id="direction_id" name="direction_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Service
                    <select id="service_id" name="service_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected((int) ($filters['service_id'] ?? 0) === (int) $service->id)>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Tri
                    <select name="sort" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Nom</option>
                        <option value="recent" @selected(($filters['sort'] ?? '') === 'recent')>Plus récents</option>
                        <option value="role" @selected(($filters['sort'] ?? '') === 'role')>Rôle</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Par page
                    <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        @foreach ([15, 30, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 30) === $perPage)>{{ $perPage }}</option>@endforeach
                    </select>
                </label>
                <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-6">
                    <button class="btn btn-primary min-h-10 px-4" type="submit">Filtrer</button>
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.referentiel.utilisateurs.index', $activeState !== '' ? ['account_state' => $activeState] : []) }}">Réinitialiser</a>
                </div>
            </form>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                <div><h2 id="users-list-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">Annuaire des utilisateurs</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ number_format($rows->total(), 0, ',', ' ') }} résultat(s) dans votre périmètre.</p></div>
                @if ($rows->total() > 0)<span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $rows->firstItem() }}-{{ $rows->lastItem() }} sur {{ $rows->total() }}</span>@endif
            </div>

            <div class="app-table-wrapper mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <table class="app-table data-table min-w-[1280px]">
                    <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Rattachement</th><th>Fonction</th><th>Coordonnées</th><th>Santé du compte</th>@if ($canWrite || ($canRequestUserDeletion ?? false))<th>Actions</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php $health = $healthByUserId[(int) $row->id] ?? ['label' => $row->is_active ? 'Actif' : 'Inactif', 'tone' => $row->is_active ? 'success' : 'danger']; @endphp
                            <tr>
                                <td>
                                    <div class="flex min-w-[250px] items-center gap-3">
                                        @if ($row->profile_photo_url)<img src="{{ $row->profile_photo_url }}" alt="Photo de {{ $row->name }}" class="h-10 w-10 rounded-full object-cover ring-2 ring-white shadow-sm dark:ring-slate-700">@else<span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#3996D3] text-xs font-bold text-white">{{ $row->profile_initials }}</span>@endif
                                        <div class="min-w-0"><strong class="block truncate text-slate-900 dark:text-slate-100">{{ $row->name }}</strong><span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $row->email }}</span></div>
                                    </div>
                                </td>
                                <td><div class="min-w-[180px]"><span class="anbg-badge anbg-badge-neutral">{{ $row->roleLabel() }}</span><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $row->effectiveRoleCode() }}</span></div></td>
                                <td><div class="min-w-[230px]"><strong class="text-slate-800 dark:text-slate-200">{{ $row->direction?->code ?? 'Périmètre global' }}</strong><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $row->service?->code ? $row->service->code.' - '.$row->service->libelle : ($row->uniteDg?->code ? $row->uniteDg->code.' - '.$row->uniteDg->libelle : $row->profileScopeLabel()) }}</span></div></td>
                                <td><div class="min-w-[180px]"><span>{{ $row->agent_fonction ?: '-' }}</span>@if ($row->agent_matricule)<span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Matricule {{ $row->agent_matricule }}</span>@endif</div></td>
                                <td><div class="min-w-[180px]"><span>{{ $row->agent_telephone ?: '-' }}</span><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">#{{ $row->id }}</span></div></td>
                                <td><span class="anbg-badge anbg-badge-{{ $health['tone'] }}">{{ $health['label'] }}</span>@if ($row->isSuspended() && $row->suspended_until)<span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">jusqu’au {{ $row->suspended_until->format('d/m/Y') }}</span>@endif</td>
                                @if ($canWrite || ($canRequestUserDeletion ?? false))
                                    <td>
                                        <div class="flex min-w-[190px] flex-wrap items-start gap-2">
                                            @if ($canWrite)<a class="btn btn-secondary btn-sm" href="{{ route('workspace.referentiel.utilisateurs.edit', $row) }}">Modifier</a>@endif
                                            @if (($canDeleteUsers ?? false) && (int) auth()->id() !== (int) $row->id)
                                                <details class="relative"><summary class="btn btn-danger btn-sm cursor-pointer list-none">Supprimer</summary><form method="POST" action="{{ route('workspace.referentiel.utilisateurs.destroy', $row) }}" class="absolute right-0 z-20 mt-2 grid w-72 gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900">@csrf @method('DELETE')<label class="grid gap-1 text-xs font-bold uppercase text-slate-500">Motif<input name="motif" type="text" minlength="5" maxlength="1000" required class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm font-normal normal-case dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label><button class="btn btn-danger btn-sm" type="submit">Confirmer</button></form></details>
                                            @elseif (($canRequestUserDeletion ?? false) && (int) auth()->id() !== (int) $row->id)
                                                <details class="relative"><summary class="btn btn-danger btn-sm cursor-pointer list-none">Demander suppression</summary><form method="POST" action="{{ route('workspace.referentiel.utilisateurs.deletion-requests.store', $row) }}" class="absolute right-0 z-20 mt-2 grid w-72 gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900">@csrf<label class="grid gap-1 text-xs font-bold uppercase text-slate-500">Motif<input name="motif" type="text" minlength="5" maxlength="1000" required class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm font-normal normal-case dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label><button class="btn btn-danger btn-sm" type="submit">Transmettre</button></form></details>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ ($canWrite || ($canRequestUserDeletion ?? false)) ? 7 : 6 }}"><x-ui.empty-state title="Aucun utilisateur trouvé" message="Aucun compte ne correspond aux filtres courants." icon="users" tone="info" class="my-4" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pagination mt-4">{{ $rows->links() }}</div>
        </section>
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var directionInput = document.getElementById('direction_id');
            var serviceInput = document.getElementById('service_id');
            if (!directionInput || !serviceInput) return;
            function syncServices() {
                var direction = String(directionInput.value || '');
                var selected = String(serviceInput.value || '');
                var selectedVisible = false;
                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    var visible = index === 0 || direction === '' || String(option.getAttribute('data-direction-id') || '') === direction;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && option.value === selected) selectedVisible = true;
                });
                if (selected && !selectedVisible) serviceInput.value = '';
            }
            directionInput.addEventListener('change', syncServices);
            syncServices();
        })();
    </script>
@endpush
