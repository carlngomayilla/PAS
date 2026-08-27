import { DashboardFilters } from '@/components/dashboard-filters';
import { DashboardStatusPanel } from '@/components/dashboard-status-panel';
import { DashboardTabs } from '@/components/dashboard-tabs';
import { MetricCard } from '@/components/metric-card';
import { ProgressiveAccordionGroup } from '@/components/progressive-accordion-group';
import { StatusBreakdown } from '@/components/status-breakdown';
import type { DashboardFetchResult } from '@/lib/dashboard-api';
import { safeRelativeLink } from '@/lib/dashboard-contract';
import {
    formatCurrency,
    formatDecimal,
    formatGeneratedAt,
    formatNumber,
} from '@/lib/formatters';

function hasOverviewData(result: Extract<DashboardFetchResult, { ok: true }>['data']): boolean {
    return Object.values(result.metrics.totals).some((value) => value > 0)
        || result.synthesis_decision_summary.total > 0;
}

function AccordionTitle({ step, children }: { step: number; children: string }) {
    return (
        <summary className="flex cursor-pointer list-none items-center gap-3 rounded-2xl px-5 py-4 text-lg font-bold text-slate-950 focus-visible:outline-2 focus-visible:outline-pilot-600 dark:text-white">
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-pilot-700 text-sm text-white">
                {step}
            </span>
            <span>{children}</span>
            <span aria-hidden="true" className="ml-auto text-pilot-700 dark:text-pilot-100">⌄</span>
        </summary>
    );
}

