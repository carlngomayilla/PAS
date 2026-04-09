@extends('layouts.workspace')

@section('title', "Centre d'alertes")

@php
    $levelStyles = [
        'urgence' => [
            'panel' => 'border-red-500/45 bg-red-50/95 dark:border-red-400/40 dark:bg-red-500/10',
            'dot' => 'bg-red-500',
            'iconBadge' => 'bg-red-500 text-white',
            'badge' => 'anbg-badge anbg-badge-danger',
            'soft' => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
            'icon' => '!',
        ],
        'critical' => [
            'panel' => 'border-[#f9b13c]/40 bg-[#fff0df]/90 dark:border-[#f9b13c]/35 dark:bg-[#f9b13c]/10',
            'dot' => 'bg-[#f9b13c]',
            'iconBadge' => 'bg-[#f9b13c] text-white',
            'badge' => 'anbg-badge anbg-badge-danger',
            'soft' => 'bg-[#fff0df] text-[#f9b13c] dark:bg-[#f9b13c]/15 dark:text-[#f8e932]',
            'icon' => '!',
        ],
        'warning' => [
            'panel' => 'border-[#f9b13c]/40 bg-[#fff8d6]/90 dark:border-[#f9b13c]/35 dark:bg-[#f9b13c]/10',
            'dot' => 'bg-[#f0e509]',
            'iconBadge' => 'bg-[#f0e509] text-[#1c203d]',
            'badge' => 'anbg-badge anbg-badge-warning',
            'soft' => 'bg-[#fff8d6] text-[#f9b13c] dark:bg-[#f9b13c]/15 dark:text-[#f8e932]',
            'icon' => '!',
        ],
        'info' => [
            'panel' => 'border-[#3996d3]/40 bg-[#e8f3fb]/90 dark:border-[#3996d3]/35 dark:bg-[#3996d3]/10',
            'dot' => 'bg-[#3996d3]',
            'iconBadge' => 'bg-[#3996d3] text-white',
            'badge' => 'anbg-badge anbg-badge-info',
            'soft' => 'bg-[#e8f3fb] text-[#3996d3] dark:bg-[#3996d3]/15 dark:text-[#8fc043]',
            'icon' => 'i',
        ],
    ];

    $filterButtonBase = 'alert-filter inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition';
@endphp

