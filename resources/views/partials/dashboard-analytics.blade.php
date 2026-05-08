@section('title', 'Dashboard')

@php
    $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
    $actionStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
    $validationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::validationStatus($status);
    $currentDashboardUser = auth()->user();
    $dashboardNotifications = $currentDashboardUser?->notifications()->latest()->limit(6)->get() ?? collect();
    $analytics = $dashboardData ?? [];
    $dashboardRole = $analytics['dashboard_role'] ?? 'global';
    $roleDashboard = $analytics['role_dashboard'] ?? [];
    $roleHero = $roleDashboard['hero'] ?? [];
    $profil = is_array($profil ?? null) ? $profil : [];
    $profileRoleLabel = (string) ($profil['role_label'] ?? $profil['role'] ?? ucfirst((string) $dashboardRole));
    $profileRole = (string) ($profil['role'] ?? $dashboardRole);
    $profileScope = (string) ($profil['scope'] ?? '-');
    $profileDirectionLabel = $currentDashboardUser?->direction
        ? trim((string) ($currentDashboardUser->direction->code ?: '').' - '.(string) $currentDashboardUser->direction->libelle, ' -')
        : 'Toutes les directions visibles';
    $profileServiceLabel = $currentDashboardUser?->service
        ? trim((string) ($currentDashboardUser->service->code ?: '').' - '.(string) $currentDashboardUser->service->libelle, ' -')
        : 'Tous les services visibles';
    $operationalGlobalScores = $analytics['operational_global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'qualite' => 0, 'risque' => 0, 'global' => 0, 'progression' => 0];
    $globalScores = $analytics['global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'qualite' => 0, 'risque' => 0, 'global' => 0, 'progression' => 0];
    $operationalStatusCards = $analytics['operational_status_cards'] ?? [];
    $officialStatusCards = $analytics['official_status_cards'] ?? [];
    $operationalMonthly = $analytics['operational_monthly'] ?? [];
    $statusCards = $analytics['status_cards'] ?? [];
    $monthlyOfficial = $analytics['monthly'] ?? [];
    $unitRows = $analytics['unit_rows'] ?? [];
    $actionRows = $analytics['action_rows'] ?? [];
    $synthesisObjectiveRows = $analytics['synthesis_objective_rows'] ?? [];
    $synthesisPaoRows = $analytics['synthesis_pao_rows'] ?? [];
    $synthesisPtaRows = $analytics['synthesis_pta_rows'] ?? [];
    $synthesisServiceRows = $analytics['synthesis_service_rows'] ?? [];
    $synthesisAgentRows = $analytics['synthesis_agent_rows'] ?? [];
    $synthesisLateRows = $analytics['synthesis_late_rows'] ?? [];
    $decisionCounts = $analytics['decision_counts'] ?? [];
    $decisionChainRows = $analytics['decision_chain_rows'] ?? [];
    $decisionServiceRows = $analytics['decision_service_rows'] ?? $synthesisServiceRows;
    $decisionAgentRows = $analytics['decision_agent_rows'] ?? $synthesisAgentRows;
    $decisionPriorityRows = $analytics['decision_priority_rows'] ?? [];
    $decisionLateRows = $analytics['decision_late_rows'] ?? [];
    $decisionPendingValidationRows = $analytics['decision_pending_validation_rows'] ?? [];
    $decisionProofRows = $analytics['decision_proof_rows'] ?? [];
    $decisionAnomalyRows = $analytics['decision_anomaly_rows'] ?? [];
    $decisionQuarterRows = $analytics['decision_quarter_rows'] ?? [];
    $directionPerformanceRows = $analytics['direction_performance_rows'] ?? [];
    $pasDirectionRows = $analytics['pas_direction_rows'] ?? [];
    $paoDirectionRows = $analytics['pao_direction_rows'] ?? [];
    $ptaServiceActionRows = $analytics['pta_service_action_rows'] ?? [];
    $agentActionRows = $analytics['agent_action_rows'] ?? [];
    $subActionRows = $analytics['sub_action_rows'] ?? [];
    $priorityActionRows = collect($actionRows)->take(8)->all();
    $quantitativeTargetRows = collect($actionRows)
        ->filter(fn (array $row): bool => (bool) ($row['has_quantitative_target'] ?? false))
        ->take(10)
        ->values()
        ->all();
    $ganttRows = $analytics['gantt_rows'] ?? [];
    $bulletRows = $analytics['bullet_rows'] ?? [];
    $alertRows = $analytics['alert_rows'] ?? [];
    $interannualRows = $analytics['interannual'] ?? [];
    $unitModeLabel = $analytics['unit_mode_label'] ?? 'Unites';
    $statisticalPolicy = is_array(($reportingAnalytics['statisticalPolicy'] ?? null)) ? $reportingAnalytics['statisticalPolicy'] : [];
    $officialPolicy = is_array(($reportingAnalytics['officialPolicy'] ?? null)) ? $reportingAnalytics['officialPolicy'] : [];
    $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
    $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $officialBaseLower = mb_strtolower($officialBaseLabel);
    $officialBaseText = 'Base statistique : '.$officialBaseLabel;
    $officialAverageText = 'Moyenne sur '.$officialBaseLabel;
    $officialFilters = (array) ($basePolicy['route_filters'] ?? []);
    $directionSelector = is_array($analytics['direction_selector'] ?? null) ? $analytics['direction_selector'] : [];
    $exerciseFilter = is_array($analytics['exercise'] ?? null) ? $analytics['exercise'] : [];
    $pilotDashboardRoles = ['global', 'admin', 'super_admin', 'dg', 'cabinet', 'planification'];
    $showDirectionSynthesisSelector = ($directionSelector['enabled'] ?? false)
        && in_array($dashboardRole, $pilotDashboardRoles, true);
    $availableDashboardTabs = [
        'overview' => 'Synthèse',
        'charts' => 'Graphiques',
    ];
    $dashboardTabAliases = [
        'overview' => 'overview',
        'synthese' => 'overview',
        'charts' => 'charts',
        'graphes' => 'charts',
        'kpi' => 'charts',
        'gantt' => 'charts',
        'analytics' => 'charts',
        'actions' => 'overview',
        'tables' => 'overview',
    ];
    $requestedDashboardTab = request()->query('dashboardTab', 'overview');
    $currentDashboardTab = $dashboardTabAliases[$requestedDashboardTab] ?? 'overview';
    if ($currentDashboardTab === 'tables') {
        $currentDashboardTab = 'overview';
    }

    $summaryStrip = ($roleDashboard['summary_cards'] ?? []) !== [] ? $roleDashboard['summary_cards'] : [
        ['label' => 'Actions totales', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#1F2937', 'bg' => '#F8FBFF', 'meta' => null, 'href' => route('workspace.actions.index')],
        ['label' => $metricLabel('global'), 'value' => number_format((float) ($globalScores['global'] ?? 0), 0), 'accent' => '#8FC043', 'bg' => '#F2F8E8', 'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc'])],
        ['label' => 'En retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? 0, 'accent' => '#B42318', 'bg' => '#FFF1EF', 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
        ['label' => 'Non demarrees', 'value' => collect($statusCards)->firstWhere('label', 'Non demarre')['count'] ?? 0, 'accent' => '#6B7280', 'bg' => '#F1F5F9', 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'non_demarre'])],
        ['label' => 'Taux validation', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? number_format(((($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100), 0).'%' : '0%', 'accent' => '#3996D3', 'bg' => '#E8F3FB', 'meta' => null, 'href' => route('workspace.actions.index', $officialFilters)],
    ];
    $personalActionsSummary = is_array($analytics['personal_actions_summary'] ?? null) ? $analytics['personal_actions_summary'] : [];
    if ($dashboardRole !== 'agent' && (int) ($personalActionsSummary['total'] ?? 0) > 0) {
        array_splice($summaryStrip, 1, 0, [[
            'label' => 'Mes actions',
            'value' => (int) $personalActionsSummary['total'],
            'accent' => '#1C203D',
            'bg' => '#E8F3FB',
            'meta' => null,
            'href' => (string) ($personalActionsSummary['url'] ?? route('workspace.actions.index', ['vue' => 'mes_actions'])),
            'badge' => null,
            'badge_tone' => 'info',
        ]]);
    }

    $summaryStrip = collect($summaryStrip)
        ->unique(fn (array $card): string => mb_strtolower(trim((string) ($card['label'] ?? ''))))
        ->values()
        ->all();

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
            'success' => '--pill-bg:#F2F8E8;--pill-fg:#8FC043;--pill-bg-dark:rgba(16,185,129,0.16);--pill-fg-dark:#A7F3D0;--pill-border-dark:rgba(16,185,129,0.28);',
            'warning' => '--pill-bg:#FFF8D6;--pill-fg:#F9B13C;--pill-bg-dark:rgba(245,158,11,0.16);--pill-fg-dark:#FCD34D;--pill-border-dark:rgba(245,158,11,0.28);',
            'danger' => '--pill-bg:#FFF1EF;--pill-fg:#B42318;--pill-bg-dark:rgba(239,68,68,0.16);--pill-fg-dark:#FCA5A5;--pill-border-dark:rgba(239,68,68,0.28);',
            'info' => '--pill-bg:#E8F3FB;--pill-fg:#3996D3;--pill-bg-dark:rgba(59,130,246,0.16);--pill-fg-dark:#BFDBFE;--pill-border-dark:rgba(59,130,246,0.28);',
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

    $fmtCount = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', ' ');
    $fmtPct = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', ' ').'%';
    $shortText = static fn ($value, int $limit = 42): string => \Illuminate\Support\Str::limit((string) ($value ?: '-'), $limit);
    $actionStatusCounts = (array) ($metrics['status_breakdown']['actions'] ?? []);
    $actionValidationCounts = (array) ($metrics['status_breakdown']['actions_validation'] ?? []);
    $statusCount = static fn (string $key): int => (int) ($actionStatusCounts[$key] ?? 0);
    $validationRows = collect($actionValidationCounts)
        ->map(fn ($count, $status): array => [
            'cells' => [
                \Illuminate\Support\Str::headline((string) $status),
                $fmtCount($count),
                $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) $count / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0),
            ],
        ])
        ->values()
        ->all();
    if ($validationRows === []) {
        $validationRows = [[
            'cells' => ['Validees', $fmtCount($metrics['totals']['actions_validees'] ?? 0), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)],
        ]];
    }

    $quarterRows = collect(range(1, 4))
        ->map(function (int $quarter) use ($monthlyOfficial, $fmtPct): array {
            $rows = collect($monthlyOfficial)->slice(($quarter - 1) * 3, 3)->values();
            $score = $rows->avg(fn (array $row): float => (float) ($row['global'] ?? 0));

            return [
                'cells' => ['T'.$quarter, $fmtPct($score), $rows->pluck('label')->filter()->implode(', ') ?: '-'],
            ];
        })
        ->all();

    $directionSynthesisTables = [
        [
            'title' => 'PAS',
            'chip' => $fmtCount($metrics['totals']['pas_total'] ?? 0),
            'headers' => ['El.', 'Nb', 'Note'],
            'rows' => [
                ['cells' => ['Total', $fmtCount($metrics['totals']['pas_total'] ?? 0), 'Plans']],
                ['cells' => ['Actifs', $fmtCount($metrics['totals']['pas_actifs'] ?? 0), 'Valides']],
                ['cells' => ['Ecart', $fmtCount(max(0, (int) ($metrics['totals']['pas_total'] ?? 0) - (int) ($metrics['totals']['pas_actifs'] ?? 0))), 'A suivre']],
            ],
        ],
        [
            'title' => 'Obj. strat.',
            'chip' => count($synthesisObjectiveRows),
            'headers' => ['Objectif', 'Act.', 'Score'],
            'rows' => collect($synthesisObjectiveRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['objectif'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['score'] ?? 0)]])->all(),
            'empty' => 'Aucun objectif.',
        ],
        [
            'title' => 'PAO',
            'chip' => $fmtCount($metrics['totals']['paos_total'] ?? 0),
            'headers' => ['PAO', 'Act.', 'Prog.'],
            'rows' => collect($synthesisPaoRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['pao'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->prepend(['cells' => ['Actifs', $fmtCount($metrics['totals']['paos_actifs'] ?? 0), 'PAO']])->all(),
            'empty' => 'Aucun PAO.',
        ],
        [
            'title' => 'PTA',
            'chip' => $fmtCount($metrics['totals']['ptas_total'] ?? 0),
            'headers' => ['PTA', 'Act.', 'Prog.'],
            'rows' => collect($synthesisPtaRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['pta'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->prepend(['cells' => ['Actifs', $fmtCount($metrics['totals']['ptas_actifs'] ?? 0), 'PTA']])->all(),
            'empty' => 'Aucun PTA.',
        ],
        [
            'title' => 'Actions',
            'chip' => $fmtCount($metrics['totals']['actions_total'] ?? 0),
            'headers' => ['Statut', 'Nb', 'Part'],
            'rows' => [
                ['cells' => ['Total', $fmtCount($metrics['totals']['actions_total'] ?? 0), '100%']],
                ['cells' => ['Finies', $fmtCount($statusCount('acheve')), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('acheve') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
                ['cells' => ['Cours', $fmtCount($statusCount('en_cours')), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('en_cours') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
                ['cells' => ['Retard', $fmtCount($metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard')), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard')) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
            ],
        ],
        [
            'title' => 'Retards',
            'chip' => $fmtCount($metrics['alerts']['actions_en_retard'] ?? 0),
            'headers' => ['Action', 'J', 'Prog.'],
            'rows' => collect($synthesisLateRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['libelle'] ?? '-', 34), $fmtCount($row['retard_jours'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->all(),
            'empty' => 'Aucun retard.',
        ],
        [
            'title' => 'Finies',
            'chip' => $fmtCount($statusCount('acheve')),
            'headers' => ['El.', 'Nb', 'Taux'],
            'rows' => [
                ['cells' => ['Achevees', $fmtCount($statusCount('acheve')), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('acheve') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
                ['cells' => ['Validees', $fmtCount($metrics['totals']['actions_validees'] ?? 0), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
            ],
        ],
        [
            'title' => 'Cours',
            'chip' => $fmtCount($statusCount('en_cours') + $statusCount('a_risque')),
            'headers' => ['Statut', 'Nb', 'Note'],
            'rows' => [
                ['cells' => ['Cours', $fmtCount($statusCount('en_cours')), 'Actives']],
                ['cells' => ['Risque', $fmtCount($statusCount('a_risque')), 'A suivre']],
                ['cells' => ['Non dem.', $fmtCount($statusCount('non_demarre')), 'A lancer']],
            ],
        ],
        [
            'title' => 'Services',
            'chip' => count($synthesisServiceRows),
            'headers' => ['Service', 'Act.', 'Score'],
            'rows' => collect($synthesisServiceRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['label'] ?? '-', 28), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['kpi_global'] ?? 0)]])->all(),
            'empty' => 'Aucun service.',
        ],
        [
            'title' => 'Agents',
            'chip' => count($synthesisAgentRows),
            'headers' => ['Agent', 'Act.', 'Tx'],
            'rows' => collect($synthesisAgentRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['agent'] ?? '-', 28), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['taux_execution'] ?? 0)]])->all(),
            'empty' => 'Aucun agent.',
        ],
        [
            'title' => 'Valid.',
            'chip' => $fmtCount($metrics['totals']['actions_validees'] ?? 0),
            'headers' => ['Etat', 'Nb', 'Part'],
            'rows' => $validationRows,
        ],
        [
            'title' => 'Alertes',
            'chip' => count($alertRows),
            'headers' => ['Alerte', 'Niv.', 'Detail'],
            'rows' => collect($alertRows)->take(6)->map(fn (array $row): array => ['cells' => [$shortText($row['titre'] ?? '-', 30), $shortText($row['niveau'] ?? '-', 14), $shortText($row['details'] ?? '-', 18)]])->all(),
            'empty' => 'Aucune alerte.',
        ],
        [
            'title' => 'KPI',
            'chip' => $fmtPct($globalScores['global'] ?? 0),
            'headers' => ['KPI', 'Score', 'Note'],
            'rows' => [
                ['cells' => ['Delai', $fmtPct($globalScores['delai'] ?? 0), 'Temps']],
                ['cells' => ['Perf.', $fmtPct($globalScores['performance'] ?? 0), 'Exec.']],
                ['cells' => ['Qual.', $fmtPct($globalScores['qualite'] ?? 0), 'Qualite']],
                ['cells' => ['Risque', $fmtPct($globalScores['risque'] ?? 0), 'Risque']],
            ],
        ],
        [
            'title' => 'Trim.',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous',
            'headers' => ['Trim.', 'Score', 'Mois'],
            'rows' => $quarterRows,
        ],
        [
            'title' => 'Ctrl.',
            'chip' => $fmtCount($metrics['alerts']['mesures_kpi_sous_seuil'] ?? 0),
            'headers' => ['Point', 'Nb', 'Note'],
            'rows' => [
                ['cells' => ['Ind.', $fmtCount($metrics['totals']['kpis_total'] ?? 0), 'KPI']],
                ['cells' => ['Mesures', $fmtCount($metrics['totals']['kpi_mesures_total'] ?? 0), 'Suivi']],
                ['cells' => ['Sous seuil', $fmtCount($metrics['alerts']['mesures_kpi_sous_seuil'] ?? 0), 'Alerte']],
            ],
        ],
    ];

    $directionSynthesisTables = [
        [
            'title' => 'Chaîne PAS PAO PTA Actions',
            'chip' => 'Alignement',
            'headers' => ['Niveau', 'Total', 'Actifs ou validés', 'Point à surveiller'],
            'rows' => [
                ['cells' => ['PAS', $fmtCount($metrics['totals']['pas_total'] ?? 0), $fmtCount($metrics['totals']['pas_actifs'] ?? 0), 'Le plan doit couvrir les objectifs']],
                ['cells' => ['PAO', $fmtCount($metrics['totals']['paos_total'] ?? 0), $fmtCount($metrics['totals']['paos_actifs'] ?? 0), 'Les objectifs doivent descendre vers les services']],
                ['cells' => ['PTA', $fmtCount($metrics['totals']['ptas_total'] ?? 0), $fmtCount($metrics['totals']['ptas_actifs'] ?? 0), 'Chaque service doit avoir des actions']],
                ['cells' => ['Actions', $fmtCount($metrics['totals']['actions_total'] ?? 0), $fmtCount($statusCount('acheve')), 'Les retards et validations pilotent la performance']],
            ],
        ],
        [
            'title' => 'Performance des services',
            'chip' => count($synthesisServiceRows).' services',
            'headers' => ['Service', 'Actions', 'Progression', 'Score', 'Alertes'],
            'rows' => collect($synthesisServiceRows)->take(8)->map(fn (array $row): array => ['cells' => [
                $shortText($row['label'] ?? '-', 38),
                $fmtCount($row['actions_total'] ?? 0),
                $fmtPct($row['progression_moyenne'] ?? 0),
                $fmtPct($row['kpi_global'] ?? 0),
                $fmtCount($row['alertes'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun service disponible.',
        ],
        [
            'title' => 'Performance des agents',
            'chip' => count($synthesisAgentRows).' agents',
            'headers' => ['Agent', 'Actions affectées', 'Terminées', 'En retard', 'Taux exécution'],
            'rows' => collect($synthesisAgentRows)->take(8)->map(fn (array $row): array => ['cells' => [
                $shortText($row['agent'] ?? '-', 38),
                $fmtCount($row['actions_total'] ?? 0),
                $fmtCount($row['achevees'] ?? 0),
                $fmtCount($row['retards'] ?? 0),
                $fmtPct($row['taux_execution'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun agent disponible.',
        ],
        [
            'title' => 'Actions prioritaires',
            'chip' => count($priorityActionRows).' actions',
            'headers' => ['Action', 'Service', 'Responsable', 'Statut', 'Progression'],
            'rows' => collect($priorityActionRows)->take(8)->map(fn (array $row): array => ['cells' => [
                $shortText($row['libelle'] ?? '-', 42),
                $shortText($row['service'] ?? '-', 24),
                $shortText($row['responsable'] ?? '-', 28),
                $actionStatusLabel((string) ($row['statut'] ?? '')),
                $fmtPct($row['progression'] ?? 0),
            ]])->all(),
            'empty' => 'Aucune action prioritaire.',
        ],
        [
            'title' => 'Actions en retard',
            'chip' => $fmtCount($metrics['alerts']['actions_en_retard'] ?? 0).' retards',
            'headers' => ['Action', 'Échéance', 'Jours de retard', 'Progression', 'Validation'],
            'rows' => collect($synthesisLateRows)->take(8)->map(fn (array $row): array => ['cells' => [
                $shortText($row['libelle'] ?? '-', 42),
                (string) ($row['echeance'] ?? '-'),
                $fmtCount($row['retard_jours'] ?? 0),
                $fmtPct($row['progression'] ?? 0),
                $validationStatusLabel((string) ($row['validation_status'] ?? '')),
            ]])->all(),
            'empty' => 'Aucune action en retard.',
        ],
        [
            'title' => 'Validations des actions',
            'chip' => $fmtCount($metrics['totals']['actions_validees'] ?? 0).' validées',
            'headers' => ['État de validation', 'Nombre', 'Part des actions'],
            'rows' => collect($actionValidationCounts)->map(fn ($count, $status): array => ['cells' => [
                $validationStatusLabel((string) $status),
                $fmtCount($count),
                $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) $count / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0),
            ]])->values()->all(),
            'empty' => 'Aucune validation enregistrée.',
        ],
        [
            'title' => 'Alertes et anomalies',
            'chip' => count($alertRows).' alertes',
            'headers' => ['Alerte', 'Niveau', 'Action concernée', 'Détail'],
            'rows' => collect($alertRows)->take(8)->map(fn (array $row): array => ['cells' => [
                $shortText($row['titre'] ?? '-', 34),
                $shortText($row['niveau'] ?? '-', 18),
                $shortText($row['action'] ?? '-', 36),
                $shortText($row['details'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune alerte active.',
        ],
        [
            'title' => 'Évolution trimestrielle',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous les trimestres',
            'headers' => ['Trimestre', 'Score moyen', 'Mois inclus'],
            'rows' => $quarterRows,
        ],
    ];

    $directionSynthesisTables = [
        [
            'title' => 'Chaine PAS PAO PTA Actions',
            'chip' => $fmtPct($decisionCounts['taux_alignement'] ?? 0),
            'headers' => ['PAS', 'Objectif strategique', 'PAO', 'Objectif operationnel', 'PTA', 'Actions', 'Etat'],
            'rows' => collect($decisionChainRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['pas'] ?? 'PAS', 22),
                $shortText($row['objectif_strategique'] ?? '-', 34),
                $shortText($row['pao'] ?? 'PAO', 22),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['pta'] ?? 'PTA', 22),
                $fmtCount($row['actions'] ?? 0),
                $row['etat'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune chaine PAS PAO PTA Actions sur ce perimetre.',
        ],
        [
            'title' => 'Performance par service',
            'chip' => count($decisionServiceRows).' services',
            'headers' => ['Service', 'PTA', 'Actions', 'Terminees', 'En cours', 'Retard', 'Taux', 'Score'],
            'rows' => collect($decisionServiceRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['service'] ?? '-', 32),
                $fmtCount($row['pta'] ?? 0),
                $fmtCount($row['actions'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['en_cours'] ?? 0),
                $fmtCount($row['retard'] ?? 0),
                $fmtPct($row['taux'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun service disponible.',
        ],
        [
            'title' => 'Actions prioritaires',
            'chip' => count($decisionPriorityRows).' actions',
            'headers' => ['Action', 'Service', 'Responsable', 'Date fin', 'Statut', 'Progression', 'Validation'],
            'rows' => collect($decisionPriorityRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 38),
                $shortText($row['service'] ?? '-', 24),
                $shortText($row['responsable'] ?? '-', 28),
                $row['date_fin'] ?? '-',
                $row['statut'] ?? '-',
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['validation'] ?? '-', 22),
            ]])->all(),
            'empty' => 'Aucune action prioritaire.',
        ],
        [
            'title' => 'Actions en retard',
            'chip' => count($decisionLateRows).' retards',
            'headers' => ['Action', 'Responsable', 'Service', 'Date fin', 'Jours retard', 'Progression', 'Motif'],
            'rows' => collect($decisionLateRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 34),
                $shortText($row['responsable'] ?? '-', 24),
                $shortText($row['service'] ?? '-', 22),
                $row['date_fin'] ?? '-',
                $fmtCount($row['jours_retard'] ?? 0),
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['motif'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune action en retard.',
        ],
        [
            'title' => 'Performance des agents',
            'chip' => count($decisionAgentRows).' agents',
            'headers' => ['Agent', 'Service', 'Actions affectees', 'Terminees', 'En retard', 'Sous-actions', 'Score'],
            'rows' => collect($decisionAgentRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['agent'] ?? '-', 30),
                $shortText($row['service'] ?? '-', 22),
                $fmtCount($row['actions_affectees'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['en_retard'] ?? 0),
                $fmtCount($row['sous_actions'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun agent disponible.',
        ],
        [
            'title' => 'Validations en attente',
            'chip' => count($decisionPendingValidationRows).' attente',
            'headers' => ['Element', 'Service', 'Responsable', 'Niveau', 'Statut', 'Depuis', 'Action'],
            'rows' => collect($decisionPendingValidationRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['element'] ?? '-', 34),
                $shortText($row['service'] ?? '-', 22),
                $shortText($row['responsable'] ?? '-', 24),
                $row['niveau'] ?? '-',
                $shortText($row['statut'] ?? '-', 20),
                $row['depuis'] ?? '-',
                $row['action'] ?? 'Verifier',
            ]])->all(),
            'empty' => 'Aucune validation en attente.',
        ],
        [
            'title' => 'Justificatifs et preuves',
            'chip' => $fmtCount($decisionCounts['justificatifs_total'] ?? 0).' preuves',
            'headers' => ['Action', 'Agent', 'Justificatif', 'Statut preuve', 'Validateur', 'Observation'],
            'rows' => collect($decisionProofRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 32),
                $shortText($row['agent'] ?? '-', 22),
                $shortText($row['justificatif'] ?? '-', 30),
                $row['statut_preuve'] ?? '-',
                $shortText($row['validateur'] ?? '-', 22),
                $shortText($row['observation'] ?? '-', 26),
            ]])->all(),
            'empty' => 'Aucun justificatif disponible.',
        ],
        [
            'title' => 'Alertes et anomalies',
            'chip' => count($decisionAnomalyRows).' points',
            'headers' => ['Type', 'Element', 'Service', 'Gravite', 'Detail', 'Action corrective'],
            'rows' => collect($decisionAnomalyRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $row['type'] ?? '-',
                $shortText($row['element'] ?? '-', 30),
                $shortText($row['service'] ?? '-', 22),
                $row['gravite'] ?? '-',
                $shortText($row['detail'] ?? '-', 26),
                $shortText($row['action_corrective'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune anomalie active.',
        ],
        [
            'title' => 'Evolution trimestrielle',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous les trimestres',
            'headers' => ['Trimestre', 'Actions prevues', 'Terminees', 'Retard', 'Taux execution', 'Score'],
            'rows' => collect($decisionQuarterRows)->map(fn (array $row): array => ['cells' => [
                $row['trimestre'] ?? '-',
                $fmtCount($row['actions_prevues'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['retard'] ?? 0),
                $fmtPct($row['taux_execution'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
        ],
    ];

    $directionSynthesisTables = [
        [
            'title' => 'Performance des directions',
            'chip' => count($directionPerformanceRows).' directions',
            'headers' => ['Direction', 'PAO cree', 'Objectifs operationnels', 'Services', 'PTA crees', 'Actions', 'Non demarrees', 'En cours', 'Realisees', 'Validees', 'Retards', 'Hors delai', 'Taux execution', 'Taux realisation', 'Performance', 'Statut', 'Derniere activite'],
            'rows' => collect($directionPerformanceRows)->take(12)->map(fn (array $row): array => ['cells' => [
                $shortText($row['direction'] ?? '-', 28),
                $row['pao_cree'] ?? '-',
                $fmtCount($row['objectifs_operationnels'] ?? 0),
                $fmtCount($row['services'] ?? 0),
                $row['pta_ratio'] ?? '-',
                $fmtCount($row['actions_total'] ?? 0),
                $fmtCount($row['non_demarre'] ?? 0),
                $fmtCount($row['en_cours'] ?? 0),
                $fmtCount($row['realisees'] ?? 0),
                $fmtCount($row['validees'] ?? 0),
                $fmtCount($row['retards'] ?? 0),
                $fmtCount($row['hors_delai'] ?? 0),
                $fmtPct($row['taux_execution'] ?? 0),
                $fmtPct($row['taux_realisation'] ?? 0),
                $row['performance'] ?? '-',
                $row['statut'] ?? '-',
                $row['derniere_activite'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune direction disponible.',
        ],
        [
            'title' => 'Declinaison du PAS par direction',
            'chip' => count($pasDirectionRows).' lignes',
            'headers' => ['Direction', 'Axes concernes', 'Objectifs strategiques', 'PAO cree', 'Objectifs operationnels', 'Taux declinaison', 'Statut', 'Derniere mise a jour'],
            'rows' => collect($pasDirectionRows)->take(12)->map(fn (array $row): array => ['cells' => [
                $shortText($row['direction'] ?? '-', 28),
                $fmtCount($row['axes'] ?? 0),
                $fmtCount($row['objectifs_strategiques'] ?? 0),
                $row['pao_cree'] ?? '-',
                $fmtCount($row['objectifs_operationnels'] ?? 0),
                $fmtPct($row['taux_declinaison'] ?? 0),
                $row['statut'] ?? '-',
                $row['derniere_maj'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune declinaison PAS disponible.',
        ],
        [
            'title' => 'PAO par direction',
            'chip' => count($paoDirectionRows).' objectifs',
            'headers' => ['Direction', 'Objectif strategique', 'Objectif operationnel', 'Service', 'Echeance', 'PTA cree', 'Actions creees', 'Actions affectees', 'En cours', 'Realisees', 'Retards', 'Taux execution', 'Statut'],
            'rows' => collect($paoDirectionRows)->take(15)->map(fn (array $row): array => ['cells' => [
                $shortText($row['direction'] ?? '-', 20),
                $shortText($row['objectif_strategique'] ?? '-', 34),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['service'] ?? '-', 24),
                $row['echeance'] ?? '-',
                $row['pta_cree'] ?? '-',
                $fmtCount($row['actions_creees'] ?? 0),
                $fmtCount($row['actions_affectees'] ?? 0),
                $fmtCount($row['en_cours'] ?? 0),
                $fmtCount($row['realisees'] ?? 0),
                $fmtCount($row['retards'] ?? 0),
                $fmtPct($row['taux_execution'] ?? 0),
                $row['statut'] ?? '-',
            ]])->all(),
            'empty' => 'Aucun PAO disponible.',
        ],
        [
            'title' => 'Services de la direction',
            'chip' => count($decisionServiceRows).' services',
            'headers' => ['Service', 'PTA', 'Actions', 'Terminees', 'En cours', 'Retard', 'Taux execution', 'Score'],
            'rows' => collect($decisionServiceRows)->take(12)->map(fn (array $row): array => ['cells' => [
                $shortText($row['service'] ?? '-', 32),
                $fmtCount($row['pta'] ?? 0),
                $fmtCount($row['actions'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['en_cours'] ?? 0),
                $fmtCount($row['retard'] ?? 0),
                $fmtPct($row['taux'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun service disponible.',
        ],
        [
            'title' => 'PTA par service',
            'chip' => count($ptaServiceActionRows).' actions',
            'headers' => ['Service', 'Objectif operationnel', 'Action', 'Responsable', 'Debut', 'Echeance', 'Cible', 'Realise', 'Reste', 'Taux realisation', 'Progression technique', 'Statut evolution', 'Statut delai', 'Justificatifs', 'Performance'],
            'rows' => collect($ptaServiceActionRows)->take(15)->map(fn (array $row): array => ['cells' => [
                $shortText($row['service'] ?? '-', 24),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['action'] ?? '-', 36),
                $shortText($row['responsable'] ?? '-', 24),
                $row['debut'] ?? '-',
                $row['echeance'] ?? '-',
                $row['cible'] ?? '-',
                $row['realise'] ?? '-',
                $row['reste'] ?? '-',
                $fmtPct($row['taux_realisation'] ?? 0),
                $fmtPct($row['progression'] ?? 0),
                $row['statut'] ?? '-',
                $row['statut_delai'] ?? '-',
                $fmtCount($row['justificatifs'] ?? 0),
                $row['performance'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune action PTA disponible.',
        ],
        [
            'title' => 'Agents par service',
            'chip' => count($decisionAgentRows).' agents',
            'headers' => ['Agent', 'Service', 'Actions affectees', 'Terminees', 'En retard', 'Sous-actions', 'Score'],
            'rows' => collect($decisionAgentRows)->take(12)->map(fn (array $row): array => ['cells' => [
                $shortText($row['agent'] ?? '-', 30),
                $shortText($row['service'] ?? '-', 22),
                $fmtCount($row['actions_affectees'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['en_retard'] ?? 0),
                $fmtCount($row['sous_actions'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
            'empty' => 'Aucun agent disponible.',
        ],
        [
            'title' => 'Actions des agents',
            'chip' => count($agentActionRows).' lignes',
            'headers' => ['Agent', 'Action', 'Objectif operationnel', 'PTA', 'Direction', 'Service', 'Echeance', 'Cible', 'Realise', 'Reste', 'Sous-actions', 'Progression', 'Taux realisation', 'Statut', 'Delai', 'Performance', 'Justificatifs', 'Commentaires', 'Derniere activite'],
            'rows' => collect($agentActionRows)->take(18)->map(fn (array $row): array => ['cells' => [
                $shortText($row['agent'] ?? '-', 24),
                $shortText($row['action'] ?? '-', 32),
                $shortText($row['objectif_operationnel'] ?? '-', 32),
                $shortText($row['pta'] ?? '-', 22),
                $shortText($row['direction'] ?? '-', 16),
                $shortText($row['service'] ?? '-', 22),
                $row['echeance'] ?? '-',
                $row['cible'] ?? '-',
                $row['realise'] ?? '-',
                $row['reste'] ?? '-',
                $row['sous_actions'] ?? '-',
                $fmtPct($row['progression'] ?? 0),
                $fmtPct($row['taux_realisation'] ?? 0),
                $row['statut'] ?? '-',
                $row['statut_delai'] ?? '-',
                $row['performance'] ?? '-',
                $fmtCount($row['justificatifs'] ?? 0),
                $fmtCount($row['commentaires'] ?? 0),
                $row['derniere_activite'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune action affectee disponible.',
        ],
        [
            'title' => 'Sous-actions et preuves',
            'chip' => count($subActionRows).' sous-actions',
            'headers' => ['Action', 'Sous-action', 'Description', 'Cible prevue', 'Quantite realisee', 'Unite', 'Taux', 'Resultat obtenu', 'Effectuee', 'Date realisation', 'Justificatif', 'Commentaire agent', 'Controle superieur', 'Statut'],
            'rows' => collect($subActionRows)->take(18)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 28),
                $shortText($row['sous_action'] ?? '-', 28),
                $shortText($row['description'] ?? '-', 32),
                $row['cible'] ?? '-',
                $row['realise'] ?? '-',
                $row['unite'] ?? '-',
                $fmtPct($row['taux'] ?? 0),
                $shortText($row['resultat'] ?? '-', 28),
                $row['effectuee'] ?? '-',
                $row['date_realisation'] ?? '-',
                $row['justificatif'] ?? '-',
                $shortText($row['commentaire'] ?? '-', 24),
                $shortText($row['controle'] ?? '-', 24),
                $row['statut'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune sous-action disponible.',
        ],
        [
            'title' => 'Chaine PAS PAO PTA Actions',
            'chip' => $fmtPct($decisionCounts['taux_alignement'] ?? 0),
            'headers' => ['PAS', 'Objectif strategique', 'PAO', 'Objectif operationnel', 'PTA', 'Actions', 'Etat'],
            'rows' => collect($decisionChainRows)->take(12)->map(fn (array $row): array => ['cells' => [
                $shortText($row['pas'] ?? 'PAS', 22),
                $shortText($row['objectif_strategique'] ?? '-', 34),
                $shortText($row['pao'] ?? 'PAO', 22),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['pta'] ?? 'PTA', 22),
                $fmtCount($row['actions'] ?? 0),
                $row['etat'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune chaine disponible.',
        ],
        [
            'title' => 'Actions prioritaires',
            'chip' => count($decisionPriorityRows).' actions',
            'headers' => ['Action', 'Service', 'Responsable', 'Date fin', 'Statut', 'Progression', 'Validation'],
            'rows' => collect($decisionPriorityRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 38),
                $shortText($row['service'] ?? '-', 24),
                $shortText($row['responsable'] ?? '-', 28),
                $row['date_fin'] ?? '-',
                $row['statut'] ?? '-',
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['validation'] ?? '-', 22),
            ]])->all(),
            'empty' => 'Aucune action prioritaire.',
        ],
        [
            'title' => 'Actions en retard',
            'chip' => count($decisionLateRows).' retards',
            'headers' => ['Action', 'Responsable', 'Service', 'Date fin', 'Jours retard', 'Progression', 'Motif'],
            'rows' => collect($decisionLateRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 34),
                $shortText($row['responsable'] ?? '-', 24),
                $shortText($row['service'] ?? '-', 22),
                $row['date_fin'] ?? '-',
                $fmtCount($row['jours_retard'] ?? 0),
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['motif'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune action en retard.',
        ],
        [
            'title' => 'Validations en attente',
            'chip' => count($decisionPendingValidationRows).' attente',
            'headers' => ['Element', 'Service', 'Responsable', 'Niveau', 'Statut', 'Depuis', 'Action'],
            'rows' => collect($decisionPendingValidationRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['element'] ?? '-', 34),
                $shortText($row['service'] ?? '-', 22),
                $shortText($row['responsable'] ?? '-', 24),
                $row['niveau'] ?? '-',
                $shortText($row['statut'] ?? '-', 20),
                $row['depuis'] ?? '-',
                $row['action'] ?? 'Verifier',
            ]])->all(),
            'empty' => 'Aucune validation en attente.',
        ],
        [
            'title' => 'Justificatifs et preuves',
            'chip' => $fmtCount($decisionCounts['justificatifs_total'] ?? 0).' preuves',
            'headers' => ['Action', 'Agent', 'Justificatif', 'Statut preuve', 'Validateur', 'Observation'],
            'rows' => collect($decisionProofRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 32),
                $shortText($row['agent'] ?? '-', 22),
                $shortText($row['justificatif'] ?? '-', 30),
                $row['statut_preuve'] ?? '-',
                $shortText($row['validateur'] ?? '-', 22),
                $shortText($row['observation'] ?? '-', 26),
            ]])->all(),
            'empty' => 'Aucun justificatif disponible.',
        ],
        [
            'title' => 'Alertes et anomalies',
            'chip' => count($decisionAnomalyRows).' points',
            'headers' => ['Type', 'Element', 'Service', 'Gravite', 'Detail', 'Action corrective'],
            'rows' => collect($decisionAnomalyRows)->take(10)->map(fn (array $row): array => ['cells' => [
                $row['type'] ?? '-',
                $shortText($row['element'] ?? '-', 30),
                $shortText($row['service'] ?? '-', 22),
                $row['gravite'] ?? '-',
                $shortText($row['detail'] ?? '-', 26),
                $shortText($row['action_corrective'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune anomalie active.',
        ],
        [
            'title' => 'Evolution trimestrielle',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous les trimestres',
            'headers' => ['Trimestre', 'Actions prevues', 'Terminees', 'Retard', 'Taux execution', 'Score'],
            'rows' => collect($decisionQuarterRows)->map(fn (array $row): array => ['cells' => [
                $row['trimestre'] ?? '-',
                $fmtCount($row['actions_prevues'] ?? 0),
                $fmtCount($row['terminees'] ?? 0),
                $fmtCount($row['retard'] ?? 0),
                $fmtPct($row['taux_execution'] ?? 0),
                $fmtPct($row['score'] ?? 0),
            ]])->all(),
        ],
    ];

    $decisionCharts = [
        [
            'title' => 'Graphique performance des services',
            'rows' => collect($synthesisServiceRows)->take(8)->map(fn (array $row): array => [
                'label' => $shortText($row['label'] ?? '-', 34),
                'value' => (float) ($row['kpi_global'] ?? 0),
                'meta' => $fmtCount($row['actions_total'] ?? 0).' actions',
                'color' => '#3996D3',
            ])->all(),
        ],
        [
            'title' => 'Graphique performance des agents',
            'rows' => collect($synthesisAgentRows)->take(8)->map(fn (array $row): array => [
                'label' => $shortText($row['agent'] ?? '-', 34),
                'value' => (float) ($row['taux_execution'] ?? 0),
                'meta' => $fmtCount($row['actions_total'] ?? 0).' actions',
                'color' => '#8FC043',
            ])->all(),
        ],
        [
            'title' => 'Graphique état des actions',
            'rows' => [
                ['label' => 'Actions terminées', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('acheve') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0, 'meta' => $fmtCount($statusCount('acheve')).' actions', 'color' => '#8FC043'],
                ['label' => 'Actions en cours', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('en_cours') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0, 'meta' => $fmtCount($statusCount('en_cours')).' actions', 'color' => '#3996D3'],
                ['label' => 'Actions en retard', 'value' => ($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard')) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0, 'meta' => $fmtCount($metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard')).' actions', 'color' => '#B42318'],
            ],
        ],
    ];

    $decisionCharts = [
        [
            'title' => 'Graphique performance des services',
            'rows' => collect($decisionServiceRows)->take(8)->map(fn (array $row): array => [
                'label' => $shortText($row['service'] ?? '-', 34),
                'value' => (float) ($row['score'] ?? 0),
                'meta' => $fmtCount($row['actions'] ?? 0).' actions',
                'color' => '#3996D3',
            ])->all(),
        ],
        [
            'title' => 'Graphique performance des agents',
            'rows' => collect($decisionAgentRows)->take(8)->map(fn (array $row): array => [
                'label' => $shortText($row['agent'] ?? '-', 34),
                'value' => (float) ($row['score'] ?? 0),
                'meta' => $fmtCount($row['actions_affectees'] ?? 0).' actions',
                'color' => '#8FC043',
            ])->all(),
        ],
        [
            'title' => 'Graphique evolution trimestrielle',
            'rows' => collect($decisionQuarterRows)->map(fn (array $row): array => [
                'label' => $row['trimestre'] ?? '-',
                'value' => (float) ($row['taux_execution'] ?? 0),
                'meta' => $fmtCount($row['terminees'] ?? 0).' terminees / '.$fmtCount($row['actions_prevues'] ?? 0).' prevues',
                'color' => '#F9B13C',
            ])->all(),
        ],
    ];

@endphp


<div class="relative z-[90] mb-4 flex flex-wrap items-center gap-2 overflow-visible rounded-[1.35rem] border border-[#3996d3]/18 bg-white/95 p-2 shadow-[0_20px_44px_-36px_rgba(15,23,42,0.45)]" data-dashboard-tabs>
    @foreach ($availableDashboardTabs as $tabKey => $tabLabel)
        @if ($showDirectionSynthesisSelector && $tabKey === 'overview')
            <details class="relative z-[100]" data-dashboard-synthesis-selector>
                <summary
                    class="dashboard-tab {{ $currentDashboardTab === 'overview' ? 'dashboard-tab-active' : 'dashboard-tab-inactive' }} cursor-pointer list-none"
                    aria-current="{{ $currentDashboardTab === 'overview' ? 'page' : 'false' }}"
                >
                    Synthèse
                </summary>
                <div class="absolute left-0 top-[calc(100%+0.5rem)] z-[9999] min-w-[240px] overflow-hidden rounded-2xl border border-[#3996d3]/20 bg-white p-2 shadow-xl">
                    <a class="block rounded-xl px-3 py-2 text-sm font-semibold text-[#17324a] hover:bg-[#e8f3fb]" href="{{ request()->fullUrlWithQuery(['dashboardTab' => 'overview', 'direction_id' => 'all']) }}">
                        Synthèse globale
                    </a>
                    @foreach (($directionSelector['options'] ?? []) as $directionOption)
                        <a class="block rounded-xl px-3 py-2 text-sm font-semibold text-[#17324a] hover:bg-[#e8f3fb]" href="{{ request()->fullUrlWithQuery(['dashboardTab' => 'overview', 'direction_id' => $directionOption['id'], 'service_id' => 'all']) }}">
                            {{ $directionOption['label'] }}
                        </a>
                    @endforeach
                </div>
            </details>
        @else
            <a
                href="{{ request()->fullUrlWithQuery(['dashboardTab' => $tabKey]) }}"
                class="dashboard-tab {{ $currentDashboardTab === $tabKey ? 'dashboard-tab-active' : 'dashboard-tab-inactive' }}"
                data-dashboard-tab="{{ $tabKey }}"
                aria-current="{{ $currentDashboardTab === $tabKey ? 'page' : 'false' }}"
            >
                {{ $tabLabel }}
            </a>
        @endif
    @endforeach
    <div class="ml-auto flex flex-wrap gap-2 pr-1">
        <a class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.alertes') }}">Alertes</a>
        <a class="btn btn-primary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.reporting') }}">Rapports</a>
    </div>
</div>

@if ($showDirectionSynthesisSelector)
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-[#3996d3]/18 bg-[#e8f3fb] px-4 py-3 text-sm font-semibold text-[#17324a]">
        <span>{{ $directionSelector['selected_label'] ?? 'Synthèse globale' }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $directionSelector['service_selected_label'] ?? 'Tous les services' }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $exerciseFilter['label'] ?? 'Exercice courant' }}</span>
        @if (!empty($directionSelector['selected_id']) && !empty($directionSelector['service_options']))
            <details class="relative z-[100] ml-auto" data-dashboard-synthesis-selector>
                <summary class="btn btn-primary btn-sm cursor-pointer list-none rounded-xl px-3 py-1.5 text-xs">
                    Choisir un service
                </summary>
                <div class="absolute right-0 top-[calc(100%+0.5rem)] z-[9999] min-w-[260px] overflow-hidden rounded-2xl border border-[#3996d3]/20 bg-white p-2 shadow-xl">
                    <a class="block rounded-xl px-3 py-2 text-sm font-semibold text-[#17324a] hover:bg-[#e8f3fb]" href="{{ request()->fullUrlWithQuery(['dashboardTab' => 'overview', 'service_id' => 'all']) }}">
                        Tous les services
                    </a>
                    @foreach (($directionSelector['service_options'] ?? []) as $serviceOption)
                        <a class="block rounded-xl px-3 py-2 text-sm font-semibold text-[#17324a] hover:bg-[#e8f3fb]" href="{{ request()->fullUrlWithQuery(['dashboardTab' => 'overview', 'service_id' => $serviceOption['id']]) }}">
                            {{ $serviceOption['label'] }}
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@else
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-[#3996d3]/18 bg-[#e8f3fb] px-4 py-3 text-sm font-semibold text-[#17324a]">
        <span>{{ $profileRoleLabel }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $profileScope }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $profileDirectionLabel }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $profileServiceLabel }}</span>
        <span class="text-[#3996d3]">|</span>
        <span>{{ $exerciseFilter['label'] ?? 'Exercice courant' }}</span>
    </div>
@endif

@php
    $showRoleOverview = ($roleDashboard['enabled'] ?? false)
        && (
            ($roleDashboard['overview_enabled'] ?? true)
            || ($roleDashboard['comparison_chart_enabled'] ?? true)
            || ($roleDashboard['status_chart_enabled'] ?? true)
            || ($roleDashboard['trend_chart_enabled'] ?? true)
            || ($roleDashboard['support_chart_enabled'] ?? true)
        );
    $showDashboardMacroCharts = in_array($dashboardRole, ['global', 'admin', 'super_admin', 'dg', 'cabinet', 'planification', 'direction'], true);
    $showDashboardAdvancedReporting = in_array($dashboardRole, ['global', 'admin', 'super_admin', 'dg', 'cabinet', 'planification', 'direction'], true);
    $showDashboardAnalyticalTables = in_array($dashboardRole, ['global', 'admin', 'super_admin', 'dg', 'cabinet', 'planification', 'direction'], true);
@endphp

@if ($currentDashboardTab === 'overview')
<section class="dashboard-tab-panel active" data-dashboard-panel="overview">
    @if (false)
    <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        @foreach ($summaryStrip as $card)
            @php
                $dashboardSizeClass = match ($card['dashboard_size'] ?? 'md') {
                    'lg' => 'sm:col-span-2 xl:col-span-2',
                    'sm' => 'max-w-none',
                    default => '',
                };
            @endphp
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge'] ?? null"
                :badge-tone="$card['badge_tone'] ?? 'neutral'"
                :tone="$card['tone'] ?? null"
                card-class="dashboard-summary-card dashboard-summary-card-{{ $loop->index % 5 }} {{ $dashboardSizeClass }} rounded-[1.2rem] border p-4 shadow-[0_18px_34px_-30px_rgba(15,23,42,0.45)]"
                label-class="dashboard-summary-label"
                value-class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none"
                meta-class="dashboard-summary-meta mt-2 text-xs"
                :value-style="'color: '.$card['accent'].';'"
            />
        @endforeach
    </div>
    @endif

    @if (!in_array($dashboardRole, ['agent'], true))
    <div class="mb-4 space-y-3">
        @php
            $planningHierarchyRows = [
                [
                    'group' => 'PAS',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['pas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pas.index', ['statut' => 'valide_ou_verrouille'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['pas_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                    ],
                ],
                [
                    'group' => 'PAO',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['paos_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pao.index', ['statut' => 'valide_ou_verrouille'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['paos_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pao.index')],
                    ],
                ],
                [
                    'group' => 'PTA',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['ptas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pta.index', ['statut' => 'valide_ou_verrouille'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['ptas_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pta.index')],
                    ],
                ],
                [
                    'group' => 'ACTION',
                    'cards' => [
                        ['label' => 'Total', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.actions.index')],
                        ['label' => 'Finies', 'value' => $statusCount('acheve'), 'accent' => '#178f5f', 'href' => route('workspace.actions.index', ['statut' => 'achevees'])],
                        ['label' => 'Cours', 'value' => $statusCount('en_cours'), 'accent' => '#3996D3', 'href' => route('workspace.actions.index', ['statut' => 'en_cours'])],
                        ['label' => 'Retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard'), 'accent' => '#B42318', 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
                    ],
                ],
            ];
            $planningHierarchyRows = [
                [
                    'group' => 'PAS',
                    'cards' => [
                        ['label' => 'PAS actif', 'value' => $metrics['totals']['pas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pas.index', ['statut' => 'valide_ou_verrouille'])],
                        ['label' => 'Axes concernes', 'value' => $decisionCounts['axes_concernes'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                        ['label' => 'Objectifs strategiques concernes', 'value' => $decisionCounts['objectifs_strategiques_concernes'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                        ['label' => 'Taux alignement strategique', 'value' => $fmtPct($decisionCounts['taux_alignement'] ?? 0), 'accent' => '#178f5f', 'href' => route('workspace.actions.index')],
                    ],
                ],
                [
                    'group' => 'PAO',
                    'cards' => [
                        ['label' => 'PAO de la direction', 'value' => $metrics['totals']['paos_total'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs operationnels', 'value' => $decisionCounts['objectifs_operationnels'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs transmis aux services', 'value' => $decisionCounts['objectifs_transmis_services'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs non repris dans les PTA', 'value' => max(0, (int) ($decisionCounts['objectifs_operationnels'] ?? 0) - (int) ($decisionCounts['ptas_lies'] ?? 0)), 'accent' => '#B42318', 'href' => route('workspace.pao.index')],
                    ],
                ],
                [
                    'group' => 'PTA',
                    'cards' => [
                        ['label' => 'PTA des services', 'value' => $metrics['totals']['ptas_total'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pta.index')],
                        ['label' => 'PTA valides', 'value' => $metrics['totals']['ptas_actifs'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.pta.index', ['statut' => 'valide_ou_verrouille'])],
                        ['label' => 'PTA sans actions', 'value' => max(0, (int) ($metrics['totals']['ptas_total'] ?? 0) - (int) ($decisionCounts['ptas_avec_actions'] ?? 0)), 'accent' => '#B42318', 'href' => route('workspace.pta.index')],
                        ['label' => 'Services couverts', 'value' => $decisionCounts['services_couverts'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pta.index')],
                    ],
                ],
                [
                    'group' => 'ACTIONS',
                    'cards' => [
                        ['label' => 'Actions totales', 'value' => $decisionCounts['actions_total'] ?? ($metrics['totals']['actions_total'] ?? 0), 'accent' => '#17324a', 'href' => route('workspace.actions.index')],
                        ['label' => 'Actions terminees', 'value' => $decisionCounts['actions_terminees'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.actions.index', ['statut' => 'achevees'])],
                        ['label' => 'Actions en cours', 'value' => $decisionCounts['actions_en_cours'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.actions.index', ['statut' => 'en_cours'])],
                        ['label' => 'Actions en retard', 'value' => $decisionCounts['actions_en_retard'] ?? 0, 'accent' => '#B42318', 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
                        ['label' => 'Taux execution', 'value' => $fmtPct($decisionCounts['taux_execution'] ?? 0), 'accent' => '#178f5f', 'href' => route('workspace.actions.index')],
                        ['label' => 'Taux validation', 'value' => $fmtPct($decisionCounts['taux_validation'] ?? 0), 'accent' => '#3996D3', 'href' => route('workspace.actions.index')],
                    ],
                ],
            ];
        @endphp
        @foreach ($planningHierarchyRows as $row)
            <div class="grid gap-2 md:grid-cols-[92px_minmax(0,1fr)]">
                <div class="flex items-center justify-center rounded-[1rem] border border-[#3996d3]/18 bg-[#e8f3fb] px-3 py-2 text-xs font-black uppercase text-[#17324a]">
                    {{ $row['group'] }}
                </div>
                <div class="flex gap-3 overflow-x-auto pb-1">
                    @foreach ($row['cards'] as $card)
                        <x-stat-card-link
                            :href="$card['href']"
                            :label="$card['label']"
                            :value="$card['value']"
                            :meta="null"
                            :badge="null"
                            badge-tone="neutral"
                            card-class="min-w-[170px] flex-1 rounded-[1rem] border p-3 shadow-[0_10px_20px_-18px_rgba(15,23,42,0.28)]"
                            label-class="text-[0.66rem] font-semibold uppercase tracking-wide text-[#667085]"
                            value-class="mt-1.5 text-[1.45rem] font-black leading-none"
                            :value-style="'color: '.$card['accent'].';'"
                        />
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @endif

    @if ($directionSynthesisTables !== [])
        @if (false)
        <section class="mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Graphiques de décision</h2>
                <span class="showcase-chip">Services et agents</span>
            </div>
            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ($decisionCharts as $chart)
                    <article class="showcase-panel dashboard-synthesis-card">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $chart['title'] }}</h3>
                        </div>
                        <div class="space-y-3">
                            @forelse (($chart['rows'] ?? []) as $row)
                                @php
                                    $barValue = min(100, max(0, (float) ($row['value'] ?? 0)));
                                    $barColor = (string) ($row['color'] ?? '#3996D3');
                                @endphp
                                <div>
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs font-semibold text-[#17324a]">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="whitespace-nowrap">{{ number_format($barValue, 0, ',', ' ') }}%</span>
                                    </div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-200/80">
                                        <div class="h-full rounded-full" style="width: {{ $barValue }}%; background: {{ $barColor }};"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] font-medium text-[#667085]">{{ $row['meta'] ?? '' }}</p>
                                </div>
                            @empty
                                <div class="rounded-[1rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-8 text-center text-sm text-[#667085]">
                                    Aucune donnée disponible.
                                </div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        @endif

        <section class="mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Tableaux de décision</h2>
                <span class="showcase-chip">Performance et alertes</span>
            </div>
            <div class="space-y-4">
                @foreach ($directionSynthesisTables as $synthesisTable)
                    @php
                        $synthesisTableId = 'dashboard-synthesis-table-'.$loop->index;
                        $synthesisExportName = \Illuminate\Support\Str::slug((string) ($synthesisTable['title'] ?? 'tableau')).'-'.now()->format('Ymd-His');
                    @endphp
                    <article class="showcase-panel dashboard-synthesis-card w-full overflow-hidden p-0">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 px-3 py-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $synthesisTable['title'] }}</h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="showcase-chip">{{ $synthesisTable['chip'] }}</span>
                                <button type="button" class="btn btn-primary btn-sm rounded-xl"
                                    data-dashboard-export-table="{{ $synthesisTableId }}"
                                    data-dashboard-export-name="{{ $synthesisExportName }}">
                                    Export Excel
                                </button>
                            </div>
                        </div>
                        <div class="max-w-full overflow-x-auto">
                            <table id="{{ $synthesisTableId }}" class="dashboard-table dashboard-synthesis-table">
                                <thead>
                                    <tr>
                                        @foreach ($synthesisTable['headers'] as $header)
                                            <th>{{ $header }}</th>
                                        @endforeach
                                        <th class="dashboard-no-export">D&eacute;tail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse (($synthesisTable['rows'] ?? []) as $row)
                                        @php
                                            $detailPayload = base64_encode(json_encode([
                                                'title' => (string) ($synthesisTable['title'] ?? 'Tableau'),
                                                'headers' => array_values((array) ($synthesisTable['headers'] ?? [])),
                                                'cells' => array_values((array) ($row['cells'] ?? [])),
                                                'url' => (string) ($row['url'] ?? ''),
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                        @endphp
                                        <tr>
                                            @foreach (($row['cells'] ?? []) as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                            <td class="dashboard-no-export">
                                                <button type="button" class="btn btn-primary btn-sm rounded-xl"
                                                    data-dashboard-row-detail="{{ $detailPayload }}">
                                                    Voir
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ count($synthesisTable['headers']) + 1 }}">{{ $synthesisTable['empty'] ?? 'Aucune donnee.' }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <div id="dashboard-row-detail-modal" class="fixed inset-0 z-[1000] hidden items-center justify-center bg-slate-950/55 p-4" aria-hidden="true">
            <div class="max-h-[88vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#3996d3]">Detail de ligne</p>
                        <h3 id="dashboard-row-detail-title" class="mt-1 text-lg font-black text-[#17324a]">Detail</h3>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm rounded-xl" data-dashboard-row-detail-close>Fermer</button>
                </div>
                <div class="max-h-[62vh] overflow-y-auto p-5">
                    <dl id="dashboard-row-detail-body" class="grid gap-3 md:grid-cols-2"></dl>
                    <a id="dashboard-row-detail-link" href="#" class="btn btn-blue mt-5 hidden rounded-xl">Ouvrir la page</a>
                </div>
            </div>
        </div>
    @endif

    @if ($showRoleOverview && ! $showDirectionSynthesisSelector)
        @include('partials.dashboard-role-overview', [
            'roleDashboard' => $roleDashboard,
            'dashboardRole' => $dashboardRole,
            'statisticalPolicy' => $statisticalPolicy,
            'officialPolicy' => $officialPolicy,
            'displayMode' => 'overview',
        ])
    @endif

</section>
@endif

@if ($currentDashboardTab === 'charts')
<section class="dashboard-tab-panel active" data-dashboard-panel="charts">
    @if ($showRoleOverview && ! $showDirectionSynthesisSelector)
        @include('partials.dashboard-role-overview', [
            'roleDashboard' => $roleDashboard,
            'dashboardRole' => $dashboardRole,
            'statisticalPolicy' => $statisticalPolicy,
            'officialPolicy' => $officialPolicy,
            'displayMode' => 'charts',
        ])
    @endif

    @if ($showDirectionSynthesisSelector)
        <section class="mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Graphiques de decision</h2>
                <span class="showcase-chip">Services, agents et evolution</span>
            </div>
            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ($decisionCharts as $chart)
                    <article class="showcase-panel dashboard-synthesis-card">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $chart['title'] }}</h3>
                        </div>
                        <div class="space-y-3">
                            @forelse (($chart['rows'] ?? []) as $row)
                                @php
                                    $barValue = min(100, max(0, (float) ($row['value'] ?? 0)));
                                    $barColor = (string) ($row['color'] ?? '#3996D3');
                                @endphp
                                <div>
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs font-semibold text-[#17324a]">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="whitespace-nowrap">{{ number_format($barValue, 0, ',', ' ') }}%</span>
                                    </div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-200/80">
                                        <div class="h-full rounded-full" style="width: {{ $barValue }}%; background: {{ $barColor }};"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] font-medium text-[#667085]">{{ $row['meta'] ?? '' }}</p>
                                </div>
                            @empty
                                <div class="rounded-[1rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-8 text-center text-sm text-[#667085]">
                                    Aucune donnee disponible.
                                </div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>

            @php
                $curveRows = collect($decisionQuarterRows)->values();
                $curveSteps = max(1, $curveRows->count() - 1);
                $executionCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['taux_execution'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
                    })
                    ->implode(' ');
                $scoreCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['score'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
                    })
                    ->implode(' ');
            @endphp
            <article class="showcase-panel mt-3">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-black text-[#17324a]">Courbes d'evolution trimestrielle</h3>
                    <span class="showcase-chip">{{ $exerciseFilter['label'] ?? 'Exercice courant' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <svg class="min-w-[520px]" viewBox="0 0 360 150" role="img" aria-label="Courbes trimestrielles">
                        <line x1="24" y1="118" x2="336" y2="118" stroke="#d8ecf8" stroke-width="1" />
                        <line x1="24" y1="28" x2="336" y2="28" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                        <polyline points="{{ $executionCurvePoints }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="{{ $scoreCurvePoints }}" fill="none" stroke="#8FC043" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($curveRows as $row)
                            @php
                                $x = 24 + (($loop->index * 312) / $curveSteps);
                                $executionY = 118 - (min(100, max(0, (float) ($row['taux_execution'] ?? 0))) * 0.9);
                                $scoreY = 118 - (min(100, max(0, (float) ($row['score'] ?? 0))) * 0.9);
                            @endphp
                            <circle cx="{{ $x }}" cy="{{ $executionY }}" r="4" fill="#3996D3" />
                            <circle cx="{{ $x }}" cy="{{ $scoreY }}" r="4" fill="#8FC043" />
                            <text x="{{ $x }}" y="140" text-anchor="middle" font-size="10" font-weight="800" fill="#667085">{{ $row['trimestre'] ?? '-' }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold text-[#667085]">
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#3996D3]"></i>Taux execution</span>
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#8FC043]"></i>Score</span>
                </div>
            </article>
        </section>
    @endif

    <article class="showcase-panel mb-4">
        <div class="mb-4 flex items-center justify-between gap-3">
                    <div><h2 class="showcase-panel-title">Jauges des indicateurs de performance</h2></div>
            <span class="showcase-chip">5 mesures</span>
        </div>
        <div class="dashboard-gauge-grid">
            @foreach ([['key' => 'delai', 'label' => $metricLabel('delai')],['key' => 'performance', 'label' => $metricLabel('performance')],['key' => 'conformite', 'label' => $metricLabel('conformite')],['key' => 'qualite', 'label' => $metricLabel('qualite')],['key' => 'risque', 'label' => $metricLabel('risque')]] as $gauge)
                <article class="dashboard-gauge-card">
                    <strong>{{ $gauge['label'] }}</strong>
                    <div class="dashboard-gauge-canvas">
                        <div id="dashboard-kpi-gauge-{{ $gauge['key'] }}" class="dashboard-chart-host"></div>
                    </div>
                </article>
            @endforeach
        </div>
    </article>

    <article class="showcase-panel mb-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div><h2 class="showcase-panel-title">Répartition des statuts</h2></div>
            <span class="showcase-chip">{{ $metrics['totals']['actions_total'] ?? 0 }} actions</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-status-mix-chart" class="dashboard-chart-host"></div></div>
    </article>

    <div class="space-y-4">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Indicateurs mensuels</h2></div>
                <span class="showcase-chip">{{ count($analytics['monthly'] ?? []) }} mois</span>
            </div>
            <div class="dashboard-canvas"><div id="dashboard-kpi-line-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Synthèse par {{ strtolower($unitModeLabel) }}</h2></div>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-canvas"><div id="dashboard-unit-summary-chart" class="dashboard-chart-host"></div></div>
        </article>
    </div>

    <div class="mt-4 space-y-4">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Indicateurs par mois</h2></div><span class="showcase-chip">12 mois</span></div><div class="dashboard-canvas"><div id="dashboard-kpi-grouped-chart" class="dashboard-chart-host"></div></div></article>
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">{{ $metricLabel('global') }}</h2></div><span class="showcase-chip">Seuil 60</span></div><div class="rounded-[1.4rem] border border-slate-200/85 p-5 text-white" style="background: #3996d3;"><p class="text-[11px] font-semibold uppercase tracking-[0.15em] text-white/75">Score actuel</p><p class="mt-3 text-5xl font-black leading-none">{{ number_format((float) ($globalScores['global'] ?? 0), 0) }}</p><p class="mt-3 text-sm text-white/80">Progression moyenne: {{ number_format((float) ($globalScores['progression'] ?? 0), 0) }}%</p><div class="mt-4 h-2 rounded-full bg-white/20"><div class="h-2 rounded-full bg-white" style="width: {{ min(100, max(0, (float) ($globalScores['global'] ?? 0))) }}%;"></div></div></div><div class="mt-4 grid gap-2">@foreach ($statusCards as $card)<div class="rounded-2xl border border-slate-200/80 bg-slate-50/90 px-4 py-3"><div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $card['color'] }};"></span><span class="text-sm font-semibold text-[#17324a]">{{ $card['label'] }}</span></div><span class="text-sm font-black" style="color: {{ $card['color'] }};">{{ $card['count'] }}</span></div></div>@endforeach</div></article>
    </div>

    <div class="mt-4 space-y-4">
        @if ($showDashboardMacroCharts)
            <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Comparaison interannuelle</h2></div><span class="showcase-chip">{{ count($interannualRows) }} année(s)</span></div><div class="dashboard-canvas"><div id="dashboard-interannual-chart" class="dashboard-chart-host"></div></div></article>
        @endif
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Cible vs réalisé</h2></div><span class="showcase-chip">Cible 80</span></div>@if ($bulletRows !== [])<div class="grid gap-3">@foreach ($bulletRows as $row)@php $real = (float) ($row['real'] ?? 0); $bulletColor = $real >= 80 ? '#178f5f' : ($real >= 60 ? '#3996D3' : '#b7791f'); @endphp<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-[#E8F3FB]/70"><span class="truncate text-xs font-semibold text-[#667085]">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-threshold"></span><span class="dashboard-bullet-target"></span><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, $real)) }}%; background: {{ $bulletColor }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $bulletColor }};">{{ number_format($real, 0) }}</span></a>@endforeach</div>@else<div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune action avec indicateur disponible pour cette lecture.</div>@endif</article>
    </div>

    <div class="mt-4 space-y-4">
        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Classement des actions par indicateur</h2></div><span class="showcase-chip">Top 6</span></div>@if ($analytics['top_action_bars'] ?? false)<div class="grid gap-3">@foreach ($analytics['top_action_bars'] as $row)<a href="{{ $row['url'] }}" class="dashboard-bullet rounded-2xl px-2 py-1 transition hover:bg-[#E8F3FB]/70"><span class="truncate text-xs font-semibold text-[#667085]">{{ $row['label'] }}</span><span class="dashboard-bullet-track"><span class="dashboard-bullet-value" style="width: {{ min(100, max(0, (float) $row['value'])) }}%; background: {{ $row['color'] }};"></span></span><span class="text-right text-[11px] font-black" style="color: {{ $row['color'] }};">{{ number_format((float) $row['value'], 0) }}</span></a>@endforeach</div>@else<div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune action classée pour le moment.</div>@endif</article>
        @if ($showDashboardMacroCharts)
            <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Radar de comparaison</h2></div><span class="showcase-chip">{{ min(3, count($unitRows)) }} jeux</span></div><div class="dashboard-canvas"><div id="dashboard-radar-chart" class="dashboard-chart-host"></div></div></article>
        @endif
    </div>

    @if ($showDashboardMacroCharts)
        <article class="showcase-panel mt-4"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Scatter performance / conformité</h2></div><span class="showcase-chip">{{ count($analytics['scatter_points'] ?? []) }} points</span></div><div class="dashboard-canvas"><div id="dashboard-scatter-chart" class="dashboard-chart-host"></div></div></article>
    @endif

    <article class="showcase-panel mt-4"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Diagramme de Gantt</h2></div><span class="showcase-chip">{{ count($ganttRows) }} actions</span></div>@if ($ganttRows !== [])<div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-gantt-chart" class="dashboard-chart-host"></div></div>@else<div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune action datée disponible pour produire un Gantt.</div>@endif</article>

    @if ($showDashboardAdvancedReporting)
        <section class="mt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Analytique avancée</h2></div>
                <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports</a>
            </div>
            @include('partials.dashboard-reporting-analytics', [
                'reportingAnalytics' => $reportingAnalytics ?? [],
                'displayMode' => 'charts',
            ])
        </section>
    @endif
</section>
@endif

@if ($currentDashboardTab === 'overview')
<section class="dashboard-tab-panel active" data-dashboard-panel="overview-tables">
    <div class="space-y-4">
        @if ($showRoleOverview)
            @include('partials.dashboard-role-overview', [
                'roleDashboard' => $roleDashboard,
                'dashboardRole' => $dashboardRole,
                'statisticalPolicy' => $statisticalPolicy,
                'officialPolicy' => $officialPolicy,
                'displayMode' => 'tables',
            ])
        @endif

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Tableau de synthèse par {{ strtolower($unitModeLabel) }}</h2></div>
                <span class="showcase-chip">{{ count($unitRows) }} lignes</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>{{ $unitModeLabel }}</th><th>Actions</th><th>Progression</th><th>Indicateur moyen</th><th>Alertes</th><th>Validation</th></tr></thead>
                    <tbody>
                        @forelse ($unitRows as $row)
                            @php
                                $progress = (float) ($row['progression_moyenne'] ?? 0);
                                $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F9B13C' : '#94A3B8'));
                                $kpi = (float) ($row['kpi_global'] ?? 0);
                            @endphp
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['label'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpi)) }}">{{ number_format($kpi, 0) }}</span></td>
                                <td>@if (($row['alertes'] ?? 0) > 0)<span class="dashboard-pill" style="{{ $dashboardPillVars('danger') }}">{{ $row['alertes'] }}</span>@else<span class="dashboard-pill" style="{{ $dashboardPillVars('success') }}">0</span>@endif</td>
                                <td>{{ number_format((float) ($row['validation_pct'] ?? 0), 0) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucune donnée disponible.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Actions prioritaires</h2></div><span class="showcase-chip">{{ count($priorityActionRows) }} lignes</span></div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Action</th><th>Direction</th><th>Statut</th><th>Progression</th><th>Indicateur</th><th>Délai</th><th>Perf.</th><th>Conf.</th><th>Qual.</th><th>Risque</th></tr></thead>
                    <tbody>
                        @forelse ($priorityActionRows as $row)
                            @php
                                $statusColor = match ($row['statut']) {'acheve' => '#1C203D','en_avance' => '#8FC043','a_risque' => '#F9B13C','en_retard' => '#B42318','suspendu' => '#B42318','annule' => '#6B7280','non_demarre' => '#6B7280',default => '#3996D3'};
                                $progress = (float) ($row['progression'] ?? 0);
                                $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F9B13C' : '#94A3B8'));
                            @endphp
                            <tr>
                                <td><a href="{{ $row['url'] }}" class="font-semibold text-[#17324a] hover:text-[#3996D3]">{{ $row['libelle'] }}</a><div class="mt-1 text-[11px] text-[#667085]">{{ $row['responsable'] }} | {{ $row['service'] }}</div></td>
                                <td>{{ $row['direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}"><span class="h-2 w-2 rounded-full" style="background: {{ $statusColor }};"></span>{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                                @foreach (['kpi_global', 'kpi_delai', 'kpi_performance', 'kpi_conformite', 'kpi_qualite', 'kpi_risque'] as $metricKey)
                                    @php $metricValue = (float) ($row[$metricKey] ?? 0); @endphp
                                    <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($metricValue)) }}">{{ number_format($metricValue, 0) }}</span></td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="10">Aucune action disponible sur ce périmètre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="showcase-panel"><div class="mb-4 flex items-center justify-between gap-3"><div><h2 class="showcase-panel-title">Alertes actives</h2></div><span class="showcase-chip">{{ count($alertRows) }} alerte(s)</span></div><div class="overflow-x-auto"><table class="dashboard-table"><thead><tr><th>Alerte</th><th>Direction</th><th>Action</th><th>Niveau</th><th>Détail</th><th>{{ $metricLabel('global') }}</th><th>Qual.</th><th>Risque</th><th>Accès</th></tr></thead><tbody>@forelse ($alertRows as $row)<tr><td class="font-semibold text-[#17324a]">{{ $row['titre'] }}</td><td>{{ $row['direction'] }}</td><td>{{ $row['action'] }}</td><td><span class="dashboard-pill" style="{{ $dashboardPillVars(in_array($row['niveau'], ['Critique', 'Urgence'], true) ? 'danger' : 'warning') }}">{{ $row['niveau'] }}</span></td><td>{{ $row['details'] }}</td>@php $kpiValue = (float) ($row['kpi'] ?? 0); $qualityValue = (float) ($row['kpi_qualite'] ?? 0); $riskValue = (float) ($row['kpi_risque'] ?? 0); @endphp<td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpiValue)) }}">{{ number_format($kpiValue, 0) }}</span></td><td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($qualityValue)) }}">{{ number_format($qualityValue, 0) }}</span></td><td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($riskValue)) }}">{{ number_format($riskValue, 0) }}</span></td><td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td></tr>@empty<tr><td colspan="9">Aucune alerte active sur ce périmètre.</td></tr>@endforelse</tbody></table></div></article>

        @if ($showDashboardAnalyticalTables)
            <section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="showcase-panel-title">Tables analytiques</h2></div>
                    <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports</a>
                </div>
                @include('partials.dashboard-reporting-analytics', [
                    'reportingAnalytics' => $reportingAnalytics ?? [],
                    'displayMode' => 'tables',
                ])
            </section>
        @endif
    </div>
</section>
@endif

@once
    @push('scripts')
        <script @cspNonce id="anbg-dashboard-payload" type="application/json">
            {!! json_encode($currentDashboardTab === 'charts' ? [
                'dashboardData' => $dashboardClientData ?? $dashboardData ?? [],
                'reportingAnalytics' => $reportingClientAnalytics ?? ['charts' => (($reportingAnalytics ?? [])['charts'] ?? [])],
                'dgPayload' => $dgPayload ?? [],
                'ganttRows' => $ganttRows ?? [],
            ] : [
                'dashboardData' => [],
                'reportingAnalytics' => ['charts' => []],
                'dgPayload' => [],
                'ganttRows' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>
        <script @cspNonce>
            (() => {
                const decodePayload = (encoded) => {
                    const binary = window.atob(encoded);
                    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
                    return JSON.parse(new TextDecoder().decode(bytes));
                };

                const modal = document.getElementById('dashboard-row-detail-modal');
                const modalTitle = document.getElementById('dashboard-row-detail-title');
                const modalBody = document.getElementById('dashboard-row-detail-body');
                const modalLink = document.getElementById('dashboard-row-detail-link');

                document.querySelectorAll('[data-dashboard-row-detail]').forEach((button) => {
                    button.addEventListener('click', () => {
                        if (!modal || !modalTitle || !modalBody || !modalLink) {
                            return;
                        }

                        const payload = decodePayload(button.dataset.dashboardRowDetail || '');
                        modalTitle.textContent = payload.title || 'Detail';
                        modalBody.innerHTML = '';

                        (payload.headers || []).forEach((header, index) => {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'rounded-xl border border-slate-200 bg-slate-50/80 p-3';

                            const term = document.createElement('dt');
                            term.className = 'text-[11px] font-black uppercase tracking-wide text-[#667085]';
                            term.textContent = header;

                            const value = document.createElement('dd');
                            value.className = 'mt-1 text-sm font-semibold text-[#17324a]';
                            value.textContent = (payload.cells || [])[index] || '-';

                            wrapper.append(term, value);
                            modalBody.appendChild(wrapper);
                        });

                        if (payload.url) {
                            modalLink.href = payload.url;
                            modalLink.classList.remove('hidden');
                        } else {
                            modalLink.href = '#';
                            modalLink.classList.add('hidden');
                        }

                        modal.classList.remove('hidden');
                        modal.classList.add('flex');
                        modal.setAttribute('aria-hidden', 'false');
                    });
                });

                document.querySelectorAll('[data-dashboard-row-detail-close]').forEach((button) => {
                    button.addEventListener('click', () => {
                        if (!modal) {
                            return;
                        }

                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                        modal.setAttribute('aria-hidden', 'true');
                    });
                });

                modal?.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                        modal.setAttribute('aria-hidden', 'true');
                    }
                });

                document.querySelectorAll('[data-dashboard-export-table]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const tableId = button.dataset.dashboardExportTable;
                        const sourceTable = tableId ? document.getElementById(tableId) : null;
                        if (!sourceTable) {
                            return;
                        }

                        const table = sourceTable.cloneNode(true);
                        table.querySelectorAll('.dashboard-no-export').forEach((node) => node.remove());

                        const html = `
                            <html>
                                <head><meta charset="utf-8"></head>
                                <body>${table.outerHTML}</body>
                            </html>
                        `;
                        const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
                        const url = URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = `${button.dataset.dashboardExportName || 'tableau'}.xls`;
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        URL.revokeObjectURL(url);
                    });
                });
            })();
        </script>
    @endpush
@endonce
