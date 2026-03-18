@php
    $dashboardNotifications = auth()->user()?->notifications()->latest()->limit(6)->get() ?? collect();
    $analytics = $dashboardData ?? [];
    $globalScores = $analytics['global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'global' => 0, 'progression' => 0];
    $statusCards = $analytics['status_cards'] ?? [];
    $unitRows = $analytics['unit_rows'] ?? [];
    $actionRows = $analytics['action_rows'] ?? [];
    $ganttRows = $analytics['gantt_rows'] ?? [];
    $bulletRows = $analytics['bullet_rows'] ?? [];
    $alertRows = $analytics['alert_rows'] ?? [];
    $interannualRows = $analytics['interannual'] ?? [];
    $unitModeLabel = $analytics['unit_mode_label'] ?? 'Unites';

    $summaryStrip = [
        ['label' => 'Actions totales', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#162566', 'bg' => '#F8FAFC', 'meta' => 'Portefeuille scope'],
        ['label' => 'KPI global moyen', 'value' => number_format((float) ($globalScores['global'] ?? 0), 0), 'accent' => '#75BC43', 'bg' => '#E8F6D8', 'meta' => 'Moyenne action_kpis'],
        ['label' => 'En retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? 0, 'accent' => '#F05323', 'bg' => '#FDE9E3', 'meta' => 'Actions hors delai'],
        ['label' => 'Non demarrees', 'value' => collect($statusCards)->firstWhere('label', 'Non demarre')['count'] ?? 0, 'accent' => '#64748B', 'bg' => '#F1F5F9', 'meta' => 'Aucune progression'],
        ['label' => 'Taux validation', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? number_format(((($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100), 0).'%' : '0%', 'accent' => '#1586D4', 'bg' => '#E3F3FF', 'meta' => 'Validation direction'],
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
@endphp

@once
    @push('head')
        <style>
            .dashboard-tab{border:0;border-radius:9999px;padding:.75rem 1rem;font-size:.8rem;font-weight:800;white-space:nowrap;transition:all .15s ease}
            .dashboard-tab-active{background:linear-gradient(135deg,#34B8FF 0%,#1586D4 46%,#162566 100%);color:#fff;box-shadow:0 12px 22px -18px rgba(21,134,212,.75)}
            .dashboard-tab-inactive{background:transparent;color:rgb(71 85 105)}
            .dashboard-tab-panel{display:none;animation:dashboardFadeUp .24s ease}
            .dashboard-tab-panel.active{display:block}
            .dashboard-canvas{position:relative;min-height:300px}
            .dashboard-canvas canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
            .dashboard-table{width:100%;border-collapse:collapse;font-size:.84rem}
            .dashboard-table th{padding:.75rem .85rem;text-align:left;font-size:.67rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:rgb(100 116 139);background:rgb(248 250 252 / .96);border-bottom:1px solid rgb(226 232 240 / .92);white-space:nowrap}
            .dashboard-table td{padding:.8rem .85rem;border-bottom:1px solid rgb(241 245 249);color:rgb(51 65 85);vertical-align:middle}
            .dashboard-table tbody tr:nth-child(even) td{background:rgb(250 252 255 / .7)}
            .dashboard-table tbody tr:hover td{background:rgb(240 249 255 / .82)}
            .dashboard-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:9999px;padding:.3rem .7rem;font-size:.72rem;font-weight:800}
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
            .dashboard-gantt-today{position:absolute;top:0;bottom:0;width:2px;background:rgb(240 83 35 / .72)}
            @keyframes dashboardFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
            .dark .dashboard-tab-inactive{color:rgb(148 163 184)}
            .dark .dashboard-table th{background:rgb(15 23 42 / .92);border-bottom-color:rgb(51 65 85 / .88);color:rgb(148 163 184)}
            .dark .dashboard-table td{border-bottom-color:rgb(30 41 59 / .8);color:rgb(226 232 240)}
            .dark .dashboard-table tbody tr:nth-child(even) td{background:rgb(10 20 46 / .7)}
            .dark .dashboard-table tbody tr:hover td{background:rgb(18 35 72 / .75)}
            .dark .dashboard-bullet-track,.dark .dashboard-gantt-track{background:rgb(30 41 59 / .95)}
            .dark .dashboard-bullet-target{background:rgb(248 250 252)}
            .dark .dashboard-gantt-track::before{background-image:linear-gradient(to right,rgb(51 65 85 / .72) 1px,transparent 1px)}
            .dark .dashboard-gantt-month{border-left-color:rgb(30 41 59 / .9);color:rgb(148 163 184)}
            @media (max-width:1024px){.dashboard-bullet,.dashboard-gantt-grid,.dashboard-gantt-head{grid-template-columns:1fr}}
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
                <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1586D4]"></span>{{ $user->name }}</span>
                <span class="showcase-chip"><span class="showcase-chip-dot bg-[#75BC43]"></span>{{ $profil['role_label'] }}</span>
                @if ($user->direction?->libelle)
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F2C14E]"></span>{{ $user->direction->libelle }}</span>
                @endif
                @if ($user->service?->libelle)
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#34B8FF]"></span>{{ $user->service->libelle }}</span>
                @endif
            </div>
        </div>
        <div class="showcase-action-row">
            <a class="inline-flex items-center justify-center rounded-2xl bg-[#1586D4] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0E6CBE]" href="{{ route('workspace.alertes') }}">Centre d alertes</a>
            <a class="inline-flex items-center justify-center rounded-2xl bg-[#162566] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0F1B4E]" href="{{ route('workspace.reporting') }}">Reporting consolide</a>
        </div>
    </div>
</section>

<div class="mb-4 flex flex-wrap gap-2 rounded-[1.35rem] border border-sky-100/80 bg-white/95 p-2 shadow-[0_20px_44px_-36px_rgba(15,23,42,0.45)] dark:border-slate-800 dark:bg-slate-950/80" data-dashboard-tabs>
    <button type="button" class="dashboard-tab dashboard-tab-active" data-dashboard-tab="overview">Vue generale</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="kpi">KPI et tendances</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="actions">Actions</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="gantt">Gantt</button>
    <button type="button" class="dashboard-tab dashboard-tab-inactive" data-dashboard-tab="tables">Tableaux</button>
</div>

<section class="dashboard-tab-panel active" data-dashboard-panel="overview">
    <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        @foreach ($summaryStrip as $card)
            <article class="rounded-[1.2rem] border border-slate-200/85 p-4 shadow-[0_18px_34px_-30px_rgba(15,23,42,0.45)]" style="background: {{ $card['bg'] }};">
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $card['label'] }}</p>
                <p class="mt-3 text-[2rem] font-black leading-none" style="color: {{ $card['accent'] }};">{{ $card['value'] }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ $card['meta'] }}</p>
            </article>
        @endforeach
    </div>

    <div class="mb-4 grid gap-4 xl:grid-cols-[repeat(3,minmax(0,1fr))_1.2fr]">
        @foreach ([['key' => 'delai', 'label' => 'KPI Delai'],['key' => 'performance', 'label' => 'KPI Performance'],['key' => 'conformite', 'label' => 'KPI Conformite']] as $gauge)
            @php
                $value = (float) ($globalScores[$gauge['key']] ?? 0);
                $radius = 38;
                $circumference = pi() * $radius;
                $offset = $circumference * (1 - ($value / 100));
                $color = $value >= 80 ? '#75BC43' : ($value >= 60 ? '#F2C14E' : ($value > 0 ? '#F05323' : '#94A3B8'));
            @endphp
            <article class="showcase-panel">
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $gauge['label'] }}</p>
                <div class="flex min-h-[120px] items-center justify-center">
                    <svg width="132" height="86" viewBox="0 0 132 86" aria-hidden="true">
                        <path d="M 28 70 A 38 38 0 0 1 104 70" fill="none" stroke="rgba(226,232,240,.95)" stroke-width="10" stroke-linecap="round"></path>
                        <path d="M 28 70 A 38 38 0 0 1 104 70" fill="none" stroke="{{ $color }}" stroke-width="10" stroke-linecap="round" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}"></path>
                        <text x="66" y="54" text-anchor="middle" font-size="24" font-weight="900" fill="{{ $color }}">{{ number_format($value, 0) }}</text>
                        <text x="66" y="67" text-anchor="middle" font-size="9" fill="#94A3B8">/100</text>
                    </svg>
                </div>
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
            <div class="dashboard-canvas"><canvas id="dashboard-status-mix-chart"></canvas></div>
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
            <div class="dashboard-canvas"><canvas id="dashboard-kpi-line-chart"></canvas></div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Synthese par {{ strtolower($unitModeLabel) }}</h2>
                    <p class="showcase-panel-subtitle">Lecture rapide des charges et performances.</p>
                </div>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-canvas"><canvas id="dashboard-unit-summary-chart"></canvas></div>
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
                            $progressColor = $progress >= 80 ? '#75BC43' : ($progress >= 60 ? '#1586D4' : ($progress > 0 ? '#F2C14E' : '#94A3B8'));
                            $kpi = (float) ($row['kpi_global'] ?? 0);
                        @endphp
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['label'] }}</td>
                            <td>{{ $row['actions_total'] }}</td>
                            <td>
                                <div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90 dark:bg-slate-700"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div>
                            </td>
                            <td><span class="dashboard-pill" style="background: {{ $kpi >= 80 ? '#E8F6D8' : ($kpi >= 60 ? '#FFF7DA' : ($kpi > 0 ? '#FDE9E3' : '#F1F5F9')) }}; color: {{ $kpi >= 80 ? '#75BC43' : ($kpi >= 60 ? '#D38B06' : ($kpi > 0 ? '#F05323' : '#64748B')) }};">{{ number_format($kpi, 0) }}</span></td>
                            <td>@if (($row['alertes'] ?? 0) > 0)<span class="dashboard-pill" style="background:#FDE9E3;color:#F05323;">{{ $row['alertes'] }}</span>@else<span class="dashboard-pill" style="background:#E8F6D8;color:#75BC43;">0</span>@endif</td>
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
                <div><h2 class="showcase-panel-title">Mes notifications recentes</h2><p class="showcase-panel-subtitle">Evenements recentes avec acces direct.</p></div>
                <span class="showcase-chip">{{ $dashboardNotifications->count() }} visibles</span>
            </div>
            <div class="grid gap-3">
                @forelse ($dashboardNotifications as $notification)
                    @php $moduleLabel = strtoupper((string) ($notification->data['module'] ?? 'autres')); @endphp
                    <a href="{{ route('workspace.notifications.read', $notification->id) }}" class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm transition hover:border-sky-200 hover:bg-sky-50/80 dark:border-slate-800 dark:bg-slate-900/70 dark:hover:bg-slate-800">
                        <div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $notification->read_at === null ? 'bg-rose-500' : 'bg-slate-300 dark:bg-slate-600' }}"></span><p class="font-semibold text-slate-900 dark:text-slate-100">{{ $notification->data['title'] ?? 'Notification' }}</p></div><span class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">{{ $moduleLabel }}</span></div>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $notification->data['message'] ?? '' }}</p>
                    </a>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune notification recente.</div>
                @endforelse
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Profil utilisateur</h2><p class="showcase-panel-subtitle">Identite et perimetre d utilisation.</p></div><span class="showcase-chip">{{ $profil['role_label'] }}</span></div>
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
                    <article class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70"><div class="flex items-center justify-between gap-2"><strong class="text-slate-900 dark:text-slate-100">{{ $module['label'] }}</strong><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $module['can_write'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' }}">{{ $module['can_write'] ? 'Ecriture' : 'Lecture' }}</span></div><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $module['description'] }}</p><div class="mt-3 flex items-center justify-between gap-2"><code class="rounded bg-slate-100 px-2 py-1 text-[11px] dark:bg-slate-800">{{ $module['endpoint'] }}</code><a href="{{ $module['endpoint'] }}" class="inline-flex items-center justify-center rounded-xl bg-[#162566] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#0F1B4E]">Ouvrir</a></div></article>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucun module directement accessible.</div>
                @endforelse
            </div>
        </article>
    </div>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="kpi">
    <div class="grid gap-4 xl:grid-cols-[1.45fr_1fr]">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">KPI par mois</h2><p class="showcase-panel-subtitle">Comparaison delai, performance, conformite et global.</p></div><span class="showcase-chip">12 mois</span></div><div class="dashboard-canvas"><canvas id="dashboard-kpi-grouped-chart"></canvas></div></article>
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">KPI global moyen</h2><p class="showcase-panel-subtitle">Vue macro du perimetre actuellement scope.</p></div><span class="showcase-chip">Seuil 60</span></div>
            <div class="rounded-[1.4rem] p-5 text-white" style="background: linear-gradient(135deg, #75BC43 0%, #1586D4 52%, #162566 100%);"><p class="text-[11px] font-semibold uppercase tracking-[0.15em] text-white/75">Score actuel</p><p class="mt-3 text-5xl font-black leading-none">{{ number_format((float) ($globalScores['global'] ?? 0), 0) }}</p><p class="mt-3 text-sm text-white/80">Progression moyenne: {{ number_format((float) ($globalScores['progression'] ?? 0), 0) }}%</p><div class="mt-4 h-2 rounded-full bg-white/20"><div class="h-2 rounded-full bg-white" style="width: {{ min(100, max(0, (float) ($globalScores['global'] ?? 0))) }}%;"></div></div></div>
            <div class="mt-4 grid gap-2">@foreach ($statusCards as $card)<div class="rounded-2xl border border-slate-200/80 bg-slate-50/90 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/70"><div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $card['color'] }};"></span><span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $card['label'] }}</span></div><span class="text-sm font-black" style="color: {{ $card['color'] }};">{{ $card['count'] }}</span></div></div>@endforeach</div>
        </article>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr_1fr]">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Comparaison interannuelle</h2><p class="showcase-panel-subtitle">Actions total, actions validees et progression moyenne.</p></div><span class="showcase-chip">{{ count($interannualRows) }} annee(s)</span></div><div class="dashboard-canvas"><canvas id="dashboard-interannual-chart"></canvas></div></article>
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Cible vs realise</h2><p class="showcase-panel-subtitle">Lecture type bullet chart sur les actions prioritaires.</p></div><span class="showcase-chip">Cible 80</span></div>
            @if ($bulletRows !== [])
                <div class="grid gap-3">@foreach ($bulletRows as $row)@php $real = (float) ($row['real'] ?? 0); $bulletColor = $real >= 80 ? '#75BC43' : ($real >= 60 ? '#F2C14E' : '#F05323'); @endphp<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-sky-50/70 dark:hover:bg-slate-900/60"><span class="truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-threshold"></span><span class="dashboard-bullet-target"></span><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, $real)) }}%; background: {{ $bulletColor }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $bulletColor }};">{{ number_format($real, 0) }}</span></a>@endforeach</div>
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
                <div class="grid gap-3">@foreach ($analytics['top_action_bars'] as $row)<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-sky-50/70 dark:hover:bg-slate-900/60"><span class="truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, (float) $row['value'])) }}%; background: {{ $row['color'] }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $row['color'] }};">{{ number_format((float) $row['value'], 0) }}</span></a>@endforeach</div>
            @else
                <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune action classee pour le moment.</div>
            @endif
        </article>
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Radar de comparaison</h2><p class="showcase-panel-subtitle">Delai, performance, conformite et progression.</p></div><span class="showcase-chip">{{ min(3, count($unitRows)) }} jeux</span></div><div class="dashboard-canvas"><canvas id="dashboard-radar-chart"></canvas></div></article>
    </div>

    <div class="showcase-panel mt-4"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Scatter performance / conformite</h2><p class="showcase-panel-subtitle">Repere visuel pour isoler les actions solides ou fragiles.</p></div><span class="showcase-chip">{{ count($analytics['scatter_points'] ?? []) }} points</span></div><div class="dashboard-canvas"><canvas id="dashboard-scatter-chart"></canvas></div></div>

    <div class="showcase-panel mt-4">
        <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Tableau detaille des actions</h2><p class="showcase-panel-subtitle">Statut, progression, KPI et responsable.</p></div><span class="showcase-chip">{{ count($actionRows) }} lignes</span></div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Action</th><th>Direction</th><th>Statut</th><th>Progression</th><th>KPI</th><th>Delai</th><th>Perf.</th><th>Conf.</th></tr></thead>
                <tbody>
                    @forelse ($actionRows as $row)
                        @php
                            $statusColor = match ($row['statut']) {'acheve' => '#162566','en_avance' => '#75BC43','en_retard' => '#F05323','non_demarre' => '#64748B',default => '#1586D4'};
                            $statusBg = match ($row['statut']) {'acheve' => '#E8EAFF','en_avance' => '#E8F6D8','en_retard' => '#FDE9E3','non_demarre' => '#F1F5F9',default => '#E3F3FF'};
                            $progress = (float) ($row['progression'] ?? 0);
                            $progressColor = $progress >= 80 ? '#75BC43' : ($progress >= 60 ? '#1586D4' : ($progress > 0 ? '#F2C14E' : '#94A3B8'));
                        @endphp
                        <tr>
                            <td><a href="{{ $row['url'] }}" class="font-semibold text-slate-900 hover:text-[#1586D4] dark:text-slate-100">{{ $row['libelle'] }}</a><div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $row['responsable'] }} · {{ $row['service'] }}</div></td>
                            <td>{{ $row['direction'] }}</td>
                            <td><span class="dashboard-pill" style="background: {{ $statusBg }}; color: {{ $statusColor }};"><span class="h-2 w-2 rounded-full" style="background: {{ $statusColor }};"></span>{{ $row['statut_label'] }}</span></td>
                            <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90 dark:bg-slate-700"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                            @foreach (['kpi_global', 'kpi_delai', 'kpi_performance', 'kpi_conformite'] as $metricKey)
                                @php $metricValue = (float) ($row[$metricKey] ?? 0); $metricColor = $metricValue >= 80 ? '#75BC43' : ($metricValue >= 60 ? '#D38B06' : ($metricValue > 0 ? '#F05323' : '#64748B')); $metricBg = $metricValue >= 80 ? '#E8F6D8' : ($metricValue >= 60 ? '#FFF7DA' : ($metricValue > 0 ? '#FDE9E3' : '#F1F5F9')); @endphp
                                <td><span class="dashboard-pill" style="background: {{ $metricBg }}; color: {{ $metricColor }};">{{ number_format($metricValue, 0) }}</span></td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="8">Aucune action disponible sur ce perimetre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="gantt">
    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Diagramme de Gantt compact</h2><p class="showcase-panel-subtitle">Barre grise = duree planifiee, barre couleur = progression reelle, ligne orange = aujourd hui.</p></div><span class="showcase-chip">{{ count($ganttRows) }} actions</span></div>
        @if ($ganttRows !== [])
            <div class="overflow-x-auto">
                <div class="dashboard-gantt-head mb-3"><div></div><div class="relative min-w-[620px] h-5">@foreach ($ganttMonths as $month)<div class="dashboard-gantt-month" style="left: {{ $month['offset'] }}%; width: {{ $month['width'] }}%;">{{ $month['label'] }}</div>@endforeach</div><div class="text-right text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">KPI</div></div>
                <div class="grid gap-2">
                    @foreach ($ganttRows as $row)
                        @php
                            $start = $row['date_debut'] ? \Illuminate\Support\Carbon::parse($row['date_debut']) : null;
                            $end = $row['date_fin'] ? \Illuminate\Support\Carbon::parse($row['date_fin']) : null;
                            $rangeDays = max(1, $ganttStart->diffInDays($ganttEnd));
                            $offset = $start ? round(($ganttStart->diffInDays($start) / $rangeDays) * 100, 2) : 0;
                            $width = ($start && $end) ? round((max(1, $start->diffInDays($end)) / $rangeDays) * 100, 2) : 0;
                            $progressWidth = round($width * ((float) ($row['progression'] ?? 0) / 100), 2);
                            $kpiRow = collect($actionRows)->firstWhere('id', $row['id']);
                            $kpiValue = (float) ($kpiRow['kpi_global'] ?? 0);
                        @endphp
                        <div class="dashboard-gantt-grid">
                            <div><a href="{{ $row['url'] }}" class="text-sm font-semibold text-slate-900 hover:text-[#1586D4] dark:text-slate-100">{{ $row['libelle'] }}</a><p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $row['responsable'] }} · {{ $row['date_debut_label'] }} - {{ $row['date_fin_label'] }}</p></div>
                            <div class="dashboard-gantt-track"><span class="dashboard-gantt-today" style="left: {{ min(100, max(0, $todayPercent)) }}%;"></span><span class="dashboard-gantt-bar" style="left: {{ min(100, max(0, $offset)) }}%; width: {{ min(100, max(0, $width)) }}%; background: {{ $row['color'] }};"></span><span class="dashboard-gantt-progress" style="left: {{ min(100, max(0, $offset)) }}%; width: {{ min(100, max(0, $progressWidth)) }}%; background: {{ $row['color'] }};"></span></div>
                            <div class="text-right text-sm font-black" style="color: {{ $kpiValue >= 80 ? '#75BC43' : ($kpiValue >= 60 ? '#D38B06' : ($kpiValue > 0 ? '#F05323' : '#64748B')) }};">{{ number_format($kpiValue, 0) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-400">Aucune action datee disponible pour produire un Gantt.</div>
        @endif
    </article>
</section>

<section class="dashboard-tab-panel" data-dashboard-panel="tables">
    <div class="grid gap-4">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Comparaison interannuelle</h2><p class="showcase-panel-subtitle">Lecture tabulaire des volumes, validations, retards et progression moyenne.</p></div><span class="showcase-chip">{{ count($interannualRows) }} lignes</span></div><div class="overflow-x-auto"><table class="dashboard-table"><thead><tr><th>Annee</th><th>PAO</th><th>PTA</th><th>Actions</th><th>Validees</th><th>Retard</th><th>Progression</th><th>Taux validation</th></tr></thead><tbody>@forelse ($interannualRows as $row)<tr><td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['annee'] }}</td><td>{{ $row['paos_total'] }}</td><td>{{ $row['ptas_total'] }}</td><td>{{ $row['actions_total'] }}</td><td>{{ $row['actions_validees'] }}</td><td>{{ $row['actions_retard'] }}</td><td>{{ number_format((float) $row['progression_moyenne'], 0) }}%</td><td>{{ number_format((float) $row['taux_validation'], 0) }}%</td></tr>@empty<tr><td colspan="8">Aucune comparaison interannuelle exploitable.</td></tr>@endforelse</tbody></table></div></article>
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Alertes actives</h2><p class="showcase-panel-subtitle">Actions en retard, KPI critiques, ecarts de progression et validations bloquees.</p></div><span class="showcase-chip">{{ count($alertRows) }} alerte(s)</span></div><div class="overflow-x-auto"><table class="dashboard-table"><thead><tr><th>Alerte</th><th>Direction</th><th>Action</th><th>Niveau</th><th>Detail</th><th>KPI</th><th>Acces</th></tr></thead><tbody>@forelse ($alertRows as $row)<tr><td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['titre'] }}</td><td>{{ $row['direction'] }}</td><td>{{ $row['action'] }}</td><td><span class="dashboard-pill" style="background: {{ $row['niveau'] === 'Critique' ? '#FDE9E3' : '#FFF7DA' }}; color: {{ $row['niveau'] === 'Critique' ? '#F05323' : '#D38B06' }};">{{ $row['niveau'] }}</span></td><td>{{ $row['details'] }}</td>@php $kpiValue = (float) ($row['kpi'] ?? 0); @endphp<td><span class="dashboard-pill" style="background: {{ $kpiValue >= 80 ? '#E8F6D8' : ($kpiValue >= 60 ? '#FFF7DA' : ($kpiValue > 0 ? '#FDE9E3' : '#F1F5F9')) }}; color: {{ $kpiValue >= 80 ? '#75BC43' : ($kpiValue >= 60 ? '#D38B06' : ($kpiValue > 0 ? '#F05323' : '#64748B')) }};">{{ number_format($kpiValue, 0) }}</span></td><td><a href="{{ $row['url'] }}" class="inline-flex items-center justify-center rounded-xl bg-[#1586D4] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#0E6CBE]">Voir</a></td></tr>@empty<tr><td colspan="7">Aucune alerte active sur ce perimetre.</td></tr>@endforelse</tbody></table></div></article>
    </div>
