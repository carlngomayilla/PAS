@extends('layouts.workspace')

@section('content')
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $statusOptions = is_array($financingStatusOptions ?? null) ? $financingStatusOptions : \App\Models\Action::financingStatusOptions();
        $summary = is_array($queueSummary ?? null) ? $queueSummary : [];
    @endphp

    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">{{ $financeWorkspaceRole ?? 'Circuit financier' }}</span>
                    <h1 class="showcase-title">Pilotage des financements</h1>
                </div>
                <div class="showcase-action-row">
                    <a class="btn btn-secondary" href="{{ route('workspace.actions.index') }}">Retour actions</a>
                </div>
            </div>
        </section>

        <section class="mb-4 border-y border-slate-200 bg-white/70 dark:border-slate-700 dark:bg-slate-900/70" aria-label="Synthese du portefeuille financier">
            <div class="grid grid-cols-2 divide-x divide-y divide-slate-200 md:grid-cols-4 xl:grid-cols-7 dark:divide-slate-700">
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Dossiers</p>
                    <p class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ number_format((int) ($summary['total'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Chez le RMO</p>
                    <p class="mt-1 text-xl font-bold text-amber-700 dark:text-amber-300">{{ number_format((int) ($summary['preparation'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">A instruire DAF</p>
                    <p class="mt-1 text-xl font-bold text-sky-700 dark:text-sky-300">{{ number_format((int) ($summary['daf'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Decision DG</p>
                    <p class="mt-1 text-xl font-bold text-violet-700 dark:text-violet-300">{{ number_format((int) ($summary['dg'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Accordes</p>
                    <p class="mt-1 text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ number_format((int) ($summary['accordes'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">Refuses DG</p>
                    <p class="mt-1 text-xl font-bold text-rose-700 dark:text-rose-300">{{ number_format((int) ($summary['refuses'] ?? 0), 0, ',', ' ') }}</p>
                </div>
                <div class="col-span-2 min-w-0 px-4 py-3 md:col-span-2 xl:col-span-1">
                    <p class="text-xs font-semibold uppercase text-slate-500">Montant sollicite</p>
                    <p class="mt-1 truncate text-lg font-bold text-slate-950 dark:text-white" title="{{ number_format((float) ($summary['montant_estime'] ?? 0), 0, ',', ' ') }}">
                        {{ number_format((float) ($summary['montant_estime'] ?? 0), 0, ',', ' ') }}
                    </p>
                </div>
            </div>
        </section>

        <section class="showcase-panel mb-4 app-screen-block">
            <form method="GET" class="form-shell" data-auto-filter-form>
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
                        <label for="date_debut">Période debut</label>
                        <input id="date_debut" name="date_debut" type="date" value="{{ $filters['date_debut'] ?? '' }}">
                    </div>
                    <div>
                        <label for="date_fin">Période fin</label>
                        <input id="date_fin" name="date_fin" type="date" value="{{ $filters['date_fin'] ?? '' }}">
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-secondary" href="{{ route('workspace.daf.financements.index') }}">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="showcase-panel-title mb-0">Portefeuille financier des actions</h2>
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
                                $canDafReview = ($canTreatDaf ?? false)
                                    && $status === \App\Models\Action::FINANCEMENT_SOUMIS_DAF;
                                $canDgReview = ($canTreatDg ?? false)
                                    && in_array($status, [
                                        \App\Models\Action::FINANCEMENT_TRANSMIS_DG,
                                        \App\Models\Action::FINANCEMENT_VALIDE_DAF,
                                    ], true);
                                $statusTone = match ($status) {
                                    \App\Models\Action::FINANCEMENT_PRE_SIGNALE_DAF,
                                    \App\Models\Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                                    \App\Models\Action::FINANCEMENT_REJETE_DAF => 'anbg-badge-warning',
                                    \App\Models\Action::FINANCEMENT_SOUMIS_DAF,
                                    \App\Models\Action::FINANCEMENT_TRANSMIS_DG,
                                    \App\Models\Action::FINANCEMENT_VALIDE_DAF => 'anbg-badge-info',
                                    \App\Models\Action::FINANCEMENT_VALIDE_DG => 'anbg-badge-success',
                                    \App\Models\Action::FINANCEMENT_REJETE_DG => 'anbg-badge-danger',
                                    default => 'anbg-badge-neutral',
                                };
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
                                <td><span class="anbg-badge {{ $statusTone }} px-2 py-1 text-xs">{{ $statusLabel }}</span></td>
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
                                <td class="min-w-[180px]">
                                    @if ($canDafReview)
                                        <a class="btn btn-primary" href="{{ route('workspace.actions.suivi', $action) }}#action-financement">Instruire DAF</a>
                                    @elseif ($canDgReview)
                                        <a class="btn btn-primary" href="{{ route('workspace.actions.suivi', $action) }}#action-financement">Decision DG</a>
                                    @else
                                        <a class="btn btn-secondary" href="{{ route('workspace.actions.suivi', $action) }}#action-financement">Consulter</a>
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
