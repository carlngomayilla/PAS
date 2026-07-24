@extends('layouts.workspace')

@section('title', 'Suivi PTA')

@php
    $query = collect(request()->query())->filter(fn ($value): bool => trim((string) $value) !== '' && trim((string) $value) !== 'all')->all();
@endphp

@push('head')
    <style>
        .pta-suivi-page { background:#fff; border:1px solid #d7d7d7; color:#000; overflow:hidden; }
        .pta-suivi-top { display:grid; grid-template-columns:260px 1fr; align-items:stretch; border-bottom:1px solid #d7d7d7; }
        .pta-suivi-logo { padding:10px 12px; min-height:78px; }
        .pta-suivi-logo img { width:128px; height:auto; display:block; }
        .pta-suivi-title { min-height:78px; display:flex; align-items:center; justify-content:center; background:#bdd7ee; border-left:1px solid #d7d7d7; border-right:1px solid #d7d7d7; font-size:18px; font-weight:900; letter-spacing:.02em; text-align:center; }
        .pta-suivi-meta { display:grid; grid-template-columns:1fr auto; gap:10px; padding:12px; border-bottom:1px solid #d7d7d7; }
        .pta-suivi-meta p { margin:0 0 5px; color:#ff6600; font-size:12px; font-weight:700; }
        .pta-suivi-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:end; justify-content:flex-end; }
        .pta-suivi-toolbar label { display:block; margin-bottom:3px; font-size:11px; font-weight:800; color:#17324a; }
        .pta-suivi-toolbar select { min-width:126px; border:1px solid #b7c7d6; border-radius:6px; padding:6px 8px; font-size:12px; background:#fff; }
        .pta-suivi-actionbar { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; padding:10px 12px; border-bottom:1px solid #d7d7d7; background:#f8fbff; }
        .pta-suivi-table-wrap { width:100%; overflow-x:auto; }
        .pta-suivi-table { width:100%; min-width:2485px; border-collapse:collapse; table-layout:fixed; font-size:12px; }
        .pta-col-number { width:46px; }
        .pta-col-action { width:260px; }
        .pta-col-sub-action { width:230px; }
        .pta-col-indicator { width:360px; }
        .pta-col-responsable { width:170px; }
        .pta-col-ratio { width:110px; }
        .pta-col-threshold { width:190px; }
        .pta-col-realized { width:130px; }
        .pta-col-rate { width:110px; }
        .pta-col-performance { width:160px; }
        .pta-col-gap { width:110px; }
        .pta-col-deadline { width:150px; }
        .pta-col-delay { width:90px; }
        .pta-col-status { width:130px; }
        .pta-col-proof { width:115px; }
        .pta-col-observation { width:230px; }
        .pta-col-row-actions { width:165px; }
        .pta-suivi-table th, .pta-suivi-table td { border:1px solid #111; padding:6px 6px; vertical-align:middle; overflow-wrap:anywhere; }
        .pta-action-row td { vertical-align:top; }
        .pta-suivi-table th { background:#d9d9d9; color:#000; text-align:center; font-weight:900; }
        .pta-pas-row td { background:#2f75b5; color:#fff; font-weight:900; text-align:center; }
        .pta-pas-code { width:42px; }
        .pta-pas-rate { font-size:20px; }
        .pta-level-axis td { background:#0f2f57; color:#fff; font-weight:900; text-align:center; }
        .pta-level-strategic-objective td { background:#1e5fa8; color:#fff; font-weight:900; text-align:center; }
        .pta-level-operational-objective td { background:#d8ecff; color:#0f2f57; font-weight:900; text-align:center; }
        .pta-level-action td { background:#f8fafc; color:#111827; }
        .pta-level-sub-action td { background:#f1f5f9; color:#334155; }
        .pta-sub-action-row td { background:#f1f5f9; color:#334155; }
        .pta-sub-action-row .pta-action-index-cell, .pta-sub-action-row .pta-action-parent-cell { background:#f8fafc; color:#111827; }
        .pta-hierarchy-action-cell { background:#f8fafc; color:#111827; }
        .pta-hierarchy-sub-action-cell { background:#f1f5f9; color:#334155; }
        .pta-hierarchy-rate { font-size:20px; }
        .pta-hierarchy-title { text-transform:uppercase; }
        .pta-hierarchy-number, .pta-objective-number { width:42px; }
        .pta-strategy-row td { background:#5b9bd5; color:#000; font-weight:900; text-align:center; }
        .pta-strategy-rate { background:#ddebf7 !important; font-size:20px; }
        .pta-objective-row td { background:#ddebf7; font-weight:900; text-align:center; }
        .pta-objective-rate { font-size:20px; }
        .pta-action-cell { font-weight:700; color:#17324a; }
        .pta-action-link { display:block; width:100%; border:0; padding:2px 3px; margin:-2px -3px; background:transparent; color:#17324a; font:inherit; font-weight:800; text-decoration:none; cursor:pointer; text-align:left; border-radius:4px; }
        .pta-preview-link { display:block; width:100%; min-height:100%; margin:-2px -3px; border:0; border-radius:4px; padding:2px 3px; background:transparent; color:inherit; font:inherit; text-align:inherit; text-decoration:none; cursor:pointer; transition:background-color .15s ease, color .15s ease; }
        .pta-action-link.pta-preview-link { color:#17324a; font-weight:800; }
        .pta-preview-link:hover { background:rgba(15,47,87,.045); color:inherit; box-shadow:none; }
        .pta-preview-link:active { background:rgba(57,150,211,.12); }
        .pta-preview-link:focus-visible { outline:2px solid rgba(57,150,211,.55); outline-offset:2px; }
        .pta-inline-hidden-form { display:none; }
        .pta-inline-stack { display:grid; gap:6px; align-items:start; }
        .pta-inline-field { width:100%; min-height:30px; border:1px solid #b7c7d6; border-radius:8px; background:#fff; color:#111827; padding:6px 7px; font:inherit; font-size:11px; line-height:1.25; box-shadow:inset 0 1px 0 rgba(15,47,87,.03); transition:border-color .15s ease, box-shadow .15s ease, background .15s ease; }
        .pta-inline-field:focus { border-color:#1e5fa8; box-shadow:0 0 0 3px rgba(30,95,168,.14); outline:0; }
        .pta-suivi-table td:has([data-pta-cell-input]) { cursor:text; }
        .pta-suivi-table td:focus-within { box-shadow:inset 0 0 0 2px rgba(30,95,168,.22); }
        .pta-inline-textarea { resize:vertical; min-height:42px; }
        .pta-inline-save { min-height:31px; border:1px solid #1e5fa8; border-radius:999px; background:linear-gradient(180deg,#1e6fb8,#174f84); color:#fff; padding:6px 10px; font-size:10px; font-weight:900; line-height:1.1; cursor:pointer; box-shadow:0 8px 18px rgba(30,95,168,.2); }
        .pta-inline-save:hover { background:#17324a; }
        .pta-inline-delete { min-height:31px; border:1px solid #d7a7a7; border-radius:999px; background:linear-gradient(180deg,#fff7f7,#ffecec); color:#b42318; padding:6px 10px; font-size:10px; font-weight:900; line-height:1.1; cursor:pointer; box-shadow:0 8px 18px rgba(180,35,24,.12); }
        .pta-inline-delete:hover { border-color:#b42318; background:#fff1f1; color:#8a1c14; }
        .pta-row-actions { position:sticky; right:0; z-index:4; background:#f8fafc !important; text-align:center; box-shadow:-7px 0 14px rgba(15,47,87,.08); }
        .pta-row-actions-heading { position:sticky; right:0; z-index:6; background:#d9d9d9 !important; box-shadow:-7px 0 14px rgba(15,47,87,.08); }
        .pta-row-actions-stack { display:grid; gap:6px; align-content:start; justify-items:stretch; }
        .pta-inline-save-state { min-height:14px; color:#64748b; font-size:9px; font-weight:800; line-height:1.2; }
        .pta-action-row.is-dirty > td { box-shadow:inset 0 2px 0 #f9b13c, inset 0 -2px 0 #f9b13c; }
        .pta-action-row.is-submitting > td { opacity:.78; }
        .pta-inline-field:invalid:not(:focus):not(:placeholder-shown) { border-color:#b42318; background:#fff7f6; }
        .pta-inline-open { display:inline-flex; min-height:31px; align-items:center; justify-content:center; border:1px solid #b7c7d6; border-radius:999px; background:#fff; color:#17324a; padding:6px 10px; font-size:10px; font-weight:900; line-height:1.1; text-decoration:none; box-shadow:0 8px 18px rgba(15,47,87,.08); }
        .pta-inline-open:hover { border-color:#1e5fa8; background:#eef6fc; color:#0f2f57; }
        .pta-inline-report { display:inline-flex; min-height:31px; align-items:center; justify-content:center; border:1px solid #d5932e; border-radius:6px; background:#fff8e8; color:#7b4b0b; padding:6px 9px; font-size:10px; font-weight:900; line-height:1.1; text-decoration:none; }
        .pta-inline-report:hover { border-color:#b87512; background:#ffefc7; color:#5f3908; }
        .pta-inline-report.is-disabled { border-color:#cbd5e1; background:#f1f5f9; color:#64748b; cursor:not-allowed; }
        .pta-param-editor { display:grid; gap:7px; min-width:0; }
        .pta-param-trigger, .pta-param-panel, .pta-param-readonly, .pta-threshold-card { border:1px solid #c6d8e6; border-radius:6px; background:#ffffff; box-shadow:0 4px 12px rgba(15,47,87,.06); }
        .pta-param-trigger { display:grid; width:100%; gap:5px; padding:10px; color:#17324a; font:inherit; text-align:left; cursor:pointer; }
        .pta-param-trigger:hover, .pta-indicator-cell.is-editable .pta-param-trigger:focus-visible { border-color:#2f75b5; background:#f5faff; box-shadow:0 0 0 3px rgba(47,117,181,.14); outline:none; }
        .pta-indicator-cell.is-editable .pta-param-trigger { border-left:3px solid #2f75b5; }
        .pta-param-trigger-heading { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .pta-param-edit-affordance { display:inline-flex; align-items:center; color:#1e5fa8; font-size:10px; font-weight:900; line-height:1; }
        .pta-param-trigger strong, .pta-param-readonly-title { display:block; color:#10233b; font-size:12px; font-weight:900; line-height:1.25; }
        .pta-param-kicker, .pta-threshold-label, .pta-param-field > span { color:#5d7389; font-size:9px; font-weight:900; letter-spacing:.06em; line-height:1.1; text-transform:uppercase; }
        .pta-param-trigger-meta { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }
        .pta-param-chip { display:inline-flex; min-height:20px; align-items:center; border-radius:999px; padding:3px 7px; font-size:10px; font-weight:900; line-height:1; }
        .pta-param-chip { border:1px solid #b8d6ec; background:#e8f4fb; color:#174f84; }
        .pta-param-chip-ready { border-color:#b9d99a; background:#eff8e8; color:#315f16; }
        .pta-param-chip-missing { border-color:#efc38a; background:#fff7e8; color:#8a5414; }
        .pta-param-trigger-details { display:grid; gap:3px; color:#334155; font-size:10px; font-weight:800; line-height:1.25; }
        .pta-param-panel { display:grid; gap:8px; padding:10px; border-left:3px solid #2f75b5; }
        .pta-param-panel[hidden], .pta-param-trigger[hidden], [data-pta-type-field][hidden] { display:none !important; }
        .pta-param-type-select { font-weight:900; }
        .pta-param-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(72px,.55fr); gap:7px; }
        .pta-param-field { display:grid; gap:4px; min-width:0; }
        .pta-param-field-full { grid-column:1 / -1; }
        .pta-param-readonly { display:grid; gap:6px; padding:8px; }
        .pta-action-header-editor { min-width:0; }
        .pta-action-config-trigger { min-height:92px; }
        .pta-action-config-panel { max-height:560px; overflow-y:auto; overscroll-behavior:contain; }
        .pta-action-config-actions { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:6px; align-items:center; }
        .pta-deadline-locked-note { display:grid; gap:4px; border:1px solid #efc38a; border-radius:6px; background:#fff8e8; padding:8px; color:#6f4710; font-size:10px; font-weight:700; }
        .pta-deadline-locked-note strong { color:#513208; font-size:11px; font-weight:900; }
        .pta-inline-cancel { min-height:31px; border:1px solid #b7c7d6; border-radius:6px; background:#fff; color:#17324a; padding:6px 9px; font-size:10px; font-weight:900; cursor:pointer; }
        .pta-inline-cancel:hover { border-color:#1e5fa8; background:#eef6fc; }
        .pta-threshold-cell { text-align:left; }
        .pta-threshold-card { display:grid; gap:4px; min-height:48px; align-content:center; padding:8px; text-align:left; }
        .pta-threshold-card strong { color:#10233b; font-size:12px; font-weight:900; line-height:1.25; }
        .pta-editable-threshold { cursor:text; }
        .pta-threshold-input-wrap { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:6px; align-items:center; color:#7b5c12; font-size:11px; font-weight:900; }
        .pta-threshold-input-wrap .pta-inline-field { min-height:32px; text-align:right; font-weight:900; }
        .pta-sub-action-cell { font-weight:800; color:#334155; }
        .pta-sub-action-number { font-weight:900; color:#0f2f57; }
        .pta-center, .pta-status-cell { text-align:center; }
        .pta-status-cell { font-weight:900; line-height:1.15; }
        .pta-status-badge { display:inline-flex; min-height:24px; align-items:center; justify-content:center; border-radius:6px; padding:4px 7px; font-size:10px; font-weight:900; line-height:1.1; }
        .pta-proof-button { display:inline-flex; min-height:28px; min-width:76px; align-items:center; justify-content:center; gap:4px; border:1px solid #1e5fa8; border-radius:6px; background:#eef6fc; color:#0f2f57; padding:5px 7px; font-size:10px; font-weight:900; line-height:1.1; cursor:pointer; text-decoration:none; white-space:nowrap; }
        .pta-proof-button svg { width:13px; height:13px; flex:0 0 13px; }
        .pta-proof-button-label { line-height:1; }
        .pta-proof-count { display:inline-grid; min-width:16px; height:16px; place-items:center; border-radius:999px; background:#1e5fa8; color:#fff; font-size:10px; line-height:1; }
        .pta-proof-button:hover { background:#d8ecff; }
        .pta-proof-button-empty, .pta-proof-button:disabled { border-color:#cbd5e1; background:#f1f5f9; color:#64748b; cursor:not-allowed; }
        .pta-proof-button-readonly { cursor:default; }
        .pta-observation { font-size:11px; line-height:1.35; }
        .pta-empty { padding:18px; text-align:center; font-weight:800; color:#64748b; }
        .pta-suivi-modal-backdrop { position:fixed; inset:0; z-index:80; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,.66); padding:clamp(12px,3vw,28px); backdrop-filter:blur(6px); }
        .pta-suivi-modal-backdrop.is-open { display:flex; }
        .pta-suivi-modal { width:min(1180px, 100%); max-height:90vh; overflow:hidden; border:1px solid rgba(148,163,184,.35); border-radius:8px; background:#f8fafc; box-shadow:0 32px 90px rgba(15,23,42,.38); }
        .pta-suivi-modal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 18px; background:linear-gradient(135deg,#17324a,#1c203d); color:#fff; }
        .pta-suivi-modal-head p { margin:0 0 2px; font-size:10px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; color:#bde3fb; }
        .pta-suivi-modal-head h2 { margin:0; font-size:17px; font-weight:900; }
        .pta-suivi-modal-close { border:1px solid rgba(255,255,255,.35); background:rgba(255,255,255,.08); color:#fff; border-radius:6px; padding:6px 10px; font-weight:900; }
        .pta-suivi-modal-close:hover { background:rgba(255,255,255,.16); }
        .pta-suivi-modal-body { max-height:calc(90vh - 64px); overflow:auto; padding:18px; background:#f8fafc; }
        .pta-suivi-detail-section { border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); }
        .pta-suivi-detail-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; }
        .pta-suivi-detail-primary, .pta-suivi-detail-secondary { display:inline-flex; min-height:34px; align-items:center; justify-content:center; border-radius:6px; padding:7px 11px; font-size:12px; font-weight:900; text-decoration:none; }
        .pta-suivi-detail-primary { border:1px solid #1e5fa8; background:#1e5fa8; color:#fff; }
        .pta-suivi-detail-primary:hover { background:#17324a; color:#fff; }
        .pta-suivi-detail-secondary { border:1px solid #b7c7d6; background:#fff; color:#17324a; }
        .pta-suivi-detail-secondary:hover { background:#eef6fc; color:#0f2f57; }
        .pta-suivi-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
        .pta-suivi-detail-item { border:1px solid #e2e8f0; padding:10px; border-radius:8px; background:#fff; box-shadow:0 4px 14px rgba(15,23,42,.05); }
        .pta-suivi-detail-item dt { margin:0 0 4px; font-size:11px; color:#64748b; font-weight:900; text-transform:uppercase; }
        .pta-suivi-detail-item dd { margin:0; font-size:13px; font-weight:700; color:#111827; }
        .pta-suivi-detail-table { width:100%; border-collapse:collapse; font-size:12px; }
        .pta-suivi-detail-table th, .pta-suivi-detail-table td { border:1px solid #e2e8f0; padding:8px; vertical-align:top; }
        .pta-suivi-detail-table th { background:#eef6fc; color:#17324a; font-weight:900; }
        .pta-suivi-attachment-preview { margin-top:8px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#f8fafc; }
        .pta-suivi-attachment-preview iframe { width:100%; height:420px; border:0; }
        .pta-suivi-attachment-preview img { display:block; max-width:100%; height:auto; margin:0 auto; }
        @media (max-width:1180px) {
            .pta-suivi-top { grid-template-columns:1fr; }
            .pta-suivi-title { border-left:0; border-right:0; }
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
            <a class="btn btn-primary rounded-xl px-4 py-2 text-sm" href="{{ route('pta.suivi.export.excel', $query) }}">Export Excel</a>
            <a class="btn btn-primary rounded-xl px-4 py-2 text-sm" href="{{ route('pta.suivi.export.pdf', $query) }}">Export PDF</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800 no-print">
            <p>{{ $errors->first('pta_suivi_inline') ?: 'Le paramétrage n’a pas été enregistré. Corrigez les champs signalés.' }}</p>
            @if (! $errors->has('pta_suivi_inline'))
                <ul class="mt-2 list-disc pl-5 text-xs font-semibold">
                    @foreach (collect($errors->all())->unique()->take(5) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <section class="pta-suivi-page">
        <div class="pta-suivi-top">
            <div class="pta-suivi-logo">
                <img src="{{ asset('images/logo-wordmark.png') }}" alt="ANBG">
            </div>
            <div class="pta-suivi-title">{{ $title }}</div>
        </div>

        <div class="pta-suivi-meta">
            <div>
                @foreach (explode(' | ', $scopeLabel) as $scopeLine)
                    <p>{{ $scopeLine }}</p>
                @endforeach
                <p>Total actions : {{ $summary['actions'] ?? 0 }} | Performance consolidee : {{ number_format((float) ($summary['performance'] ?? 0), 2) }}% | A parametrer : {{ $summary['a_parametrer'] ?? 0 }}</p>
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
                    <label for="objectif_operationnel_id">Objectif operationnel</label>
                    <select id="objectif_operationnel_id" name="objectif_operationnel_id">
                        <option value="all">Tous</option>
                        @foreach (($filterOptions['objectifs_operationnels'] ?? []) as $objective)
                            <option
                                value="{{ $objective['id'] }}"
                                data-direction="{{ $objective['direction_id'] }}"
                                data-service="{{ $objective['service_id'] }}"
                                @selected((int) ($filters['objectif_operationnel_id'] ?? 0) === (int) $objective['id'])
                            >
                                {{ $objective['label'] }}
                            </option>
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
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const directionSelect = document.getElementById('direction_id');
            const serviceSelect = document.getElementById('service_id');
            const objectiveSelect = document.getElementById('objectif_operationnel_id');
            const tableWrap = document.querySelector('.pta-suivi-table-wrap');
            const scrollStorageKey = 'pta-suivi:inline-scroll';

            try {
                const savedScroll = JSON.parse(window.sessionStorage.getItem(scrollStorageKey) || 'null');
                if (savedScroll) {
                    window.requestAnimationFrame(function () {
                        window.scrollTo({ top: Number(savedScroll.windowY || 0), behavior: 'auto' });
                        if (tableWrap) tableWrap.scrollLeft = Number(savedScroll.tableX || 0);
                    });
                    window.sessionStorage.removeItem(scrollStorageKey);
                }
            } catch (error) {
                window.sessionStorage.removeItem(scrollStorageKey);
            }

            function optionValue(select) {
                return select && select.value !== 'all' ? String(select.value) : '';
            }

            function setOptionAvailability(option, isAvailable) {
                if (!option || option.value === 'all') return;

                option.hidden = !isAvailable;
                option.disabled = !isAvailable;
                option.style.display = isAvailable ? '' : 'none';
            }

            function selectedOptionIsUnavailable(select) {
                return Boolean(select?.selectedOptions?.[0]?.disabled);
            }

            function updateServiceOptions() {
                const directionId = optionValue(directionSelect);

                serviceSelect?.querySelectorAll('option').forEach(function (option) {
                    const optionDirection = String(option.dataset.direction || '');
                    setOptionAvailability(option, directionId === '' || optionDirection === directionId);
                });

                if (selectedOptionIsUnavailable(serviceSelect)) {
                    serviceSelect.value = 'all';
                }
            }

            function updateObjectiveOptions() {
                const directionId = optionValue(directionSelect);
                const serviceId = optionValue(serviceSelect);

                objectiveSelect?.querySelectorAll('option').forEach(function (option) {
                    const optionDirection = String(option.dataset.direction || '');
                    const optionService = String(option.dataset.service || '');
                    const matchesDirection = directionId === '' || optionDirection === directionId;
                    const matchesService = serviceId === '' || optionService === serviceId;

                    setOptionAvailability(option, matchesDirection && matchesService);
                });

                if (selectedOptionIsUnavailable(objectiveSelect)) {
                    objectiveSelect.value = 'all';
                }
            }

            function syncPtaSuiviFilters() {
                updateServiceOptions();
                updateObjectiveOptions();
            }

            function applyIndicatorType(editor, type, label) {
                editor.dataset.ptaCurrentType = type;
                editor.querySelectorAll('[data-pta-current-type-label]').forEach(function (target) {
                    target.textContent = label || target.textContent;
                });
                editor.querySelectorAll('[data-pta-type-field]').forEach(function (field) {
                    const allowedTypes = String(field.dataset.ptaTypeField || '').split(/\s+/).filter(Boolean);
                    field.hidden = !allowedTypes.includes(type);
                    field.querySelectorAll('input, select, textarea').forEach(function (control) {
                        control.disabled = field.hidden;
                    });
                });
            }

            function showIndicatorStep(editor, step) {
                const trigger = editor.querySelector('[data-pta-param-open]');
                const fields = editor.querySelector('[data-pta-param-fields]');

                if (trigger) {
                    trigger.hidden = step !== 'preview';
                    trigger.setAttribute('aria-expanded', step === 'fields' ? 'true' : 'false');
                }
                if (fields) fields.hidden = step !== 'fields';
            }

            function saveButtonFor(form) {
                return Array.from(document.querySelectorAll('[data-pta-save]')).find(function (button) {
                    return button.form === form;
                }) || null;
            }

            function markFormDirty(control) {
                const form = control?.form;
                if (!form || !form.matches('[data-pta-inline-form]')) return;

                const row = control.closest('[data-pta-inline-row]');
                row?.classList.add('is-dirty');

                const saveButton = saveButtonFor(form);
                if (saveButton) saveButton.classList.add('is-dirty');

                const state = row?.querySelector('[data-pta-save-state]');
                if (state) state.textContent = 'Modifications non enregistrées';
            }

            function focusIndicatorEditor(editor) {
                const indicatorInput = editor.querySelector('[data-pta-param-fields] [name="indicateur"]:not([disabled])');
                const firstInput = indicatorInput || editor.querySelector('[data-pta-param-fields] [data-pta-cell-input]:not([disabled])');

                if (!firstInput) return;

                firstInput.focus({ preventScroll: true });
                if (typeof firstInput.select === 'function' && firstInput.tagName !== 'TEXTAREA') {
                    firstInput.select();
                }
            }

            document.querySelectorAll('[data-pta-param-editor]').forEach(function (editor) {
                const typeInput = editor.querySelector('[data-pta-type-input]');
                const selectedOption = typeInput?.selectedOptions?.[0];
                const type = typeInput?.value || editor.dataset.ptaCurrentType || 'non_quantitatif';
                const label = selectedOption?.dataset.typeLabel || typeInput?.dataset.typeLabel || '';
                applyIndicatorType(editor, type, label);
                showIndicatorStep(editor, 'preview');
            });

            syncPtaSuiviFilters();

            document.addEventListener('click', function (event) {
                const cancel = event.target.closest('[data-pta-param-cancel]');
                if (cancel) {
                    event.preventDefault();
                    const editor = cancel.closest('[data-pta-param-editor]');
                    const form = editor?.querySelector('[data-pta-cell-input]')?.form;
                    form?.reset();

                    const typeInput = editor?.querySelector('[data-pta-type-input]');
                    if (editor && typeInput) {
                        const selectedOption = typeInput.selectedOptions?.[0];
                        applyIndicatorType(editor, typeInput.value, selectedOption?.dataset.typeLabel || typeInput.value);
                    }

                    if (editor) showIndicatorStep(editor, 'preview');
                    editor?.closest('[data-pta-inline-row]')?.classList.remove('is-dirty');

                    return;
                }

                const trigger = event.target.closest('[data-pta-param-open]');
                if (trigger) {
                    event.preventDefault();
                    const editor = trigger.closest('[data-pta-param-editor]');
                    if (editor) {
                        showIndicatorStep(editor, 'fields');
                        focusIndicatorEditor(editor);
                    }

                    return;
                }
            });

            document.addEventListener('change', function (event) {
                const typeInput = event.target.closest('[data-pta-type-input]');
                if (!typeInput) {
                    if (event.target.matches('[data-pta-cell-input]')) markFormDirty(event.target);

                    return;
                }

                const editor = typeInput.closest('[data-pta-param-editor]');
                if (!editor) return;

                const selectedOption = typeInput.selectedOptions?.[0];
                applyIndicatorType(editor, typeInput.value, selectedOption?.dataset.typeLabel || typeInput.dataset.typeLabel || typeInput.value);
                showIndicatorStep(editor, 'fields');
                markFormDirty(typeInput);
            });

            document.addEventListener('input', function (event) {
                if (event.target.matches('[data-pta-cell-input]')) markFormDirty(event.target);
            });

            document.querySelectorAll('[data-pta-inline-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.reportValidity()) {
                        event.preventDefault();

                        return;
                    }

                    try {
                        window.sessionStorage.setItem(scrollStorageKey, JSON.stringify({
                            windowY: window.scrollY,
                            tableX: tableWrap?.scrollLeft || 0,
                        }));
                    } catch (error) {
                        // Scroll restoration is optional.
                    }

                    const row = saveButtonFor(form)?.closest('[data-pta-inline-row]')
                        || form.closest('[data-pta-inline-row]');
                    row?.classList.add('is-submitting');

                    const saveButton = saveButtonFor(form);
                    if (saveButton) {
                        saveButton.disabled = true;
                        saveButton.textContent = 'Enregistrement...';
                    }

                    const state = row?.querySelector('[data-pta-save-state]');
                    if (state) state.textContent = 'Enregistrement en cours';
                });
            });

            document.addEventListener('keydown', function (event) {
                if (!(event.ctrlKey || event.metaKey) || event.key !== 'Enter') return;

                const control = event.target.closest('[data-pta-cell-input]');
                const form = control?.form;
                if (!form || !form.matches('[data-pta-inline-form]')) return;

                event.preventDefault();
                const saveButton = saveButtonFor(form);
                if (saveButton) form.requestSubmit(saveButton);
            });

            document.addEventListener('click', function (event) {
                if (event.target.closest('input, textarea, select, button, a, label')) return;

                const cell = event.target.closest('td');
                const indicatorEditor = cell?.querySelector('[data-pta-param-editor]');
                if (indicatorEditor) {
                    showIndicatorStep(indicatorEditor, 'fields');
                    focusIndicatorEditor(indicatorEditor);

                    return;
                }

                const input = cell?.querySelector('[data-pta-cell-input]:not([disabled])');
                if (!input) return;

                input.focus({ preventScroll: true });
                if (typeof input.select === 'function' && input.tagName !== 'TEXTAREA') {
                    input.select();
                }
            });

            directionSelect?.addEventListener('change', function () {
                if (serviceSelect) serviceSelect.value = 'all';
                if (objectiveSelect) objectiveSelect.value = 'all';
                syncPtaSuiviFilters();
            });

            serviceSelect?.addEventListener('change', function () {
                if (objectiveSelect) objectiveSelect.value = 'all';
                updateObjectiveOptions();
            });
        });
    </script>
@endpush