</section>

@once
    @push('scripts')
        <script>
            (function(){
                var tabsRoot=document.querySelector('[data-dashboard-tabs]');
                if(tabsRoot){tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach(function(button){button.addEventListener('click',function(){var key=button.getAttribute('data-dashboard-tab');tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach(function(tabButton){tabButton.classList.toggle('dashboard-tab-active',tabButton===button);tabButton.classList.toggle('dashboard-tab-inactive',tabButton!==button);});document.querySelectorAll('[data-dashboard-panel]').forEach(function(panel){panel.classList.toggle('active',panel.getAttribute('data-dashboard-panel')===key);});window.setTimeout(function(){Object.keys(chartInstances).forEach(function(chartKey){if(chartInstances[chartKey]){chartInstances[chartKey].resize();}});},120);});});}
                var payload=@json($dashboardData ?? []), statusCards=payload.status_cards||[], monthly=payload.monthly||[], unitRows=payload.unit_rows||[], interannual=payload.interannual||[], scatterPoints=payload.scatter_points||[], radarDatasets=payload.radar_datasets||[], chartInstances={}, rendered=false;
                function scatterSets(points){return points.map(function(point){return{label:point.title,data:[{x:point.x,y:point.y,r:point.r}],backgroundColor:point.color,borderColor:point.color,pointRadius:point.r,pointHoverRadius:point.r+2};});}
                function mountChart(id,config){var canvas=document.getElementById(id); if(!canvas||typeof window.Chart==='undefined'){return;} if(chartInstances[id]){chartInstances[id].destroy();} chartInstances[id]=new window.Chart(canvas,config);}
                function render(){if(rendered||typeof window.Chart==='undefined'){return;} rendered=true;
                    mountChart('dashboard-status-mix-chart',{type:'doughnut',data:{labels:statusCards.map(function(item){return item.label;}),datasets:[{data:statusCards.map(function(item){return item.count;}),backgroundColor:statusCards.map(function(item){return item.color;}),borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-kpi-line-chart',{type:'line',data:{labels:monthly.map(function(item){return item.label;}),datasets:[{label:'Delai',data:monthly.map(function(item){return item.delai;}),borderColor:'#1586D4',backgroundColor:'#1586D41A',tension:.35,fill:true},{label:'Performance',data:monthly.map(function(item){return item.performance;}),borderColor:'#75BC43',backgroundColor:'#75BC431A',tension:.35,fill:true},{label:'Conformite',data:monthly.map(function(item){return item.conformite;}),borderColor:'#F2C14E',backgroundColor:'#F2C14E1A',tension:.35,fill:true},{label:'Global',data:monthly.map(function(item){return item.global;}),borderColor:'#162566',backgroundColor:'#1625661A',tension:.35,fill:true}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,suggestedMax:100}},plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-unit-summary-chart',{type:'bar',data:{labels:unitRows.map(function(item){return item.label;}),datasets:[{label:'KPI moyen',data:unitRows.map(function(item){return item.kpi_global;}),backgroundColor:'#1586D4',borderRadius:8},{label:'Progression',data:unitRows.map(function(item){return item.progression_moyenne;}),backgroundColor:'#75BC43',borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,suggestedMax:100}},plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-kpi-grouped-chart',{type:'bar',data:{labels:monthly.map(function(item){return item.label;}),datasets:[{label:'Delai',data:monthly.map(function(item){return item.delai;}),backgroundColor:'#1586D4',borderRadius:6},{label:'Perf.',data:monthly.map(function(item){return item.performance;}),backgroundColor:'#75BC43',borderRadius:6},{label:'Conf.',data:monthly.map(function(item){return item.conformite;}),backgroundColor:'#F2C14E',borderRadius:6},{label:'Global',data:monthly.map(function(item){return item.global;}),backgroundColor:'#162566',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,suggestedMax:100}},plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-interannual-chart',{data:{labels:interannual.map(function(item){return item.annee;}),datasets:[{type:'bar',label:'Actions',data:interannual.map(function(item){return item.actions_total;}),backgroundColor:'#34B8FF',borderRadius:8},{type:'bar',label:'Validees',data:interannual.map(function(item){return item.actions_validees;}),backgroundColor:'#75BC43',borderRadius:8},{type:'line',label:'Progression moyenne',data:interannual.map(function(item){return item.progression_moyenne;}),borderColor:'#162566',backgroundColor:'#1625661A',yAxisID:'y1',tension:.35}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,position:'left'},y1:{beginAtZero:true,suggestedMax:100,position:'right',grid:{drawOnChartArea:false}}},plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-radar-chart',{type:'radar',data:{labels:['Delai','Performance','Conformite','Progression'],datasets:radarDatasets},options:{responsive:true,maintainAspectRatio:false,scales:{r:{beginAtZero:true,suggestedMax:100}},plugins:{legend:{position:'bottom'}}}});
                    mountChart('dashboard-scatter-chart',{type:'bubble',data:{datasets:scatterSets(scatterPoints)},options:{responsive:true,maintainAspectRatio:false,parsing:false,scales:{x:{beginAtZero:true,suggestedMax:100,title:{display:true,text:'Performance'}},y:{beginAtZero:true,suggestedMax:100,title:{display:true,text:'Conformite'}}},plugins:{legend:{display:false},tooltip:{callbacks:{label:function(context){var value=context.raw||{};return context.dataset.label+' - Perf '+value.x+' / Conf '+value.y;}}}}}});
                }
                document.addEventListener('anbg:dashboard-assets-ready',render,{once:true}); if(typeof window.Chart!=='undefined'){render();}
            })();
        </script>
    @endpush
@endonce
