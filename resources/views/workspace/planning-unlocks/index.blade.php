@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
        <x-ui.page-title
            title="Demandes de deverrouillage"
            subtitle="Validation DG des demandes de modification PAS, PTA et Actions deja enregistres."
        />

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Demandes</h2>
                    <p class="form-section-subtitle">Une approbation deverrouille l'enregistrement jusqu'a la prochaine sauvegarde.</p>
                </div>
                <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th>Element</th>
                            <th>Demandeur</th>
                            <th>Motif</th>
                            <th>Statut</th>
                            <th>Decision</th>
                            @if ($canReview)
                                <th>Traitement DG</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    <div class="font-semibold text-slate-900">{{ strtoupper((string) $row->module) }} - {{ $row->target_label }}</div>
                                    <p class="mt-1 text-xs text-slate-500">Demande #{{ $row->id }}</p>
                                </td>
                                <td>
                                    {{ $row->requester?->name ?? '-' }}
                                    <p class="mt-1 text-xs text-slate-500">{{ $row->requester?->role ?? '-' }}</p>
                                </td>
                                <td class="max-w-md">{{ $row->reason }}</td>
                                <td>
                                    <span class="anbg-badge {{ $row->status === 'soumise' ? 'anbg-badge-warning' : ($row->status === 'approuvee' ? 'anbg-badge-success' : 'anbg-badge-danger') }} px-3">
                                        {{ str_replace('_', ' ', (string) $row->status) }}
                                    </span>
                                </td>
                                <td>
                                    {{ $row->reviewer?->name ?? '-' }}
                                    @if ($row->review_comment)
                                        <p class="mt-1 text-xs text-slate-500">{{ $row->review_comment }}</p>
                                    @endif
                                </td>
                                @if ($canReview)
                                    <td>
                                        @if ($row->status === 'soumise')
                                            <form method="POST" action="{{ route('workspace.planning-unlocks.dg', $row) }}" class="grid min-w-[260px] gap-2">
                                                @csrf
                                                <select name="decision" required>
                                                    <option value="approuver">Approuver</option>
                                                    <option value="rejeter">Rejeter</option>
                                                </select>
                                                <textarea name="review_comment" rows="2" placeholder="Commentaire DG"></textarea>
                                                <button class="btn btn-primary btn-sm" type="submit">Enregistrer decision</button>
                                            </form>
                                        @else
                                            <span class="text-sm text-slate-500">Traitee</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canReview ? 6 : 5 }}">
                                    <x-ui.empty-state
                                        title="Aucune demande"
                                        message="Aucune demande de deverrouillage ne correspond a votre perimetre."
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
