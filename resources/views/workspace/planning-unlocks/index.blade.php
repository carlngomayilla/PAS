@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
        <x-ui.page-title
            eyebrow="Gouvernance du planning"
            title="Demandes de modification"
            subtitle="Circuit : demandeur -> decision DG / SCIQ / Planification. Une approbation rouvre l'element en ecriture."
        />

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Demandes</h2>
                    <p class="form-section-subtitle">La DG, le SCIQ et la Planification peuvent approuver ou rejeter une demande de modification selon leur habilitation.</p>
                </div>
                <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th>Element</th>
                            <th>Demandeur</th>
                            <th>Motif / Justificatif</th>
                            <th>Etape en cours</th>
                            <th>Suivi du circuit</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $statusMeta = [
                                    'soumise' => ['En attente de decision', 'anbg-badge-warning'],
                                    'transmise' => ['Transmise pour decision', 'anbg-badge-info'],
                                    'approuvee' => ['Approuvee', 'anbg-badge-success'],
                                    'rejetee' => ['Rejetee', 'anbg-badge-danger'],
                                ];
                                [$stLabel, $stClass] = $statusMeta[(string) $row->status] ?? [str_replace('_', ' ', (string) $row->status), 'anbg-badge-neutral'];
                            @endphp
                            <tr id="unlock-request-{{ $row->id }}" class="scroll-mt-28 target:bg-yellow-50">
                                <td>
                                    <div class="font-semibold text-slate-900">{{ strtoupper((string) $row->module) }} - {{ $row->target_label }}</div>
                                    <p class="mt-1 text-xs text-slate-500">Demande #{{ $row->id }}</p>
                                </td>
                                <td>
                                    {{ $row->requester?->name ?? '-' }}
                                    <p class="mt-1 text-xs text-slate-500">{{ $row->requester?->role ?? '-' }}</p>
                                </td>
                                <td class="max-w-md">
                                    {{ $row->reason }}
                                    @if ($row->justificatif_path)
                                        <p class="mt-1 text-xs text-emerald-600">Justificatif joint</p>
                                    @endif
                                </td>
                                <td>
                                    <span class="anbg-badge {{ $stClass }} px-3">{{ $stLabel }}</span>
                                </td>
                                <td class="space-y-1 text-xs text-slate-600">
                                    @if ($row->transferred_by)
                                        <p>Transmise par <strong>{{ $row->transferredBy?->name ?? 'controleur' }}</strong></p>
                                    @endif
                                    @if ($row->planif_avis)
                                        <p>Avis controleur : <strong>{{ $row->planif_avis }}</strong>{{ $row->planif_comment ? ' - '.$row->planif_comment : '' }}</p>
                                    @endif
                                    @if ($row->reviewer)
                                        <p>Decision : <strong>{{ $row->reviewer?->name }}</strong>{{ $row->review_comment ? ' - '.$row->review_comment : '' }}</p>
                                    @endif
                                    @if (! $row->transferred_by && ! $row->planif_avis && ! $row->reviewer)
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="min-w-[260px]">
                                    @if (in_array((string) $row->status, ['soumise', 'transmise'], true) && ($canReview ?? false))
                                        <form method="POST" action="{{ route('workspace.planning-unlocks.dg', $row) }}" class="grid gap-2">
                                            @csrf
                                            <select name="decision" required>
                                                <option value="approuver">Approuver</option>
                                                <option value="rejeter">Rejeter</option>
                                            </select>
                                            <textarea name="review_comment" rows="2" placeholder="Commentaire decision (obligatoire si rejet)"></textarea>
                                            <button class="btn btn-primary btn-sm" type="submit">Enregistrer la decision</button>
                                        </form>
                                    @elseif (in_array((string) $row->status, ['approuvee', 'rejetee'], true))
                                        <span class="text-sm text-slate-500">Traitee</span>
                                    @else
                                        <span class="text-sm text-slate-400">En attente de l'etape suivante</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state
                                        title="Aucune demande"
                                        message="Aucune demande de modification ne correspond a votre perimetre."
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
