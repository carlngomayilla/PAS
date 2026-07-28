@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $lockService = app(\App\Services\PlanningModificationLockService::class);
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $ps = is_array($ptaStats ?? null) ? $ptaStats : [];
        $directionOptions = collect($serviceOptions ?? [])
            ->pluck('direction')
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->values();
        $summaryCards = [
            ['label' => 'Total PTA',            'value' => $ps['total'] ?? $rows->total(),   'meta' => null, 'href' => route('workspace.pta.index'),                             'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'En cours',             'value' => $ps['en_cours'] ?? 0,             'meta' => null, 'href' => route('workspace.pta.index', ['statut' => 'en_cours']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Controle SCIQ',        'value' => $ps['controle_sciq'] ?? 0,        'meta' => null, 'href' => route('workspace.pta.index', ['statut' => 'controle_sciq']), 'badge' => null, 'badge_tone' => (($ps['controle_sciq'] ?? 0) > 0 ? 'warning' : 'neutral')],
            ['label' => 'Clotures',             'value' => $ps['clotures'] ?? 0,             'meta' => null, 'href' => route('workspace.pta.index', ['statut' => 'cloture']),  'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Sans action',          'value' => $ps['sans_action'] ?? 0,          'meta' => null, 'href' => route('workspace.pta.index', ['without_action' => 1]),    'badge' => null, 'badge_tone' => ($ps['sans_action'] ?? 0) > 0 ? 'warning' : 'neutral'],
        ];
        $workflowSteps = [
            ['status' => 'en_cours', 'label' => 'En cours', 'count' => $ps['en_cours'] ?? 0],
            ['status' => 'controle_sciq', 'label' => 'Controle SCIQ', 'count' => $ps['controle_sciq'] ?? 0],
            ['status' => 'cloture', 'label' => 'Cloture', 'count' => $ps['clotures'] ?? 0],
            ['status' => 'archive', 'label' => 'Archive', 'count' => $ps['archives'] ?? 0],
        ];
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        eyebrow="Exécution annuelle"
        title="Plan de travail annuel"
    >
        <x-slot:actions>
            @if ($canWrite)
                <a class="btn btn-primary" href="{{ route('workspace.pta.create') }}">Nouveau PTA</a>
            @endif
        </x-slot:actions>
    </x-ui.page-title>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="grid gap-4 xl:grid-cols-[1.35fr_0.9fr]">
            <div>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200/80 bg-white/80 p-3">
                        <p class="text-xs font-bold uppercase text-slate-500">Services couverts</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $ps['services'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200/80 bg-white/80 p-3">
                        <p class="text-xs font-bold uppercase text-slate-500">Actions rattachees</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $ps['actions_total'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200/80 bg-white/80 p-3">
                        <p class="text-xs font-bold uppercase text-slate-500">A parametrer</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $ps['sans_action'] ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200/80 bg-white/85 p-4">
                <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Workflow valide</p>
                <div class="mt-4 space-y-3">
                    @foreach ($workflowSteps as $step)
                        <a href="{{ route('workspace.pta.index', ['statut' => $step['status']]) }}" class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:border-[#3996d3] hover:text-[#17324a]">
                            <span>{{ $loop->iteration }}. {{ $step['label'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ $step['count'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid pta-summary-row mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div><h2 class="showcase-panel-title">Filtres</h2></div>
        <form method="GET" action="{{ route('workspace.pta.index') }}" class="mt-4">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre ou description">
                </div>
                <div>
                    <label for="pao_id">PAO</label>
                    <select id="pao_id" name="pao_id">
                        <option value="">Tous</option>
                        @foreach ($paoOptions as $pao)
                            <option value="{{ $pao->id }}" @selected($filters['pao_id'] === $pao->id)>
                                {{ $pao->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>
                                {{ $direction->code }} - {{ $direction->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected($filters['service_id'] === $service->id)>
                                {{ $service->code }} - {{ $service->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $workflowStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($filters['without_action'])
                <input type="hidden" name="without_action" value="1">
            @endif
            <div class="showcase-filter-actions mt-4">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Réinitialiser</a>
            </div>
            @if ($filters['without_action'])
                <div class="mt-4 rounded-[1rem] border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm font-medium text-amber-900">
                    PTA sans action
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PTA</h2>
            </div>
            <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>PAO</th>
                        <th>Objectif opérationnel</th>
                        <th>Direction</th>
                        <th>Service</th>
                        <th>Échéance OO</th>
                        <th>Statut</th>
                        <th>Nb actions</th>
                        <th>Validateur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $statusClasses = match ((string) $row->statut) {
                                'brouillon' => 'anbg-badge anbg-badge-neutral',
                                'en_cours' => 'anbg-badge anbg-badge-warning',
                                'controle_sciq' => 'anbg-badge anbg-badge-info',
                                'cloture' => 'anbg-badge anbg-badge-info',
                                'archive' => 'anbg-badge anbg-badge-neutral',
                                default => 'anbg-badge anbg-badge-neutral',
                            };
                            // Le meme endpoint fait avancer le PTA dans le circuit canonique.
                            $canClose = in_array((string) $row->statut, ['en_cours', 'controle_sciq'], true);
                            $canArchive = $row->statut === 'cloture';
                            $closeButtonLabel = $row->statut === 'controle_sciq' ? 'Cloturer' : 'Envoyer au controle';
                            $closeConfirmMessage = $row->statut === 'controle_sciq'
                                ? 'Cloturer ce PTA apres controle SCIQ/Planification ?'
                                : 'Transmettre ce PTA au controle SCIQ/Planification ?';
                            $closeMotif = $row->statut === 'controle_sciq'
                                ? 'Cloture PTA apres controle SCIQ/Planification'
                                : 'Transmission PTA au controle SCIQ/Planification';
                            $isModificationLocked = $lockService->isLocked($row);
                            $canRequestUnlock = $currentUser && $lockService->canRequestUnlock($currentUser, $row);
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-600">{{ $row->id }}</td>
                            <td class="font-mono text-xs font-semibold text-slate-800">{{ $row->code ?? '-' }}</td>
                            <td class="font-semibold text-slate-900">{{ $row->titre }}</td>
                            <td>{{ $row->pao?->titre ?? '-' }}</td>
                            <td class="min-w-[220px]">
                                @if ($row->objectifOperationnel)
                                    <div class="text-sm text-slate-800">{{ $row->objectifOperationnel->libelle }}</div>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $row->direction?->code }} {{ $row->direction?->libelle ? '- '.$row->direction->libelle : '' }}</td>
                            <td>{{ $row->service?->code }} {{ $row->service?->libelle ? '- '.$row->service->libelle : '' }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-700">{{ $row->objectifOperationnel?->echeance ?? '-' }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                                @if ($isModificationLocked)
                                    <p class="mt-2"><span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">Modification verrouillee</span></p>
                                @endif
                            </td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-info px-3">{{ $row->actions_count }}</span></td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-secondary" href="{{ route('workspace.pta.show', $row) }}">Explorer</a>
                                    @if ($canWrite)
                                        @if (! $isModificationLocked)
                                            <a class="btn btn-warning" href="{{ route('workspace.pta.edit', $row) }}">Modifier</a>
                                        @elseif ($canRequestUnlock)
                                            @include('workspace.planning-unlocks._request-inline', [
                                                'target' => $row,
                                                'route' => route('workspace.pta.unlock-requests.store', $row),
                                                'context' => 'Modification PTA demandee par '.$currentUser->name,
                                            ])
                                        @endif
                                        @if ($canClose)
                                            <form method="POST" action="{{ route('workspace.pta.close', $row) }}" data-confirm-message="{{ $closeConfirmMessage }}" data-confirm-tone="warning" data-confirm-label="{{ $closeButtonLabel }}">
                                                @csrf
                                                <input type="hidden" name="motif" value="{{ $closeMotif }}">
                                                <button class="btn btn-primary" type="submit">{{ $closeButtonLabel }}</button>
                                            </form>
                                        @endif
                                        @if ($canArchive)
                                            <form method="POST" action="{{ route('workspace.pta.archive', $row) }}" data-confirm-message="Archiver ce PTA cloture ?" data-confirm-tone="warning" data-confirm-label="Archiver">
                                                @csrf
                                                <input type="hidden" name="motif" value="Archivage PTA cloture depuis la liste">
                                                <button class="btn btn-secondary" type="submit">Archiver</button>
                                            </form>
                                        @endif
                                            <form method="POST" action="{{ route('workspace.pta.destroy', $row) }}" data-confirm-message="Supprimer ce PTA ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="motif" value="Demande de suppression PTA depuis le module PTA">
                                                <button class="btn btn-danger" type="submit">Supprimer</button>
                                            </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12">
                                <x-ui.empty-state
                                    title="Aucun PTA trouvé"
                                    message="Aucun plan de travail annuel ne correspond aux filtres courants."
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
        <div class="pagination">{{ $rows->links() }}</div>
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
                var directionId = String(directionInput.value || '');
                var selectedService = String(serviceInput.value || '');
                var selectedStillVisible = false;

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }

                    var visible = directionId === '' || String(option.getAttribute('data-direction-id') || '') === directionId;
                    option.hidden = !visible;

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
