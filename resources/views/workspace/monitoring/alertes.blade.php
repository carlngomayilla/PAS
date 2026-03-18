@extends('layouts.workspace')

@section('title', 'Centre d alertes')

@php
    $levelStyles = [
        'critical' => [
            'panel' => 'border-rose-300/80 bg-rose-50/90 dark:border-rose-500/40 dark:bg-rose-950/20',
            'dot' => 'bg-rose-500',
            'badge' => 'bg-rose-600 text-white',
            'soft' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-200',
            'icon' => '!',
        ],
        'warning' => [
            'panel' => 'border-amber-300/80 bg-amber-50/90 dark:border-amber-500/40 dark:bg-amber-950/20',
            'dot' => 'bg-amber-500',
            'badge' => 'bg-amber-500 text-white',
            'soft' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-200',
            'icon' => '!',
        ],
        'info' => [
            'panel' => 'border-sky-300/80 bg-sky-50/90 dark:border-sky-500/40 dark:bg-sky-950/20',
            'dot' => 'bg-sky-500',
            'badge' => 'bg-sky-600 text-white',
            'soft' => 'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-200',
            'icon' => 'i',
        ],
    ];

    $filterButtonBase = 'alert-filter inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition';
@endphp

@section('content')
    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Alertes operationnelles</span>
                <h1 class="showcase-title">Centre d alertes</h1>
                <p class="showcase-subtitle">
                    Vue unifiee des retards, KPI sous seuil, incidents de suivi et delegations proches d expiration.
                    Cliquer sur une alerte ouvre directement la cause dans le module concerne.
                </p>
            </div>

            <div class="showcase-action-row">
                <span class="showcase-chip">
                    <span class="showcase-chip-dot bg-blue-600"></span>
                    Limite d affichage: {{ $limit }} elements
                </span>
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

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Non lues</p>
            <p class="showcase-kpi-number text-rose-600 dark:text-rose-300">{{ $summary['unread'] ?? 0 }}</p>
            <p class="showcase-kpi-meta">Alertes a traiter</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Critiques</p>
            <p class="showcase-kpi-number text-rose-600 dark:text-rose-300">{{ $summary['critical'] ?? 0 }}</p>
            <p class="showcase-kpi-meta">Escalade immediate</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Attention</p>
            <p class="showcase-kpi-number text-amber-600 dark:text-amber-300">{{ $summary['warning'] ?? 0 }}</p>
            <p class="showcase-kpi-meta">Surveillance rapprochee</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Info</p>
            <p class="showcase-kpi-number text-sky-600 dark:text-sky-300">{{ $summary['info'] ?? 0 }}</p>
            <p class="showcase-kpi-meta">Information de contexte</p>
        </article>
    </section>

    <section class="showcase-toolbar mb-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-1.5 dark:border-slate-800 dark:bg-slate-950/50">
                <button class="{{ $filterButtonBase }} border-slate-300 bg-slate-900 text-white dark:border-slate-700 dark:bg-slate-100 dark:text-slate-900" data-level-filter="all" type="button">
                    Tous
                    <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs dark:bg-slate-900/10">{{ $summary['total'] ?? 0 }}</span>
                </button>
                @foreach (['critical', 'warning', 'info'] as $level)
                    @php $style = $levelStyles[$level]; @endphp
                    <button class="{{ $filterButtonBase }} border-transparent {{ $style['soft'] }}" data-level-filter="{{ $level }}" type="button">
                        {{ $level === 'critical' ? 'Critique' : ($level === 'warning' ? 'Attention' : 'Info') }}
                        @if (($levelUnreadCounts[$level] ?? 0) > 0)
                            <span class="rounded-full bg-white/40 px-2 py-0.5 text-xs dark:bg-slate-900/20">{{ $levelUnreadCounts[$level] }}</span>
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
                        <span class="rounded-full bg-rose-500 px-2 py-0.5 text-xs text-white">{{ $summary['unread'] }}</span>
                    @endif
                </button>
                <button class="{{ $filterButtonBase }} border-transparent bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-200" data-state-filter="read" type="button">
                    Lues
                </button>
            </div>
        </div>
    </section>

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
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-lg font-bold {{ $style['badge'] }}">
                        {{ $style['icon'] }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-950 dark:text-slate-50">{{ $alert['titre'] }}</h2>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $style['badge'] }}">
                                {{ $alert['niveau_label'] }}
                            </span>
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                {{ $alert['type_label'] }}
                            </span>
                            @if ($isUnread)
                                <span class="inline-flex items-center rounded-full bg-rose-500 px-2.5 py-0.5 text-xs font-semibold text-white">
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
            var activeLevel = 'all';
            var activeState = 'all';

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

            levelButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activeLevel = button.getAttribute('data-level-filter') || 'all';
                    syncButtons(levelButtons, activeLevel, 'data-level-filter');
                    applyFilters();
                });
            });

            stateButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activeState = button.getAttribute('data-state-filter') || 'all';
                    syncButtons(stateButtons, activeState, 'data-state-filter');
                    applyFilters();
                });
            });

            syncButtons(levelButtons, activeLevel, 'data-level-filter');
            syncButtons(stateButtons, activeState, 'data-state-filter');
            applyFilters();
        })();
    </script>
@endpush
