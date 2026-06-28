@extends('layouts.workspace')

@section('title', 'Suivi PTA')

@php
    $query = collect(request()->query())->filter(fn ($value): bool => trim((string) $value) !== '' && trim((string) $value) !== 'all')->all();
    $syntheseQuery = collect([
        'dashboardTab' => 'overview',
        'direction_id' => request('direction_id'),
        'service_id' => request('service_id'),
        'exercice' => request('exercice', request('annee')),
        'periode' => request('periode'),
        'trimestre' => request('trimestre'),
        'statut_suivi' => request('statut_suivi'),
        'statut_delai' => request('statut_delai'),
        'alerte_echeance' => request('alerte_echeance'),
    ])->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '' && trim((string) $value) !== 'all')->all();
@endphp

@push('head')
    <style>
        .pta-suivi-page { background:#fff; border:1px solid #d7d7d7; color:#000; overflow:hidden; }
        .pta-suivi-top { display:grid; grid-template-columns: 260px 1fr 540px; align-items:start; border-bottom:1px solid #d7d7d7; }
        .pta-suivi-logo { padding:10px 12px; min-height:78px; }
        .pta-suivi-logo img { width:128px; height:auto; display:block; }
        .pta-suivi-title { min-height:78px; display:flex; align-items:center; justify-content:center; background:#bdd7ee; border-left:1px solid #d7d7d7; border-right:1px solid #d7d7d7; font-size:18px; font-weight:900; letter-spacing:.02em; text-align:center; }
        .pta-suivi-legend { display:grid; grid-template-columns:120px 1fr; border-left:1px solid #111; }
        .pta-suivi-legend-title { grid-row:span 3; display:flex; align-items:center; justify-content:center; background:#d0cece; font-size:18px; font-weight:900; border-right:1px solid #111; }
        .pta-suivi-legend-group { border-bottom:1px solid #111; }
        .pta-suivi-legend-heading { padding:5px 8px; background:#f8fafc; font-size:11px; font-weight:900; border-bottom:1px solid #111; }
        .pta-suivi-legend-items { display:grid; gap:0; }
        .pta-suivi-legend-item { display:grid; grid-template-columns:68px 1fr; min-height:24px; font-size:11px; font-weight:800; }
        .pta-suivi-legend-swatch { border-right:1px solid #111; border-bottom:1px solid rgba(0,0,0,.15); }
        .pta-suivi-legend-label { display:flex; align-items:center; padding:3px 7px; border-bottom:1px solid rgba(0,0,0,.12); }
        .pta-suivi-meta { display:grid; grid-template-columns:1fr auto; gap:10px; padding:12px; border-bottom:1px solid #d7d7d7; }
        .pta-suivi-meta p { margin:0 0 5px; color:#ff6600; font-size:12px; font-weight:700; }
        .pta-suivi-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:end; justify-content:flex-end; }
        .pta-suivi-toolbar label { display:block; margin-bottom:3px; font-size:11px; font-weight:800; color:#17324a; }
        .pta-suivi-toolbar select { min-width:126px; border:1px solid #b7c7d6; border-radius:6px; padding:6px 8px; font-size:12px; background:#fff; }
        .pta-suivi-actionbar { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; padding:10px 12px; border-bottom:1px solid #d7d7d7; background:#f8fbff; }
        .pta-suivi-table-wrap { width:100%; overflow-x:auto; }
        .pta-suivi-table { width:100%; min-width:1620px; border-collapse:collapse; table-layout:fixed; font-size:12px; }
        .pta-suivi-table th, .pta-suivi-table td { border:1px solid #111; padding:6px 6px; vertical-align:middle; overflow-wrap:anywhere; }
        .pta-suivi-table th { background:#d9d9d9; color:#000; text-align:center; font-weight:900; }
        .pta-pas-row td { background:#2f75b5; color:#fff; font-weight:900; text-align:center; }
        .pta-pas-code { width:42px; }
        .pta-pas-rate { font-size:20px; }
        .pta-strategy-row td { background:#5b9bd5; color:#000; font-weight:900; text-align:center; }
        .pta-strategy-rate { background:#ddebf7 !important; font-size:20px; }
        .pta-objective-row td { background:#ddebf7; font-weight:900; text-align:center; }
        .pta-objective-number { width:42px; background:#fff !important; }
        .pta-objective-rate { font-size:20px; }
        .pta-action-cell { font-weight:700; color:#17324a; }
        .pta-action-link { display:inline; border:0; padding:0; background:transparent; color:#17324a; font:inherit; font-weight:800; text-decoration:underline; cursor:pointer; text-align:left; }
        .pta-action-link:hover { color:#3996d3; }
        .pta-center, .pta-status-cell { text-align:center; }
        .pta-status-cell { font-weight:900; line-height:1.15; }
        .pta-observation { font-size:11px; line-height:1.35; }
        .pta-empty { padding:18px; text-align:center; font-weight:800; color:#64748b; }
        .pta-suivi-modal-backdrop { position:fixed; inset:0; z-index:80; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,.58); padding:24px; }
        .pta-suivi-modal-backdrop.is-open { display:flex; }
        .pta-suivi-modal { width:min(1120px, 100%); max-height:88vh; overflow:hidden; border-radius:8px; background:#fff; box-shadow:0 30px 80px rgba(15,23,42,.32); }
        .pta-suivi-modal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:#1c203d; color:#fff; }
        .pta-suivi-modal-head h2 { margin:0; font-size:16px; font-weight:900; }
        .pta-suivi-modal-close { border:1px solid rgba(255,255,255,.35); background:transparent; color:#fff; border-radius:6px; padding:5px 9px; font-weight:900; }
        .pta-suivi-modal-body { max-height:calc(88vh - 56px); overflow:auto; padding:16px; }
        .pta-suivi-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
        .pta-suivi-detail-item { border:1px solid #d7d7d7; padding:8px; border-radius:6px; background:#fff; }
        .pta-suivi-detail-item dt { margin:0 0 4px; font-size:11px; color:#64748b; font-weight:900; text-transform:uppercase; }
        .pta-suivi-detail-item dd { margin:0; font-size:13px; font-weight:700; color:#111827; }
        .pta-suivi-detail-table { width:100%; border-collapse:collapse; margin-top:8px; font-size:12px; }
        .pta-suivi-detail-table th, .pta-suivi-detail-table td { border:1px solid #d7d7d7; padding:7px; vertical-align:top; }
        .pta-suivi-detail-table th { background:#eef6fc; color:#17324a; font-weight:900; }
        .pta-suivi-attachment-preview { margin-top:8px; border:1px solid #d7d7d7; border-radius:6px; overflow:hidden; background:#f8fafc; }
        .pta-suivi-attachment-preview iframe { width:100%; height:360px; border:0; }
        .pta-suivi-attachment-preview img { display:block; max-width:100%; height:auto; margin:0 auto; }
        @media (max-width:1180px) {
            .pta-suivi-top { grid-template-columns:1fr; }
            .pta-suivi-title, .pta-suivi-legend { border-left:0; border-right:0; }
            .pta-suivi-meta { grid-template-columns:1fr; }
            .pta-suivi-toolbar { justify-content:flex-start; }
        }
        @media print {
            body { background:#fff !important; }
            .admin-page-header, .app-sidebar, aside, nav, .pta-suivi-actionbar, .pta-suivi-toolbar, .no-print, .pta-suivi-modal-backdrop { display:none !important; }
            .admin-content-shell { padding-left:0 !important; }
            .pta-suivi-page { border:0; }
            .pta-suivi-table-wrap { overflow:visible; }
            .pta-suivi-table { min-width:0; font-size:8px; }
            .pta-suivi-table th, .pta-suivi-table td { padding:3px; }
            @page { size:A4 landscape; margin:9mm; }
        }
    </style>
@endpush

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 no-print">
        <div>
            <p class="text-xs font-black uppercase tracking-wide text-[#3996d3]">Controle PTA</p>
            <h1 class="text-2xl font-black text-[#17324a]">Suivi PTA officiel</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn btn-secondary rounded-xl px-4 py-2 text-sm" href="{{ route('dashboard', $syntheseQuery) }}">Synthese</a>
            <button class="btn btn-secondary rounded-xl px-4 py-2 text-sm" type="button" onclick="window.print()">Imprimer</button>
            <a class="btn btn-primary rounded-xl px-4 py-2 text-sm" href="{{ route('pta.suivi.export.excel', $query) }}">Export Excel</a>
            <a class="btn btn-primary rounded-xl px-4 py-2 text-sm" href="{{ route('pta.suivi.export.pdf', $query) }}">Export PDF</a>
        </div>
    </div>

    <section class="pta-suivi-page">
        <div class="pta-suivi-top">
            <div class="pta-suivi-logo">
                <img src="{{ asset('images/logo-wordmark.png') }}" alt="ANBG">
            </div>
            <div class="pta-suivi-title">{{ $title }}</div>
            <div class="pta-suivi-legend">
                <div class="pta-suivi-legend-title">Legende</div>
                @foreach ($legends as $legendTitle => $items)
                    <div class="pta-suivi-legend-group">
                        <div class="pta-suivi-legend-heading">{{ $legendTitle }}</div>
                        <div class="pta-suivi-legend-items">
                            @foreach ($items as $item)
                                <div class="pta-suivi-legend-item">
                                    <span class="pta-suivi-legend-swatch" style="background:{{ $item['color'] }};"></span>
                                    <span class="pta-suivi-legend-label" style="color:{{ $item['text'] ?? '#111827' }};">{{ $item['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pta-suivi-meta">
            <div>
                @foreach (explode(' | ', $scopeLabel) as $scopeLine)
                    <p>{{ $scopeLine }}</p>
                @endforeach
                <p>Total actions : {{ $summary['actions'] ?? 0 }} | Performance moyenne : {{ number_format((float) ($summary['performance'] ?? 0), 0) }}%</p>
            </div>
            <form method="GET" action="{{ route('pta.suivi.index') }}" class="pta-suivi-toolbar no-print">
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="all">Toutes</option>
                        @foreach (($filterOptions['directions'] ?? []) as $direction)
                            <option value="{{ $direction['id'] }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction['id'])>{{ $direction['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="all">Tous</option>
                        @foreach (($filterOptions['services'] ?? []) as $service)
                            <option value="{{ $service['id'] }}" data-direction="{{ $service['direction_id'] }}" @selected((int) ($filters['service_id'] ?? 0) === (int) $service['id'])>{{ $service['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="annee">Annee</label>
                    <select id="annee" name="annee">
                        @foreach (($filterOptions['exercices'] ?? []) as $option)
                            <option value="{{ $option['value'] }}" @selected((string) ($filters['annee'] ?? 'all') === (string) $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periode">Periode</label>
                    <select id="periode" name="periode">
                        @foreach (($filterOptions['periodes'] ?? $filterOptions['trimestres'] ?? []) as $option)
                            <option value="{{ $option['value'] }}" @selected((string) ($filters['periode'] ?? 'all') === (string) $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut_suivi">Statut suivi</label>
                    <select id="statut_suivi" name="statut_suivi">
                        <option value="all">Tous</option>
                        @foreach (($filterOptions['statut_suivi'] ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['statut_suivi'] ?? '') === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut_delai">Statut delai</label>
                    <select id="statut_delai" name="statut_delai">
                        <option value="all">Tous</option>
                        @foreach (($filterOptions['statut_delai'] ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['statut_delai'] ?? '') === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="alerte_echeance">Alerte</label>
                    <select id="alerte_echeance" name="alerte_echeance">
                        <option value="all">Toutes</option>
                        @foreach (($filterOptions['alerte_echeance'] ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected((string) ($filters['alerte_echeance'] ?? '') === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary rounded-xl px-4 py-2 text-sm" type="submit">Filtrer</button>
            </form>
        </div>

        @include('workspace.pta-suivi.partials.table', ['groups' => $groups])
    </section>

    <div class="pta-suivi-modal-backdrop" data-pta-modal aria-hidden="true">
        <div class="pta-suivi-modal" role="dialog" aria-modal="true" aria-labelledby="pta-suivi-modal-title">
            <div class="pta-suivi-modal-head">
                <h2 id="pta-suivi-modal-title">Detail de l'action</h2>
                <button type="button" class="pta-suivi-modal-close" data-pta-modal-close>Fermer</button>
            </div>
            <div class="pta-suivi-modal-body" data-pta-modal-body></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.querySelector('[data-pta-modal]');
            const modalBody = document.querySelector('[data-pta-modal-body]');
            const closeButtons = document.querySelectorAll('[data-pta-modal-close]');
            const directionSelect = document.getElementById('direction_id');
            const serviceSelect = document.getElementById('service_id');

            function closeModal() {
                if (!modal || !modalBody) return;
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                modalBody.innerHTML = '';
            }

            document.addEventListener('click', async function (event) {
                const button = event.target.closest('[data-pta-action-open]');
                if (!button || !modal || !modalBody) return;
                event.preventDefault();
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                modalBody.innerHTML = '<div class="p-6 text-center text-sm font-semibold text-slate-600">Chargement...</div>';

                try {
                    const response = await fetch(button.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!response.ok) throw new Error('Erreur de chargement');
                    modalBody.innerHTML = await response.text();
                } catch (error) {
                    modalBody.innerHTML = '<div class="p-6 text-center text-sm font-semibold text-red-700">Impossible de charger le detail de cette action.</div>';
                }
            });

            closeButtons.forEach((button) => button.addEventListener('click', closeModal));
            modal?.addEventListener('click', function (event) {
                if (event.target === modal) closeModal();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeModal();
            });

            directionSelect?.addEventListener('change', function () {
                if (!serviceSelect) return;
                serviceSelect.value = 'all';
            });
        });
    </script>
@endpush
