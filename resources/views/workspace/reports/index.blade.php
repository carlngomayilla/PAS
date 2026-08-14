@extends('layouts.workspace')

@section('content')
    @php
        $typeLabels = [
            \App\Models\InstitutionalReport::TYPE_MEETING => 'Compte rendu de réunion',
            \App\Models\InstitutionalReport::TYPE_INCIDENT => 'Rapport d’incident',
            \App\Models\InstitutionalReport::TYPE_ACTIVITY => 'Rapport d’activité',
            \App\Models\InstitutionalReport::TYPE_OTHER => 'Autre rapport',
        ];
        $tabs = [
            'register' => 'Autres rapports',
            'review' => 'Vérification',
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Communication et gouvernance" title="Autres rapports institutionnels" class="app-screen-block">
            <x-slot:actions>
                <a class="btn btn-primary min-h-10 px-4" href="{{ route('workspace.meetings.index') }}">Réunions &amp; PV</a>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.audit.index', ['module' => 'institutional_reports']) }}">Voir la traçabilité</a>
                @if ($canExportMeetings)
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'pdf'] + $filters) }}">PDF</a>
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'xlsx'] + $filters) }}">Excel</a>
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'docx'] + $filters) }}">Word</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        {{-- Meme composant que les cartes KPI du tableau de bord : un seul style
             dans toute l'application. --}}
        <section class="showcase-summary-grid app-screen-kpis app-screen-block" aria-label="Indicateurs des rapports institutionnels">
            @foreach ([
                ['Rapports visibles', $summary['total'] ?? 0, 'blue'],
                ['En vérification', $summary['pending'] ?? 0, 'gold'],
                ['Rapports vérifiés', $summary['verified'] ?? 0, 'green'],
            ] as [$label, $value, $tone])
                <x-ui.stat-card :label="$label" :value="$value" :tone="$tone" />
            @endforeach
        </section>

        @if ($followUpDecisions->isNotEmpty())
            <section class="app-screen-block mt-5 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Decisions de reunion a suivre</h2>
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ $followUpDecisions->count() }} element(s) prioritaire(s)</span>
                </div>
                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table min-w-[880px]">
                        <thead><tr><th>Decision</th><th>Reunion</th><th>Responsable</th><th>Échéance</th><th>Etat</th></tr></thead>
                        <tbody>
                            @foreach ($followUpDecisions as $decision)
                                <tr>
                                    <td class="font-semibold text-[#17324a] dark:text-slate-100">{{ $decision->description }}</td>
                                    <td><a class="font-semibold text-[#176a9d] hover:underline dark:text-sky-200" href="{{ route('workspace.reports.show', $decision->institutionalReport) }}">{{ $decision->institutionalReport?->title ?? 'Reunion' }}</a></td>
                                    <td>{{ $decision->responsible?->name ?? 'A attribuer' }}</td>
                                    <td>{{ $decision->due_at?->format('d/m/Y') ?? '-' }}</td>
                                    <td><x-ui.badge :label="match($decision->status) { 'to_do' => 'A faire', 'in_progress' => 'En cours', 'suspended' => 'Suspendue', default => $decision->status }" :tone="match($decision->status) { 'in_progress' => 'warning', 'suspended' => 'danger', default => 'neutral' }" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Meme motif de barre de filtres que les pages PAS / PAO / PTA. --}}
        <section class="showcase-toolbar app-screen-block mt-5">
            <div><h2 class="showcase-panel-title">Filtres</h2></div>
            <form method="GET" action="{{ route('workspace.reports.index') }}">
                <input type="hidden" name="tab" value="{{ $activeTab }}">
                <div class="showcase-filter-grid">
                    <div><label for="report_q">Recherche</label><input id="report_q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Objet, résumé ou décision"></div>
                    <div><label for="report_direction">Direction</label><select id="report_direction" name="direction_id"><option value="">Toutes</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}" @selected((string) $filters['direction_id'] === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>@endforeach</select></div>
                    <div><label for="report_service">Service</label><select id="report_service" name="service_id"><option value="">Tous</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}" @selected((string) $filters['service_id'] === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>@endforeach</select></div>
                    <div><label for="report_responsible">Responsable</label><select id="report_responsible" name="responsible_id"><option value="">Tous</option>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected((string) $filters['responsible_id'] === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></div>
                    <div><label for="report_status">Statut</label><select id="report_status" name="status"><option value="">Tous</option><option value="draft" @selected($filters['status'] === 'draft')>Brouillon</option><option value="submitted_sciq" @selected($filters['status'] === 'submitted_sciq')>En vérification</option><option value="verified" @selected($filters['status'] === 'verified')>Vérifié</option><option value="returned" @selected($filters['status'] === 'returned')>À corriger</option></select></div>
                </div>
                <div class="showcase-filter-actions mt-4">
                    <button class="btn btn-primary" type="submit">Appliquer</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.reports.index', ['tab' => $activeTab]) }}">Réinitialiser</a>
                </div>
            </form>
        </section>

        <nav class="app-screen-block mt-5 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Vues des rapports">
            @foreach ($tabs as $code => $label)
                <a href="{{ route('workspace.reports.index', ['tab' => $code]) }}" @if ($activeTab === $code) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 py-2 text-sm font-bold transition {{ $activeTab === $code ? 'border-[#3996d3] text-[#176a9d] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}">
                    {{ $label }}
                    @if ($code === 'review' && (int) ($summary['pending'] ?? 0) > 0)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">{{ $summary['pending'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        @if ($canSubmit && ($activeTab !== 'schedule' || $canScheduleMeeting))
            <section class="app-screen-block mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <form method="POST" action="{{ route('workspace.reports.store') }}" enctype="multipart/form-data" class="form-shell">
                    @csrf
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="form-section-title !mb-0">{{ $activeTab === 'schedule' ? 'Programmer une réunion' : 'Déposer un rapport' }}</h2>
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Cet espace concerne uniquement les rapports d’activité, d’incident et autres rapports institutionnels.</span>
                    </div>
                    <div class="form-grid mt-4">
                        @if ($activeTab === 'schedule')
                            <input name="report_type" type="hidden" value="{{ \App\Models\InstitutionalReport::TYPE_MEETING }}">
                            <div>
                                <label for="meeting_type">Type de réunion</label>
                                <select id="meeting_type" name="meeting_type" required>
                                    <option value="service" @selected(old('meeting_type') === 'service')>Réunion de service</option>
                                    <option value="direction" @selected(old('meeting_type') === 'direction')>Réunion de direction</option>
                                </select>
                                @error('meeting_type')<x-form.error :message="$message" />@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="title">Objet de la réunion <span class="font-normal text-slate-500">(facultatif)</span></label>
                                <input id="title" name="title" type="text" value="{{ old('title') }}" maxlength="255" placeholder="Laissez vide pour utiliser un intitulé automatique">
                                @error('title')<x-form.error :message="$message" />@enderror
                            </div>
                        @else
                            <div>
                                <label for="report_type">Nature</label>
                                <select id="report_type" name="report_type" required>
                                    @foreach ($typeLabels as $type => $label)
                                        @if ($type !== \App\Models\InstitutionalReport::TYPE_MEETING)
                                            <option value="{{ $type }}" @selected(old('report_type', \App\Models\InstitutionalReport::TYPE_ACTIVITY) === $type)>{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2"><label for="title">Objet</label><input id="title" name="title" type="text" value="{{ old('title') }}" maxlength="255" required></div>
                        @endif
                        <div>
                            <label for="direction_id">Direction</label>
                            <select id="direction_id" name="direction_id">
                                <option value="">Ma direction</option>
                                @foreach ($directionOptions as $direction)
                                    <option value="{{ $direction->id }}" @selected((string) old('direction_id', auth()->user()?->direction_id) === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>
                                @endforeach
                            </select>
                            @error('direction_id')<x-form.error :message="$message" />@enderror
                        </div>
                        <div>
                            <label for="service_id">Service</label>
                            <select id="service_id" name="service_id">
                                <option value="">Toute la direction</option>
                                @foreach ($serviceOptions as $service)
                                    <option value="{{ $service->id }}" @selected((string) old('service_id', auth()->user()?->service_id) === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>
                                @endforeach
                            </select>
                            @error('service_id')<x-form.error :message="$message" />@enderror
                        </div>
                        @if ($activeTab === 'schedule')
                            <div><label for="location">Lieu</label><input id="location" name="location" type="text" value="{{ old('location') }}" maxlength="255" required>@error('location')<x-form.error :message="$message" />@enderror</div>
                            <div><label for="responsible_id">Responsable</label><select id="responsible_id" name="responsible_id" required><option value="">Choisir</option>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected((string) old('responsible_id', auth()->id()) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select>@error('responsible_id')<x-form.error :message="$message" />@enderror</div>
                            <div class="md:col-span-2"><label for="participant_ids">Participants</label><select id="participant_ids" name="participant_ids[]" multiple size="6" required>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected(in_array((int) $member->id, old('participant_ids', [auth()->id()])))>{{ $member->name }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Utilisez Ctrl ou Cmd pour sélectionner plusieurs personnes.</p>@error('participant_ids')<x-form.error :message="$message" />@enderror</div>
                        @endif
                        <div class="md:col-span-2">
                            <label for="summary">Résumé ou contexte</label>
                            <textarea id="summary" name="summary" rows="3" maxlength="5000">{{ old('summary') }}</textarea>
                            @error('summary')<x-form.error :message="$message" />@enderror
                        </div>
                        <div>
                            <label for="attachments">Pièces jointes</label>
                            <input id="attachments" name="attachments[]" type="file" multiple>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Une pièce jointe est obligatoire pour transmettre le rapport.</p>
                            @error('attachment')<x-form.error :message="$message" />@enderror
                            @error('attachments')<x-form.error :message="$message" />@enderror
                        </div>
                    </div>
                    <div class="form-actions mt-5">
                        <button class="btn btn-primary min-h-10 px-4" type="submit">Enregistrer le dossier</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="app-screen-block mt-5 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">{{ $tabs[$activeTab] }}</h2>
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ $reports->total() }} dossier(s)</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table min-w-[820px]">
                    <thead><tr><th>Dossier</th><th>Périmètre</th><th>Déposant</th><th>Date de dépôt</th><th>Statut</th><th class="text-right">Action</th></tr></thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td><p class="font-semibold text-[#17324a] dark:text-slate-100">{{ $report->title }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $typeLabels[$report->report_type] ?? $report->report_type }}</p></td>
                                <td>{{ $report->direction?->code ?? 'Agence' }}@if ($report->service) · {{ $report->service->code }}@endif</td>
                                <td>{{ $report->responsible?->name ?? $report->submittedBy?->name ?? 'N/A' }}</td>
                                <td>{{ $report->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td><x-ui.badge :tone="match($report->status) { \App\Models\InstitutionalReport::STATUS_VERIFIED => 'success', \App\Models\InstitutionalReport::STATUS_RETURNED => 'danger', \App\Models\InstitutionalReport::STATUS_DRAFT => 'neutral', default => 'warning' }">{{ $reportService->statusLabel($report->status) }}</x-ui.badge></td>
                                <td class="text-right"><a class="btn btn-secondary btn-sm" href="{{ route('workspace.reports.show', $report) }}">Ouvrir</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state title="Aucun dossier" message="Aucun rapport ne correspond à cette vue." icon="docs" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($reports->hasPages())<div class="border-t border-slate-200 px-4 py-3 dark:border-slate-700">{{ $reports->links() }}</div>@endif
        </section>
    </div>
@endsection
