@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
        <x-ui.page-title
            title="Demandes de modification"
            subtitle="Circuit : Chef de service → Directeur → Planification (avis) → DG (décision). Une approbation rouvre l'action en écriture."
        />

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Demandes</h2>
                    <p class="form-section-subtitle">Chaque étape notifie l'acteur suivant. L'avis de la Planification est consultatif ; seul le DG tranche.</p>
                </div>
                <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th>Élément</th>
                            <th>Demandeur</th>
                            <th>Motif / Justificatif</th>
                            <th>Étape en cours</th>
                            <th>Suivi du circuit</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $statusMeta = [
                                    'soumise' => ['Attente directeur', 'anbg-badge-warning'],
                                    'transmise' => ['Attente Planif / DG', 'anbg-badge-info'],
                                    'approuvee' => ['Approuvée (DG)', 'anbg-badge-success'],
                                    'rejetee' => ['Rejetée (DG)', 'anbg-badge-danger'],
                                ];
                                [$stLabel, $stClass] = $statusMeta[(string) $row->status] ?? [str_replace('_', ' ', (string) $row->status), 'anbg-badge-neutral'];
                                $isDirecteurOfRow = $currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                    && (int) ($currentUser->direction_id ?? 0) === (int) ($row->direction_id ?? -1);
                            @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold text-slate-900">{{ strtoupper((string) $row->module) }} — {{ $row->target_label }}</div>
                                    <p class="mt-1 text-xs text-slate-500">Demande #{{ $row->id }}</p>
                                </td>
                                <td>
                                    {{ $row->requester?->name ?? '-' }}
                                    <p class="mt-1 text-xs text-slate-500">{{ $row->requester?->role ?? '-' }}</p>
                                </td>
                                <td class="max-w-md">
                                    {{ $row->reason }}
                                    @if ($row->justificatif_path)
                                        <p class="mt-1 text-xs text-emerald-600">📎 Justificatif joint</p>
                                    @endif
                                </td>
                                <td>
                                    <span class="anbg-badge {{ $stClass }} px-3">{{ $stLabel }}</span>
                                </td>
                                <td class="text-xs text-slate-600 space-y-1">
                                    @if ($row->transferred_by)
                                        <p>↳ Transférée par <strong>{{ $row->transferredBy?->name ?? 'directeur' }}</strong></p>
                                    @endif
                                    @if ($row->planif_avis)
                                        <p>↳ Avis Planif : <strong>{{ $row->planif_avis }}</strong>{{ $row->planif_comment ? ' — '.$row->planif_comment : '' }}</p>
                                    @endif
                                    @if ($row->reviewer)
                                        <p>↳ DG : <strong>{{ $row->reviewer?->name }}</strong>{{ $row->review_comment ? ' — '.$row->review_comment : '' }}</p>
                                    @endif
                                    @if (! $row->transferred_by && ! $row->planif_avis && ! $row->reviewer)
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="min-w-[260px]">
                                    {{-- ÉTAPE 1 : Directeur transfère --}}
                                    @if ($row->status === 'soumise' && $isDirecteurOfRow)
                                        <form method="POST" action="{{ route('workspace.planning-unlocks.transfer', $row) }}" class="grid gap-2">
                                            @csrf
                                            <textarea name="transfer_comment" rows="2" placeholder="Commentaire (optionnel)"></textarea>
                                            <button class="btn btn-primary btn-sm" type="submit">Transférer à la Planif + DG</button>
                                        </form>
                                    {{-- ÉTAPE 2 : Planification avis (consultatif) --}}
                                    @elseif ($row->status === 'transmise' && ($canGivePlanifAvis ?? false) && ! $row->planif_avis)
                                        <form method="POST" action="{{ route('workspace.planning-unlocks.planif', $row) }}" class="grid gap-2">
                                            @csrf
                                            <select name="planif_avis" required>
                                                <option value="favorable">Avis favorable</option>
                                                <option value="defavorable">Avis défavorable</option>
                                            </select>
                                            <textarea name="planif_comment" rows="2" placeholder="Commentaire Planification"></textarea>
                                            <button class="btn btn-secondary btn-sm" type="submit">Enregistrer l'avis</button>
                                        </form>
                                    {{-- ÉTAPE 3 : DG décide --}}
                                    @elseif ($row->status === 'transmise' && ($canReview ?? false))
                                        <form method="POST" action="{{ route('workspace.planning-unlocks.dg', $row) }}" class="grid gap-2">
                                            @csrf
                                            <select name="decision" required>
                                                <option value="approuver">Approuver</option>
                                                <option value="rejeter">Rejeter</option>
                                            </select>
                                            <textarea name="review_comment" rows="2" placeholder="Commentaire DG (obligatoire si rejet)"></textarea>
                                            <button class="btn btn-primary btn-sm" type="submit">Enregistrer la décision</button>
                                        </form>
                                    @elseif (in_array((string) $row->status, ['approuvee', 'rejetee'], true))
                                        <span class="text-sm text-slate-500">Traitée</span>
                                    @else
                                        <span class="text-sm text-slate-400">En attente de l'étape suivante</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state
                                        title="Aucune demande"
                                        message="Aucune demande de modification ne correspond à votre périmètre."
                                        icon="lock"
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
    </div>
@endsection
