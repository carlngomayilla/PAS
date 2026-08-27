export type IdentifierOption = {
    id: number;
    label: string;
};

export type ValueOption = {
    value: string;
    label: string;
};

export type DashboardFilterOptions = {
    years: ValueOption[];
    quarters: ValueOption[];
    periods: ValueOption[];
    action_statuses: ValueOption[];
    tracking_statuses: ValueOption[];
    delay_statuses: ValueOption[];
    deadline_alerts: ValueOption[];
    responsibles: IdentifierOption[];
};

export type DashboardBreakdownLinks = {
    actions: Record<string, string | null>;
    workflow: Record<string, string | null>;
    alerts: Record<string, string | null>;
};

export type DashboardLinks = {
    blade_pilotage: string | null;
    tables: string | null;
    charts: string | null;
    actions: string | null;
    reporting: string | null;
    alerts: string | null;
    pta_tracking: string | null;
    pas: string | null;
    paos: string | null;
    ptas: string | null;
    late_actions: string | null;
    kpi_below_threshold: string | null;
    breakdowns: DashboardBreakdownLinks;
};

export type DashboardOverviewPayload = {
    schema_version: '1.0';
    generated_at: string;
    scope: {
        mode: string;
        user_role: string;
        effective_role: string;
        cross_organization_filters: boolean;
        organization_filters_enabled: boolean;
        read_only: boolean;
        direction_id: number | null;
        service_id: number | null;
        selected_direction_id: number | null;
        selected_service_id: number | null;
    };
    direction_selector: {
        enabled: boolean;
        selected_id: number | null;
        selected_label: string;
        service_selected_id: number | null;
        service_selected_label: string;
        options: IdentifierOption[];
        service_options: IdentifierOption[];
    };
    filters: {
        periode: string;
        periode_label: string;
        statut_action: string | null;
        statut_suivi: string | null;
        statut_delai: string | null;
        alerte_echeance: string | null;
        responsable_id: number | null;
    };
    filter_options: DashboardFilterOptions;
    exercise: {
        year: number | null;
        quarter: string | null;
    };
    metrics: {
        totals: Record<string, number>;
        alerts: Record<string, number>;
        status_breakdown: Record<string, Record<string, number>>;
        action_scope: {
            mode: string;
            visible_actions_total: number;
            personal_actions_total: number;
            dashboard_actions_total: number;
        };
    };
    synthesis_decision_summary: {
        total: number;
        taux_execution: number;
        performance_pta: number;
        workflow: Record<string, number>;
        delay: Record<string, number>;
        alerts: Record<string, number>;
    };
    financial_summary: {
        budget: number;
        engaged: number;
        disbursed: number;
        remaining: number;
        engagement_rate: number;
        disbursement_rate: number;
        actions_total: number;
    } | null;
    links: DashboardLinks;
};

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isFiniteNumber(value: unknown): value is number {
    return typeof value === 'number' && Number.isFinite(value);
}

function isInteger(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value);
}

function isNullableInteger(value: unknown): value is number | null {
    return value === null || isInteger(value);
}

function isNullableString(value: unknown): value is string | null {
    return value === null || typeof value === 'string';
}

function isIntegerMap(value: unknown): value is Record<string, number> {
    return isRecord(value)
        && Object.values(value).every(isInteger);
}

function isNestedIntegerMap(value: unknown): value is Record<string, Record<string, number>> {
    return isRecord(value) && Object.values(value).every(isIntegerMap);
}

function isNullableStringMap(
    value: unknown,
    allowedKeys: string[],
): value is Record<string, string | null> {
    return isRecord(value)
        && Object.keys(value).every((key) => allowedKeys.includes(key))
        && allowedKeys.every((key) => (
            Object.prototype.hasOwnProperty.call(value, key)
            && isNullableString(value[key])
        ));
}

function isBreakdownLinks(value: unknown): value is DashboardBreakdownLinks {
    if (!isRecord(value)) {
        return false;
    }

    const allowedCategories = ['actions', 'workflow', 'alerts'];
    const allowedKeys = {
        actions: [
            'a_parametrer',
            'non_demarre',
            'en_cours',
            'a_risque',
            'en_avance',
            'en_retard',
            'a_corriger',
            'suspendu',
            'annule',
            'acheve',
        ],
        workflow: [
            'a_parametrer',
            'non_demarre',
            'en_cours',
            'validation_chef',
            'validation_controleur',
            'validation_planification',
            'cloture',
        ],
        alerts: [
            'aucune_alerte',
            'echeance_proche',
            'critique',
            'en_retard',
            'cloturee',
            'a_parametrer',
        ],
    };

    return Object.keys(value).every((key) => allowedCategories.includes(key))
        && isNullableStringMap(value.actions, allowedKeys.actions)
        && isNullableStringMap(value.workflow, allowedKeys.workflow)
        && isNullableStringMap(value.alerts, allowedKeys.alerts);
}

function isIdentifierOptions(value: unknown): value is IdentifierOption[] {
    return Array.isArray(value) && value.every((option) => (
        isRecord(option)
        && typeof option.id === 'number'
        && Number.isInteger(option.id)
        && option.id > 0
        && typeof option.label === 'string'
    ));
}

