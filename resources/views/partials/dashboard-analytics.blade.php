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
    $qualityThreshold = min(100, max(0, (float) ($analytics['quality_threshold'] ?? $globalScores['quality_threshold'] ?? 60)));
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
    $agentPerformance = is_array($analytics['agent_performance'] ?? null) ? $analytics['agent_performance'] : [];
    $agentPerformanceSummary = is_array($agentPerformance['summary'] ?? null) ? $agentPerformance['summary'] : [];
    $agentPerformanceRows = is_array($agentPerformance['rows'] ?? null) ? $agentPerformance['rows'] : [];
    $agentPerformanceTopRows = is_array($agentPerformance['top_rows'] ?? null) ? $agentPerformance['top_rows'] : [];
    $agentPerformanceAlerts = is_array($agentPerformance['alerts'] ?? null) ? $agentPerformance['alerts'] : [];
    $agentPerformanceThreshold = (float) ($agentPerformanceSummary['threshold'] ?? $agentPerformance['threshold'] ?? 77);
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
    $ptaQuarterlyAnalysis = is_array(($reportingAnalytics['ptaQuarterlyAnalysis'] ?? null)) ? $reportingAnalytics['ptaQuarterlyAnalysis'] : [];
    $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
    $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $officialBaseLower = mb_strtolower($officialBaseLabel);
    $officialBaseText = 'Base statistique : '.$officialBaseLabel;
    $officialAverageText = 'Moyenne sur '.$officialBaseLabel;
    $officialFilters = (array) ($basePolicy['route_filters'] ?? []);
    $directionSelector = is_array($analytics['direction_selector'] ?? null) ? $analytics['direction_selector'] : [];
    $exerciseFilter = is_array($analytics['exercise'] ?? null) ? $analytics['exercise'] : [];
    $synthesisFilters = is_array($analytics['synthesis_filters'] ?? null) ? $analytics['synthesis_filters'] : [];
    $synthesisDecisionSummary = is_array($analytics['synthesis_decision_summary'] ?? null) ? $analytics['synthesis_decision_summary'] : [];
    $synthesisWorkflowCounts = is_array($synthesisDecisionSummary['workflow'] ?? null) ? $synthesisDecisionSummary['workflow'] : [];
    $synthesisDelayCounts = is_array($synthesisDecisionSummary['delay'] ?? null) ? $synthesisDecisionSummary['delay'] : [];
    $synthesisAlertCounts = is_array($synthesisDecisionSummary['alerts'] ?? null) ? $synthesisDecisionSummary['alerts'] : [];
    $exerciceContext = app(\App\Services\ExerciceContext::class);
    $ptaSuiviService = app(\App\Services\PtaSuiviService::class);
    $synthesisExerciseOptions = $exerciceContext->options();
    $synthesisPeriodOptions = $ptaSuiviService->periodOptions();
    $synthesisWorkflowOptions = [
        'all' => 'Tous suivis',
        'a_parametrer' => 'A parametrer',
        'non_demarre' => 'Non demarre',
        'en_cours' => 'En cours',
        'validation_chef' => 'Validation chef',
        'validation_controleur' => 'Validation controleur',
        'cloture' => 'Cloture',
    ];
    $synthesisDelayOptions = [
        'all' => 'Tous delais',
        'dans_les_delais' => 'Dans les delais',
        'hors_delai' => 'Hors delai',
    ];
    $synthesisAlertOptions = [
        'all' => 'Toutes alertes',
        'aucune_alerte' => 'Aucune alerte',
        'echeance_proche' => 'Echeance proche',
        'critique' => 'Critique',
        'en_retard' => 'En retard',
        'cloturee' => 'Cloturee',
        'a_parametrer' => 'A parametrer',
    ];
    $selectedSynthesisYear = request()->query('exercice', $exerciseFilter['year'] ?? 'all');
    $selectedSynthesisPeriod = $ptaSuiviService->normalizePeriod(request()->query('periode', request()->query('trimestre', $exerciseFilter['period'] ?? 'all')));
    $selectedSynthesisDirection = (string) ($directionSelector['selected_id'] ?? request('direction_id', 'all'));
    $selectedSynthesisService = (string) ($directionSelector['service_selected_id'] ?? request('service_id', 'all'));
    $pilotDashboardRoles = ['global', 'admin', 'super_admin', 'dg', 'cabinet', 'planification'];
    $showDirectionSynthesisSelector = ($directionSelector['enabled'] ?? false)
        && in_array($dashboardRole, $pilotDashboardRoles, true);
    $availableDashboardTabs = [
        'overview' => 'Synthese',
        'charts' => 'Graphiques',
        'advanced' => 'Analyse avancee',
    ];
    $dashboardTabAliases = [
        'overview' => 'overview',
        'synthese' => 'overview',
        'charts' => 'charts',
        'graphes' => 'charts',
        'kpi' => 'charts',
        'gantt' => 'charts',
        'analytics' => 'charts',
        'actions' => 'advanced',
        'tables' => 'advanced',
        'advanced' => 'advanced',
        'analyse' => 'advanced',
    ];
    $requestedDashboardTab = request()->query('dashboardTab', 'overview');
    $currentDashboardTab = $dashboardTabAliases[$requestedDashboardTab] ?? 'overview';
    if ($currentDashboardTab === 'tables') {
        $currentDashboardTab = 'overview';
    }
    $canOpenPtaSuivi = $currentDashboardUser
        && (
            $currentDashboardUser->hasPermission(\App\Services\PtaSuiviService::PERMISSION)
            || $currentDashboardUser->isPlanningControlChief()
            || $currentDashboardUser->hasRole(
                \App\Models\User::ROLE_SUPER_ADMIN,
                \App\Models\User::ROLE_ADMIN,
                \App\Models\User::ROLE_ADMIN_FONCTIONNEL,
                \App\Models\User::ROLE_PLANIFICATION,
                \App\Models\User::ROLE_SCIQ,
                \App\Models\User::ROLE_SCIQ_SUIVI_GLOBAL
            )
        );
    $ptaSuiviQuery = collect([
        'direction_id' => $directionSelector['selected_id'] ?? request('direction_id'),
        'service_id' => $directionSelector['service_selected_id'] ?? request('service_id'),
        'annee' => $exerciseFilter['year'] ?? request('exercice'),
        'periode' => $selectedSynthesisPeriod,
        'statut_suivi' => request('statut_suivi'),
        'statut_delai' => request('statut_delai'),
        'alerte_echeance' => request('alerte_echeance'),
    ])->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '' && trim((string) $value) !== 'all')->all();

    $summaryStrip = ($roleDashboard['summary_cards'] ?? []) !== [] ? $roleDashboard['summary_cards'] : [
        ['label' => 'Actions totales', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#1F2937', 'bg' => '#F8FBFF', 'meta' => null, 'href' => route('workspace.actions.index')],
        ['label' => $metricLabel('global'), 'value' => number_format((float) ($globalScores['global'] ?? 0), 0, ',', ' '), 'accent' => '#8FC043', 'bg' => '#F2F8E8', 'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc'])],
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

    $summaryCardIsUsed = static function (array $card): bool {
        if (array_key_exists('used', $card)) {
            return (bool) $card['used'];
        }

        $normalized = str_replace(['%', ' ', "\u{00A0}"], '', (string) ($card['value'] ?? ''));
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized)
            ? (float) $normalized > 0
            : trim((string) ($card['value'] ?? '')) !== '';
    };

    $summaryStrip = collect($summaryStrip)
        ->filter($summaryCardIsUsed)
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

    $delayStatusTone = static function (string $status): string {
        return match ($status) {
            'dans_delais' => 'success',
            'proche_echeance' => 'warning',
            'en_retard', 'acheve_hors_delai' => 'danger',
            'suspendu' => 'danger',
            'annule', 'sans_echeance' => 'neutral',
            default => 'info',
        };
    };

    $fmtCount = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', ' ');
    $fmtPct = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', ' ').'%';
    $shortText = static fn ($value, int $limit = 42): string => \Illuminate\Support\Str::limit((string) ($value ?: '-'), $limit);
    $chartFallbackPoints = static function (array $rows, string $key = 'global'): string {
        $items = collect($rows)->values();
        $steps = max(1, $items->count() - 1);

        return $items
            ->map(function (array $row, int $index) use ($key, $steps): string {
                $value = min(100, max(0, (float) ($row[$key] ?? 0)));
                $x = 20 + (($index * 320) / $steps);
                $y = 120 - ($value * 0.9);

                return number_format($x, 0, '.', '').','.number_format($y, 0, '.', '');
            })
            ->implode(' ');
    };
    // Lisse une suite de points "x,y x,y …" en courbe Bézier (Catmull-Rom).
    // Retourne le corps du path SANS préfixe (ni M ni L) pour être réutilisable
    // en ligne (M {body}) comme en aire (M base L {body} L base Z).
    $smoothPath = static function (string $points): string {
        $pairs = [];
        foreach (preg_split('/\s+/', trim($points)) as $pt) {
            if ($pt === '') { continue; }
            $xy = explode(',', $pt);
            if (count($xy) !== 2) { continue; }
            $pairs[] = [(float) $xy[0], (float) $xy[1]];
        }
        $n = count($pairs);
        if ($n === 0) { return ''; }
        $fmt = static fn (float $v): string => (string) (int) round($v);
        if ($n === 1) { return $fmt($pairs[0][0]).','.$fmt($pairs[0][1]); }
        $t = 0.18;
        $d = $fmt($pairs[0][0]).','.$fmt($pairs[0][1]);
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $pairs[$i === 0 ? 0 : $i - 1];
            $p1 = $pairs[$i];
            $p2 = $pairs[$i + 1];
            $p3 = $pairs[$i + 2 < $n ? $i + 2 : $n - 1];
            $c1x = $p1[0] + ($p2[0] - $p0[0]) * $t;
            $c1y = $p1[1] + ($p2[1] - $p0[1]) * $t;
            $c2x = $p2[0] - ($p3[0] - $p1[0]) * $t;
            $c2y = $p2[1] - ($p3[1] - $p1[1]) * $t;
            $d .= ' C '.$fmt($c1x).','.$fmt($c1y).' '.$fmt($c2x).','.$fmt($c2y).' '.$fmt($p2[0]).','.$fmt($p2[1]);
        }
        return $d;
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
            'title' => 'Directions',
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
            'title' => 'PAS par direction',
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
            'title' => 'Services',
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
            'title' => 'Actions agents',
            'chip' => count($agentActionRows).' lignes',
            'headers' => ['Agent', 'Action', 'Objectif opérationnel', 'PTA', 'Direction', 'Service', 'Échéance', 'Cible', 'Réalisé', 'Reste', 'Sous-actions', 'Progression', 'Taux de réalisation', 'Statut', 'Statut délai', 'Performance', 'Justificatifs', 'Commentaires', 'Dernière activité'],
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
            'title' => 'Sous-actions',
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
            'title' => 'Chaîne PAS-PAO-PTA',
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
            'title' => 'Priorités',
            'chip' => count($decisionPriorityRows).' actions',
            'headers' => ['Action', 'Service', 'Responsable', 'Date fin', 'Statut délai', 'Statut', 'Progression', 'Validation'],
            'rows' => collect($decisionPriorityRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 38),
                $shortText($row['service'] ?? '-', 24),
                $shortText($row['responsable'] ?? '-', 28),
                $row['date_fin'] ?? '-',
                $row['statut_delai'] ?? '-',
                $row['statut'] ?? '-',
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['validation'] ?? '-', 22),
            ]])->all(),
            'empty' => 'Aucune action prioritaire.',
        ],
        [
            'title' => 'Retards',
            'chip' => count($decisionLateRows).' retards',
            'headers' => ['Action', 'Responsable', 'Service', 'Date fin', 'Statut délai', 'Jours retard', 'Progression', 'Motif'],
            'rows' => collect($decisionLateRows)->map(fn (array $row): array => ['cells' => [
                $shortText($row['action'] ?? '-', 34),
                $shortText($row['responsable'] ?? '-', 24),
                $shortText($row['service'] ?? '-', 22),
                $row['date_fin'] ?? '-',
                $row['statut_delai'] ?? '-',
                $fmtCount($row['jours_retard'] ?? 0),
                $fmtPct($row['progression'] ?? 0),
                $shortText($row['motif'] ?? '-', 28),
            ]])->all(),
            'empty' => 'Aucune action en retard.',
        ],
        [
            'title' => 'Validations',
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
            'title' => 'Justificatifs',
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
            'title' => 'Alertes',
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
            'title' => 'Evolution trimestrielle',
            'chip' => $exerciseFilter['period_label'] ?? $exerciseFilter['quarter_label'] ?? 'Annuelle',
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
            'title' => 'Services',
            'rows' => collect($decisionServiceRows)->map(fn (array $row): array => [
                'label' => $shortText($row['service'] ?? '-', 34),
                'value' => (float) ($row['score'] ?? 0),
                'meta' => $fmtCount($row['actions'] ?? 0).' actions',
                'color' => '#3996D3',
            ])->all(),
        ],
        [
            'title' => 'Agents',
            'rows' => collect($decisionAgentRows)->map(fn (array $row): array => [
                'label' => $shortText($row['agent'] ?? '-', 34),
                'value' => (float) ($row['score'] ?? 0),
                'meta' => $fmtCount($row['actions_affectees'] ?? 0).' actions',
                'color' => '#8FC043',
            ])->all(),
        ],
        [
            'title' => 'Evolution trimestrielle',
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
        <a class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.notifications.index', ['tab' => 'alertes']) }}">Alertes</a>
        @if ($canOpenPtaSuivi)
            <a class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('pta.suivi.index', $ptaSuiviQuery) }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16v16H4V4zm0 5h16M9 4v16" />
                </svg>
                Suivi PTA
            </a>
        @endif
        <a class="btn btn-primary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('workspace.reporting') }}">Rapports</a>
    </div>
