@extends('layouts.workspace')

@section('content')
    @php
        $workflow = [
            'programmee' => 'Programmation',
            'pv_attendu' => 'PV attendu',
            'en_validation_sciq' => 'Visa SCIQ',
            'en_validation_planification' => 'Visa Planification',
            'validee_definitivement' => 'Diffusion',
        ];
        $statusRank = match ($meeting->status) {
            \App\Enums\MeetingStatus::Programmee, \App\Enums\MeetingStatus::Reportee => 1,
            \App\Enums\MeetingStatus::PvAttendu, \App\Enums\MeetingStatus::ACorriger => 2,
            \App\Enums\MeetingStatus::EnValidationSciq => 3,
            \App\Enums\MeetingStatus::EnValidationPlanification => 4,
            \App\Enums\MeetingStatus::ValideeDefinitivement => 5,
            default => 0,
        };
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Réunions & PV" :title="$meeting->label" class="app-screen-block">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.meetings.index') }}">Retour à la liste</a>
                @if ($canDownloadReport && $currentReport)
                    <a class="btn btn-primary min-h-10 px-4" href="{{ route('workspace.meetings.reports.download', [$meeting, $currentReport]) }}">Télécharger le PV actif</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" aria-labelledby="workflow-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 id="workflow-title" class="font-bold text-[#17324a] dark:text-slate-100">Avancement du dossier</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Chaque visa est nominatif, horodaté et non interchangeable.</p></div>
                <x-ui.badge :tone="$meeting->status->tone()">{{ $meeting->status->label() }}</x-ui.badge>
            </div>
            @if ($meeting->status === \App\Enums\MeetingStatus::Annulee)
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"><strong>Réunion annulée.</strong> {{ $meeting->cancellation_reason }}</div>
            @else
                <ol class="mt-5 grid gap-2 sm:grid-cols-5" aria-label="Étapes de validation">
                    @foreach ($workflow as $status => $label)
                        @php $rank = $loop->iteration; @endphp
                        <li class="rounded-lg border p-3 {{ $rank < $statusRank ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30' : ($rank === $statusRank ? 'border-sky-300 bg-sky-50 ring-2 ring-sky-100 dark:border-sky-700 dark:bg-sky-950/30 dark:ring-sky-950' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50') }}">
                            <span class="text-xs font-black {{ $rank <= $statusRank ? 'text-[#176a9d] dark:text-sky-200' : 'text-slate-400' }}">0{{ $rank }}</span><p class="mt-1 text-sm font-bold text-[#17324a] dark:text-slate-100">{{ $label }}</p>
                        </li>
                    @endforeach
                </ol>
                @if ($meeting->status === \App\Enums\MeetingStatus::ACorriger)
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"><strong>Correction requise.</strong> Consultez le dernier avis ci-dessous, corrigez le document, puis déposez une nouvelle version.</div>
                @endif
            @endif
        </section>

        <section class="app-screen-block grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Informations de la réunion</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Créée par {{ $meeting->createdBy?->name ?? 'Système' }}</p></div>@if ($meeting->is_extra)<x-ui.badge tone="warning">Hors objectif mensuel</x-ui.badge>@endif</div>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Périmètre</dt><dd class="mt-1 font-semibold text-[#17324a] dark:text-slate-100">{{ $meeting->structureLabel() }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Type</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $meeting->meeting_type->label() }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Date et heure</dt><dd class="mt-1 font-semibold text-slate-700 dark:text-slate-200">{{ $meeting->current_scheduled_date?->format('d/m/Y') ?? '-' }} à {{ substr((string) $meeting->scheduled_time, 0, 5) }}@if ($meeting->was_postponed)<span class="ml-2 text-xs text-amber-700 dark:text-amber-300">({{ $meeting->postponement_count }} report(s))</span>@endif</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Lieu ou lien</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $meeting->location ?? '-' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Responsable</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $meeting->responsible?->name ?? 'Non attribué' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Date d’origine</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $meeting->original_scheduled_date?->format('d/m/Y') ?? '-' }}</dd></div>
                </dl>
                @if ($meeting->agenda)<div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700"><h3 class="text-sm font-bold text-[#17324a] dark:text-slate-100">Ordre du jour prévisionnel</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700 dark:text-slate-200">{{ $meeting->agenda }}</p></div>@endif
            </article>

            <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Participants</h2>
                <div class="mt-4 space-y-2">
                    @forelse ($participantUsers as $participant)
                        <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700"><p class="text-sm font-semibold text-[#17324a] dark:text-slate-100">{{ $participant->name }}</p><p class="truncate text-xs text-slate-500">{{ $participant->email }}</p></div>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500 dark:bg-slate-800">Aucun participant nominatif. Les membres concernés par le périmètre restent informés.</p>
                    @endforelse
                </div>
            </aside>
        </section>

        @if ($canPostpone || $canCancel)
            <section class="app-screen-block grid gap-4 lg:grid-cols-2">
                @if ($canPostpone)
                    <article class="rounded-xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-800 dark:bg-amber-950/20"><h2 class="font-bold text-[#17324a] dark:text-slate-100">Reporter la réunion</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">La nouvelle date doit être postérieure à la date actuelle. Tous les destinataires seront notifiés.</p>
                        <form class="form-shell mt-4" method="POST" action="{{ route('workspace.meetings.postpone', $meeting) }}">@csrf<div class="form-grid"><div><label for="postponed_date">Nouvelle date</label><input id="postponed_date" name="scheduled_date" type="date" min="{{ today()->toDateString() }}" required></div><div><label for="postponed_time">Nouvelle heure</label><input id="postponed_time" name="scheduled_time" type="time" required></div><div class="md:col-span-2"><label for="postponement_reason">Motif détaillé</label><textarea id="postponement_reason" name="reason" rows="3" minlength="10" required>{{ old('reason') }}</textarea></div></div><div class="form-actions mt-4"><button class="btn btn-secondary" type="submit">Reporter et notifier</button></div></form>
                    </article>
                @endif
                @if ($canCancel)
                    <article class="rounded-xl border border-rose-200 bg-rose-50/60 p-5 dark:border-rose-900 dark:bg-rose-950/20"><h2 class="font-bold text-rose-800 dark:text-rose-200">Annuler la réunion</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">L’annulation est définitive et conservée dans l’historique.</p>
                        <form class="form-shell mt-4" method="POST" action="{{ route('workspace.meetings.cancel', $meeting) }}">@csrf<label for="cancellation_reason">Motif détaillé</label><textarea id="cancellation_reason" name="reason" rows="3" minlength="10" required>{{ old('reason') }}</textarea><div class="form-actions mt-4"><button class="btn btn-danger" type="submit">Annuler et notifier</button></div></form>
                    </article>
                @endif
            </section>
        @endif

        @if ($canSubmitReport)
            <section class="app-screen-block rounded-xl border border-sky-200 bg-white p-5 shadow-sm dark:border-sky-900 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">{{ $meeting->status === \App\Enums\MeetingStatus::ACorriger ? 'Déposer la version corrigée du PV' : 'Déposer le procès-verbal' }}</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Le fichier est contrôlé, chiffré au stockage et transmis au SCIQ.</p></div><x-ui.badge tone="info">Prochaine version : {{ ($currentReport?->version ?? 0) + 1 }}</x-ui.badge></div>
                <form class="form-shell mt-5" method="POST" action="{{ route('workspace.meetings.reports.store', $meeting) }}" enctype="multipart/form-data">@csrf
                    <div class="form-grid">
                        <div><label for="report_file">Fichier du PV</label><input id="report_file" name="report" type="file" required>@error('report')<x-form.error :message="$message" />@enderror</div>
                        <div class="md:col-span-2"><label for="report_summary">Synthèse <span aria-hidden="true">*</span></label><textarea id="report_summary" name="summary" rows="4" minlength="20" maxlength="5000" required>{{ old('summary', $currentReport?->summary) }}</textarea>@error('summary')<x-form.error :message="$message" />@enderror</div>
                        <div class="md:col-span-2"><label for="actual_agenda">Ordre du jour réellement traité</label><textarea id="actual_agenda" name="actual_agenda" rows="3">{{ old('actual_agenda', $currentReport?->actual_agenda) }}</textarea></div>
                        <div class="md:col-span-2"><label for="report_decisions">Décisions prises</label><textarea id="report_decisions" name="decisions" rows="4">{{ old('decisions', $currentReport?->decisions) }}</textarea></div>
                        <div><label for="report_recommendations">Recommandations</label><textarea id="report_recommendations" name="recommendations" rows="3">{{ old('recommendations', $currentReport?->recommendations) }}</textarea></div>
                        <div><label for="report_difficulties">Difficultés rencontrées</label><textarea id="report_difficulties" name="difficulties" rows="3">{{ old('difficulties', $currentReport?->difficulties) }}</textarea></div>
                        <div class="md:col-span-2"><label for="report_observations">Observations complémentaires</label><textarea id="report_observations" name="observations" rows="3">{{ old('observations', $currentReport?->observations) }}</textarea></div>
                    </div>
                    @error('workflow')<div class="mt-4 rounded-lg bg-rose-50 p-3 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</div>@enderror
                    <div class="form-actions mt-5"><button class="btn btn-primary" type="submit">Chiffrer et transmettre au SCIQ</button></div>
                </form>
            </section>
        @endif

        @if ($canReview && $currentReport)
            <section class="app-screen-block rounded-xl border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800 dark:bg-amber-950/20">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Poser le {{ $currentReport->pendingLevel()?->label() }}</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Vérifiez le PV avant de décider. Une correction renvoie le dossier au responsable et exige un motif.</p></div><x-ui.badge tone="warning">PV v{{ $currentReport->version }}</x-ui.badge></div>
                <form class="form-shell mt-5" method="POST" action="{{ route('workspace.meetings.reports.review', [$meeting, $currentReport]) }}">@csrf<div class="form-grid"><div><label for="review_decision">Décision</label><select id="review_decision" name="decision" required><option value="VALIDATED">Valider et transmettre à l’étape suivante</option><option value="CORRECTION_REQUESTED">Demander une correction</option></select></div><div class="md:col-span-2"><label for="review_comment">Motif ou observation</label><textarea id="review_comment" name="comment" rows="4" maxlength="3000">{{ old('comment') }}</textarea><p class="mt-1 text-xs text-slate-500">Obligatoire pour une demande de correction.</p>@error('comment')<x-form.error :message="$message" />@enderror</div></div><div class="form-actions mt-4"><button class="btn btn-primary" type="submit">Enregistrer le visa</button></div></form>
            </section>
        @endif

        <section class="app-screen-block rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Versions du procès-verbal</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Aucune version n’est écrasée : chaque correction reste traçable.</p></div><span class="text-sm font-semibold text-slate-500">{{ $meeting->reports->count() }} version(s)</span></div>
            <div class="mt-5 space-y-4">
                @forelse ($meeting->reports as $report)
                    <article class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-bold text-[#17324a] dark:text-slate-100">Version {{ $report->version }} · {{ $report->original_file_name }}</h3><p class="mt-1 text-xs text-slate-500">Déposée par {{ $report->uploadedBy?->name ?? 'Système' }} le {{ $report->uploaded_at?->format('d/m/Y à H:i') ?? '-' }} · {{ $report->humanSize() }}</p></div><div class="flex items-center gap-2"><x-ui.badge :tone="$report->status->tone()">{{ $report->status->label() }}</x-ui.badge>@if ($canDownloadReport)<a class="btn btn-secondary btn-sm" href="{{ route('workspace.meetings.reports.download', [$meeting, $report]) }}">Télécharger</a>@endif</div></div>
                        @if ($report->summary)<p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-700 dark:text-slate-200">{{ $report->summary }}</p>@endif
                        @if ($report->approvals->isNotEmpty())
                            <div class="mt-4 grid gap-2 md:grid-cols-2">
                                @foreach ($report->approvals as $approval)
                                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800"><p class="font-bold text-[#17324a] dark:text-slate-100">{{ $approval->approval_level->label() }} · {{ $approval->decision->label() }}</p><p class="mt-1 text-xs text-slate-500">{{ $approval->reviewer?->name ?? 'Système' }} · {{ $approval->reviewed_at?->format('d/m/Y à H:i') ?? '-' }}</p>@if ($approval->comment)<p class="mt-2 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $approval->comment }}</p>@endif</div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-lg bg-slate-50 p-6 text-center dark:bg-slate-800"><p class="font-semibold text-slate-700 dark:text-slate-200">Aucun PV déposé.</p><p class="mt-1 text-sm text-slate-500">Le dépôt sera disponible après la date et l’heure programmées.</p></div>
                @endforelse
            </div>
        </section>

        <section class="app-screen-block rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Historique complet</h2>
            <ol class="mt-5 space-y-4 border-l-2 border-slate-200 pl-5 dark:border-slate-700">
                @forelse ($meeting->statusHistories as $history)
                    <li class="relative"><span class="absolute -left-[1.65rem] top-1 h-3 w-3 rounded-full bg-[#3996d3] ring-4 ring-white dark:ring-slate-900" aria-hidden="true"></span><p class="text-sm font-bold text-[#17324a] dark:text-slate-100">{{ $history->new_status->label() }}</p><p class="mt-1 text-xs text-slate-500">{{ $history->changedBy?->name ?? 'Système' }} · {{ $history->changed_at?->format('d/m/Y à H:i') ?? '-' }}</p>@if ($history->comment)<p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $history->comment }}</p>@endif</li>
                @empty
                    <li class="text-sm text-slate-500">Aucun événement enregistré.</li>
                @endforelse
            </ol>
        </section>
    </div>
@endsection
