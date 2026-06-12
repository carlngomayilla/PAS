@extends('layouts.workspace')

@section('content')
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $statusOptions = is_array($financingStatusOptions ?? null) ? $financingStatusOptions : \App\Models\Action::financingStatusOptions();
    @endphp

    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">DAF</span>
                    <h1 class="showcase-title">Demandes de financement des actions</h1>
                </div>
                <div class="showcase-action-row">
                    <a class="btn btn-secondary" href="{{ route('workspace.actions.index') }}">Retour actions</a>
                </div>
            </div>
        </section>

        <section class="showcase-panel mb-4 app-screen-block">
            <form method="GET" class="form-shell">
                <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
                    <div>
                        <label for="q">Action</label>
                        <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Libellé, nature, source">
                    </div>
                    <div>
                        <label for="pta_id">PTA</label>
                        <select id="pta_id" name="pta_id">
                            <option value="">Tous</option>
                            @foreach ($ptaOptions as $pta)
                                <option value="{{ $pta->id }}" @selected((int) ($filters['pta_id'] ?? 0) === (int) $pta->id)>{{ $pta->titre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="direction_id">Direction</label>
                        <select id="direction_id" name="direction_id">
                            <option value="">Toutes</option>
                            @foreach ($directionOptions as $direction)
                                <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="service_id">Service</label>
                        <select id="service_id" name="service_id">
                            <option value="">Tous</option>
                            @foreach ($serviceOptions as $service)
                                <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected((int) ($filters['service_id'] ?? 0) === (int) $service->id)>{{ $service->code }} - {{ $service->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="statut_financement">Statut financement</label>
                        <select id="statut_financement" name="statut_financement">
                            <option value="">Tous</option>
                            @foreach ($statusOptions as $status => $label)
                                <option value="{{ $status }}" @selected(($filters['statut_financement'] ?? '') === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="rmo_id">RMO</label>
                        <select id="rmo_id" name="rmo_id">
                            <option value="">Tous</option>
                            @foreach ($rmoOptions as $rmo)
                                <option value="{{ $rmo->id }}" @selected((int) ($filters['rmo_id'] ?? 0) === (int) $rmo->id)>{{ $rmo->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="date_debut">Periode debut</label>
                        <input id="date_debut" name="date_debut" type="date" value="{{ $filters['date_debut'] ?? '' }}">
                    </div>
                    <div>
                        <label for="date_fin">Periode fin</label>
                        <input id="date_fin" name="date_fin" type="date" value="{{ $filters['date_fin'] ?? '' }}">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Appliquer</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.daf.financements.index') }}">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="showcase-panel-title mb-0">Demandes transmises a la DAF</h2>
                <span class="anbg-badge anbg-badge-info px-3 py-1 text-xs">{{ $rows->total() }} demande(s)</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>PTA</th>
                            <th>Service</th>
                            <th>Direction</th>
                            <th>RMO</th>
                            <th>Montant demandé</th>
                            <th>Nature</th>
                            <th>Source</th>
                            <th>Commentaire</th>
                            <th>Statut</th>
                            <th>Pièce</th>
                            <th>Opérations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $action)
                            @php
                                $status = $action->financementStatus();
                                $statusLabel = $statusOptions[$status] ?? $status;
                                $doc = $action->justificatifs->firstWhere('categorie', 'financement')
                                    ?: $action->justificatifs->firstWhere('categorie', 'financement_daf');
                                $rmoNames = $action->relationLoaded('responsables') && $action->responsables->isNotEmpty()
                                    ? $action->responsables->pluck('name')->implode(', ')
                                    : ($action->responsable?->name ?? '-');
                            @endphp
                            <tr>
                                <td>
                                    <a class="font-semibold text-[#3996d3]" href="{{ route('workspace.actions.suivi', $action) }}">{{ $action->libelle }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $action->objectifOperationnel?->libelle ?: '-' }}</p>
                                </td>
                                <td>{{ $action->pta?->titre ?: '-' }}</td>
                                <td>{{ $action->pta?->service?->code ?: '-' }}</td>
                                <td>{{ $action->pta?->direction?->code ?: '-' }}</td>
                                <td>{{ $rmoNames }}</td>
                                <td>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 0) : '-' }}</td>
                                <td>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</td>
                                <td>{{ $action->source_financement ?: '-' }}</td>
                                <td>{{ $action->commentaire_financement ?: '-' }}</td>
                                <td><span class="anbg-badge anbg-badge-info px-2 py-1 text-xs">{{ $statusLabel }}</span></td>
                                <td>
                                    @if ($doc)
                                        <button
                                            class="text-[#3996d3] font-semibold"
                                            type="button"
                                            data-preview-file
                                            data-preview-title="{{ $doc->nom_original }}"
                                            data-preview-subtitle="{{ $doc->mime_type ?: 'Justificatif financement' }}"
                                            data-preview-mime="{{ $doc->mime_type }}"
                                            data-preview-url="{{ route('workspace.actions.justificatifs.preview', [$action, $doc]) }}"
                                            data-download-url="{{ route('workspace.actions.justificatifs.download', [$action, $doc]) }}"
                                        >Voir</button>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="min-w-[280px]">
                                    @if ($canTreatDaf)
                                        <details>
                                            <summary class="cursor-pointer font-semibold text-[#3996d3]">Traiter</summary>
                                            <div class="mt-3 space-y-3">
                                                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.daf', $action) }}">
                                                    @csrf
                                                    <input type="hidden" name="decision_financement" value="valider">
                                                    <label>Montant valide</label>
                                                    <input name="montant_valide" type="number" step="1" min="0" value="{{ $action->montant_estime !== null ? (int) round((float) $action->montant_estime) : '' }}">
                                                    <label>Reference financement</label>
                                                    <input name="reference_financement" type="text" value="{{ $action->financement_reference }}">
                                                    <label>Commentaire DAF</label>
                                                    <textarea name="commentaire_financement">{{ $action->financement_daf_commentaire }}</textarea>
                                                    <button class="btn btn-primary mt-2" type="submit">Approuver</button>
                                                </form>

                                                <form method="POST" action="{{ route('workspace.actions.financement.daf', $action) }}">
                                                    @csrf
                                                    <input type="hidden" name="decision_financement" value="rejeter">
                                                    <label>Motif DAF</label>
                                                    <textarea name="commentaire_financement" required>{{ old('commentaire_financement') }}</textarea>
                                                    <button class="btn btn-danger mt-2" type="submit">Rejeter</button>
                                                </form>

                                                <form method="POST" action="{{ route('workspace.actions.financement.daf.status', $action) }}">
                                                    @csrf
                                                    <label>Statut de traitement financier</label>
                                                    <select name="statut_financement" required>
                                                        <option value="{{ \App\Models\Action::FINANCEMENT_EN_COURS_ANALYSE }}">En cours d'analyse</option>
                                                        <option value="{{ \App\Models\Action::FINANCEMENT_FINANCE }}">Finance</option>
                                                        <option value="{{ \App\Models\Action::FINANCEMENT_NON_FINANCE }}">Non finance</option>
                                                    </select>
                                                    <label>Montant valide</label>
                                                    <input name="montant_valide" type="number" step="1" min="0" value="{{ ($action->financement_montant_valide ?: $action->montant_estime) !== null ? (int) round((float) ($action->financement_montant_valide ?: $action->montant_estime)) : '' }}">
                                                    <label>Commentaire DAF</label>
                                                    <textarea name="commentaire_financement">{{ $action->financement_daf_commentaire }}</textarea>
                                                    <button class="btn btn-secondary mt-2" type="submit">Mettre à jour le statut</button>
                                                </form>
                                            </div>
                                        </details>
                                    @else
                                        <a class="text-[#3996d3] font-semibold" href="{{ route('workspace.actions.suivi', $action) }}">Consulter</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12">
                                    <x-ui.empty-state
                                        title="Aucune demande de financement"
                                        message="Aucune action ne correspond aux filtres financiers courants."
                                        icon="filter"
                                        tone="info"
                                        class="my-4"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $rows->links() }}
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var directionInput = document.getElementById('direction_id');
            var serviceInput = document.getElementById('service_id');

            if (!directionInput || !serviceInput) {
                return;
            }

            function syncServices() {
                var selectedDirection = String(directionInput.value || '');
                var selectedService = String(serviceInput.value || '');
                var selectedStillVisible = false;

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    var visible = selectedDirection === '' || String(option.getAttribute('data-direction-id') || '') === selectedDirection;
                    option.hidden = !visible;
                    option.disabled = !visible;

                    if (visible && option.value === selectedService) {
                        selectedStillVisible = true;
                    }
                });

                if (selectedService && !selectedStillVisible) {
                    serviceInput.value = '';
                }
            }

            directionInput.addEventListener('change', syncServices);
            syncServices();
        })();
    </script>
@endpush