</div>

@if ($currentDashboardTab === 'overview')
    <form
        method="GET"
        action="{{ route('synthese.index') }}"
        class="mb-4 rounded-2xl border border-[#3996d3]/20 bg-white/95 p-3 shadow-sm"
        data-dashboard-synthesis-filter-form
        data-services-url-template="{{ route('synthese.services-by-direction', ['direction' => '__DIRECTION__']) }}"
    >
        <input type="hidden" name="dashboardTab" value="overview">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-7">
            <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                Annee
                <select name="exercice" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]">
                    @foreach ($synthesisExerciseOptions as $option)
                        <option value="{{ $option['value'] }}" @selected((string) $selectedSynthesisYear === (string) $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                Periode
                <select name="periode" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]">
                    @foreach ($synthesisPeriodOptions as $option)
                        <option value="{{ $option['value'] }}" @selected((string) $selectedSynthesisPeriod === (string) $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @if ($showDirectionSynthesisSelector)
                <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                    Direction
                    <select name="direction_id" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]" data-synthesis-direction-select>
                        <option value="all" @selected($selectedSynthesisDirection === '' || $selectedSynthesisDirection === 'all')>Toutes directions</option>
                        @foreach (($directionSelector['options'] ?? []) as $directionOption)
                            <option value="{{ $directionOption['id'] }}" @selected($selectedSynthesisDirection === (string) $directionOption['id'])>{{ $directionOption['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                    Service
                    <select name="service_id" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]" data-synthesis-service-select @disabled($selectedSynthesisDirection === '' || $selectedSynthesisDirection === 'all')>
                        <option value="all" @selected($selectedSynthesisService === '' || $selectedSynthesisService === 'all')>Tous services</option>
                        @foreach (($directionSelector['service_options'] ?? []) as $serviceOption)
                            <option value="{{ $serviceOption['id'] }}" @selected($selectedSynthesisService === (string) $serviceOption['id'])>{{ $serviceOption['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                Statut suivi
                <select name="statut_suivi" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]">
                    @foreach ($synthesisWorkflowOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($synthesisFilters['statut_suivi'] ?? 'all') === (string) $value || (($synthesisFilters['statut_suivi'] ?? null) === null && $value === 'all'))>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                Statut delai
                <select name="statut_delai" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]">
                    @foreach ($synthesisDelayOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($synthesisFilters['statut_delai'] ?? 'all') === (string) $value || (($synthesisFilters['statut_delai'] ?? null) === null && $value === 'all'))>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-[11px] font-black uppercase tracking-wide text-[#667085]">
                Alerte echeance
                <select name="alerte_echeance" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-[#17324a]">
                    @foreach ($synthesisAlertOptions as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($synthesisFilters['alerte_echeance'] ?? 'all') === (string) $value || (($synthesisFilters['alerte_echeance'] ?? null) === null && $value === 'all'))>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs font-semibold text-[#667085]">
                {{ $directionSelector['selected_label'] ?? 'Synthese globale' }} | {{ $directionSelector['service_selected_label'] ?? 'Tous les services' }} | {{ $exerciseFilter['label'] ?? 'Exercice courant' }}
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5 text-xs" href="{{ route('synthese.index', ['dashboardTab' => 'overview', 'direction_id' => 'all', 'service_id' => 'all', 'exercice' => 'all', 'periode' => 'all', 'trimestre' => 'all', 'statut_suivi' => 'all', 'statut_delai' => 'all', 'alerte_echeance' => 'all']) }}">Reinitialiser</a>
                <button type="submit" class="btn btn-primary btn-sm rounded-xl px-3 py-1.5 text-xs">Appliquer</button>
            </div>
        </div>
    </form>
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

@if ($currentDashboardTab === 'advanced')
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
    @endpush
@endonce
