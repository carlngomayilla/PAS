@php
    $trackingUrl = trim((string) ($trackingUrl ?? ''));
@endphp

<div class="space-y-5">
    <section class="pta-suivi-detail-section">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-black uppercase tracking-wide text-[#3996d3]">Action PTA</p>
                <h3 class="text-lg font-black text-[#17324a]">{{ $row['libelle'] ?? '-' }}</h3>
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end">
                <div class="flex flex-wrap gap-2 text-xs font-black">
                    <x-pta.status-badge type="action" :status="$row['statut_action'] ?? 'en_cours'" :label="$row['statut_action_label'] ?? null" />
                    <x-pta.status-badge type="suivi" :status="$row['statut_suivi'] ?? 'en_cours'" :label="$row['statut_suivi_label'] ?? null" />
                    <x-pta.status-badge type="delai" :status="$row['statut_delai'] ?? 'dans_les_delais'" :label="$row['statut_delai_label'] ?? null" />
                </div>
                <div class="pta-suivi-detail-actions">
                    @if ($trackingUrl !== '')
                        <a class="btn btn-secondary btn-sm pta-suivi-detail-secondary rounded-lg px-3 py-2 text-xs" href="{{ $trackingUrl }}">Ouvrir le suivi</a>
                    @endif
                </div>
            </div>
        </div>

        <dl class="pta-suivi-detail-grid grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($details as $label => $value)
                <div class="pta-suivi-detail-item rounded-md border border-slate-200 bg-white p-3 shadow-sm">
                    <dt class="mb-1 text-[11px] font-black uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                    <dd class="text-sm font-bold text-slate-900">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="pta-suivi-detail-section">
        <h3 class="text-base font-black text-[#17324a]">Parcours de l'action</h3>
        <div class="mt-2 app-table-wrapper overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table class="app-table data-table min-w-full">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Etape</th>
                        <th>Utilisateur</th>
                        <th>Action effectuee</th>
                        <th>Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $historyRow)
                        <tr>
                            <td>{{ $historyRow['date'] }}</td>
                            <td>{{ $historyRow['etape'] }}</td>
                            <td>{{ $historyRow['utilisateur'] }}</td>
                            <td>{{ $historyRow['action'] }}</td>
                            <td>{{ $historyRow['commentaire'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Aucun parcours disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="pta-suivi-detail-section">
        <h3 class="text-base font-black text-[#17324a]">Validations</h3>
        <div class="mt-2 app-table-wrapper overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table class="app-table data-table min-w-full">
                <thead>
                    <tr>
                        <th>Niveau</th>
                        <th>Statut</th>
                        <th>Validateur</th>
                        <th>Date</th>
                        <th>Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($validations as $validation)
                        <tr>
                            <td>{{ $validation['niveau'] }}</td>
                            <td>{{ $validation['statut'] }}</td>
                            <td>{{ $validation['validateur'] }}</td>
                            <td>{{ $validation['date'] }}</td>
                            <td>{{ $validation['commentaire'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="pta-suivi-detail-section">
        <h3 class="text-base font-black text-[#17324a]">Pieces jointes / preuves</h3>
        <div class="mt-2 app-table-wrapper overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table class="app-table data-table min-w-full">
                <thead>
                    <tr>
                        <th>Piece</th>
                        <th>Origine</th>
                        <th>Type</th>
                        <th>Ajoutee par</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attachments as $attachment)
                        <tr>
                            <td>{{ $attachment['nom'] }}</td>
                            <td>{{ $attachment['source'] ?? 'Action' }}</td>
                            <td>{{ $attachment['type'] }}</td>
                            <td>{{ $attachment['ajoute_par'] }}</td>
                            <td>{{ $attachment['date'] }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @if ($attachment['is_previewable'])
                                        <a class="btn btn-secondary btn-sm rounded-lg px-3 py-1 text-xs" href="{{ $attachment['preview_url'] }}" target="_blank" rel="noopener">Voir</a>
                                    @endif
                                    <a class="btn btn-primary btn-sm rounded-lg px-3 py-1 text-xs" href="{{ $attachment['download_url'] }}">Télécharger</a>
                                </div>
                            </td>
                        </tr>
                        @if ($attachment['is_previewable'])
                            <tr>
                                <td colspan="6">
                                    <div class="pta-suivi-attachment-preview mt-2 overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                        @if ($attachment['is_image'])
                                            <img class="mx-auto block h-auto max-w-full" src="{{ $attachment['preview_url'] }}" alt="{{ $attachment['nom'] }}">
                                        @else
                                            <iframe class="h-[420px] w-full border-0" src="{{ $attachment['preview_url'] }}" title="{{ $attachment['nom'] }}"></iframe>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6">Aucune preuve rattachee a cette action.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
