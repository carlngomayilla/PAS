@extends('layouts.workspace')

@section('content')
    @php
        $isMeeting = $report->report_type === \App\Models\InstitutionalReport::TYPE_MEETING;
        $typeLabels = [
            \App\Models\InstitutionalReport::TYPE_MEETING => 'Compte rendu de réunion',
            \App\Models\InstitutionalReport::TYPE_INCIDENT => 'Rapport d’incident',
            \App\Models\InstitutionalReport::TYPE_ACTIVITY => 'Rapport d’activité',
            \App\Models\InstitutionalReport::TYPE_OTHER => 'Autre rapport',
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Rapport institutionnel" :title="$report->title" class="app-screen-block">
            <x-slot:actions><a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.index') }}">Retour aux rapports</a></x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $typeLabels[$report->report_type] ?? $report->report_type }}</span>
                    <x-ui.badge :label="$reportService->statusLabel($report->status)" :tone="match($report->status) { \App\Models\InstitutionalReport::STATUS_VERIFIED => 'success', \App\Models\InstitutionalReport::STATUS_RETURNED => 'danger', \App\Models\InstitutionalReport::STATUS_DRAFT => 'neutral', default => 'warning' }" />
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Périmètre</dt><dd class="mt-1 font-semibold text-[#17324a] dark:text-slate-100">{{ $report->direction?->libelle ?? 'Agence' }}@if ($report->service) · {{ $report->service->libelle }}@endif</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Déposant</dt><dd class="mt-1 font-semibold text-[#17324a] dark:text-slate-100">{{ $report->submittedBy?->name ?? 'N/A' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Réunion programmée</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $report->scheduled_at?->format('d/m/Y H:i') ?? 'Non concernée' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">État</dt><dd class="mt-1 font-semibold text-slate-700 dark:text-slate-200">{{ $isMeeting ? $reportService->meetingStateLabel($report) : $reportService->statusLabel($report->status) }}</dd></div>
                    @if ($isMeeting)
                        <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Responsable</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $report->responsible?->name ?? 'Non renseigné' }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Lieu</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $report->location ?? 'Non renseigné' }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Réunion tenue</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $report->held_at?->format('d/m/Y H:i') ?? 'Non renseignée' }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">PV diffusé</dt><dd class="mt-1 text-slate-700 dark:text-slate-200">{{ $report->minutes_published_at?->format('d/m/Y H:i') ?? 'En attente' }}</dd></div>
                    @endif
                </dl>
                @if ($report->summary)<div class="mt-5 border-t border-slate-200 pt-4 text-sm leading-6 text-slate-700 dark:border-slate-700 dark:text-slate-200">{{ $report->summary }}</div>@endif
            </article>

            <aside class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-[#17324a] dark:text-slate-100">Pièces jointes</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($report->justificatifs as $justificatif)
                        <a class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-[#176a9d] hover:border-[#3996d3] dark:border-slate-700 dark:text-sky-200" href="{{ route('workspace.reports.attachments.download', [$report, $justificatif]) }}"><span class="truncate">{{ $justificatif->nom_original }}</span><span>Télécharger</span></a>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400">Aucune pièce jointe.</p>
                    @endforelse
                </div>
                @if ($isMeeting)
                    <h3 class="mt-6 text-sm font-bold text-[#17324a] dark:text-slate-100">Participants</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($participantUsers as $participant)
                            <p class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:text-slate-200">{{ $participant->name }}</p>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">Tous les membres du périmètre recevront le PV.</p>
                        @endforelse
                    </div>
                @endif
            </aside>
        </section>

        @if ($isMeeting && collect([
            $report->actual_agenda,
            $report->decisions,
            $report->recommendations,
            $report->difficulties,
            $report->observations,
        ])->filter()->isNotEmpty())
            <section class="app-screen-block grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 md:grid-cols-2 dark:border-slate-700 dark:bg-slate-700">
                @foreach ([
                    'Ordre du jour traité' => $report->actual_agenda,
                    'Décisions prises' => $report->decisions,
                    'Recommandations' => $report->recommendations,
                    'Difficultés rencontrées' => $report->difficulties,
                    'Observations' => $report->observations,
                ] as $label => $value)
                    @if ($value)<article class="bg-white p-4 dark:bg-slate-900"><h2 class="text-sm font-bold text-[#17324a] dark:text-slate-100">{{ $label }}</h2><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700 dark:text-slate-200">{{ $value }}</p></article>@endif
                @endforeach
            </section>
        @endif

        @if ($canPostpone)
            <section class="app-screen-block grid gap-4 lg:grid-cols-2">
                <article class="rounded-lg border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800 dark:bg-amber-950/20">
                    <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Reporter la réunion</h2>
                    <form class="mt-4 form-shell" method="POST" action="{{ route('workspace.reports.postpone', $report) }}">@csrf
                        <div class="form-grid"><div><label for="scheduled_at">Nouvelle date et heure</label><input id="scheduled_at" name="scheduled_at" type="datetime-local" required>@error('scheduled_at')<x-form.error :message="$message" />@enderror</div><div class="md:col-span-2"><label for="reason">Motif du report</label><textarea id="reason" name="reason" rows="3" required></textarea>@error('reason')<x-form.error :message="$message" />@enderror</div></div>
                        <div class="form-actions mt-4"><button class="btn btn-secondary min-h-10 px-4" type="submit">Notifier le report</button></div>
                    </form>
                </article>
                <article class="rounded-lg border border-rose-200 bg-rose-50/50 p-5 dark:border-rose-900 dark:bg-rose-950/20">
                    <h2 class="text-base font-bold text-rose-800 dark:text-rose-200">Annuler la réunion</h2>
                    <form class="mt-4 form-shell" method="POST" action="{{ route('workspace.reports.cancel', $report) }}">@csrf
                        <label for="cancellation_reason">Motif de l’annulation</label><textarea id="cancellation_reason" name="reason" rows="3" required></textarea>@error('reason')<x-form.error :message="$message" />@enderror
                        <div class="form-actions mt-4"><button class="btn btn-danger min-h-10 px-4" type="submit">Annuler et notifier</button></div>
                    </form>
                </article>
            </section>
        @endif

        @if ($canAmend || $canPublishMeetingMinutes)
            <section class="app-screen-block rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                @php
                    $isMeetingToComplete = $isMeeting && $report->held_at === null;
                    $needsAmendmentForm = $report->status === \App\Models\InstitutionalReport::STATUS_RETURNED || $isMeetingToComplete;
                @endphp
                <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">{{ $isMeetingToComplete ? 'Déposer le compte rendu de réunion' : ($report->status === \App\Models\InstitutionalReport::STATUS_RETURNED ? 'Corriger et soumettre de nouveau' : 'Soumettre au SCIQ') }}</h2>
                @if ($needsAmendmentForm)
                    <form class="mt-4 form-shell" method="POST" action="{{ route('workspace.reports.resubmit', $report) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="form-grid">
                            @if ($isMeetingToComplete)<div><label for="held_at">Date effective de la réunion</label><input id="held_at" name="held_at" type="datetime-local" value="{{ old('held_at') }}" required>@error('held_at')<x-form.error :message="$message" />@enderror</div>@endif
                            <div class="md:col-span-2"><label for="summary">{{ $isMeeting ? 'Résumé de la réunion' : ($isMeetingToComplete ? 'Synthèse du compte rendu' : 'Résumé corrigé') }}</label><textarea id="summary" name="summary" rows="3">{{ old('summary', $report->summary) }}</textarea>@error('summary')<x-form.error :message="$message" />@enderror</div>
                            @if ($isMeeting)
                                <div class="md:col-span-2"><label for="actual_agenda">Ordre du jour réellement traité</label><textarea id="actual_agenda" name="actual_agenda" rows="3">{{ old('actual_agenda', $report->actual_agenda) }}</textarea></div>
                                <div class="md:col-span-2"><label for="decisions">Décisions prises</label><textarea id="decisions" name="decisions" rows="4">{{ old('decisions', $report->decisions) }}</textarea></div>
                                <div><label for="recommendations">Recommandations</label><textarea id="recommendations" name="recommendations" rows="3">{{ old('recommendations', $report->recommendations) }}</textarea></div>
                                <div><label for="difficulties">Difficultés rencontrées</label><textarea id="difficulties" name="difficulties" rows="3">{{ old('difficulties', $report->difficulties) }}</textarea></div>
                                <div><label for="observations">Observations</label><textarea id="observations" name="observations" rows="3">{{ old('observations', $report->observations) }}</textarea></div>
                            @endif
                            <div><label for="attachments">{{ $isMeetingToComplete ? 'PV et pièces jointes' : 'Nouvelles pièces jointes' }}</label><input id="attachments" name="attachments[]" type="file" multiple @if ($isMeetingToComplete) required @endif>@error('attachments')<x-form.error :message="$message" />@enderror</div>
                        </div>
                        <div class="form-actions mt-4"><button class="btn btn-primary min-h-10 px-4" type="submit">{{ $isMeetingToComplete ? 'Transmettre le compte rendu' : 'Soumettre la correction' }}</button></div>
                    </form>
                @else
                    <form class="mt-4" method="POST" action="{{ route('workspace.reports.submit', $report) }}">@csrf<button class="btn btn-primary min-h-10 px-4" type="submit">Transmettre au SCIQ</button></form>
                @endif
            </section>
        @endif

        @if ($isMeeting && ($report->held_at !== null || $report->meetingDecisions->isNotEmpty()))
            <section class="app-screen-block rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Suivi des decisions</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Les responsables mettent a jour directement l'avancement de chaque decision issue de la reunion.</p>
                    </div>
                    <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ $report->meetingDecisions->where('status', '!=', 'completed')->count() }} a suivre</span>
                </div>

                @if ($canManageMeetingDecisions)
                    <form class="mt-5 form-shell border-t border-slate-200 pt-5 dark:border-slate-700" method="POST" action="{{ route('workspace.reports.decisions.store', $report) }}">
                        @csrf
                        <div class="form-grid">
                            <div class="md:col-span-2"><label for="decision_description">Decision ou action a suivre</label><textarea id="decision_description" name="description" rows="3" required>{{ old('description') }}</textarea>@error('description')<x-form.error :message="$message" />@enderror</div>
                            <div><label for="decision_responsible_id">Responsable</label><select id="decision_responsible_id" name="responsible_id"><option value="">A attribuer</option>@foreach ($decisionUserOptions as $member)<option value="{{ $member->id }}" @selected((string) old('responsible_id') === (string) $member->id)>{{ $member->name }}</option>@endforeach</select>@error('responsible_id')<x-form.error :message="$message" />@enderror</div>
                            <div><label for="decision_priority">Priorite</label><select id="decision_priority" name="priority" required><option value="low">Basse</option><option value="normal" selected>Normale</option><option value="high">Haute</option><option value="critical">Critique</option></select>@error('priority')<x-form.error :message="$message" />@enderror</div>
                            <div><label for="decision_due_at">Échéance</label><input id="decision_due_at" name="due_at" type="date" value="{{ old('due_at') }}">@error('due_at')<x-form.error :message="$message" />@enderror</div>
                        </div>
                        <div class="form-actions mt-4"><button class="btn btn-primary min-h-10 px-4" type="submit">Ajouter au suivi</button></div>
                    </form>
                @endif

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700 dark:text-slate-400"><tr><th class="px-3 py-3">Decision</th><th class="px-3 py-3">Responsable</th><th class="px-3 py-3">Échéance</th><th class="px-3 py-3">Etat</th><th class="px-3 py-3">Suivi</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse ($report->meetingDecisions as $decision)
                                <tr class="align-top"><td class="px-3 py-4 font-medium text-[#17324a] dark:text-slate-100">{{ $decision->description }}<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Priorite: {{ ['low' => 'basse', 'normal' => 'normale', 'high' => 'haute', 'critical' => 'critique'][$decision->priority] ?? $decision->priority }}</p></td><td class="px-3 py-4 text-slate-700 dark:text-slate-200">{{ $decision->responsible?->name ?? 'A attribuer' }}</td><td class="px-3 py-4 text-slate-700 dark:text-slate-200">{{ $decision->due_at?->format('d/m/Y') ?? '-' }}</td><td class="px-3 py-4"><x-ui.badge :label="match($decision->status) { 'to_do' => 'A faire', 'in_progress' => 'En cours', 'completed' => 'Terminee', 'suspended' => 'Suspendue', default => $decision->status }" :tone="match($decision->status) { 'completed' => 'success', 'suspended' => 'danger', 'in_progress' => 'warning', default => 'neutral' }" /></td><td class="px-3 py-4 text-slate-700 dark:text-slate-200">@if ($decision->follow_up_note)<p class="mb-3 whitespace-pre-line">{{ $decision->follow_up_note }}</p>@endif @if ($reportService->canUpdateMeetingDecision($currentUser, $report, $decision))<form class="space-y-2" method="POST" action="{{ route('workspace.reports.decisions.update', [$report, $decision]) }}">@csrf @method('PATCH')<select name="status"><option value="to_do" @selected($decision->status === 'to_do')>A faire</option><option value="in_progress" @selected($decision->status === 'in_progress')>En cours</option><option value="completed" @selected($decision->status === 'completed')>Terminee</option><option value="suspended" @selected($decision->status === 'suspended')>Suspendue</option></select><textarea name="follow_up_note" rows="2" placeholder="Point de suivi">{{ $decision->follow_up_note }}</textarea><button class="btn btn-secondary btn-sm" type="submit">Mettre a jour</button></form>@endif</td></tr>
                            @empty
                                <tr><td class="px-3 py-5 text-slate-500 dark:text-slate-400" colspan="5">Aucune decision de reunion a suivre.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($canReview)
            <section class="app-screen-block rounded-lg border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800 dark:bg-amber-950/20">
                <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Décision de vérification</h2>
                <form class="mt-4 form-shell" method="POST" action="{{ route('workspace.reports.review', $report) }}">
                    @csrf
                    <div class="form-grid"><div><label for="decision">Décision</label><select id="decision" name="decision"><option value="approve">Valider et transmettre</option><option value="return">Demander une correction</option></select></div><div class="md:col-span-2"><label for="note">Note de vérification</label><textarea id="note" name="note" rows="3" required></textarea>@error('note')<x-form.error :message="$message" />@enderror</div></div>
                    <div class="form-actions mt-4"><button class="btn btn-primary min-h-10 px-4" type="submit">Enregistrer la décision</button></div>
                </form>
            </section>
        @endif

        <section class="app-screen-block rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Historique du dossier</h2>
            <ol class="mt-4 space-y-3 border-l-2 border-slate-200 pl-4 dark:border-slate-700">
                @forelse (($report->review_history ?? []) as $entry)
                    <li><p class="text-sm font-semibold text-[#17324a] dark:text-slate-100">{{ $entry['message'] ?? '-' }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $entry['user'] ?? 'Système' }} · {{ $entry['role'] ?? '-' }} · {{ isset($entry['at']) ? \Illuminate\Support\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-' }}</p>@if (! empty($entry['note']))<p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ $entry['note'] }}</p>@endif</li>
                @empty
                    <li class="text-sm text-slate-500 dark:text-slate-400">Aucun historique disponible.</li>
                @endforelse
            </ol>
        </section>
    </div>
@endsection