export function DashboardPage({ result }: { result: DashboardFetchResult }) {
    if (!result.ok) {
        return <DashboardStatusPanel result={result} />;
    }

    const data = result.data;
    const totals = data.metrics.totals;
    const alerts = data.metrics.alerts;
    const links = {
        pilotage: safeRelativeLink(data.links.blade_pilotage, '/dashboard?dashboardTab=overview'),
        tables: data.links.tables,
        charts: data.links.charts,
        actions: safeRelativeLink(data.links.actions, '/workspace/actions'),
        reporting: safeRelativeLink(data.links.reporting, '/workspace/reporting'),
        alerts: safeRelativeLink(data.links.alerts, '/workspace/notifications'),
        tracking: safeRelativeLink(data.links.pta_tracking, '/workspace/pilotage'),
        pas: safeRelativeLink(
            data.links.pas,
            safeRelativeLink(data.links.pta_tracking, '/workspace/pilotage'),
        ),
        paos: safeRelativeLink(
            data.links.paos,
            safeRelativeLink(data.links.pta_tracking, '/workspace/pilotage'),
        ),
        ptas: safeRelativeLink(
            data.links.ptas,
            safeRelativeLink(data.links.pta_tracking, '/workspace/pilotage'),
        ),
        lateActions: safeRelativeLink(
            data.links.late_actions,
            safeRelativeLink(data.links.actions, '/workspace/actions'),
        ),
        kpiBelowThreshold: safeRelativeLink(
            data.links.kpi_below_threshold,
            safeRelativeLink(data.links.reporting, '/workspace/reporting'),
        ),
    };
    const selectedScope = [
        data.direction_selector.selected_label,
        data.direction_selector.service_selected_label,
    ].filter(Boolean).join(' · ');

    return (
        <main className="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
            <header className="rounded-3xl bg-pilot-900 p-6 text-white shadow-lg sm:p-8">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div className="max-w-3xl">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full bg-white/15 px-3 py-1 text-xs font-bold uppercase tracking-widest">
                                {data.scope.mode === 'personnel' ? 'Espace personnel' : 'Pilotage'}
                            </span>
                            {data.scope.read_only ? (
                                <span className="rounded-full bg-amber-300 px-3 py-1 text-xs font-bold text-amber-950">
                                    Lecture seule
                                </span>
                            ) : null}
                        </div>
                        <h1 className="mt-4 text-3xl font-black tracking-tight sm:text-4xl">
                            Tableau de bord de Pilotage
                        </h1>
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-emerald-50 sm:text-base">
                            Suivez l’exécution, les délais et les alertes selon votre périmètre autorisé.
                        </p>
                        {selectedScope ? (
                            <p className="mt-3 text-sm font-semibold text-emerald-100">
                                Périmètre : {selectedScope}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-col items-start gap-2 text-sm text-emerald-100 lg:items-end">
                        <span>Profil : {data.scope.effective_role || data.scope.user_role}</span>
                        <time dateTime={data.generated_at}>
                            Actualisé le {formatGeneratedAt(data.generated_at)}
                        </time>
                        <a
                            className="font-bold text-white underline decoration-2 underline-offset-4 focus-visible:outline-2 focus-visible:outline-white"
                            href={links.pilotage}
                        >
                            Revenir à la version actuelle
                        </a>
                    </div>
                </div>
            </header>

            <DashboardTabs links={{ ...data.links, tables: links.tables, charts: links.charts }} />
            <DashboardFilters data={data} />

            {!hasOverviewData(data) ? (
                <section className="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                    <p className="text-sm font-bold uppercase tracking-widest text-pilot-700 dark:text-pilot-100">
                        Aucun résultat
                    </p>
                    <h2 className="mt-3 text-2xl font-bold text-slate-950 dark:text-white">
                        Aucune donnée ne correspond aux filtres sélectionnés
                    </h2>
                    <p className="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                        Modifiez un filtre ci-dessus ou consultez les actions détaillées de votre périmètre.
                    </p>
                    <a
                        className="mt-6 inline-flex rounded-xl bg-pilot-700 px-5 py-3 text-sm font-bold text-white hover:bg-pilot-600 focus-visible:outline-2 focus-visible:outline-pilot-600"
                        href={links.actions}
                    >
                        Consulter les actions
                    </a>
                </section>
            ) : (
                <ProgressiveAccordionGroup>
                    <details
                        className="group rounded-3xl border border-slate-200 bg-slate-50 shadow-sm open:bg-white dark:border-slate-800 dark:bg-slate-950 dark:open:bg-slate-900"
                        data-progressive-section
                        name="dashboard-sections"
                        open
                    >
                        <AccordionTitle step={1}>Vue d’ensemble</AccordionTitle>
                        <div className="grid grid-cols-1 gap-4 border-t border-slate-200 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-slate-800">
                            <MetricCard
                                description={`${formatNumber(totals.pas_actifs ?? 0)} actif(s)`}
                                href={links.pas}
                                label="PAS"
                                value={formatNumber(totals.pas_total ?? 0)}
                            />
                            <MetricCard
                                description={`${formatNumber(totals.paos_actifs ?? 0)} actif(s)`}
                                href={links.paos}
                                label="PAO"
                                value={formatNumber(totals.paos_total ?? 0)}
                                tone="blue"
                            />
                            <MetricCard
                                description={`${formatNumber(totals.ptas_actifs ?? 0)} en cours`}
                                href={links.ptas}
                                label="PTA"
                                value={formatNumber(totals.ptas_total ?? 0)}
                                tone="green"
                            />
                            <MetricCard
                                description={`${formatNumber(totals.actions_validees ?? 0)} validée(s)`}
                                href={links.actions}
                                label="Actions"
                                value={formatNumber(totals.actions_total ?? 0)}
                                tone="blue"
                            />
                            <MetricCard
                                href={links.reporting}
                                label="Indicateurs KPI"
                                value={formatNumber(totals.kpis_total ?? 0)}
                            />
                            <MetricCard
                                href={links.reporting}
                                label="Mesures KPI"
                                value={formatNumber(totals.kpi_mesures_total ?? 0)}
                            />
                            <MetricCard
                                description="Moyenne des actions filtrées"
                                href={links.actions}
                                label="Taux d’exécution"
                                value={`${formatDecimal(data.synthesis_decision_summary.taux_execution)} %`}
                                tone="green"
                            />
                            <MetricCard
                                description="Performance consolidée du PTA"
                                href={links.reporting}
                                label="Performance PTA"
                                value={`${formatDecimal(data.synthesis_decision_summary.performance_pta)} %`}
                                tone="green"
                            />
                        </div>
                    </details>

                    <details
                        className="group rounded-3xl border border-slate-200 bg-slate-50 shadow-sm open:bg-white dark:border-slate-800 dark:bg-slate-950 dark:open:bg-slate-900"
                        data-progressive-section
                        name="dashboard-sections"
                    >
                        <AccordionTitle step={2}>Contrôle et suivi</AccordionTitle>
                        <div className="flex flex-col gap-5 border-t border-slate-200 p-5 dark:border-slate-800">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <MetricCard
                                    href={links.lateActions}
                                    label="Actions en retard"
                                    tone="red"
                                    value={formatNumber(alerts.actions_en_retard ?? 0)}
                                />
                                <MetricCard
                                    href={links.kpiBelowThreshold}
                                    label="KPI sous le seuil"
                                    tone="amber"
                                    value={formatNumber(alerts.mesures_kpi_sous_seuil ?? 0)}
                                />
                                <MetricCard
                                    href={links.alerts}
                                    label="Alertes actives"
                                    tone="amber"
                                    value={formatNumber(alerts.alertes_action_actives ?? 0)}
                                />
                            </div>
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                                <StatusBreakdown
                                    href={links.actions}
                                    hrefs={data.links.breakdowns?.actions}
                                    title="Avancement des actions"
                                    values={data.metrics.status_breakdown.actions ?? {}}
                                />
                                <StatusBreakdown
                                    href={links.actions}
                                    hrefs={data.links.breakdowns?.workflow}
                                    title="Circuit de validation"
                                    values={data.synthesis_decision_summary.workflow}
                                />
                                <StatusBreakdown
                                    href={links.alerts}
                                    hrefs={data.links.breakdowns?.alerts}
                                    title="Alertes d’échéance"
                                    values={data.synthesis_decision_summary.alerts}
                                />
                            </div>
                        </div>
                    </details>

                    {data.financial_summary ? (
                        <details
                            className="group rounded-3xl border border-slate-200 bg-slate-50 shadow-sm open:bg-white dark:border-slate-800 dark:bg-slate-950 dark:open:bg-slate-900"
                            data-progressive-section
                            name="dashboard-sections"
                        >
                            <AccordionTitle step={3}>Suivi financier</AccordionTitle>
                            <div className="grid grid-cols-1 gap-4 border-t border-slate-200 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-slate-800">
                                <MetricCard
                                    href={links.reporting}
                                    label="Budget"
                                    value={formatCurrency(data.financial_summary.budget)}
                                />
                                <MetricCard
                                    description={`${formatDecimal(data.financial_summary.engagement_rate)} % du budget`}
                                    href={links.reporting}
                                    label="Engagé"
                                    tone="blue"
                                    value={formatCurrency(data.financial_summary.engaged)}
                                />
                                <MetricCard
                                    description={`${formatDecimal(data.financial_summary.disbursement_rate)} % du budget`}
                                    href={links.reporting}
                                    label="Décaissé"
                                    tone="green"
                                    value={formatCurrency(data.financial_summary.disbursed)}
                                />
                                <MetricCard
                                    href={links.reporting}
                                    label="Disponible"
                                    tone={data.financial_summary.remaining < 0 ? 'red' : 'slate'}
                                    value={formatCurrency(data.financial_summary.remaining)}
                                />
                            </div>
                        </details>
                    ) : null}
                </ProgressiveAccordionGroup>
            )}
        </main>
    );
}
