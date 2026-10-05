@extends('layouts.workspace')

@section('content')
    @php
        $typeLabels = [
            \App\Models\InstitutionalReport::TYPE_INCIDENT => 'Rapport d’incident',
            \App\Models\InstitutionalReport::TYPE_ACTIVITY => 'Rapport d’activité',
            \App\Models\InstitutionalReport::TYPE_OTHER => 'Autre rapport',
        ];
        $statusTone = match ($report->status) {
            \App\Models\InstitutionalReport::STATUS_VERIFIED => 'success',
            \App\Models\InstitutionalReport::STATUS_RETURNED => 'danger',
            \App\Models\InstitutionalReport::STATUS_DRAFT => 'neutral',
            default => 'warning',
        };
        $nextAction = $reportService->nextAction($report);
        $verificationProgress = $reportService->verificationProgress($report);
        $latestReturn = $reportService->latestReturnEntry($report);
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title eyebrow="Rapports institutionnels" :title="$report->title" class="app-screen-block">
            <x-slot:actions>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.reports.index') }}">Retour aux rapports</a>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.audit.index', ['module' => 'institutional_reports']) }}">Traçabilité</a>
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-4" aria-label="Informations du rapport">
            <div><dt class="text-xs font-bold uppercase text-slate-500">Nature</dt><dd class="mt-1 font-semibold">{{ $typeLabels[$report->report_type] ?? 'Rapport institutionnel' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase text-slate-500">Statut</dt><dd class="mt-1"><x-ui.badge :label="$reportService->statusLabel($report->status)" :tone="$statusTone" /></dd></div>
            <div><dt class="text-xs font-bold uppercase text-slate-500">Périmètre</dt><dd class="mt-1">{{ $report->service?->code ?? $report->direction?->code ?? 'Agence' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase text-slate-500">Déposant</dt><dd class="mt-1">{{ $report->submittedBy?->name ?? 'Non renseigné' }}</dd></div>
            <div class="md:col-span-2 xl:col-span-4"><dt class="text-xs font-bold uppercase text-slate-500">Résumé</dt><dd class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $report->summary ?: 'Aucun résumé fourni.' }}</dd></div>
        </section>

        <section class="app-screen-block grid gap-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)]">
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Prochaine action</p>
                        <h2 class="mt-2 text-lg font-bold text-[#17324a] dark:text-slate-100">{{ $nextAction['label'] }}</h2>
                    </div>
                    <x-ui.badge :tone="$nextAction['tone']">{{ $reportService->statusLabel($report->status) }}</x-ui.badge>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $nextAction['description'] }}</p>

                @if ($latestReturn !== null && ! empty($latestReturn['note']))
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                        <p class="font-bold">Dernier motif de correction</p>
                        <p class="mt-1">{{ $latestReturn['note'] }}</p>
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-200">{{ $latestReturn['user'] ?? 'Utilisateur' }} · {{ isset($latestReturn['at']) ? \Illuminate\Support\Carbon::parse($latestReturn['at'])->format('d/m/Y H:i') : '-' }}</p>
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Circuit de vérification</p>
                        <h2 class="mt-2 text-lg font-bold text-[#17324a] dark:text-slate-100">SCIQ → Planification → Chefs</h2>
                    </div>
                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Les visas documentaires ne clôturent pas une action.</span>
                </div>
                <ol class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach ($verificationProgress as $step)
                        @php
                            $stepClasses = match ($step['state']) {
                                'done' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100',
                                'current' => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-100',
                                'returned' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100',
                                default => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200',
                            };
                            $stateLabel = match ($step['state']) {
                                'done' => 'Réalisé',
                                'current' => 'En cours',
                                'returned' => 'Suspendu',
                                default => 'À venir',
                            };
                        @endphp
                        <li class="rounded-lg border p-3 {{ $stepClasses }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold">{{ $step['label'] }}</p>
                                    <p class="mt-1 text-xs font-semibold opacity-80">{{ $step['actor'] }}</p>
                                </div>
                                <span class="rounded-full bg-white/70 px-2 py-0.5 text-xs font-bold dark:bg-slate-950/30">{{ $stateLabel }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-5 opacity-90">{{ $step['description'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section class="app-screen-block rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-bold text-[#17324a] dark:text-slate-100">Pièces justificatives</h2>
            <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                @forelse ($report->justificatifs as $piece)
                    <li class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="min-w-0"><p class="truncate font-semibold">{{ $piece->nom_original }}</p><p class="text-xs text-slate-500">Ajoutée {{ $piece->created_at?->format('d/m/Y H:i') }}</p></div>
                        <a class="btn btn-secondary btn-sm shrink-0" href="{{ route('workspace.reports.attachments.download', [$report, $piece]) }}">Télécharger</a>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">Aucune pièce jointe.</li>
                @endforelse
            </ul>
        </section>

        @if ($report->status === \App\Models\InstitutionalReport::STATUS_DRAFT && $canAmend)
            <form class="app-screen-block rounded-lg border border-sky-200 bg-sky-50/50 p-5 dark:border-sky-800 dark:bg-sky-950/20" method="POST" action="{{ route('workspace.reports.submit', $report) }}">
                @csrf
                <h2 class="text-base font-bold">Prochaine étape</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Transmettre le rapport au SCIQ pour démarrer le circuit de vérification.</p>
                <button class="btn btn-primary mt-4 min-h-10 px-4" type="submit">Soumettre au SCIQ</button>
            </form>
        @endif

        @if ($canAmend && $report->status === \App\Models\InstitutionalReport::STATUS_RETURNED)
            <section class="app-screen-block rounded-lg border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800 dark:bg-amber-950/20">
                <h2 class="text-base font-bold">Corriger le rapport</h2>
                <form class="form-shell mt-4" method="POST" action="{{ route('workspace.reports.resubmit', $report) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-grid">
                        <div class="md:col-span-2"><label for="summary">Résumé corrigé</label><textarea id="summary" name="summary" rows="4">{{ old('summary', $report->summary) }}</textarea>@error('summary')<x-form.error :message="$message" />@enderror</div>
                        <div><label for="attachments">Nouvelles pièces</label><input id="attachments" name="attachments[]" type="file" multiple>@error('attachments')<x-form.error :message="$message" />@enderror</div>
                    </div>
                    <button class="btn btn-primary mt-4 min-h-10 px-4" type="submit">Soumettre la correction</button>
                </form>
            </section>
        @endif

        @if ($canReview)
            <section class="app-screen-block rounded-lg border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800 dark:bg-amber-950/20">
                <h2 class="text-base font-bold">Décision de vérification</h2>
                <form class="form-shell mt-4" method="POST" action="{{ route('workspace.reports.review', $report) }}">
                    @csrf
                    <div class="form-grid"><div><label for="decision">Décision</label><select id="decision" name="decision"><option value="approve">Valider et transmettre</option><option value="return">Demander une correction</option></select></div><div class="md:col-span-2"><label for="note">Note de vérification</label><textarea id="note" name="note" rows="3" required></textarea>@error('note')<x-form.error :message="$message" />@enderror</div></div>
                    <button class="btn btn-primary mt-4 min-h-10 px-4" type="submit">Enregistrer la décision</button>
                </form>
            </section>
        @endif

        <section class="app-screen-block rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-bold">Historique du dossier</h2>
            <ol class="mt-4 space-y-3 border-l-2 border-slate-200 pl-4 dark:border-slate-700">
                @forelse (($report->review_history ?? []) as $entry)
                    <li><p class="text-sm font-semibold">{{ $entry['message'] ?? '-' }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry['user'] ?? 'Système' }} · {{ $entry['role'] ?? '-' }} · {{ isset($entry['at']) ? \Illuminate\Support\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-' }}</p>@if (! empty($entry['note']))<p class="mt-2 text-sm">{{ $entry['note'] }}</p>@endif</li>
                @empty
                    <li class="text-sm text-slate-500">Aucun historique disponible.</li>
                @endforelse
            </ol>
        </section>
    </div>
@endsection
