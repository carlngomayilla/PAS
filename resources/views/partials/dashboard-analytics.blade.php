@section('title', 'Tableau de bord')

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
    $profileRole = (string) ($profil['role'] ?? $dashboardRole);
    // Variables profileRoleLabel / profileScope / profileDirectionLabel / profileServiceLabel
    // ont été retirées après suppression du bandeau profil redondant en haut du dashboard
    // (le périmètre est désormais affiché par le chip de la navbar).
    $operationalGlobalScores = $analytics['operational_global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'global' => 0, 'progression' => 0];
    $globalScores = $analytics['global_scores'] ?? ['delai' => 0, 'performance' => 0, 'conformite' => 0, 'global' => 0, 'progression' => 0];
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
    $priorityActionRows = collect($actionRows)->values()->all();
    $quantitativeTargetRows = collect($actionRows)
        ->filter(fn (array $row): bool => (bool) ($row['has_quantitative_target'] ?? false))
        
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
        ['label' => $metricLabel('global'), 'value' => number_format((float) ($globalScores['global'] ?? 0), 1, ',', ' '), 'accent' => '#8FC043', 'bg' => '#F2F8E8', 'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc'])],
        ['label' => 'En retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? 0, 'accent' => '#B42318', 'bg' => '#FFF1EF', 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
        ['label' => 'Non démarrées', 'value' => collect($statusCards)->firstWhere('label', 'Non demarre')['count'] ?? 0, 'accent' => '#6B7280', 'bg' => '#F1F5F9', 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'non_demarre'])],
    ];
    $personalActionsSummary = is_array($analytics['personal_actions_summary'] ?? null) ? $analytics['personal_actions_summary'] : [];
    $personalTasksPayload = is_array($personalTasks ?? null) ? $personalTasks : [];
    $personalTaskItems = collect($personalTasksPayload['items'] ?? [])->take(5);
    $personalTaskSummary = is_array($personalTasksPayload['summary'] ?? null) ? $personalTasksPayload['summary'] : [
        'total' => $personalTaskItems->count(),
        'overdue' => $personalTaskItems->where('is_overdue', true)->count(),
        'critical' => $personalTaskItems->where('criticality', 'critique')->count(),
        'score' => 100,
    ];
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
    $fmtPct = static fn ($value): string => number_format((float) ($value ?? 0), 1, ',', ' ').'%';
    $shortText = static fn ($value, int $limit = 42): string => \Illuminate\Support\Str::limit((string) ($value ?: '-'), $limit);
    $chartFallbackPoints = static function (array $rows, string $key = 'global'): string {
        $items = collect($rows)->values();
        $steps = max(1, $items->count() - 1);

        return $items
            ->map(function (array $row, int $index) use ($key, $steps): string {
                $value = min(100, max(0, (float) ($row[$key] ?? 0)));
                $x = 20 + (($index * 320) / $steps);
                $y = 120 - ($value * 0.9);

                return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
            })
            ->implode(' ');
    };
    $unitFallbackRows = collect($unitRows)->values();
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
            'cells' => ['Validées', $fmtCount($metrics['totals']['actions_validees'] ?? 0), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)],
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
            'rows' => collect($synthesisObjectiveRows)->map(fn (array $row): array => ['cells' => [$shortText($row['objectif'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['score'] ?? 0)]])->all(),
            'empty' => 'Aucun objectif.',
        ],
        [
            'title' => 'PAO',
            'chip' => $fmtCount($metrics['totals']['paos_total'] ?? 0),
            'headers' => ['PAO', 'Act.', 'Prog.'],
            'rows' => collect($synthesisPaoRows)->map(fn (array $row): array => ['cells' => [$shortText($row['pao'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->prepend(['cells' => ['Actifs', $fmtCount($metrics['totals']['paos_actifs'] ?? 0), 'PAO']])->all(),
            'empty' => 'Aucun PAO.',
        ],
        [
            'title' => 'PTA',
            'chip' => $fmtCount($metrics['totals']['ptas_total'] ?? 0),
            'headers' => ['PTA', 'Act.', 'Prog.'],
            'rows' => collect($synthesisPtaRows)->map(fn (array $row): array => ['cells' => [$shortText($row['pta'] ?? '-', 34), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->prepend(['cells' => ['Actifs', $fmtCount($metrics['totals']['ptas_actifs'] ?? 0), 'PTA']])->all(),
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
            'rows' => collect($synthesisLateRows)->map(fn (array $row): array => ['cells' => [$shortText($row['libelle'] ?? '-', 34), $fmtCount($row['retard_jours'] ?? 0), $fmtPct($row['progression'] ?? 0)]])->all(),
            'empty' => 'Aucun retard.',
        ],
        [
            'title' => 'Réalisées',
            'chip' => $fmtCount($statusCount('acheve')),
            'headers' => ['Élément', 'Nb', 'Taux'],
            'rows' => [
                ['cells' => ['Achevées', $fmtCount($statusCount('acheve')), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (($statusCount('acheve') / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
                ['cells' => ['Validées', $fmtCount($metrics['totals']['actions_validees'] ?? 0), $fmtPct(($metrics['totals']['actions_total'] ?? 0) > 0 ? (((int) ($metrics['totals']['actions_validees'] ?? 0) / max(1, (int) ($metrics['totals']['actions_total'] ?? 0))) * 100) : 0)]],
            ],
        ],
        [
            'title' => 'Cours',
            'chip' => $fmtCount($statusCount('en_cours') + $statusCount('a_risque')),
            'headers' => ['Statut', 'Nb', 'Note'],
            'rows' => [
                ['cells' => ['Cours', $fmtCount($statusCount('en_cours')), 'Actives']],
                ['cells' => ['À surveiller', $fmtCount($statusCount('a_risque')), 'Échéance']],
                ['cells' => ['Non dém.', $fmtCount($statusCount('non_demarre')), 'À lancer']],
            ],
        ],
        [
            'title' => 'Services',
            'chip' => count($synthesisServiceRows),
            'headers' => ['Service', 'Act.', 'Score'],
            'rows' => collect($synthesisServiceRows)->map(fn (array $row): array => ['cells' => [$shortText($row['label'] ?? '-', 28), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['kpi_global'] ?? 0)]])->all(),
            'empty' => 'Aucun service.',
        ],
        [
            'title' => 'Agents',
            'chip' => count($synthesisAgentRows),
            'headers' => ['Agent', 'Act.', 'Tx'],
            'rows' => collect($synthesisAgentRows)->map(fn (array $row): array => ['cells' => [$shortText($row['agent'] ?? '-', 28), $fmtCount($row['actions_total'] ?? 0), $fmtPct($row['taux_execution'] ?? 0)]])->all(),
            'empty' => 'Aucun agent.',
        ],
        [
            'title' => 'Valid.',
            'chip' => $fmtCount($metrics['totals']['actions_validees'] ?? 0),
            'headers' => ['État', 'Nb', 'Part'],
            'rows' => $validationRows,
        ],
        [
            'title' => 'Alertes',
            'chip' => count($alertRows),
            'headers' => ['Alerte', 'Niv.', 'Détail'],
            'rows' => collect($alertRows)->map(fn (array $row): array => ['cells' => [$shortText($row['titre'] ?? '-', 30), $shortText($row['niveau'] ?? '-', 14), $shortText($row['details'] ?? '-', 18)]])->all(),
            'empty' => 'Aucune alerte.',
        ],
        [
            'title' => 'Indicateurs',
            'chip' => $fmtPct($globalScores['global'] ?? 0),
            'headers' => ['Indicateur', 'Score', 'Note'],
            // KPI "Conformite" retire (2026-05-28). Seuls Delai et Performance restent.
            'rows' => [
                ['cells' => ['Délai', $fmtPct($globalScores['delai'] ?? 0), 'Temps']],
                ['cells' => ['Perf.', $fmtPct($globalScores['performance'] ?? 0), 'Exéc.']],
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
                ['cells' => ['Ind.', $fmtCount($metrics['totals']['kpis_total'] ?? 0), 'Indicateur']],
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
            'rows' => collect($synthesisServiceRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($synthesisAgentRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($priorityActionRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($synthesisLateRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($alertRows)->map(fn (array $row): array => ['cells' => [
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
            'title' => 'Chaîne PAS PAO PTA Actions',
            'chip' => $fmtPct($decisionCounts['taux_alignement'] ?? 0),
            'headers' => ['PAS', 'Objectif stratégique', 'PAO', 'Objectif opérationnel', 'PTA', 'Actions', 'État'],
            'rows' => collect($decisionChainRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['pas'] ?? 'PAS', 22),
                $shortText($row['objectif_strategique'] ?? '-', 34),
                $shortText($row['pao'] ?? 'PAO', 22),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['pta'] ?? 'PTA', 22),
                $fmtCount($row['actions'] ?? 0),
                $row['etat'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune chaîne PAS PAO PTA Actions sur ce périmètre.',
        ],
        [
            'title' => 'Performance par service',
            'chip' => count($decisionServiceRows).' services',
            'headers' => ['Service', 'PTA', 'Actions', 'Terminées', 'En cours', 'Retard', 'Taux', 'Score'],
            'rows' => collect($decisionServiceRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($decisionPriorityRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($decisionLateRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Agent', 'Service', 'Actions affectées', 'Terminées', 'En retard', 'Sous-actions', 'Score'],
            'rows' => collect($decisionAgentRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Élément', 'Service', 'Responsable', 'Niveau', 'Statut', 'Depuis', 'Action'],
            'rows' => collect($decisionPendingValidationRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['element'] ?? '-', 34),
                $shortText($row['service'] ?? '-', 22),
                $shortText($row['responsable'] ?? '-', 24),
                $row['niveau'] ?? '-',
                $shortText($row['statut'] ?? '-', 20),
                $row['depuis'] ?? '-',
                $row['action'] ?? 'Vérifier',
            ]])->all(),
            'empty' => 'Aucune validation en attente.',
        ],
        [
            'title' => 'Justificatifs et preuves',
            'chip' => $fmtCount($decisionCounts['justificatifs_total'] ?? 0).' preuves',
            'headers' => ['Action', 'Agent', 'Justificatif', 'Statut preuve', 'Validateur', 'Observation'],
            'rows' => collect($decisionProofRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Type', 'Élément', 'Service', 'Gravité', 'Détail', 'Action corrective'],
            'rows' => collect($decisionAnomalyRows)->map(fn (array $row): array => ['cells' => [
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
            'title' => 'Évolution trimestrielle',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous les trimestres',
            'headers' => ['Trimestre', 'Actions prévues', 'Terminées', 'Retard', 'Taux d\'exécution', 'Score'],
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
            'headers' => ['Direction', 'PAO créé', 'Objectifs opérationnels', 'Services', 'PTA créés', 'Actions', 'Non démarrées', 'En cours', 'Réalisées', 'Validées', 'Retards', 'Hors délai', 'Taux d\'exécution', 'Taux de réalisation', 'Performance', 'Statut', 'Dernière activité'],
            'rows' => collect($directionPerformanceRows)->map(fn (array $row): array => ['cells' => [
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
            'title' => 'Déclinaison du PAS par direction',
            'chip' => count($pasDirectionRows).' lignes',
            'headers' => ['Direction', 'Axes concernés', 'Objectifs stratégiques', 'PAO créé', 'Objectifs opérationnels', 'Taux de déclinaison', 'Statut', 'Dernière mise à jour'],
            'rows' => collect($pasDirectionRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['direction'] ?? '-', 28),
                $fmtCount($row['axes'] ?? 0),
                $fmtCount($row['objectifs_strategiques'] ?? 0),
                $row['pao_cree'] ?? '-',
                $fmtCount($row['objectifs_operationnels'] ?? 0),
                $fmtPct($row['taux_declinaison'] ?? 0),
                $row['statut'] ?? '-',
                $row['derniere_maj'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune déclinaison PAS disponible.',
        ],
        [
            'title' => 'PAO par direction',
            'chip' => count($paoDirectionRows).' objectifs',
            'headers' => ['Direction', 'Objectif stratégique', 'Objectif opérationnel', 'Service', 'Échéance', 'PTA créé', 'Actions créées', 'Actions affectées', 'En cours', 'Réalisées', 'Retards', 'Taux d\'exécution', 'Statut'],
            'rows' => collect($paoDirectionRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Service', 'PTA', 'Actions', 'Terminées', 'En cours', 'Retard', 'Taux d\'exécution', 'Score'],
            'rows' => collect($decisionServiceRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Service', 'Objectif opérationnel', 'Action', 'Responsable', 'Début', 'Échéance', 'Cible', 'Réalisé', 'Reste', 'Taux de réalisation', 'Progression technique', 'Statut d\'évolution', 'Statut délai', 'Justificatifs', 'Performance'],
            'rows' => collect($ptaServiceActionRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Agent', 'Service', 'Actions affectées', 'Terminées', 'En retard', 'Sous-actions', 'Score'],
            'rows' => collect($decisionAgentRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Agent', 'Action', 'Objectif opérationnel', 'PTA', 'Direction', 'Service', 'Échéance', 'Cible', 'Réalisé', 'Reste', 'Sous-actions', 'Progression', 'Taux de réalisation', 'Statut', 'Délai', 'Performance', 'Justificatifs', 'Commentaires', 'Dernière activité'],
            'rows' => collect($agentActionRows)->map(fn (array $row): array => ['cells' => [
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
            'empty' => 'Aucune action affectée disponible.',
        ],
        [
            'title' => 'Sous-actions et preuves',
            'chip' => count($subActionRows).' sous-actions',
            'headers' => ['Action', 'Sous-action', 'Description', 'Cible prévue', 'Quantité réalisée', 'Unité', 'Taux', 'Résultat obtenu', 'Effectuée', 'Date de réalisation', 'Justificatif', 'Commentaire agent', 'Contrôle supérieur', 'Statut'],
            'rows' => collect($subActionRows)->map(fn (array $row): array => ['cells' => [
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
            'title' => 'Chaîne PAS PAO PTA Actions',
            'chip' => $fmtPct($decisionCounts['taux_alignement'] ?? 0),
            'headers' => ['PAS', 'Objectif stratégique', 'PAO', 'Objectif opérationnel', 'PTA', 'Actions', 'État'],
            'rows' => collect($decisionChainRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['pas'] ?? 'PAS', 22),
                $shortText($row['objectif_strategique'] ?? '-', 34),
                $shortText($row['pao'] ?? 'PAO', 22),
                $shortText($row['objectif_operationnel'] ?? '-', 34),
                $shortText($row['pta'] ?? 'PTA', 22),
                $fmtCount($row['actions'] ?? 0),
                $row['etat'] ?? '-',
            ]])->all(),
            'empty' => 'Aucune chaîne disponible.',
        ],
        [
            'title' => 'Actions prioritaires',
            'chip' => count($decisionPriorityRows).' actions',
            'headers' => ['Action', 'Service', 'Responsable', 'Date fin', 'Statut', 'Progression', 'Validation'],
            'rows' => collect($decisionPriorityRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($decisionLateRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Élément', 'Service', 'Responsable', 'Niveau', 'Statut', 'Depuis', 'Action'],
            'rows' => collect($decisionPendingValidationRows)->map(fn (array $row): array => ['cells' => [
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
            'rows' => collect($decisionProofRows)->map(fn (array $row): array => ['cells' => [
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
            'headers' => ['Type', 'Élément', 'Service', 'Gravité', 'Détail', 'Action corrective'],
            'rows' => collect($decisionAnomalyRows)->map(fn (array $row): array => ['cells' => [
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
            'title' => 'Évolution trimestrielle',
            'chip' => $exerciseFilter['quarter_label'] ?? 'Tous les trimestres',
            'headers' => ['Trimestre', 'Actions prévues', 'Terminées', 'Retard', 'Taux d\'exécution', 'Score'],
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
                'meta' => $fmtCount($row['terminees'] ?? 0).' terminées / '.$fmtCount($row['actions_prevues'] ?? 0).' prévues',
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
    <form method="GET" action="{{ route('workspace.search') }}" class="dashboard-tabs-search" role="search" aria-label="Recherche globale">
        <span class="dashboard-tabs-search-icon" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z"/>
            </svg>
        </span>
        <input
            id="dashboard-tabs-search"
            name="q"
            type="text"
            value="{{ request('q') }}"
            placeholder="Rechercher..."
            autocomplete="off"
            aria-label="Recherche globale"
            inputmode="search"
        >
    </form>
    <div class="ml-auto flex flex-wrap gap-2 pr-1">
        <a class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.alertes') }}">Alertes</a>
        <a class="btn btn-primary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.reporting') }}">Rapports</a>
    </div>
</div>

@if ($showDirectionSynthesisSelector)
    <div class="dashboard-synthesis-context mb-4 flex flex-wrap items-center gap-2 px-1 py-1 text-sm font-semibold text-[#17324a]">
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
@endif
{{-- Badge redondant supprimé : les informations rôle/périmètre/direction/service/exercice
     sont désormais accessibles via le chip de périmètre dans la navbar (et le filtre exercice). --}}

{{-- Bloc « Centre personnel » retiré du tableau de bord (Synthèse + Graphiques).
     Les tâches personnelles restent accessibles via le module dédié « Mes tâches ». --}}

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
@include("partials.dashboard-analytics._panel-overview")
@endif

@if ($currentDashboardTab === 'charts')
@include("partials.dashboard-analytics._panel-charts")
@endif

@if ($currentDashboardTab === 'overview')
@include("partials.dashboard-analytics._panel-tables")
@endif

@once
    @push('scripts')
        <script @cspNonce id="anbg-dashboard-payload" type="application/json">
            {!! json_encode([
                'dashboardData' => $dashboardClientData ?? $dashboardData ?? [],
                'reportingAnalytics' => $reportingClientAnalytics ?? ['charts' => (($reportingAnalytics ?? [])['charts'] ?? [])],
                'dgPayload' => $dgPayload ?? [],
                'ganttRows' => $ganttRows ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
        </script>
        <script @cspNonce>
            document.dispatchEvent(new CustomEvent('anbg:dashboard-payload-ready'));
            if (typeof window.__anbgDashboardBoot === 'function') {
                window.__anbgDashboardBoot(true);
            }

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
                        modalTitle.textContent = payload.title || 'Détail';
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
