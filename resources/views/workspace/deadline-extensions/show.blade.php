@extends('layouts.workspace')

@section('content')
    @php
        $metadata = is_array($deadlineRequest->metadata) ? $deadlineRequest->metadata : [];
        $revisionHistory = is_array($metadata['revision_history'] ?? null) ? $metadata['revision_history'] : [];
        $statusLabels = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'Avis chef attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'Analyse chef',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'Complement demande',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'Accord du directeur attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'Accord final DG attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'Ancienne décision à appliquer par la DG',
            \App\Models\DeadlineExtensionRequest::STATUS_REJETEE => 'Rejetee',
            \App\Models\DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE => 'Modifications appliquées',
        ];
        $statusClasses = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'anbg-badge-success',
            \App\Models\DeadlineExtensionRequest::STATUS_REJETEE => 'anbg-badge-danger',
            \App\Models\DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE => 'anbg-badge-success',
        ];
        $complementComment = $deadlineRequest->final_decision === \App\Models\DeadlineExtensionRequest::DECISION_COMPLEMENT
            ? $deadlineRequest->final_comment
            : ($deadlineRequest->director_decision === \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT
                ? $deadlineRequest->director_comment
                : $deadlineRequest->chef_comment);
        $steps = [
            ['label' => 'RMO', 'done' => true, 'actor' => $deadlineRequest->requestedBy?->name],
            ['label' => 'Chef de service', 'done' => $deadlineRequest->chef_reviewed_at !== null, 'actor' => $deadlineRequest->chefReviewedBy?->name],
            ['label' => 'Directeur', 'done' => $deadlineRequest->director_reviewed_at !== null, 'actor' => $deadlineRequest->directorReviewedBy?->name],
            ['label' => 'DG · accord final', 'done' => $deadlineRequest->final_decided_at !== null, 'actor' => $deadlineRequest->finalDecidedBy?->name],
        ];
        $requestedChanges = is_array($deadlineRequest->requested_changes) ? $deadlineRequest->requested_changes : [];
        $originalValues = is_array($deadlineRequest->original_values) ? $deadlineRequest->original_values : [];
        $responsableNames = collect($responsableOptions ?? [])->keyBy('id');
        $displayChangeValue = function (string $field, mixed $value) use ($responsableNames): string {
            if ($field === 'responsables') {
                return collect(is_array($value) ? $value : [])
                    ->map(fn ($id) => $responsableNames->get((int) $id)?->name ?? '#'.$id)
                    ->implode(', ');
            }

            return is_bool($value) ? ($value ? 'Oui' : 'Non') : (trim((string) $value) !== '' ? (string) $value : '—');
        };
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            eyebrow="Reports d'echeance"
            title="Dossier #{{ $deadlineRequest->id }}"
            subtitle="{{ $deadlineRequest->action?->libelle ?? 'Action indisponible' }}"
        />

        <div class="mb-4 flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="{{ route('workspace.deadline-extension.index') }}">Retour a la file</a>
            @if ($deadlineRequest->action)
                <a class="btn btn-secondary" href="{{ route('workspace.actions.suivi', $deadlineRequest->action) }}#action-echeances">Voir l'action</a>
            @endif
        </div>

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Progression du circuit</h2>
                    <p class="form-section-subtitle">Action ou sous-action : {{ $deadlineRequest->sousAction?->libelle ?? 'Action principale' }}</p>
                </div>
                <span class="anbg-badge {{ $statusClasses[$deadlineRequest->status] ?? 'anbg-badge-neutral' }}">
                    {{ $statusLabels[$deadlineRequest->status] ?? str_replace('_', ' ', (string) $deadlineRequest->status) }}
                </span>
            </div>

            <ol class="mt-5 grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                @foreach ($steps as $index => $step)
                    <li class="border-l-4 px-3 py-2 {{ $step['done'] ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 bg-slate-50' }}">
                        <p class="text-xs font-semibold uppercase text-slate-500">Etape {{ $index + 1 }}</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ $step['label'] }}</p>
                        <p class="mt-1 min-h-5 text-xs text-slate-500">{{ $step['actor'] ?: ($step['done'] ? 'Enregistree' : 'En attente') }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(340px,0.8fr)]">
            <div class="space-y-4">
                <section class="showcase-panel app-screen-block">
                    <h2 class="showcase-panel-title">Dossier</h2>
                    <dl class="action-fiche-dl mt-4">
                        <dt>Demandeur</dt><dd>{{ $deadlineRequest->requestedBy?->name ?? '-' }}</dd>
                        <dt>Élément</dt><dd>{{ $deadlineRequest->sousAction?->libelle ?? 'Action principale' }}</dd>
                        <dt>Motif</dt><dd>{{ $deadlineRequest->motif }}</dd>
                        <dt>Justification</dt><dd class="whitespace-pre-line">{{ $deadlineRequest->justification }}</dd>
                        <dt>Revisions</dt><dd>{{ (int) ($metadata['revision_count'] ?? 0) }}</dd>
                    </dl>

                    <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700">
                        <div class="grid grid-cols-[minmax(120px,0.7fr)_minmax(0,1fr)_minmax(0,1fr)] bg-slate-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                            <span>Paramètre</span><span>Valeur actuelle figée</span><span>Valeur demandée</span>
                        </div>
                        @forelse ($requestedChanges as $field => $value)
                            <div class="grid grid-cols-[minmax(120px,0.7fr)_minmax(0,1fr)_minmax(0,1fr)] gap-3 border-t border-slate-200 px-3 py-3 text-sm dark:border-slate-700">
                                <strong class="text-slate-900 dark:text-white">{{ $changeFieldLabels[$field] ?? $field }}</strong>
                                <span class="break-words text-slate-500 dark:text-slate-400">{{ $displayChangeValue($field, $originalValues[$field] ?? null) }}</span>
                                <span class="break-words font-semibold text-[#1e5fa8] dark:text-sky-300">{{ $displayChangeValue($field, $value) }}</span>
                            </div>
                        @empty
                            <p class="border-t border-slate-200 px-3 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Ancien dossier : modification d’échéance uniquement.</p>
                        @endforelse
                    </div>
                </section>

                <section class="showcase-panel app-screen-block">
                    <h2 class="showcase-panel-title">Pieces justificatives</h2>
                    <div class="mt-4 divide-y divide-slate-200">
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
                            <div>
                                <p class="font-semibold text-slate-900">Version actuelle</p>
                                <p class="text-sm text-slate-500">{{ $deadlineRequest->attachment_name ?: 'Piece justificative' }}</p>
                            </div>
                            <a class="btn btn-secondary btn-sm" href="{{ route('workspace.deadline-extension.attachment', $deadlineRequest) }}">Télécharger</a>
                        </div>
                        @foreach ($revisionHistory as $revisionIndex => $revision)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <p class="font-semibold text-slate-900">Version {{ $revisionIndex + 1 }}</p>
                                    <p class="text-sm text-slate-500">{{ $revision['previous_attachment_name'] ?? 'Piece justificative' }}</p>
                                </div>
                                <a class="btn btn-secondary btn-sm" href="{{ route('workspace.deadline-extension.attachment.revision', [$deadlineRequest, $revisionIndex]) }}">Télécharger</a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="showcase-panel app-screen-block">
                    <h2 class="showcase-panel-title">Decisions enregistrees</h2>
                    <dl class="action-fiche-dl mt-4">
                        <dt>Chef de service</dt>
                        <dd>{{ $deadlineRequest->chef_avis ?: '-' }}{{ $deadlineRequest->chef_comment ? ' - '.$deadlineRequest->chef_comment : '' }}</dd>
                        <dt>Directeur</dt>
                        <dd>{{ $deadlineRequest->director_decision ?: '-' }}{{ $deadlineRequest->director_comment ? ' - '.$deadlineRequest->director_comment : '' }}</dd>
                        <dt>DG · accord final</dt>
                        <dd>{{ $deadlineRequest->final_decision ?: $deadlineRequest->dg_decision ?: '-' }}{{ $deadlineRequest->final_comment ? ' - '.$deadlineRequest->final_comment : '' }}</dd>
                        <dt>Application automatique</dt>
                        <dd>{{ $deadlineRequest->appliedBy?->name ?? '-' }}{{ $deadlineRequest->applied_at ? ' - '.$deadlineRequest->applied_at->format('d/m/Y H:i') : '' }}</dd>
                    </dl>
                </section>
            </div>

            <section class="showcase-panel app-screen-block self-start xl:sticky xl:top-24">
                <h2 class="showcase-panel-title">Traitement</h2>

                @if ($canResubmit)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.deadline-extension.resubmit', $deadlineRequest) }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="return_to" value="report_detail">
                        <div class="border-l-4 border-amber-500 bg-amber-50 p-3 text-sm text-amber-950">
                            {{ $complementComment ?: 'Un complement est requis pour poursuivre le circuit.' }}
                        </div>
                        <x-deadline-change-fields
                            prefix="detail"
                            :action="$deadlineRequest->action"
                            :selected-sub-action-id="$deadlineRequest->sous_action_id"
                            :changes="$requestedChanges"
                            :responsable-options="$responsableOptions"
                        />
                        <div>
                            <label for="detail_motif">Motif actualise</label>
                            <input id="detail_motif" name="motif" type="text" value="{{ old('motif', $deadlineRequest->motif) }}" maxlength="255" required>
                        </div>
                        <div>
                            <label for="detail_justification">Justification completee</label>
                            <textarea id="detail_justification" name="justification" rows="5" required>{{ old('justification', $deadlineRequest->justification) }}</textarea>
                        </div>
                        <div>
                            <label for="detail_piece">Nouvelle piece justificative</label>
                            <input id="detail_piece" name="piece_justificative" type="file" accept="{{ $documentAccept }}" required>
                        </div>
                        <button class="btn btn-primary w-full" type="submit">Completer et retransmettre</button>
                    </form>
                @elseif ($canReviewByChef)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.deadline-extension.chef', $deadlineRequest) }}">
                        @csrf
                        <input type="hidden" name="return_to" value="report_detail">
                        <div>
                            <label for="detail_chef_decision">Avis du Chef de service</label>
                            <select id="detail_chef_decision" name="decision" required>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_FAVORABLE }}">Avis favorable</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_DEFAVORABLE }}">Avis defavorable</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT }}">Demander un complement</option>
                            </select>
                        </div>
                        <div>
                            <label for="detail_chef_comment">Commentaire</label>
                            <textarea id="detail_chef_comment" name="comment" rows="4"></textarea>
                        </div>
                        <button class="btn btn-primary w-full" type="submit">Enregistrer l'avis</button>
                    </form>
                @elseif ($canReviewByDirector)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.deadline-extension.direction', $deadlineRequest) }}">
                        @csrf
                        <input type="hidden" name="return_to" value="report_detail">
                        <div>
                            <label for="detail_controller_decision">Décision du directeur</label>
                            <select id="detail_controller_decision" name="decision" required>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_FAVORABLE }}">Avis favorable</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_DEFAVORABLE }}">Avis defavorable</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT }}">Demander un complement</option>
                            </select>
                        </div>
                        <div>
                            <label for="detail_controller_comment">Commentaire</label>
                            <textarea id="detail_controller_comment" name="comment" rows="4"></textarea>
                        </div>
                        <button class="btn btn-primary w-full" type="submit">Enregistrer l’accord direction</button>
                    </form>
                @elseif ($canReviewFinal)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.deadline-extension.final', $deadlineRequest) }}">
                        @csrf
                        <input type="hidden" name="return_to" value="report_detail">
                        <div>
                            <label for="detail_final_decision">Decision finale</label>
                            <select id="detail_final_decision" name="decision" required>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_APPROUVER }}">Approuver</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_REJETER }}">Rejeter</option>
                                <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_COMPLEMENT }}">Demander un complement</option>
                            </select>
                        </div>
                        <div>
                            <label for="detail_final_comment">Commentaire</label>
                            <textarea id="detail_final_comment" name="comment" rows="4"></textarea>
                        </div>
                        <button class="btn btn-primary w-full" type="submit">Décider et appliquer</button>
                    </form>
                @elseif ($canApply)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.deadline-extension.apply', $deadlineRequest) }}">
                        @csrf
                        <input type="hidden" name="return_to" value="report_detail">
                        <div class="border-l-4 border-emerald-500 bg-emerald-50 p-3 text-sm text-emerald-950">
                            Ancienne décision approuvée en attente d’application par la DG.
                        </div>
                        <button class="btn btn-primary w-full" type="submit">Appliquer la modification</button>
                    </form>
                @else
                    <div class="mt-4 border-l-4 border-slate-300 bg-slate-50 p-3 text-sm text-slate-600">
                        Aucune decision n'est requise de votre profil a cette etape.
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
