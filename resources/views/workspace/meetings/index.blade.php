@extends('layouts.workspace')

@section('content')
    @php
        $months = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
        $views = [
            'all' => ['label' => 'Toutes les réunions', 'count' => $meetings->total()],
            'plans' => ['label' => 'Objectifs mensuels', 'count' => $summary['remaining_to_schedule'] ?? 0],
            'reviews' => ['label' => 'Visas à poser', 'count' => ($summary['awaiting_sciq'] ?? 0) + ($summary['awaiting_planification'] ?? 0)],
            'corrections' => ['label' => 'Corrections', 'count' => $summary['to_correct'] ?? 0],
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Gouvernance interne" title="Réunions & procès-verbaux" class="app-screen-block">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.index', ['tab' => 'register']) }}">Autres rapports</a>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.audit.index', ['module' => 'meetings']) }}">Traçabilité</a>
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/30" aria-label="Circuit de traitement">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="font-bold text-[#17324a] dark:text-sky-100">Un circuit unique, sans validation implicite</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">Le responsable programme et dépose le PV après la réunion. Le SCIQ contrôle, puis la Planification pose le visa final. Une correction crée une nouvelle version et relance le circuit.</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300" aria-label="Étapes du circuit">
                    @foreach (['Programmation', 'PV', 'Visa SCIQ', 'Visa Planification', 'Diffusion'] as $step)
                        <span class="rounded-full border border-sky-200 bg-white px-3 py-1.5 dark:border-sky-800 dark:bg-slate-900">{{ $step }}</span>
                        @if (! $loop->last)<span aria-hidden="true">→</span>@endif
                    @endforeach
                </div>
            </div>
        </section>

        <section class="showcase-summary-grid app-screen-kpis app-screen-block" aria-label="Indicateurs des réunions">
            @foreach ([
                ['Objectif', $summary['expected'] ?? 0, 'navy'],
                ['Programmées', $summary['scheduled'] ?? 0, 'blue'],
                ['Restant à programmer', $summary['remaining_to_schedule'] ?? 0, 'gold'],
                ['PV attendus', $summary['without_report'] ?? 0, 'danger'],
                ['Visa SCIQ', $summary['awaiting_sciq'] ?? 0, 'orange'],
                ['Visa Planification', $summary['awaiting_planification'] ?? 0, 'yellow'],
                ['Validées', $summary['validated'] ?? 0, 'green'],
                ['Taux de réalisation', \App\Support\UiLabel::percent((float) ($summary['realization_rate'] ?? 0)), 'navy'],
            ] as [$label, $value, $tone])
                <x-ui.stat-card :label="$label" :value="$value" :tone="$tone" />
            @endforeach
        </section>

        <nav class="app-screen-block flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Vues du module réunions">
            @foreach ($views as $code => $item)
                <a href="{{ route('workspace.meetings.index', ['view' => $code]) }}" @if ($activeView === $code) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 py-2 text-sm font-bold transition {{ $activeView === $code ? 'border-[#3996d3] text-[#176a9d] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}">
                    {{ $item['label'] }}
                    @if ((int) $item['count'] > 0)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">{{ $item['count'] }}</span>@endif
                </a>
            @endforeach
        </nav>

        <section class="showcase-toolbar app-screen-block">
            <div><h2 class="showcase-panel-title">Rechercher et filtrer</h2></div>
            <form method="GET" action="{{ route('workspace.meetings.index') }}">
                <input type="hidden" name="view" value="{{ $activeView }}">
                <div class="showcase-filter-grid">
                    <div><label for="meeting_q">Recherche</label><input id="meeting_q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Objet, lieu ou responsable"></div>
                    <div><label for="meeting_year">Année</label><select id="meeting_year" name="year"><option value="">Toutes</option>@foreach (range(now()->year - 2, now()->year + 1) as $year)<option value="{{ $year }}" @selected((string) ($filters['year'] ?? '') === (string) $year)>{{ $year }}</option>@endforeach</select></div>
                    <div><label for="meeting_quarter">Trimestre</label><select id="meeting_quarter" name="quarter"><option value="">Tous</option>@foreach ([1, 2, 3, 4] as $quarter)<option value="{{ $quarter }}" @selected((string) ($filters['quarter'] ?? '') === (string) $quarter)>T{{ $quarter }}</option>@endforeach</select></div>
                    <div><label for="meeting_month">Mois</label><select id="meeting_month" name="month"><option value="">Tous</option>@foreach ($months as $month => $label)<option value="{{ $month }}" @selected((string) ($filters['month'] ?? '') === (string) $month)>{{ $label }}</option>@endforeach</select></div>
                    <div><label for="meeting_type_filter">Type</label><select id="meeting_type_filter" name="meeting_type"><option value="">Tous</option>@foreach ($meetingTypes as $value => $label)<option value="{{ $value }}" @selected(($filters['meeting_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label for="meeting_status">Statut</label><select id="meeting_status" name="status"><option value="">Tous</option>@foreach ($meetingStatuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    @if ($directionOptions->count() > 1)<div><label for="meeting_direction_filter">Direction</label><select id="meeting_direction_filter" name="direction_id"><option value="">Toutes</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}" @selected((string) ($filters['direction_id'] ?? '') === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>@endforeach</select></div>@endif
                    @if ($serviceOptions->isNotEmpty())<div><label for="meeting_service_filter">Service</label><select id="meeting_service_filter" name="service_id"><option value="">Tous</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}" @selected((string) ($filters['service_id'] ?? '') === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>@endforeach</select></div>@endif
                </div>
                <div class="showcase-filter-actions mt-4"><button class="btn btn-primary" type="submit">Appliquer</button><a class="btn btn-secondary" href="{{ route('workspace.meetings.index', ['view' => $activeView]) }}">Réinitialiser</a></div>
            </form>
        </section>

        @if ($canDefinePlans)
            <section class="app-screen-block rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Définir l’objectif mensuel</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Réservé au SCIQ. Un objectif existe par structure, type et mois.</p></div><x-ui.badge tone="info">Pilotage SCIQ</x-ui.badge></div>
                <form class="form-shell mt-5" method="POST" action="{{ route('workspace.meetings.plans.store') }}">@csrf
                    <div class="form-grid">
                        <div><label for="plan_direction_id">Direction</label><select id="plan_direction_id" name="direction_id" required><option value="">Choisir</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}" @selected((string) old('direction_id') === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>@endforeach</select>@error('direction_id')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="plan_meeting_type">Type</label><select id="plan_meeting_type" name="meeting_type" required>@foreach ($meetingTypes as $value => $label)<option value="{{ $value }}" @selected(old('meeting_type') === $value)>{{ $label }}</option>@endforeach</select>@error('meeting_type')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="plan_service_id">Service <span class="font-normal text-slate-500">(si réunion de service)</span></label><select id="plan_service_id" name="service_id"><option value="">Toute la direction</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>@endforeach</select>@error('service_id')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="plan_year">Année</label><input id="plan_year" name="year" type="number" min="{{ now()->year }}" max="{{ now()->year + 3 }}" value="{{ old('year', now()->year) }}" required></div>
                        <div><label for="plan_month">Mois</label><select id="plan_month" name="month" required>@foreach ($months as $month => $label)<option value="{{ $month }}" @selected((string) old('month', now()->month) === (string) $month)>{{ $label }}</option>@endforeach</select></div>
                        <div><label for="expected_count">Nombre attendu</label><input id="expected_count" name="expected_count" type="number" min="0" max="31" value="{{ old('expected_count', 1) }}" required>@error('expected_count')<x-form.error :message="$message" />@enderror</div>
                    </div>
                    <div class="form-actions mt-5"><button class="btn btn-primary" type="submit">Publier l’objectif</button></div>
                </form>
            </section>
        @endif

        @if ($canSchedule)
            <section class="app-screen-block rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Programmer une réunion</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Votre périmètre est contrôlé côté serveur. Les participants seront notifiés.</p></div><x-ui.badge tone="neutral">Chef de structure</x-ui.badge></div>
                <form class="form-shell mt-5" method="POST" action="{{ route('workspace.meetings.store') }}">@csrf
                    <div class="form-grid">
                        <div><label for="schedule_direction_id">Direction</label><select id="schedule_direction_id" name="direction_id" required><option value="">Choisir</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}" @selected((string) old('direction_id', auth()->user()?->direction_id) === (string) $direction->id)>{{ $direction->code }} · {{ $direction->libelle }}</option>@endforeach</select>@error('direction_id')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="schedule_meeting_type">Type</label><select id="schedule_meeting_type" name="meeting_type" required>@foreach ($meetingTypes as $value => $label)<option value="{{ $value }}" @selected(old('meeting_type') === $value)>{{ $label }}</option>@endforeach</select>@error('meeting_type')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="schedule_service_id">Service</label><select id="schedule_service_id" name="service_id"><option value="">Toute la direction</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}" @selected((string) old('service_id', auth()->user()?->service_id) === (string) $service->id)>{{ $service->code }} · {{ $service->libelle }}</option>@endforeach</select>@error('service_id')<x-form.error :message="$message" />@enderror</div>
                        <div class="md:col-span-2"><label for="meeting_label">Objet de la réunion</label><input id="meeting_label" name="label" type="text" minlength="5" maxlength="255" value="{{ old('label') }}" required>@error('label')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="meeting_location">Lieu ou lien</label><input id="meeting_location" name="location" type="text" maxlength="255" value="{{ old('location') }}" required>@error('location')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="meeting_responsible_id">Responsable</label><select id="meeting_responsible_id" name="responsible_id" required><option value="">Choisir</option>@foreach ($responsibleOptions as $member)<option value="{{ $member->id }}" @selected((string) old('responsible_id', auth()->id()) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select>@error('responsible_id')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="scheduled_date">Date</label><input id="scheduled_date" name="scheduled_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('scheduled_date') }}" required>@error('scheduled_date')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="scheduled_time">Heure</label><input id="scheduled_time" name="scheduled_time" type="time" value="{{ old('scheduled_time') }}" required>@error('scheduled_time')<x-form.error :message="$message" />@enderror</div>
                        <div class="md:col-span-2"><label for="meeting_agenda">Ordre du jour prévisionnel</label><textarea id="meeting_agenda" name="agenda" rows="4" maxlength="5000">{{ old('agenda') }}</textarea>@error('agenda')<x-form.error :message="$message" />@enderror</div>
                        <div class="md:col-span-2"><label for="meeting_participant_ids">Participants</label><select id="meeting_participant_ids" name="participant_ids[]" multiple size="6">@foreach ($userOptions as $member)<option value="{{ $member->id }}" @selected(in_array((int) $member->id, array_map('intval', old('participant_ids', [auth()->id()]))))>{{ $member->name }} · {{ $member->email }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Maintenez Ctrl (Windows) ou Cmd (Mac) pour sélectionner plusieurs personnes.</p>@error('participant_ids')<x-form.error :message="$message" />@enderror</div>
                    </div>
                    @error('workflow')<div class="mt-4 rounded-lg bg-rose-50 p-3 text-sm font-semibold text-rose-800 dark:bg-rose-950/40 dark:text-rose-200" role="alert">{{ $message }}</div>@enderror
                    <div class="form-actions mt-5"><button class="btn btn-primary" type="submit">Programmer et notifier</button></div>
                </form>
            </section>
        @endif

        @if ($activeView === 'plans')
            <section class="app-screen-block overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-700"><h2 class="font-bold text-[#17324a] dark:text-slate-100">Avancement des objectifs</h2></div>
                <div class="overflow-x-auto"><table class="app-table data-table min-w-[760px]"><thead><tr><th>Structure</th><th>Période</th><th>Type</th><th>Objectif</th><th>Programmées</th><th>Reste</th><th>Taux</th></tr></thead><tbody>
                    @forelse ($planProgress as $plan)<tr><td class="font-semibold text-[#17324a] dark:text-slate-100">{{ $plan['structure'] }}</td><td>{{ $months[$plan['month']] ?? $plan['month'] }} {{ $plan['year'] }}</td><td>{{ $plan['meeting_type'] }}</td><td>{{ $plan['expected'] }}</td><td>{{ $plan['scheduled'] }}</td><td>{{ $plan['remaining'] }}</td><td>{{ \App\Support\UiLabel::percent((float) $plan['rate']) }}</td></tr>@empty<tr><td colspan="7" class="py-8 text-center text-slate-500">Aucun objectif pour les filtres sélectionnés.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif

        <section class="app-screen-block overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700"><h2 class="font-bold text-[#17324a] dark:text-slate-100">{{ $views[$activeView]['label'] }}</h2><span class="text-sm text-slate-500 dark:text-slate-400">{{ $meetings->total() }} réunion(s)</span></div>
            <div class="overflow-x-auto"><table class="app-table data-table min-w-[980px]"><thead><tr><th>Réunion</th><th>Périmètre</th><th>Date</th><th>Responsable</th><th>Statut</th><th>Objectif</th><th></th></tr></thead><tbody>
                @forelse ($meetings as $meeting)
                    <tr>
                        <td><a class="font-bold text-[#176a9d] hover:underline dark:text-sky-200" href="{{ route('workspace.meetings.show', $meeting) }}">{{ $meeting->label }}</a><p class="mt-1 text-xs text-slate-500">{{ $meeting->meeting_type->label() }}</p></td>
                        <td>{{ $meeting->structureLabel() }}</td>
                        <td>{{ $meeting->current_scheduled_date?->format('d/m/Y') ?? '-' }} <span class="whitespace-nowrap">{{ substr((string) $meeting->scheduled_time, 0, 5) }}</span>@if ($meeting->was_postponed)<p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">Reportée {{ $meeting->postponement_count }} fois</p>@endif</td>
                        <td>{{ $meeting->responsible?->name ?? $meeting->createdBy?->name ?? 'Non attribué' }}</td>
                        <td><x-ui.badge :tone="$meeting->status->tone()">{{ $meeting->status->label() }}</x-ui.badge></td>
                        <td>@if ($meeting->is_extra)<x-ui.badge tone="warning">Hors objectif</x-ui.badge>@else<span class="text-sm text-slate-500">Dans l’objectif</span>@endif</td>
                        <td><a class="btn btn-secondary btn-sm" href="{{ route('workspace.meetings.show', $meeting) }}">Ouvrir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center"><p class="font-semibold text-slate-700 dark:text-slate-200">Aucune réunion ne correspond à cette vue.</p><p class="mt-1 text-sm text-slate-500">Modifiez les filtres ou programmez la première réunion de votre périmètre.</p></td></tr>
                @endforelse
            </tbody></table></div>
            @if ($meetings->hasPages())<div class="border-t border-slate-200 px-4 py-3 dark:border-slate-700">{{ $meetings->links() }}</div>@endif
        </section>
    </div>
@endsection
