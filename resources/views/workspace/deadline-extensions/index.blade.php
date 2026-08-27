@extends('layouts.workspace')

@section('content')
    @php
        $statusLabels = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'Avis chef attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'Analyse chef',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'Complement demande',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'Accord directeur attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'Accord final DG attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'Ancienne décision à appliquer',
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
        $stageLabels = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'Chef de service',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'Chef de service',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'Demandeur',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'Directeur',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'Directeur',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'Directeur',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'DG',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'DG',
            \App\Models\DeadlineExtensionRequest::STATUS_REJETEE => 'Terminee',
            \App\Models\DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE => 'Terminee',
        ];
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            eyebrow="PTA / Actions"
            title="Demandes de modification"
            subtitle="Circuit : RMO, Chef de service, Directeur, puis accord final DG."
        />

        <section class="showcase-panel mb-4 app-screen-block">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <nav class="flex flex-wrap gap-2" aria-label="Vues des reports">
                    <a
                        href="{{ route('workspace.deadline-extension.index', ['vue' => 'a_traiter']) }}"
                        class="btn {{ $view === 'a_traiter' ? 'btn-primary' : 'btn-secondary' }}"
                        @if ($view === 'a_traiter') aria-current="page" @endif
                    >
                        A traiter <span class="ml-1">({{ $actionable_count }})</span>
                    </a>
                    <a
                        href="{{ route('workspace.deadline-extension.index', ['vue' => 'mes_demandes']) }}"
                        class="btn {{ $view === 'mes_demandes' ? 'btn-primary' : 'btn-secondary' }}"
                        @if ($view === 'mes_demandes') aria-current="page" @endif
                    >
                        Mes demandes <span class="ml-1">({{ $mine_count }})</span>
                    </a>
                </nav>

                <form method="GET" action="{{ route('workspace.deadline-extension.index') }}" class="flex w-full max-w-xl gap-2" data-auto-filter-form>
                    <input type="hidden" name="vue" value="{{ $view }}">
                    <label class="sr-only" for="deadline-search">Rechercher un report</label>
                    <input id="deadline-search" name="recherche" type="search" value="{{ $search }}" placeholder="Action, demandeur, motif ou statut" class="min-w-0 flex-1">
                </form>
            </div>
        </section>

        <section class="showcase-panel app-screen-block">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">{{ $view === 'a_traiter' ? 'Dossiers a traiter' : 'Mes demandes de report' }}</h2>
                    <p class="form-section-subtitle">
                        {{ $view === 'a_traiter'
                            ? 'La liste est automatiquement filtree selon votre role et l etape courante.'
                            : 'Suivez ici toutes vos demandes, y compris celles deja traitees.' }}
                    </p>
                </div>
                <span class="text-sm font-medium text-slate-500">{{ $rows->total() }} dossier(s)</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th>Action / element</th>
                            <th>Demandeur</th>
                            <th>Dates</th>
                            <th>Motif</th>
                            <th>Etape courante</th>
                            <th>Piece</th>
                            <th>Commande</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $deadlineRequest)
                            <tr id="deadline-request-{{ $deadlineRequest->id }}" class="scroll-mt-28 target:bg-yellow-50">
                                <td class="min-w-[250px]">
                                    <p class="font-semibold text-slate-900">{{ $deadlineRequest->action?->libelle ?? 'Action indisponible' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $deadlineRequest->sousAction?->libelle ?? 'Action principale' }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Dossier #{{ $deadlineRequest->id }} · {{ optional($deadlineRequest->created_at)->format('d/m/Y H:i') }}</p>
                                </td>
                                <td>
                                    <p class="font-medium text-slate-800">{{ $deadlineRequest->requestedBy?->name ?? '-' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $deadlineRequest->requestedBy?->role ? \App\Support\UiLabel::roleAudience($deadlineRequest->requestedBy->role) : '-' }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    <p>{{ optional($deadlineRequest->old_deadline)->format('d/m/Y') ?: '-' }}</p>
                                    <p class="mt-1 font-semibold text-[#1e5fa8]">vers {{ optional($deadlineRequest->requested_deadline)->format('d/m/Y') ?: '-' }}</p>
                                </td>
                                <td class="max-w-sm">
                                    <p class="font-medium text-slate-800">{{ $deadlineRequest->motif }}</p>
                                    <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $deadlineRequest->justification }}</p>
                                </td>
                                <td class="min-w-[190px]">
                                    <span class="anbg-badge {{ $statusClasses[$deadlineRequest->status] ?? 'anbg-badge-neutral' }}">
                                        {{ $statusLabels[$deadlineRequest->status] ?? str_replace('_', ' ', (string) $deadlineRequest->status) }}
                                    </span>
                                    <p class="mt-2 text-xs font-medium text-slate-600">Chez : {{ $stageLabels[$deadlineRequest->status] ?? '-' }}</p>
                                </td>
                                <td>
                                    <a class="font-semibold text-[#1e5fa8]" href="{{ route('workspace.deadline-extension.attachment', $deadlineRequest) }}">
                                        {{ $deadlineRequest->attachment_name ?: 'Telecharger' }}
                                    </a>
                                </td>
                                <td>
                                    @if ($deadlineRequest->action)
                                        <a class="btn btn-primary btn-sm whitespace-nowrap" href="{{ route('workspace.deadline-extension.show', $deadlineRequest) }}">
                                            {{ $view === 'a_traiter' ? 'Traiter' : 'Consulter' }}
                                        </a>
                                    @else
                                        <span class="text-sm text-slate-400">Indisponible</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state
                                        title="Aucun report"
                                        message="Aucun dossier ne correspond a cette vue et a votre perimetre."
                                        icon="calendar-check"
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
