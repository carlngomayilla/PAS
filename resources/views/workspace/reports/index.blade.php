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
            'schedule' => 'Réunions',
            'review' => 'Vérification',
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Communication et gouvernance" title="Rapports institutionnels" class="app-screen-block">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.audit.index', ['module' => 'institutional_reports']) }}">Voir la traçabilité</a>
                @if ($canExportMeetings)
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'pdf'] + $filters) }}">PDF</a>
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'xlsx'] + $filters) }}">Excel</a>
                    <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.export', ['format' => 'docx'] + $filters) }}">Word</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2 xl:grid-cols-4 dark:border-slate-700 dark:bg-slate-700" aria-label="Indicateurs des réunions et rapports">
            @foreach ([
                ['Réunions programmées', $summary['meetings_scheduled'] ?? 0, 'text-[#176A9D] dark:text-cyan-200'],
                ['Tenues dans les délais', $summary['meetings_on_time'] ?? 0, 'text-emerald-700 dark:text-emerald-200'],
                ['Non tenues à échéance', $summary['meetings_overdue'] ?? 0, 'text-rose-700 dark:text-rose-200'],
                ['Reportées dans le trimestre', $summary['meetings_postponed'] ?? 0, 'text-amber-700 dark:text-amber-200'],
                ['Réunions annulées', $summary['meetings_cancelled'] ?? 0, 'text-slate-700 dark:text-slate-200'],
                ['PV diffusés', $summary['minutes_distributed'] ?? 0, 'text-violet-700 dark:text-violet-200'],
                ['PV à corriger', $summary['minutes_returned'] ?? 0, 'text-orange-700 dark:text-orange-200'],
                ['DÃ©cisions Ã  suivre', $summary['meeting_decisions_open'] ?? 0, 'text-rose-700 dark:text-rose-200'],
                ['Taux de tenue', number_format((float) ($summary['meeting_completion_rate'] ?? 0), 0, ',', ' ').'%', 'text-[#17324a] dark:text-sky-200'],
            ] as [$label, $value, $tone])
                <div class="min-h-28 bg-white px-4 py-4 dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</p>
                    <p class="mt-3 text-3xl font-black {{ $tone }}">{{ $value }}</p>
                </div>
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
                        <thead><tr><th>Decision</th><th>Reunion</th><th>Responsable</th><th>Echeance</th><th>Etat</th></tr></thead>
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

        <section class="app-screen-block mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <form class="form-shell" method="GET" action="{{ route('workspace.reports.index') }}">
                <input type="hidden" name="tab" value="{{ $activeTab }}">
                <div class="form-grid">
                    <div><label for="report_q">Recherche</label><input id="report_q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Objet, résumé ou décision"></div>
                    <div><label for="report_year">Année</label><select id="report_year" name="year"><option value="">Toutes</option>@foreach (range(now()->year - 2, now()->year + 1) as $year)<option value="{{ $year }}" @selected((string) $filters['year'] === (string) $year)>{{ $year }}</option>@endforeach</select></div>
                    <div><label for="report_quarter">Trimestre</label><select id="report_quarter" name="quarter"><option value="">Tous</option>@foreach ([1, 2, 3, 4] as $quarter)<option value="{{ $quarter }}" @selected((string) $filters['quarter'] === (string) $quarter)>T{{ $quarter }}</option>@endforeach</select></div>
                    <div><label for="report_month">Mois</label><select id="report_month" name="month"><option value="">Tous</option>@foreach ([1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'] as $month => $label)<option value="{{ $month }}" @selected((string) $filters['month'] === (string) $month)>{{ $label }}</option>@endforeach</select></div>
                    <div><label for="report_direction">Direction</label><select id="report_direction" name="direction_id"><option value="">Toutes</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}" @selected((string) $filters['direction_id'] === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>@endforeach</select></div>
                    <div><label for="report_service">Service</label><select id="report_service" name="service_id"><option value="">Tous</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}" @selected((string) $filters['service_id'] === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>@endforeach</select></div>
                    <div><label for="report_meeting_type">Type de réunion</label><select id="report_meeting_type" name="meeting_type"><option value="">Tous</option><option value="service" @selected($filters['meeting_type'] === 'service')>Service</option><option value="direction" @selected($filters['meeting_type'] === 'direction')>Direction</option></select></div>
                    <div><label for="report_responsible">Responsable</label><select id="report_responsible" name="responsible_id"><option value="">Tous</option>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected((string) $filters['responsible_id'] === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></div>
                    <div><label for="report_participant">Participant</label><select id="report_participant" name="participant_id"><option value="">Tous</option>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected((string) $filters['participant_id'] === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></div>
                    <div><label for="report_status">Statut</label><select id="report_status" name="status"><option value="">Tous</option><option value="held" @selected($filters['status'] === 'held')>Tenue</option><option value="overdue" @selected($filters['status'] === 'overdue')>Non tenue à échéance</option><option value="postponed" @selected($filters['status'] === 'postponed')>Reportée</option><option value="cancelled" @selected($filters['status'] === 'cancelled')>Annulée</option><option value="minutes_pending" @selected($filters['status'] === 'minutes_pending')>PV en attente</option><option value="verified" @selected($filters['status'] === 'verified')>PV vérifié</option><option value="returned" @selected($filters['status'] === 'returned')>PV à corriger</option></select></div>
                </div>
                <div class="form-actions mt-4"><a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.index', ['tab' => $activeTab]) }}">Réinitialiser</a><button class="btn btn-primary min-h-10 px-4" type="submit">Appliquer</button></div>
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
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Les dossiers déposés suivent le circuit SCIQ, Planification, Chef SCIQ, Chef Planification.</span>
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
                        <div>
                            <label for="scheduled_at">Date programmée</label>
                            <input id="scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" @if ($activeTab === 'schedule') required @endif>
                            @error('scheduled_at')<x-form.error :message="$message" />@enderror
                        </div>
                        @if ($activeTab === 'schedule')
                            <div><label for="location">Lieu</label><input id="location" name="location" type="text" value="{{ old('location') }}" maxlength="255" required>@error('location')<x-form.error :message="$message" />@enderror</div>
                            <div><label for="responsible_id">Responsable</label><select id="responsible_id" name="responsible_id" required><option value="">Choisir</option>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected((string) old('responsible_id', auth()->id()) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select>@error('responsible_id')<x-form.error :message="$message" />@enderror</div>
                            <div class="md:col-span-2"><label for="participant_ids">Participants</label><select id="participant_ids" name="participant_ids[]" multiple size="6" required>@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected(in_array((int) $member->id, old('participant_ids', [auth()->id()])))>{{ $member->name }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Utilisez Ctrl ou Cmd pour sélectionner plusieurs personnes.</p>@error('participant_ids')<x-form.error :message="$message" />@enderror</div>
                        @else
                            <div><label for="held_at">Date de tenue</label><input id="held_at" name="held_at" type="datetime-local" value="{{ old('held_at') }}">@error('held_at')<x-form.error :message="$message" />@enderror</div>
                        @endif
                        <div class="md:col-span-2">
                            <label for="summary">Résumé ou contexte</label>
                            <textarea id="summary" name="summary" rows="3" maxlength="5000">{{ old('summary') }}</textarea>
                            @error('summary')<x-form.error :message="$message" />@enderror
                        </div>
                        <div>
                            <label for="attachments">Pièces jointes</label>
                            <input id="attachments" name="attachments[]" type="file" multiple>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Obligatoire sauf pour une réunion simplement programmée.</p>
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
                <table class="app-table data-table min-w-[1040px]">
                    <thead><tr><th>Dossier</th><th>Périmètre</th><th>Programmation</th><th>État de la réunion</th><th>Responsable</th><th>Statut du PV</th><th class="text-right">Action</th></tr></thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td><p class="font-semibold text-[#17324a] dark:text-slate-100">{{ $report->title }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $typeLabels[$report->report_type] ?? $report->report_type }}</p></td>
                                <td>{{ $report->direction?->code ?? 'Agence' }}@if ($report->service) · {{ $report->service->code }}@endif</td>
                                <td>{{ $report->scheduled_at?->format('d/m/Y H:i') ?? 'Non programmée' }}</td>
                                <td>{{ $report->report_type === \App\Models\InstitutionalReport::TYPE_MEETING ? $reportService->meetingStateLabel($report) : 'Non concerné' }}</td>
                                <td>{{ $report->responsible?->name ?? $report->submittedBy?->name ?? 'N/A' }}</td>
                                <td><x-ui.badge :label="$reportService->statusLabel($report->status)" :tone="match($report->status) { \App\Models\InstitutionalReport::STATUS_VERIFIED => 'success', \App\Models\InstitutionalReport::STATUS_RETURNED => 'danger', \App\Models\InstitutionalReport::STATUS_DRAFT => 'neutral', default => 'warning' }" /></td>
                                <td class="text-right"><a class="btn btn-secondary btn-sm" href="{{ route('workspace.reports.show', $report) }}">Ouvrir</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state title="Aucun dossier" message="Aucun rapport ne correspond à cette vue." icon="docs" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($reports->hasPages())<div class="border-t border-slate-200 px-4 py-3 dark:border-slate-700">{{ $reports->links() }}</div>@endif
        </section>
    </div>
@endsection
