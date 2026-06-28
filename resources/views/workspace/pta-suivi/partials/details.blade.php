<div class="space-y-5">
    <section>
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-black uppercase tracking-wide text-[#3996d3]">Action PTA</p>
                <h3 class="text-lg font-black text-[#17324a]">{{ $row['libelle'] ?? '-' }}</h3>
            </div>
            <div class="flex flex-wrap gap-2 text-xs font-black">
                <span class="rounded-md bg-[#3996d3] px-3 py-1 text-white">{{ $row['statut_suivi_label'] ?? '-' }}</span>
                <span class="rounded-md bg-[#f8fafc] px-3 py-1 text-[#17324a] ring-1 ring-slate-200">{{ $row['statut_delai_label'] ?? '-' }}</span>
                <span class="rounded-md bg-[#fff2cc] px-3 py-1 text-[#111827]">{{ $row['alerte_echeance_label'] ?? '-' }}</span>
            </div>
        </div>

        <dl class="pta-suivi-detail-grid">
            @foreach ($details as $label => $value)
                <div class="pta-suivi-detail-item">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section>
        <h3 class="text-base font-black text-[#17324a]">Parcours de l'action</h3>
        <table class="pta-suivi-detail-table">
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
    </section>

    <section>
        <h3 class="text-base font-black text-[#17324a]">Validations</h3>
        <table class="pta-suivi-detail-table">
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
    </section>

    <section>
        <h3 class="text-base font-black text-[#17324a]">Pieces jointes / preuves</h3>
        <table class="pta-suivi-detail-table">
            <thead>
                <tr>
                    <th>Piece</th>
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
                        <td>{{ $attachment['type'] }}</td>
                        <td>{{ $attachment['ajoute_par'] }}</td>
                        <td>{{ $attachment['date'] }}</td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                @if ($attachment['is_previewable'])
                                    <a class="btn btn-secondary btn-sm rounded-lg px-3 py-1 text-xs" href="{{ $attachment['preview_url'] }}" target="_blank" rel="noopener">Voir</a>
                                @endif
                                <a class="btn btn-primary btn-sm rounded-lg px-3 py-1 text-xs" href="{{ $attachment['download_url'] }}">Telecharger</a>
                            </div>
                        </td>
                    </tr>
                    @if ($attachment['is_previewable'])
                        <tr>
                            <td colspan="5">
                                <div class="pta-suivi-attachment-preview">
                                    @if ($attachment['is_image'])
                                        <img src="{{ $attachment['preview_url'] }}" alt="{{ $attachment['nom'] }}">
                                    @else
                                        <iframe src="{{ $attachment['preview_url'] }}" title="{{ $attachment['nom'] }}"></iframe>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5">Aucune preuve rattachee a cette action.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