function isValueOptions(value: unknown): value is ValueOption[] {
    return Array.isArray(value) && value.every((option) => (
        isRecord(option)
        && typeof option.value === 'string'
        && typeof option.label === 'string'
    ));
}

function isFilterOptions(value: unknown): value is DashboardFilterOptions {
    return isRecord(value)
        && isValueOptions(value.years)
        && isValueOptions(value.quarters)
        && isValueOptions(value.periods)
        && isValueOptions(value.action_statuses)
        && isValueOptions(value.tracking_statuses)
        && isValueOptions(value.delay_statuses)
        && isValueOptions(value.deadline_alerts)
        && isIdentifierOptions(value.responsibles);
}

function isLinks(value: unknown): value is DashboardLinks {
    if (!isRecord(value)) {
        return false;
    }

    const requiredLinks = [
        'blade_pilotage',
        'tables',
        'charts',
        'actions',
        'pas',
        'paos',
        'ptas',
        'late_actions',
        'kpi_below_threshold',
        'reporting',
        'alerts',
        'pta_tracking',
    ];
    const allowedKeys = [...requiredLinks, 'breakdowns'];

    return Object.keys(value).every((key) => allowedKeys.includes(key))
        && requiredLinks.every((key) => (
            Object.prototype.hasOwnProperty.call(value, key)
            && isNullableString(value[key])
        ))
        && isBreakdownLinks(value.breakdowns);
}

export function unwrapDashboardPayload(value: unknown): DashboardOverviewPayload | null {
    if (!isRecord(value)) {
        return null;
    }

    const candidate = isRecord(value.data) ? value.data : value;

    const scope = candidate.scope;
    const selector = candidate.direction_selector;
    const filters = candidate.filters;
    const exercise = candidate.exercise;
    const metrics = candidate.metrics;
    const decision = candidate.synthesis_decision_summary;
    const financial = candidate.financial_summary;

    if (
        candidate.schema_version !== '1.0'
        || typeof candidate.generated_at !== 'string'
        || !isRecord(scope)
        || typeof scope.mode !== 'string'
        || typeof scope.user_role !== 'string'
        || typeof scope.effective_role !== 'string'
        || typeof scope.cross_organization_filters !== 'boolean'
        || typeof scope.organization_filters_enabled !== 'boolean'
        || typeof scope.read_only !== 'boolean'
        || !isNullableInteger(scope.direction_id)
        || !isNullableInteger(scope.service_id)
        || !isNullableInteger(scope.selected_direction_id)
        || !isNullableInteger(scope.selected_service_id)
        || !isRecord(selector)
        || typeof selector.enabled !== 'boolean'
        || !isNullableInteger(selector.selected_id)
        || typeof selector.selected_label !== 'string'
        || !isNullableInteger(selector.service_selected_id)
        || typeof selector.service_selected_label !== 'string'
        || !isIdentifierOptions(selector.options)
        || !isIdentifierOptions(selector.service_options)
        || !isRecord(filters)
        || typeof filters.periode !== 'string'
        || typeof filters.periode_label !== 'string'
        || !isNullableString(filters.statut_action)
        || !isNullableString(filters.statut_suivi)
        || !isNullableString(filters.statut_delai)
        || !isNullableString(filters.alerte_echeance)
        || !isNullableInteger(filters.responsable_id)
        || !isFilterOptions(candidate.filter_options)
        || !isRecord(exercise)
        || !isNullableInteger(exercise.year)
        || !isNullableString(exercise.quarter)
        || !isRecord(metrics)
        || !isIntegerMap(metrics.totals)
        || !isIntegerMap(metrics.alerts)
        || !isNestedIntegerMap(metrics.status_breakdown)
        || !isRecord(metrics.action_scope)
        || typeof metrics.action_scope.mode !== 'string'
        || !isInteger(metrics.action_scope.visible_actions_total)
        || !isInteger(metrics.action_scope.personal_actions_total)
        || !isInteger(metrics.action_scope.dashboard_actions_total)
        || !isRecord(decision)
        || !isInteger(decision.total)
        || !isFiniteNumber(decision.taux_execution)
        || !isFiniteNumber(decision.performance_pta)
        || !isIntegerMap(decision.workflow)
        || !isIntegerMap(decision.delay)
        || !isIntegerMap(decision.alerts)
        || !isLinks(candidate.links)
        || (
            financial !== null
            && (
                !isRecord(financial)
                || !isFiniteNumber(financial.budget)
                || !isFiniteNumber(financial.engaged)
                || !isFiniteNumber(financial.disbursed)
                || !isFiniteNumber(financial.remaining)
                || !isFiniteNumber(financial.engagement_rate)
                || !isFiniteNumber(financial.disbursement_rate)
                || !isInteger(financial.actions_total)
            )
        )
    ) {
        return null;
    }

    return candidate as DashboardOverviewPayload;
}

export function safeRelativeLink(value: string | null | undefined, fallback: string): string {
    if (
        typeof value === 'string'
        && value.startsWith('/')
        && !value.startsWith('//')
        && !value.includes('\\')
    ) {
        return value;
    }

    return fallback;
}
