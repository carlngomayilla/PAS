@extends('layouts.workspace')

@section('title', 'Demandes de suppression')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $statusLabels = [
            'pending' => 'En attente',
            'approved' => 'Approuvée, à exécuter',
            'complement_requested' => 'Complément requis',
            'deleted' => 'Supprimée',
            'disabled' => 'Désactivée',
            'archived' => 'Archivée',
            'rejected' => 'Refusée',
            'corrected' => 'Corrigée',
        ];
        $statusClasses = [
            'pending' => 'anbg-badge anbg-badge-warning',
            'approved' => 'anbg-badge anbg-badge-success',
            'complement_requested' => 'anbg-badge anbg-badge-info',
            'deleted' => 'anbg-badge anbg-badge-success',
            'disabled' => 'anbg-badge anbg-badge-neutral',
            'archived' => 'anbg-badge anbg-badge-neutral',
            'rejected' => 'anbg-badge anbg-badge-danger',
            'corrected' => 'anbg-badge anbg-badge-success',
        ];
        $moduleLabels = [
            'referentiel_utilisateur' => 'Utilisateur',
            'access_control' => 'Rôles et permissions',
            'pas' => 'PAS',
            'pao' => 'PAO',
            'pta' => 'PTA',
            'action' => 'Action',
        ];
        $summaryCards = [
            ['label' => 'Total', 'value' => $summary['total'] ?? 0, 'status' => 'all'],
            ['label' => 'À instruire', 'value' => $summary['pending'] ?? 0, 'status' => 'pending'],
            ['label' => 'À exécuter', 'value' => $summary['awaiting_execution'] ?? 0, 'status' => 'approved'],
            ['label' => 'Exécutées', 'value' => $summary['approved'] ?? 0, 'status' => 'all'],
            ['label' => 'Compléments', 'value' => $summary['complement'] ?? 0, 'status' => 'complement_requested'],
            ['label' => 'Refusées', 'value' => $summary['rejected'] ?? 0, 'status' => 'rejected'],
        ];
    @endphp

    <div class="app-screen-flow">
        <section class="app-screen-block border-b border-slate-200 pb-4 dark:border-slate-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Référentiel / Gouvernance</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">
                        {{ $canReview ? 'File des demandes de suppression' : 'Mes demandes de suppression' }}
                    </h1>
                </div>
                @if ($canReview && ($summary['pending'] ?? 0) > 0)
                    <span class="anbg-badge anbg-badge-warning px-3 py-2">{{ $summary['pending'] }} décision{{ ($summary['pending'] ?? 0) > 1 ? 's' : '' }}</span>
                @endif
            </div>

            <nav class="mt-4 flex flex-wrap gap-2" aria-label="Gouvernance">
                @if (auth()->user()?->hasPermission('delegations.manage'))
                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.delegations.index') }}">Délégations</a>
                @endif
                <a class="btn btn-primary btn-sm" href="{{ route('workspace.deletion-requests.index') }}" aria-current="page">Demandes de suppression</a>
            </nav>
        </section>

        <section class="showcase-summary-grid app-screen-kpis">
            @foreach ($summaryCards as $card)
                <x-stat-card-link
                    :href="route('workspace.deletion-requests.index', ['status' => $card['status']])"
                    :label="$card['label']"
                    :value="$card['value']"
                    :meta="null"
                />
            @endforeach
        </section>

        <section class="ui-card app-screen-block">
            <form method="GET" action="{{ route('workspace.deletion-requests.index') }}" class="grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_repeat(4,minmax(9rem,auto))_auto] lg:items-end">
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Recherche
                    <input name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Élément, motif, demandeur">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Statut
                    <select name="status">
                        <option value="all">Tous</option>
                        @foreach ($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Module
                    <select name="module">
                        <option value="all">Tous</option>
                        @foreach ($moduleLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['module'] ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Tri
                    <select name="sort">
                        @if ($canReview)
                            <option value="pending_first" @selected(($filters['sort'] ?? '') === 'pending_first')>À traiter d’abord</option>
                        @endif
                        <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Plus récentes</option>
                        <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Plus anciennes</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Par page
                    <select name="per_page">
                        @foreach ([10, 20, 50] as $value)
                            <option value="{{ $value }}" @selected(($filters['per_page'] ?? 20) === $value)>{{ $value }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-2">
                    <button class="btn btn-primary" type="submit">Filtrer</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.deletion-requests.index') }}" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres">×</a>
                </div>
            </form>
        </section>

        <section class="ui-card app-screen-block">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Registre des demandes</h2>
                <span class="text-sm text-slate-500">{{ $rows->total() }} résultat{{ $rows->total() > 1 ? 's' : '' }}</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table min-w-[82rem]">
                    <thead>
                        <tr>
                            <th>Demande</th>
                            @if ($canReview)<th>Demandeur</th>@endif
                            <th>Motif</th>
                            <th>Impact</th>
                            <th>Statut</th>
                            <th>Décision</th>
                            <th class="text-right">Opérations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $impact = is_array($row->impact_summary) ? $row->impact_summary : [];
                                $linkedRecords = is_array($impact['linked_records'] ?? null) ? $impact['linked_records'] : [];
                                $isUserRequest = $row->entity_type === \App\Models\User::class;
                                $canArchive = in_array($row->module, ['pas', 'pao', 'pta'], true);
                                $isAccessChange = $row->module === 'access_control';
                            @endphp
                            <tr id="request-{{ $row->id }}">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="anbg-badge anbg-badge-neutral">{{ $moduleLabels[$row->module] ?? strtoupper($row->module) }}</span>
                                        <span class="text-xs text-slate-400">#{{ $row->id }}</span>
                                    </div>
                                    <strong class="mt-1 block max-w-72">{{ $row->entity_label ?: class_basename($row->entity_type).' #'.$row->entity_id }}</strong>
                                    <span class="text-xs text-slate-500">{{ optional($row->created_at)->format('d/m/Y H:i') }}</span>
                                </td>
                                @if ($canReview)
                                    <td>
                                        <strong>{{ $row->requester?->name ?? '-' }}</strong><br>
                                        <span class="text-xs text-slate-500">{{ $row->requester?->email ?? '-' }}</span>
                                    </td>
                                @endif
                                <td>
                                    <span class="line-clamp-3 max-w-80 whitespace-pre-line" title="{{ $row->reason }}">{{ $row->reason }}</span>
                                    @if ($row->reviewer_note)
                                        <div class="mt-2 border-l-2 border-amber-400 pl-2 text-xs text-slate-600 dark:text-slate-300">
                                            {{ $row->reviewer_note }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ (int) ($impact['total'] ?? 0) }} lien{{ (int) ($impact['total'] ?? 0) > 1 ? 's' : '' }}</strong>
                                    @if ($linkedRecords !== [])
                                        <details class="mt-1 text-xs text-slate-500">
                                            <summary class="cursor-pointer">Détail</summary>
                                            <div class="mt-1 grid gap-1">
                                                @foreach ($linkedRecords as $key => $count)
                                                    @if ((int) $count > 0)
                                                        <span>{{ str_replace('_', ' ', ucfirst($key)) }} : {{ $count }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td>
                                    <span class="{{ $statusClasses[$row->status] ?? 'anbg-badge anbg-badge-neutral' }}">
                                        {{ $statusLabels[$row->status] ?? ucfirst($row->status) }}
                                    </span>
                                    @if ($row->decided_at)
                                        <div class="mt-1 text-xs text-slate-500">{{ optional($row->decided_at)->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $row->decision ? ucfirst(str_replace('_', ' ', $row->decision)) : '-' }}</strong>
                                    @if ($row->reviewer)
                                        <div class="mt-1 text-xs text-slate-500">{{ $row->reviewer->name }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($canApprove && $row->status === \App\Models\DeletionRequest::STATUS_PENDING)
                                        <details class="relative inline-block text-left">
                                            <summary class="btn btn-primary btn-sm cursor-pointer list-none">Décider</summary>
                                            <form method="POST" action="{{ route('workspace.super-admin.organization.deletion-requests.decision', $row) }}" class="absolute right-0 z-30 mt-2 grid w-[24rem] max-w-[88vw] gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                                @csrf
                                                <input type="hidden" name="return_to" value="governance">
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Décision
                                                    <select name="decision" required>
                                                        <option value="">Sélectionner</option>
                                                        <option value="approve">Approuver pour exécution</option>
                                                        <option value="request_complement">Demander un complément</option>
                                                        <option value="reject">Refuser</option>
                                                    </select>
                                                </label>
                                                @if ($isUserRequest)
                                                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                        Repreneur
                                                        <select name="transfer_to_user_id">
                                                            <option value="">Aucun</option>
                                                            @foreach ($transferUserOptions as $option)
                                                                <option value="{{ $option->id }}">
                                                                    {{ $option->name }}
                                                                    @if ($option->direction) - {{ $option->direction->code }}@endif
                                                                    @if ($option->service) / {{ $option->service->code }}@endif
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                @endif
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Note de décision
                                                    <textarea name="reviewer_note" rows="4" minlength="5" maxlength="1000" required></textarea>
                                                </label>
                                                <button class="btn btn-primary btn-sm" type="submit">Enregistrer l’accord</button>
                                            </form>
                                        </details>
                                    @elseif ($canExecute && $row->status === \App\Models\DeletionRequest::STATUS_APPROVED)
                                        <details class="relative inline-block text-left">
                                            <summary class="btn btn-primary btn-sm cursor-pointer list-none">Exécuter</summary>
                                            <form method="POST" action="{{ route('workspace.super-admin.organization.deletion-requests.decision', $row) }}" class="absolute right-0 z-30 mt-2 grid w-[24rem] max-w-[88vw] gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                                @csrf
                                                <input type="hidden" name="return_to" value="governance">
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Action administrative
                                                    <select name="decision" required>
                                                        @if ($isAccessChange)
                                                            <option value="apply">Appliquer les rôles et permissions</option>
                                                        @else
                                                            <option value="delete">Supprimer définitivement</option>
                                                        @endif
                                                        @if ($isUserRequest)<option value="disable">Désactiver et transférer</option>@endif
                                                        @if ($canArchive)<option value="archive">Archiver</option>@endif
                                                    </select>
                                                </label>
                                                @if ($isUserRequest)
                                                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                        Repreneur
                                                        <select name="transfer_to_user_id">
                                                            <option value="">Aucun</option>
                                                            @foreach ($transferUserOptions as $option)
                                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                @endif
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Note d’exécution
                                                    <textarea name="reviewer_note" rows="4" minlength="5" maxlength="1000" required></textarea>
                                                </label>
                                                <button class="btn btn-primary btn-sm" type="submit">Confirmer l’exécution</button>
                                            </form>
                                        </details>
                                    @elseif (! $canReview && $row->status === \App\Models\DeletionRequest::STATUS_COMPLEMENT_REQUESTED)
                                        <details class="relative inline-block text-left">
                                            <summary class="btn btn-primary btn-sm cursor-pointer list-none">Répondre</summary>
                                            <form method="POST" action="{{ route('workspace.deletion-requests.complement.store', $row) }}" class="absolute right-0 z-30 mt-2 grid w-[22rem] max-w-[88vw] gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                                @csrf
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Complément
                                                    <textarea name="complement" rows="5" minlength="10" maxlength="2000" required></textarea>
                                                </label>
                                                <button class="btn btn-primary btn-sm" type="submit">Transmettre le complément</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canReview ? 7 : 6 }}">
                                    <x-ui.empty-state
                                        title="Aucune demande trouvée"
                                        message="Aucun enregistrement ne correspond aux critères sélectionnés."
                                        icon="alert"
                                        tone="info"
                                        class="my-4"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination">{{ $rows->links() }}</div>
        </section>

        @if ($canReview)
            <section class="app-screen-block border-t border-slate-200 pt-5 dark:border-slate-700">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-950 dark:text-white">Mouvements d’affectation récents</h2>
                    <span class="text-sm text-slate-500">{{ $assignmentHistory->count() }} mouvement{{ $assignmentHistory->count() > 1 ? 's' : '' }}</span>
                </div>

                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table min-w-[64rem]">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Rôle</th>
                                <th>Ancien périmètre</th>
                                <th>Nouveau périmètre</th>
                                <th>Transfert</th>
                                <th>Motif</th>
                                <th>Modifié par</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($assignmentHistory as $history)
                                <tr>
                                    <td>
                                        <strong>{{ $history->user?->name ?? 'Compte supprimé' }}</strong><br>
                                        <span class="text-xs text-slate-500">{{ optional($history->changed_at)->format('d/m/Y H:i') }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-slate-500">{{ $history->previous_role ?: '-' }}</span><br>
                                        <strong>{{ $history->new_role ?: '-' }}</strong>
                                    </td>
                                    <td>
                                        {{ $history->previousDirection?->code ?? '-' }}
                                        @if ($history->previousService) / {{ $history->previousService->code }} @endif
                                        @if ($history->previousUniteDg) / {{ $history->previousUniteDg->code }} @endif
                                    </td>
                                    <td>
                                        {{ $history->newDirection?->code ?? '-' }}
                                        @if ($history->newService) / {{ $history->newService->code }} @endif
                                        @if ($history->newUniteDg) / {{ $history->newUniteDg->code }} @endif
                                    </td>
                                    <td>{{ $history->replacement?->name ?? '-' }}</td>
                                    <td><span class="line-clamp-2 max-w-72">{{ $history->reason ?: '-' }}</span></td>
                                    <td>{{ $history->actor?->name ?? 'Système' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-slate-500">Aucun mouvement enregistré.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
