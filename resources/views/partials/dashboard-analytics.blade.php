@section('title', 'Dashboard')

@php
    $dashboardNotifications = auth()->user()?->notifications()->latest()->limit(6)->get() ?? collect();
    $analytics = $dashboardData ?? [];
    $globalScores = $analytics['global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'qualite' => 0, 'risque' => 0, 'global' => 0, 'progression' => 0];
    $statusCards = $analytics['status_cards'] ?? [];
    $unitRows = $analytics['unit_rows'] ?? [];
    $actionRows = $analytics['action_rows'] ?? [];
    $priorityActionRows = collect($actionRows)->take(8)->all();
    $ganttRows = $analytics['gantt_rows'] ?? [];
    $bulletRows = $analytics['bullet_rows'] ?? [];
    $alertRows = $analytics['alert_rows'] ?? [];
    $interannualRows = $analytics['interannual'] ?? [];
    $unitModeLabel = $analytics['unit_mode_label'] ?? 'Unites';

    $summaryStrip = [
        ['label' => 'Actions totales', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#1C203D', 'bg' => '#F8FAFC', 'meta' => 'Portefeuille scope'],
        ['label' => 'KPI global moyen', 'value' => number_format((float) ($globalScores['global'] ?? 0), 0), 'accent' => '#8FC043', 'bg' => '#EEF6E1', 'meta' => 'Moyenne action_kpis'],
        ['label' => 'En retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? 0, 'accent' => '#F9B13C', 'bg' => '#FFF0DF', 'meta' => 'Actions hors delai'],
        ['label' => 'Non demarrees', 'value' => collect($statusCards)->firstWhere('label', 'Non demarre')['count'] ?? 0, 'accent' => '#64748B', 'bg' => '#F1F5F9', 'meta' => 'Aucune progression'],
        ['label' => 'Taux validation', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? number_format(((($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100), 0).'%' : '0%', 'accent' => '#3996D3', 'bg' => '#E8F3FB', 'meta' => 'Validation direction'],
    ];

    $ganttStart = \Illuminate\Support\Carbon::create(now()->year, 1, 1)->startOfDay();
    $ganttEnd = \Illuminate\Support\Carbon::create(now()->year, 12, 31)->endOfDay();
    $ganttRange = max(1, $ganttStart->diffInDays($ganttEnd));
    $todayPercent = round(($ganttStart->diffInDays(now()->startOfDay()) / $ganttRange) * 100, 2);
    $ganttMonths = collect(range(1, 12))->map(function (int $month) use ($ganttStart, $ganttEnd) {
        $start = \Illuminate\Support\Carbon::create(now()->year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $range = max(1, $ganttStart->diffInDays($ganttEnd));

        return [
            'label' => strtoupper($start->locale('fr')->translatedFormat('M')),
            'offset' => round(($ganttStart->diffInDays($start) / $range) * 100, 2),
            'width' => round(($start->diffInDays($end) / $range) * 100, 2),
        ];
    })->all();

    $dashboardPillVars = static function (string $tone): string {
        return match ($tone) {
            'success' => '--pill-bg:#EEF6E1;--pill-fg:#8FC043;--pill-bg-dark:rgba(143,192,67,0.16);--pill-fg-dark:#F8E932;--pill-border-dark:rgba(143,192,67,0.28);',
            'warning' => '--pill-bg:#FFF8D6;--pill-fg:#F9B13C;--pill-bg-dark:rgba(240,229,9,0.14);--pill-fg-dark:#F8E932;--pill-border-dark:rgba(240,229,9,0.24);',
            'danger' => '--pill-bg:#FFF0DF;--pill-fg:#F9B13C;--pill-bg-dark:rgba(249,177,60,0.14);--pill-fg-dark:#F8E932;--pill-border-dark:rgba(249,177,60,0.28);',
            'info' => '--pill-bg:#E8F3FB;--pill-fg:#3996D3;--pill-bg-dark:rgba(57,150,211,0.16);--pill-fg-dark:#BFEFFF;--pill-border-dark:rgba(57,150,211,0.28);',
            default => '--pill-bg:#F1F5F9;--pill-fg:#64748B;--pill-bg-dark:rgba(71,85,105,0.22);--pill-fg-dark:#CBD5E1;--pill-border-dark:rgba(71,85,105,0.26);',
        };
    };

    $dashboardKpiTone = static function (float $value): string {
        if ($value >= 80) {
            return 'success';
        }

        if ($value >= 60) {
            return 'warning';
        }

        if ($value > 0) {
            return 'danger';
        }

        return 'neutral';
    };

    $dashboardStatusTone = static function (string $status): string {
        return match ($status) {
            'acheve', 'en_avance' => 'success',
            'a_risque' => 'warning',
            'en_retard' => 'danger',
            'suspendu' => 'danger',
            'non_demarre' => 'neutral',
            'annule' => 'neutral',
            default => 'info',
        };
    };
@endphp

@once
    @push('head')
        <style>
            .dashboard-tab{border:0;border-radius:9999px;padding:.75rem 1rem;font-size:.8rem;font-weight:800;white-space:nowrap;transition:all .15s ease}
            .dashboard-tab-active{background:linear-gradient(135deg,#3996D3 0%,#3996D3 46%,#1C203D 100%);color:#fff;box-shadow:0 12px 22px -18px rgba(57,150,211,.72)}
            .dashboard-tab-inactive{background:transparent;color:rgb(71 85 105)}
            .dashboard-tab-panel{display:none;animation:dashboardFadeUp .24s ease}
            .dashboard-tab-panel.active{display:block}
            .dashboard-canvas{position:relative;min-height:300px}
            .dashboard-canvas-lg{min-height:320px}
            .dashboard-chart-host{width:100%;height:100%}
            .dashboard-canvas .dashboard-chart-host,.dashboard-canvas .dashboard-chart-host canvas,.dashboard-canvas svg{position:absolute;inset:0;width:100%!important;height:100%!important}
            .dashboard-chart-host canvas{display:block}
            .dashboard-table{width:100%;border-collapse:collapse;font-size:.84rem}
            .dashboard-table th{padding:.78rem .9rem;text-align:left;font-size:.67rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#1C203D;background:linear-gradient(180deg,rgba(241,246,250,.98)_0%,rgba(232,243,251,.96)_100%);border-bottom:1px solid rgba(208,218,229,.92);white-space:nowrap}
            .dashboard-table td{padding:.85rem .9rem;border-bottom:1px solid rgba(218,226,236,.95);color:rgb(30 41 59);vertical-align:middle}
            .dashboard-table tbody tr:nth-child(even) td{background:rgba(248,250,252,.86)}
            .dashboard-table tbody tr:hover td{background:rgba(232,243,251,.9)}
            .dashboard-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:9999px;padding:.3rem .7rem;font-size:.72rem;font-weight:800;background:var(--pill-bg,#f1f5f9);color:var(--pill-fg,#64748b);border:1px solid var(--pill-border,transparent)}
            .dashboard-bullet{display:grid;grid-template-columns:minmax(0,150px) 1fr 42px;gap:.75rem;align-items:center}
            .dashboard-bullet-track{position:relative;height:1rem;border-radius:.45rem;overflow:hidden;background:rgb(241 245 249 / .96)}
            .dashboard-bullet-threshold{position:absolute;inset:0 auto 0 0;width:60%;background:rgb(254 243 199 / .9)}
            .dashboard-bullet-target{position:absolute;inset:-.12rem auto -.12rem 80%;width:2px;background:rgb(15 23 42)}
            .dashboard-bullet-value{position:absolute;inset:.14rem auto .14rem 0;border-radius:.3rem}
            .dashboard-gantt-grid,.dashboard-gantt-head{display:grid;grid-template-columns:minmax(180px,220px) 1fr 48px;gap:.75rem;align-items:center}
            .dashboard-gantt-track{position:relative;min-width:620px;height:2rem;border-radius:9999px;overflow:hidden;background:linear-gradient(180deg,rgb(248 250 252 / .95) 0%,rgb(241 245 249 / .96) 100%)}
            .dashboard-gantt-track::before{content:'';position:absolute;inset:0;background-image:linear-gradient(to right,rgb(241 245 249) 1px,transparent 1px);background-size:calc(100% / 12) 100%;opacity:.72}
            .dashboard-gantt-month{position:absolute;top:0;bottom:0;display:flex;align-items:center;justify-content:center;border-left:1px solid rgb(241 245 249);font-size:.64rem;font-weight:800;color:rgb(148 163 184)}
            .dashboard-gantt-bar,.dashboard-gantt-progress{position:absolute;top:.45rem;bottom:.45rem;border-radius:9999px}
            .dashboard-gantt-bar{opacity:.28}
            .dashboard-gantt-today{position:absolute;top:0;bottom:0;width:2px;background:rgb(249 177 60 / .72)}
            .dashboard-gantt-svg{overflow:visible}
            .dashboard-gantt-axis text{font-size:.66rem;fill:#64748B;font-weight:800}
            .dashboard-gantt-axis line,.dashboard-gantt-axis path{stroke:rgba(148,163,184,.22)}
            .dashboard-gantt-label{font-size:.78rem;fill:#1C203D;font-weight:700}
            .dashboard-gantt-meta{font-size:.68rem;fill:#64748B}
            .dashboard-gantt-bg{fill:rgba(226,232,240,.78)}
            .dashboard-gantt-plan{opacity:.28}
            .dashboard-gantt-real{filter:drop-shadow(0 0 10px rgba(57,150,211,.22))}
            .dashboard-gantt-today-line{stroke:#F9B13C;stroke-width:2;stroke-dasharray:4 4}
            .dashboard-gantt-right{font-size:.72rem;fill:#1C203D;font-weight:900}
            .dashboard-advanced-shell{display:flex;flex-direction:column;gap:1rem}
            .dashboard-advanced-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
            .dashboard-advanced-card{position:relative;overflow:hidden;border:1px solid rgba(57,150,211,.18);border-radius:1.45rem;padding:1rem 1rem 1.1rem;background:linear-gradient(180deg,rgba(255,255,255,.98) 0%,rgba(248,250,252,.96) 100%);box-shadow:0 24px 46px -38px rgba(15,23,42,.45)}
            .dashboard-advanced-card::before{content:'';position:absolute;inset:0 0 auto 0;height:4px;background:linear-gradient(90deg,#3996D3 0%,#8FC043 28%,#F0E509 58%,#F9B13C 100%);opacity:.95}
            .dashboard-advanced-card:hover{transform:translateY(-2px);box-shadow:0 28px 48px -36px rgba(15,23,42,.62)}
            .dashboard-advanced-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.85rem;margin-bottom:.9rem}
            .dashboard-advanced-kpi{border-radius:1.2rem;border:1px solid rgba(57,150,211,.14);padding:1rem;background:linear-gradient(180deg,rgba(255,255,255,.98) 0%,rgba(248,250,252,.94) 100%);box-shadow:0 18px 34px -30px rgba(15,23,42,.45)}
            .dashboard-advanced-kpi .dashboard-summary-value{color:#1C203D}
            .dashboard-advanced-kpi-blue .dashboard-summary-value{color:#3996D3}
            .dashboard-advanced-kpi-green .dashboard-summary-value{color:#8FC043}
            .dashboard-advanced-kpi-amber .dashboard-summary-value{color:#F9B13C}
            .dashboard-advanced-kpi-navy .dashboard-summary-value{color:#1C203D}
            .dashboard-table-compact th,.dashboard-table-compact td{padding:.68rem .72rem}
            .dashboard-table-wide{min-width:1180px}
            .dashboard-heatmap-wrap{overflow:auto}
            .dashboard-heatmap-table{width:100%;border-collapse:separate;border-spacing:.38rem;font-size:.78rem}
            .dashboard-heatmap-table th{padding:.2rem .16rem;border:none;background:transparent;font-size:.66rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748B}
            .dashboard-heatmap-table td{padding:0;border:none;text-align:center}
            .dashboard-heatmap-cell{display:flex;align-items:center;justify-content:center;min-width:2.8rem;min-height:2.55rem;border-radius:.95rem;color:#1C203D;font-size:.76rem;font-weight:900;box-shadow:inset 0 -1px 0 rgba(255,255,255,.28)}
            .dashboard-critical-gantt-list{display:flex;flex-direction:column;gap:.7rem}
            .dashboard-critical-gantt-row{display:grid;grid-template-columns:minmax(190px,220px) 1fr;gap:.75rem;align-items:center}
            .dashboard-critical-gantt-track{position:relative;height:1rem;border-radius:9999px;background:linear-gradient(180deg,rgba(226,232,240,.95) 0%,rgba(226,232,240,.82) 100%);overflow:hidden}
            .dashboard-critical-gantt-bar{position:absolute;top:0;bottom:0;border-radius:9999px;background:linear-gradient(90deg,rgba(249,177,60,.32) 0%,rgba(57,150,211,.38) 100%)}
            .dashboard-critical-gantt-progress{display:block;height:100%;border-radius:9999px;background:linear-gradient(90deg,#F9B13C 0%,#3996D3 100%);box-shadow:0 0 22px -8px rgba(57,150,211,.58)}
            .dashboard-treemap-wrap{display:flex;flex-wrap:wrap;gap:.7rem}
            .dashboard-treemap-item{min-width:170px;border-radius:1rem;padding:.82rem;background:linear-gradient(145deg,rgba(57,150,211,.12) 0%,rgba(143,192,67,.18) 100%);border:1px solid rgba(57,150,211,.16);transition:transform .18s ease,box-shadow .18s ease}
            .dashboard-treemap-item:hover{transform:translateY(-2px);box-shadow:0 18px 28px -24px rgba(15,23,42,.6)}
            .dashboard-treemap-item strong{display:block;font-size:.78rem;color:#1C203D}
            .dashboard-treemap-item span{display:block;margin-top:.25rem;font-size:.74rem;color:#475569}
            .dashboard-risk-inline{display:flex;align-items:center;gap:.55rem}
            .dashboard-risk-inline-bar{display:inline-flex;align-items:center;flex:1;min-width:86px;height:.5rem;border-radius:9999px;background:rgba(226,232,240,.95);overflow:hidden}
            .dashboard-risk-inline-bar span{display:block;height:100%;border-radius:9999px;background:linear-gradient(90deg,#F9B13C 0%,#1C203D 100%)}
            .dashboard-gauge-grid{display:grid;gap:.8rem;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
            .dashboard-gauge-card{border-radius:1rem;border:1px solid rgba(203,213,225,.85);padding:.75rem;background:rgba(255,255,255,.94);text-align:center}
            .dashboard-gauge-card strong{display:block;min-height:2rem;font-size:.76rem;color:#1C203D}
            .dashboard-gauge-card p{margin:.1rem 0 0;font-size:.78rem;color:#64748B}
            .dashboard-gauge-canvas{position:relative;height:105px}
            .dashboard-gauge-canvas .dashboard-chart-host,.dashboard-gauge-canvas .dashboard-chart-host canvas,.dashboard-gauge-canvas svg{position:absolute;inset:0;width:100%!important;height:100%!important}
            .dashboard-status-block{border:1px solid rgba(226,232,240,.9);border-radius:1rem;background:rgba(248,250,252,.9);padding:.8rem}
            .dashboard-status-block-title{margin-bottom:.55rem;font-size:.72rem;font-weight:900;letter-spacing:.08em;color:#1C203D}
            .dashboard-chart-legend{display:flex;flex-wrap:wrap;gap:.5rem .65rem;margin-top:.75rem}
            .dashboard-chart-legend span{display:inline-flex;align-items:center;gap:.4rem;border-radius:9999px;padding:.32rem .64rem;font-size:.69rem;font-weight:800;background:rgba(241,245,249,.96);color:#1C203D}
            .dashboard-chart-legend i{display:inline-block;width:.65rem;height:.65rem;border-radius:9999px}
            .dashboard-reporting-jump{display:inline-flex;align-items:center;justify-content:center;border-radius:1rem;padding:.78rem 1rem;font-size:.78rem;font-weight:800;color:#1C203D;background:linear-gradient(135deg,rgba(248,233,50,.32) 0%,rgba(57,150,211,.18) 100%);border:1px solid rgba(57,150,211,.2);transition:all .16s ease}
            .dashboard-reporting-jump:hover{background:linear-gradient(135deg,rgba(248,233,50,.46) 0%,rgba(57,150,211,.24) 100%);transform:translateY(-1px)}
            @keyframes dashboardFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
            .dark .dashboard-tab-inactive{color:rgb(148 163 184)}
            .dark .dashboard-table th{background:linear-gradient(180deg,rgba(28,32,61,.96)_0%,rgba(20,24,47,.98)_100%);border-bottom-color:rgb(51 65 85 / .88);color:rgb(248 250 252)}
            .dark .dashboard-table td{border-bottom-color:rgb(30 41 59 / .8);color:rgb(226 232 240)}
            .dark .dashboard-table tbody tr:nth-child(even) td{background:rgba(18,24,43,.86)}
            .dark .dashboard-table tbody tr:hover td{background:rgba(28,32,61,.56)}
            .dark .dashboard-pill{background:var(--pill-bg-dark,rgba(15,23,42,.86));color:var(--pill-fg-dark,#e2e8f0);border-color:var(--pill-border-dark,rgba(71,85,105,.28))}
            .dark .dashboard-bullet-track,.dark .dashboard-gantt-track{background:rgb(30 41 59 / .95)}
            .dark .dashboard-bullet-target{background:rgb(248 250 252)}
            .dark .dashboard-gantt-track::before{background-image:linear-gradient(to right,rgb(51 65 85 / .72) 1px,transparent 1px)}
            .dark .dashboard-gantt-month{border-left-color:rgb(30 41 59 / .9);color:rgb(148 163 184)}
            .dark .dashboard-gantt-axis text,.dark .dashboard-gantt-meta{fill:#94A3B8}
            .dark .dashboard-gantt-axis line,.dark .dashboard-gantt-axis path{stroke:rgba(100,116,139,.24)}
            .dark .dashboard-gantt-label,.dark .dashboard-gantt-right{fill:#F8FAFC}
            .dark .dashboard-gantt-bg{fill:rgba(51,65,85,.78)}
            .dark .dashboard-advanced-card,.dark .dashboard-advanced-kpi{border-color:rgba(57,150,211,.18);background:linear-gradient(180deg,rgba(15,23,42,.96) 0%,rgba(15,23,42,.88) 100%)}
            .dark .dashboard-advanced-kpi .dashboard-summary-meta,.dark .dashboard-advanced-kpi .dashboard-summary-label{color:#94A3B8}
            .dark .dashboard-heatmap-table th{color:#94A3B8}
            .dark .dashboard-heatmap-cell{color:#F8FAFC;box-shadow:inset 0 -1px 0 rgba(255,255,255,.06)}
            .dark .dashboard-critical-gantt-track{background:linear-gradient(180deg,rgba(51,65,85,.72) 0%,rgba(30,41,59,.86) 100%)}
            .dark .dashboard-treemap-item{border-color:rgba(57,150,211,.2);background:linear-gradient(145deg,rgba(57,150,211,.18) 0%,rgba(143,192,67,.14) 100%)}
            .dark .dashboard-treemap-item strong{color:#F8FAFC}
            .dark .dashboard-treemap-item span,.dark .dashboard-gauge-card p{color:#CBD5E1}
            .dark .dashboard-risk-inline-bar{background:rgba(30,41,59,.95)}
            .dark .dashboard-gauge-card,.dark .dashboard-status-block{border-color:rgba(51,65,85,.88);background:rgba(15,23,42,.82)}
            .dark .dashboard-gauge-card strong,.dark .dashboard-status-block-title{color:#F8FAFC}
            .dark .dashboard-chart-legend span{background:rgba(15,23,42,.9);color:#F8FAFC}
            .dark .dashboard-reporting-jump{color:#F8FAFC;background:linear-gradient(135deg,rgba(248,233,50,.16) 0%,rgba(57,150,211,.16) 100%);border-color:rgba(57,150,211,.26)}
            @media (max-width:1024px){.dashboard-bullet,.dashboard-gantt-grid,.dashboard-gantt-head,.dashboard-critical-gantt-row{grid-template-columns:1fr}}
        </style>
    @endpush
@endonce

<section class="showcase-hero mb-4">
    <div class="showcase-hero-body">
        <div class="max-w-3xl">
            <span class="showcase-eyebrow">Cockpit analytique</span>
            <h1 class="showcase-title">Tableau de bord strategique et operationnel</h1>
            <p class="showcase-subtitle mt-1">Vue consolidee du portefeuille PAS / PAO / PTA / Actions avec un rendu analytique plus riche, sans sortir du perimetre metier actuel.</p>
            <div class="showcase-chip-row">
                <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3996D3]"></span>{{ $user->name }}</span>
                <span class="showcase-chip"><span class="showcase-chip-dot bg-[#8FC043]"></span>{{ $profil['role_label'] }}</span>
                @if ($user->direction?->libelle)
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F0E509]"></span>{{ $user->direction->libelle }}</span>
                @endif
                @if ($user->service?->libelle)
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3996D3]"></span>{{ $user->service->libelle }}</span>
                @endif
            </div>
        </div>
        <div class="showcase-action-row">
            <a class="inline-flex items-center justify-center rounded-2xl bg-white/85 px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200 transition hover:bg-white dark:bg-slate-900/70 dark:text-slate-100 dark:ring-slate-700" href="{{ route('workspace.pilotage') }}">Pilotage global</a>
            <a class="inline-flex items-center justify-center rounded-2xl bg-[#3996D3] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1C203D]" href="{{ route('workspace.alertes') }}">Centre d alertes</a>
            <a class="inline-flex items-center justify-center rounded-2xl bg-[#1C203D] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#3996D3]" href="{{ route('workspace.reporting') }}">Reporting consolide</a>
        </div>
    </div>
</section>

<div class="mb-4 flex flex-wrap gap-2 rounded-[1.35rem] border border-[#3996d3]/18 bg-white/95 p-2 shadow-[0_20px_44px_-36px_rgba(15,23,42,0.45)] dark:border-white/10 dark:bg-slate-950/80" data-dashboard-tabs>
    <button type="button" class="dashboard-tab dashboard-tab-active" data-dashboard-tab="overview">Vue generale</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="kpi">KPI et tendances</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="actions">Actions</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="gantt">Gantt</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="tables">Tableaux</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="analytics">Analytique avancee</button>
</div>

<section class="dashboard-tab-panel active" data-dashboard-panel="overview">
    <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        @foreach ($summaryStrip as $card)
            <article class="dashboard-summary-card dashboard-summary-card-{{ $loop->index }} rounded-[1.2rem] border p-4 shadow-[0_18px_34px_-30px_rgba(15,23,42,0.45)]">
                <p class="dashboard-summary-label">{{ $card['label'] }}</p>
                <p class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none" style="color: {{ $card['accent'] }};">{{ $card['value'] }}</p>
                <p class="dashboard-summary-meta mt-2 text-xs">{{ $card['meta'] }}</p>
            </article>
        @endforeach
    </div>

    <div class="mb-4 grid gap-4 xl:grid-cols-[repeat(5,minmax(0,1fr))_1.2fr]">
        @foreach ([['key' => 'delai', 'label' => 'KPI Delai'],['key' => 'performance', 'label' => 'KPI Performance'],['key' => 'conformite', 'label' => 'KPI Conformite'],['key' => 'qualite', 'label' => 'KPI Qualite'],['key' => 'risque', 'label' => 'KPI Risque']] as $gauge)
            @php
                $value = (float) ($globalScores[$gauge['key']] ?? 0);
            @endphp
            <article class="showcase-panel">
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $gauge['label'] }}</p>
                <div class="dashboard-canvas !min-h-[140px]"><div id="dashboard-kpi-gauge-{{ $gauge['key'] }}" class="dashboard-chart-host"></div></div>
                <p class="text-center text-xs text-slate-500">Moyenne scopee sur les actions calculees.</p>
            </article>
        @endforeach

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Repartition des statuts</h2>
                    <p class="showcase-panel-subtitle">Mix global des actions.</p>
                </div>
                <span class="showcase-chip">{{ $metrics['totals']['actions_total'] ?? 0 }} actions</span>
            </div>
            <div class="dashboard-canvas"><div id="dashboard-status-mix-chart" class="dashboard-chart-host"></div></div>
        </article>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.55fr_1fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">KPI mensuels par cohortes d actions</h2>
                    <p class="showcase-panel-subtitle">Moyennes des KPI par mois de demarrage.</p>
                </div>
                <span class="showcase-chip">{{ count($analytics['monthly'] ?? []) }} mois</span>
            </div>
            <div class="dashboard-canvas"><div id="dashboard-kpi-line-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Synthese par {{ strtolower($unitModeLabel) }}</h2>
                    <p class="showcase-panel-subtitle">Lecture rapide des charges et performances.</p>
                </div>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-canvas"><div id="dashboard-unit-summary-chart" class="dashboard-chart-host"></div></div>
        </article>
    </div>

    <div class="showcase-panel mt-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Tableau de synthese par {{ strtolower($unitModeLabel) }}</h2>
                <p class="showcase-panel-subtitle">Actions, progression, KPI moyen, alertes et validation.</p>
            </div>
            <span class="showcase-chip">{{ count($unitRows) }} lignes</span>
        </div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>{{ $unitModeLabel }}</th><th>Actions</th><th>Progression</th><th>KPI moyen</th><th>Alertes</th><th>Validation</th></tr></thead>
                <tbody>
                    @forelse ($unitRows as $row)
                        @php
                            $progress = (float) ($row['progression_moyenne'] ?? 0);
                            $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F0E509' : '#94A3B8'));
                            $kpi = (float) ($row['kpi_global'] ?? 0);
                        @endphp
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['label'] }}</td>
                            <td>{{ $row['actions_total'] }}</td>
                            <td>
                                <div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90 dark:bg-slate-700"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div>
                            </td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpi)) }}">{{ number_format($kpi, 0) }}</span></td>
                            <td>@if (($row['alertes'] ?? 0) > 0)<span class="dashboard-pill" style="{{ $dashboardPillVars('danger') }}">{{ $row['alertes'] }}</span>@else<span class="dashboard-pill" style="{{ $dashboardPillVars('success') }}">0</span>@endif</td>
                            <td>{{ number_format((float) ($row['validation_pct'] ?? 0), 0) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Aucune donnee consolidee disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr_0.9fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Mes notifications recentes</h2><p class="showcase-panel-subtitle">Evenements recents avec acces direct.</p></div>
                <span class="showcase-chip">{{ $dashboardNotifications->count() }} visibles</span>
            </div>
            <div class="grid gap-3">
                @forelse ($dashboardNotifications as $notification)
                    @php $moduleLabel = strtoupper((string) ($notification->data['module'] ?? 'autres')); @endphp
                    <a href="{{ route('workspace.notifications.read', $notification->id) }}" class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm transition hover:border-[#3996d3]/28 hover:bg-[#e8f3fb]/80 dark:border-slate-800 dark:bg-slate-900/70 dark:hover:bg-slate-800">
                        <div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $notification->read_at === null ? 'bg-[#f9b13c]' : 'bg-slate-300 dark:bg-slate-600' }}"></span><p class="font-semibold text-slate-900 dark:text-slate-100">{{ $notification->data['title'] ?? 'Notification' }}</p></div><span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px] leading-none">{{ $moduleLabel }}</span></div>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $notification->data['message'] ?? '' }}</p>
                    </a>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune notification recente.</div>
                @endforelse
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Profil utilisateur</h2><p class="showcase-panel-subtitle">Identite et perimetre d'utilisation.</p></div><span class="showcase-chip">{{ $profil['role_label'] }}</span></div>
            <div class="showcase-data-list">
                <div class="showcase-data-point"><p class="showcase-data-key">Utilisateur</p><p class="showcase-data-value">{{ $user->name }}</p></div>
                <div class="showcase-data-point"><p class="showcase-data-key">Email</p><p class="showcase-data-value">{{ $user->email }}</p></div>
                <div class="showcase-data-point"><p class="showcase-data-key">Role</p><p class="showcase-data-value">{{ $profil['role'] }}</p></div>
                <div class="showcase-data-point"><p class="showcase-data-key">Direction</p><p class="showcase-data-value">{{ $user->direction?->libelle ?? 'Aucune' }}</p></div>
                <div class="showcase-data-point"><p class="showcase-data-key">Service</p><p class="showcase-data-value">{{ $user->service?->libelle ?? 'Aucun' }}</p></div>
                <div class="showcase-data-point"><p class="showcase-data-key">Portee</p><p class="showcase-data-value">{{ $profil['scope'] }}</p></div>
            </div>
        </article>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.15fr_1fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Interactions disponibles pour ce profil</h2><p class="showcase-panel-subtitle">Operations metier permises dans l application.</p></div><span class="showcase-chip">{{ count($profil['items']) }} modules</span></div>
            <div class="grid gap-3">
                @forelse ($profil['items'] as $item)
                    <article class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70"><div class="flex items-center justify-between gap-3"><strong class="text-slate-900 dark:text-slate-100">{{ $item['module'] }}</strong><span class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">{{ $item['portee'] }}</span></div><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ implode(' | ', $item['operations']) }}</p></article>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune interaction configuree pour ce profil.</div>
                @endforelse
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Espace de travail (interactions utilisables)</h2><p class="showcase-panel-subtitle">Modules directement actionnables depuis ce tableau de bord.</p></div><span class="showcase-chip">{{ count($modules) }} modules</span></div>
            <div class="grid gap-3">
                @forelse ($modules as $module)
                    <article class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70"><div class="flex items-center justify-between gap-2"><strong class="text-slate-900 dark:text-slate-100">{{ $module['label'] }}</strong><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $module['can_write'] ? 'bg-[#eef6e1] text-[#1c203d] dark:bg-[#8fc043]/15 dark:text-[#f8e932]' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' }}">{{ $module['can_write'] ? 'Ecriture' : 'Lecture' }}</span></div><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $module['description'] }}</p><div class="mt-3 flex items-center justify-between gap-2"><code class="rounded bg-slate-100 px-2 py-1 text-[11px] dark:bg-slate-800">{{ $module['endpoint'] }}</code><a href="{{ $module['endpoint'] }}" class="inline-flex items-center justify-center rounded-xl bg-[#1C203D] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#3996D3]">Ouvrir</a></div></article>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucun module directement accessible.</div>
                @endforelse
            </div>
        </article>
    </div>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="kpi">
    <div class="grid gap-4 xl:grid-cols-[1.45fr_1fr]">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">KPI par mois</h2><p class="showcase-panel-subtitle">Comparaison delai, performance, conformite, qualite, risque et global.</p></div><span class="showcase-chip">12 mois</span></div><div class="dashboard-canvas"><div id="dashboard-kpi-grouped-chart" class="dashboard-chart-host"></div></div></article>
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">KPI global moyen</h2><p class="showcase-panel-subtitle">Vue macro du perimetre actuellement scope.</p></div><span class="showcase-chip">Seuil 60</span></div>
            <div class="rounded-[1.4rem] p-5 text-white" style="background: linear-gradient(135deg, #8FC043 0%, #3996D3 52%, #1C203D 100%);"><p class="text-[11px] font-semibold uppercase tracking-[0.15em] text-white/75">Score actuel</p><p class="mt-3 text-5xl font-black leading-none">{{ number_format((float) ($globalScores['global'] ?? 0), 0) }}</p><p class="mt-3 text-sm text-white/80">Progression moyenne: {{ number_format((float) ($globalScores['progression'] ?? 0), 0) }}%</p><div class="mt-4 h-2 rounded-full bg-white/20"><div class="h-2 rounded-full bg-white" style="width: {{ min(100, max(0, (float) ($globalScores['global'] ?? 0))) }}%;"></div></div></div>
            <div class="mt-4 grid gap-2">@foreach ($statusCards as $card)<div class="rounded-2xl border border-slate-200/80 bg-slate-50/90 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/70"><div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $card['color'] }};"></span><span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $card['label'] }}</span></div><span class="text-sm font-black" style="color: {{ $card['color'] }};">{{ $card['count'] }}</span></div></div>@endforeach</div>
        </article>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr_1fr]">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Comparaison interannuelle</h2><p class="showcase-panel-subtitle">Actions total, actions validees et progression moyenne.</p></div><span class="showcase-chip">{{ count($interannualRows) }} annee(s)</span></div><div class="dashboard-canvas"><div id="dashboard-interannual-chart" class="dashboard-chart-host"></div></div></article>
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Cible vs realise</h2><p class="showcase-panel-subtitle">Lecture type bullet chart sur les actions prioritaires.</p></div><span class="showcase-chip">Cible 80</span></div>
            @if ($bulletRows !== [])
                <div class="grid gap-3">@foreach ($bulletRows as $row)@php $real = (float) ($row['real'] ?? 0); $bulletColor = $real >= 80 ? '#8FC043' : ($real >= 60 ? '#F0E509' : '#F9B13C'); @endphp<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-[#e8f3fb]/70 dark:hover:bg-slate-900/60"><span class="truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-threshold"></span><span class="dashboard-bullet-target"></span><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, $real)) }}%; background: {{ $bulletColor }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $bulletColor }};">{{ number_format($real, 0) }}</span></a>@endforeach</div>
            @else
                <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune action KPI disponible pour cette lecture.</div>
            @endif
        </article>
    </div>
</section>
<section class="dashboard-tab-panel" data-dashboard-panel="actions">
    <div class="grid gap-4 xl:grid-cols-[1fr_1.15fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Classement des actions par KPI</h2><p class="showcase-panel-subtitle">Top des actions les mieux notees.</p></div><span class="showcase-chip">Top 6</span></div>
            @if ($analytics['top_action_bars'] ?? false)
                <div class="grid gap-3">@foreach ($analytics['top_action_bars'] as $row)<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-[#e8f3fb]/70 dark:hover:bg-slate-900/60"><span class="truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, (float) $row['value'])) }}%; background: {{ $row['color'] }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $row['color'] }};">{{ number_format((float) $row['value'], 0) }}</span></a>@endforeach</div>
            @else
                <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune action classee pour le moment.</div>
            @endif
        </article>
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Radar de comparaison</h2><p class="showcase-panel-subtitle">Delai, performance, conformite et progression.</p></div><span class="showcase-chip">{{ min(3, count($unitRows)) }} jeux</span></div><div class="dashboard-canvas"><div id="dashboard-radar-chart" class="dashboard-chart-host"></div></div></article>
    </div>

    <div class="showcase-panel mt-4"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Scatter performance / conformite</h2><p class="showcase-panel-subtitle">Repere visuel pour isoler les actions solides ou fragiles.</p></div><span class="showcase-chip">{{ count($analytics['scatter_points'] ?? []) }} points</span></div><div class="dashboard-canvas"><div id="dashboard-scatter-chart" class="dashboard-chart-host"></div></div></div>

    <div class="showcase-panel mt-4">
        <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Actions prioritaires</h2><p class="showcase-panel-subtitle">Vue decisionnelle compacte sur les actions les plus visibles du portefeuille.</p></div><span class="showcase-chip">{{ count($priorityActionRows) }} lignes</span></div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Action</th><th>Direction</th><th>Statut</th><th>Progression</th><th>KPI</th><th>Delai</th><th>Perf.</th><th>Conf.</th><th>Qual.</th><th>Risque</th></tr></thead>
                <tbody>
                    @forelse ($priorityActionRows as $row)
                        @php
                            $statusColor = match ($row['statut']) {'acheve' => '#1C203D','en_avance' => '#8FC043','a_risque' => '#F0E509','en_retard' => '#F9B13C','suspendu' => '#7C3AED','annule' => '#475569','non_demarre' => '#64748B',default => '#3996D3'};
                            $statusBg = match ($row['statut']) {'acheve' => '#EEF1F8','en_avance' => '#EEF6E1','a_risque' => '#FFF8D6','en_retard' => '#FFF0DF','suspendu' => '#F3E8FF','annule' => '#E2E8F0','non_demarre' => '#F1F5F9',default => '#E8F3FB'};
                            $progress = (float) ($row['progression'] ?? 0);
                            $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F0E509' : '#94A3B8'));
                        @endphp
                        <tr>
                            <td><a href="{{ $row['url'] }}" class="font-semibold text-slate-900 hover:text-[#3996D3] dark:text-slate-100">{{ $row['libelle'] }}</a><div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $row['responsable'] }} | {{ $row['service'] }}</div></td>
                            <td>{{ $row['direction'] }}</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}"><span class="h-2 w-2 rounded-full" style="background: {{ $statusColor }};"></span>{{ $row['statut_label'] }}</span></td>
                            <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90 dark:bg-slate-700"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                            @foreach (['kpi_global', 'kpi_delai', 'kpi_performance', 'kpi_conformite', 'kpi_qualite', 'kpi_risque'] as $metricKey)
                                @php $metricValue = (float) ($row[$metricKey] ?? 0); $metricColor = $metricValue >= 80 ? '#8FC043' : ($metricValue >= 60 ? '#F9B13C' : ($metricValue > 0 ? '#F9B13C' : '#64748B')); $metricBg = $metricValue >= 80 ? '#EEF6E1' : ($metricValue >= 60 ? '#FFF8D6' : ($metricValue > 0 ? '#FFF0DF' : '#F1F5F9')); @endphp
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($metricValue)) }}">{{ number_format($metricValue, 0) }}</span></td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="10">Aucune action disponible sur ce perimetre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="gantt">
    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Diagramme de Gantt compact</h2><p class="showcase-panel-subtitle">Barre grise = duree planifiee, barre couleur = progression reelle, ligne orange = aujourd'hui.</p></div><span class="showcase-chip">{{ count($ganttRows) }} actions</span></div>
        @if ($ganttRows !== [])
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-gantt-chart" class="dashboard-chart-host"></div></div>
        @else
            <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune action datee disponible pour produire un Gantt.</div>
        @endif
    </article>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="tables">
    <div class="grid gap-4">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Alertes actives</h2><p class="showcase-panel-subtitle">Tableau principal de decision: retards, KPI critiques, ecarts de progression et validations bloquees.</p></div><span class="showcase-chip">{{ count($alertRows) }} alerte(s)</span></div><div class="overflow-x-auto"><table class="dashboard-table"><thead><tr><th>Alerte</th><th>Direction</th><th>Action</th><th>Niveau</th><th>Detail</th><th>KPI</th><th>Qual.</th><th>Risque</th><th>Acces</th></tr></thead><tbody>@forelse ($alertRows as $row)<tr><td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['titre'] }}</td><td>{{ $row['direction'] }}</td><td>{{ $row['action'] }}</td><td><span class="dashboard-pill" style="{{ $dashboardPillVars(in_array($row['niveau'], ['Critique', 'Urgence'], true) ? 'danger' : 'warning') }}">{{ $row['niveau'] }}</span></td><td>{{ $row['details'] }}</td>@php $kpiValue = (float) ($row['kpi'] ?? 0); $qualityValue = (float) ($row['kpi_qualite'] ?? 0); $riskValue = (float) ($row['kpi_risque'] ?? 0); @endphp<td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpiValue)) }}">{{ number_format($kpiValue, 0) }}</span></td><td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($qualityValue)) }}">{{ number_format($qualityValue, 0) }}</span></td><td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($riskValue)) }}">{{ number_format($riskValue, 0) }}</span></td><td><a href="{{ $row['url'] }}" class="inline-flex items-center justify-center rounded-xl bg-[#3996D3] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#1C203D]">Voir</a></td></tr>@empty<tr><td colspan="9">Aucune alerte active sur ce perimetre.</td></tr>@endforelse</tbody></table></div></article>
    </div>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="analytics">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="showcase-panel-title">Analytique avancee consolidee</h2>
            <p class="showcase-panel-subtitle">Tous les graphes et les tableaux de reporting sont embarques ici pour concentrer la lecture decisionnelle sur le dashboard.</p>
        </div>
        <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports et centre de diffusion</a>
    </div>

    @include('partials.dashboard-reporting-analytics', ['reportingAnalytics' => $reportingAnalytics ?? []])
</section>

@once
    @push('scripts')
        <script id="anbg-dashboard-payload" type="application/json">
            {!! json_encode([
                'dashboardData' => $dashboardData ?? [],
                'reportingAnalytics' => $reportingAnalytics ?? [],
                'dgPayload' => $dgPayload ?? [],
                'ganttRows' => $ganttRows ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush
@endonce