@section('content')
    @php
        $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
        $alertSummaryCards = [
            [
                'label' => 'Urgences',
                'value' => $summary['urgence'] ?? 0,
                'meta' => 'Escalade DG immediate',
                'href' => route('workspace.alertes', ['niveau' => 'urgence', 'limit' => 100]),
                'valueClass' => 'showcase-kpi-number text-red-600 dark:text-red-300',
                'badge' => null,
                'badge_tone' => 'info',
            ],
            [
                'label' => 'Non lues',
                'value' => $summary['unread'] ?? 0,
                'meta' => 'Alertes a traiter',
                'href' => route('workspace.alertes', ['etat' => 'unread', 'limit' => 100]),
                'valueClass' => 'showcase-kpi-number text-[#f9b13c] dark:text-[#f8e932]',
                'badge' => null,
                'badge_tone' => 'info',
            ],
            [
                'label' => 'Critiques',
                'value' => $summary['critical'] ?? 0,
                'meta' => 'Escalade immediate',
                'href' => route('workspace.alertes', ['niveau' => 'critical', 'limit' => 100]),
                'valueClass' => 'showcase-kpi-number text-[#f9b13c] dark:text-[#f8e932]',
                'badge' => null,
                'badge_tone' => 'info',
            ],
            [
                'label' => 'Attention',
                'value' => $summary['warning'] ?? 0,
                'meta' => 'Surveillance rapprochee',
                'href' => route('workspace.alertes', ['niveau' => 'warning', 'limit' => 100]),
                'valueClass' => 'showcase-kpi-number text-[#f9b13c] dark:text-[#f8e932]',
                'badge' => null,
                'badge_tone' => 'info',
            ],
            [
                'label' => 'Info',
                'value' => $summary['info'] ?? 0,
                'meta' => 'Information de contexte',
                'href' => route('workspace.alertes', ['niveau' => 'info', 'limit' => 100]),
                'valueClass' => 'showcase-kpi-number text-[#3996d3] dark:text-[#8fc043]',
                'badge' => null,
                'badge_tone' => 'info',
            ],
        ];
        $kpiCards = [
            [
                'label' => $metricLabel('global'),
                'value' => number_format((float) ($kpiSummary['global'] ?? 0), 0),
                'meta' => 'Synthese DG des actions validees direction',
                'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc']),
                'valueClass' => 'showcase-kpi-number text-[#3996d3] dark:text-[#8fc043]',
                'badge' => null,
                'badge_tone' => 'success',
            ],
            [
                'label' => 'Qualite',
                'value' => number_format((float) ($kpiSummary['qualite'] ?? 0), 0),
                'meta' => 'Conformite des preuves et du suivi',
                'href' => route('workspace.actions.index', ['sort' => 'kpi_qualite_desc']),
                'valueClass' => 'showcase-kpi-number text-[#8fc043] dark:text-[#f8e932]',
                'badge' => null,
                'badge_tone' => 'success',
            ],
            [
                'label' => 'Risque',
                'value' => number_format((float) ($kpiSummary['risque'] ?? 0), 0),
                'meta' => 'Exposition globale et actions fragiles',
                'href' => route('workspace.actions.index', ['sort' => 'kpi_risque_desc']),
                'valueClass' => 'showcase-kpi-number text-[#f9b13c] dark:text-[#f8e932]',
                'badge' => null,
                'badge_tone' => 'success',
            ],
            [
                'label' => 'Progression',
                'value' => number_format((float) ($kpiSummary['progression'] ?? 0), 0),
                'meta' => 'Execution moyenne consolidee',
                'href' => route('workspace.actions.index', ['sort' => 'progression_desc']),
                'valueClass' => 'showcase-kpi-number text-[#3996d3] dark:text-[#8fc043]',
                'badge' => null,
                'badge_tone' => 'success',
            ],
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Alertes operationnelles</span>
                <h1 class="showcase-title">Centre d'alertes</h1>
            </div>

            <div class="showcase-action-row">
                <span class="showcase-chip">
                    <span class="showcase-chip-dot bg-blue-600"></span>
                    Limite d affichage: {{ $limit }} elements
                </span>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.pilotage') }}">
                    Retour pilotage
                </a>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting') }}">
                    Ouvrir le reporting
                </a>
                @if (($summary['unread'] ?? 0) > 0)
                    <form method="POST" action="{{ route('workspace.alertes.read_all', ['limit' => $limit]) }}">
                        @csrf
                        <button class="btn btn-blue rounded-2xl px-4 py-2.5" type="submit">
                            Tout marquer comme lu
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($alertSummaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :value-class="$card['valueClass']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($kpiCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :value-class="$card['valueClass']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-1.5 dark:border-slate-800 dark:bg-slate-950/50">
                <button class="{{ $filterButtonBase }} border-slate-300 bg-slate-900 text-white dark:border-slate-700 dark:bg-slate-100 dark:text-slate-900" data-level-filter="all" type="button">
                    Tous
                    <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px] leading-none">{{ $summary['total'] ?? 0 }}</span>
                </button>
                @foreach (['urgence', 'critical', 'warning', 'info'] as $level)
                    @php $style = $levelStyles[$level]; @endphp
                    <button class="{{ $filterButtonBase }} border-transparent {{ $style['soft'] }}" data-level-filter="{{ $level }}" type="button">
                        {{ $level === 'urgence' ? 'Urgence' : ($level === 'critical' ? 'Critique' : ($level === 'warning' ? 'Attention' : 'Info')) }}
                        @if (($levelUnreadCounts[$level] ?? 0) > 0)
                            <span class="{{ $style['badge'] }} px-2 py-0.5 text-[10px] leading-none">{{ $levelUnreadCounts[$level] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-1.5 dark:border-slate-800 dark:bg-slate-950/50">
                <button class="{{ $filterButtonBase }} border-slate-300 bg-slate-900 text-white dark:border-slate-700 dark:bg-slate-100 dark:text-slate-900" data-state-filter="all" type="button">
                    Toutes
                </button>
                <button class="{{ $filterButtonBase }} border-transparent bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-200" data-state-filter="unread" type="button">
                    Non lues
                    @if (($summary['unread'] ?? 0) > 0)
                        <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px] leading-none">{{ $summary['unread'] }}</span>
                    @endif
                </button>
                <button class="{{ $filterButtonBase }} border-transparent bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-200" data-state-filter="read" type="button">
                    Lues
                </button>
            </div>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3 app-screen-block">
        <div>
            <h2 class="showcase-panel-title">Fil d alertes</h2>
        </div>
    </div>

    <section class="space-y-3" id="alert-feed">
        @forelse ($alertItems as $alert)
            @php
                $style = $levelStyles[$alert['niveau']] ?? $levelStyles['info'];
                $isUnread = (bool) ($alert['is_unread'] ?? false);
            @endphp
            <a
                class="alert-card group relative block overflow-hidden rounded-2xl border bg-white/95 p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:bg-slate-900/95 {{ $isUnread ? $style['panel'] : 'border-slate-200 dark:border-slate-800' }}"
                data-alert-card
                data-level="{{ $alert['niveau'] }}"
                data-state="{{ $isUnread ? 'unread' : 'read' }}"
                href="{{ $alert['read_url'] }}"
            >
                @if ($isUnread)
                    <span class="absolute right-4 top-4 inline-flex h-3 w-3 rounded-full {{ $style['dot'] }} ring-4 ring-white/80 dark:ring-slate-900/80"></span>
                @endif

                <div class="flex items-start gap-4">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-lg font-bold {{ $style['iconBadge'] }}">
                        {{ $style['icon'] }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-950 dark:text-slate-50">{{ $alert['titre'] }}</h2>
                            <span class="{{ $style['badge'] }} px-3">
                                {{ $alert['niveau_label'] }}
                            </span>
                            <span class="anbg-badge anbg-badge-neutral px-3">
                                {{ $alert['type_label'] }}
                            </span>
                            @if ($isUnread)
                                <span class="anbg-badge anbg-badge-warning px-3">
                                    Non lu
                                </span>
                            @endif
                        </div>

                        <p class="mt-2 text-sm leading-6 text-slate-700 dark:text-slate-200">{{ $alert['message'] }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                            <span>{{ $alert['direction'] }}</span>
                            <span>{{ $alert['service'] }}</span>
                            <span>{{ $alert['date_label'] }}</span>
                            <span>Section cible: {{ $alert['section_label'] }}</span>
                        </div>

                        @if (!empty($alert['action']))
                            <div class="mt-3 rounded-2xl border border-slate-200/80 bg-slate-50/90 px-3 py-2 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/50 dark:text-slate-200">
                                <strong>Action:</strong> {{ $alert['action']['libelle'] }}
                                <span class="mx-2 text-slate-400">|</span>
                                <strong>PTA:</strong> {{ $alert['action']['pta'] }}
                            </div>
                        @endif

                        @if (!empty($alert['metrics']) || !empty($alert['escalation_label']))
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @foreach ([
                                    'kpi_global' => 'Indicateur global',
                                    'kpi_qualite' => 'Qualite',
                                    'kpi_risque' => 'Risque',
                                ] as $metricKey => $metricLabel)
                                    @php
                                        $metricValue = (float) ($alert['metrics'][$metricKey] ?? 0);
                                        $metricTone = $metricValue >= 80 ? 'success' : ($metricValue >= 60 ? 'warning' : 'danger');
                                    @endphp
                                    @if (array_key_exists($metricKey, (array) ($alert['metrics'] ?? [])))
                                        <span class="anbg-badge anbg-badge-{{ $metricTone }} px-3 py-1 text-[11px]">
                                            {{ $metricLabel }} {{ number_format($metricValue, 0) }}
                                        </span>
                                    @endif
                                @endforeach

                                @if (!empty($alert['escalation_label']))
                                    <span class="anbg-badge anbg-badge-info px-3 py-1 text-[11px]">
                                        Escalade {{ $alert['escalation_label'] }}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="hidden shrink-0 self-center rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition group-hover:bg-slate-900 group-hover:text-white dark:bg-slate-800 dark:text-slate-200 dark:group-hover:bg-slate-100 dark:group-hover:text-slate-900 md:block">
                        Voir la cause
                    </div>
                </div>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white/90 px-6 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/90 dark:text-slate-400">
                Aucune alerte sur le perimetre courant.
            </div>
        @endforelse

        <div id="alert-empty-state" class="hidden rounded-2xl border border-dashed border-slate-300 bg-white/90 px-6 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/90 dark:text-slate-400">
            Aucune alerte ne correspond aux filtres selectionnes.
        </div>
    </section>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var cards = Array.prototype.slice.call(document.querySelectorAll('[data-alert-card]'));
            if (!cards.length) {
                return;
            }

            var levelButtons = Array.prototype.slice.call(document.querySelectorAll('[data-level-filter]'));
            var stateButtons = Array.prototype.slice.call(document.querySelectorAll('[data-state-filter]'));
            var emptyState = document.getElementById('alert-empty-state');
            var activeLevel = @json($activeLevel);
            var activeState = @json($activeState);

            function syncButtons(buttons, activeValue, attribute) {
                buttons.forEach(function (button) {
                    var isActive = button.getAttribute(attribute) === activeValue;
                    button.classList.toggle('bg-slate-900', isActive);
                    button.classList.toggle('text-white', isActive);
                    button.classList.toggle('dark:bg-slate-100', isActive);
                    button.classList.toggle('dark:text-slate-900', isActive);
                    if (!isActive) {
                        button.classList.remove('border-slate-300', 'dark:border-slate-700');
                    }
                });
            }

            function applyFilters() {
                var visible = 0;

                cards.forEach(function (card) {
                    var levelOk = activeLevel === 'all' || card.getAttribute('data-level') === activeLevel;
                    var stateOk = activeState === 'all' || card.getAttribute('data-state') === activeState;
                    var shouldShow = levelOk && stateOk;
                    card.classList.toggle('hidden', !shouldShow);
                    if (shouldShow) {
                        visible += 1;
                    }
                });

                if (emptyState) {
                    emptyState.classList.toggle('hidden', visible !== 0);
                }
            }

            function syncQueryString() {
                var url = new URL(window.location.href);

                if (activeLevel === 'all') {
                    url.searchParams.delete('niveau');
                } else {
                    url.searchParams.set('niveau', activeLevel);
                }

                if (activeState === 'all') {
                    url.searchParams.delete('etat');
                } else {
                    url.searchParams.set('etat', activeState);
                }

                window.history.replaceState({}, '', url.toString());
            }

            levelButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activeLevel = button.getAttribute('data-level-filter') || 'all';
                    syncButtons(levelButtons, activeLevel, 'data-level-filter');
                    applyFilters();
                    syncQueryString();
                });
            });

            stateButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activeState = button.getAttribute('data-state-filter') || 'all';
                    syncButtons(stateButtons, activeState, 'data-state-filter');
                    applyFilters();
                    syncQueryString();
                });
            });

            syncButtons(levelButtons, activeLevel, 'data-level-filter');
            syncButtons(stateButtons, activeState, 'data-state-filter');
            applyFilters();
        })();
    </script>
@endpush

