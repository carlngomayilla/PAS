'use client';

import { useTransition } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import type {
    DashboardFilterOptions,
    DashboardOverviewPayload,
    IdentifierOption,
    ValueOption,
} from '@/lib/dashboard-contract';

type FilterProperties = {
    data: DashboardOverviewPayload;
};

type SelectOption = {
    value: string;
    label: string;
};

function valueOptions(options: ValueOption[], allLabel: string): SelectOption[] {
    return [
        { value: 'all', label: allLabel },
        ...options
            .filter((option) => option.value !== '' && option.value !== 'all')
            .map((option) => ({ value: option.value, label: option.label })),
    ];
}

function identifierOptions(options: IdentifierOption[], allLabel: string): SelectOption[] {
    return [
        { value: 'all', label: allLabel },
        ...options.map((option) => ({
            value: String(option.id),
            label: option.label,
        })),
    ];
}

function FilterSelect({
    label,
    name,
    options,
    value,
    disabled,
    onChange,
}: {
    label: string;
    name: string;
    options: SelectOption[];
    value: string;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <label className="flex min-w-0 flex-col gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
            <span>{label}</span>
            <select
                className="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm focus:border-pilot-600 focus:outline-none focus:ring-2 focus:ring-pilot-100 disabled:cursor-wait disabled:opacity-70 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-pilot-900"
                disabled={disabled}
                name={name}
                onChange={(event) => onChange(event.target.value)}
                value={value}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function currentOption(value: string | number | null, options: SelectOption[]): string {
    const candidate = value === null ? 'all' : String(value);

    return options.some((option) => option.value === candidate) ? candidate : 'all';
}

export function DashboardFilters({ data }: FilterProperties) {
    const filterOptions: DashboardFilterOptions | undefined = data.filter_options;
    const router = useRouter();
    const searchParameters = useSearchParams();
    const [isPending, startTransition] = useTransition();

    if (!filterOptions) {
        return null;
    }

    const years = valueOptions(filterOptions.years, 'Tous les exercices');
    const periods = valueOptions(
        filterOptions.periods.length > 0 ? filterOptions.periods : filterOptions.quarters,
        'Toute la période',
    );
    const actionStatuses = valueOptions(filterOptions.action_statuses, 'Tous les statuts');
    const trackingStatuses = valueOptions(filterOptions.tracking_statuses, 'Tous les suivis');
    const delayStatuses = valueOptions(filterOptions.delay_statuses, 'Tous les délais');
    const deadlineAlerts = valueOptions(filterOptions.deadline_alerts, 'Toutes les alertes');
    const responsibles = identifierOptions(filterOptions.responsibles, 'Tous les responsables');
    const directions = identifierOptions(data.direction_selector.options, 'Toutes les directions');
    const services = identifierOptions(data.direction_selector.service_options, 'Tous les services');

    function updateFilter(name: string, value: string, reset: string[] = []): void {
        const nextParameters = new URLSearchParams(searchParameters.toString());

        for (const key of reset) {
            nextParameters.delete(key);
        }

        if (value === '' || value === 'all') {
            nextParameters.delete(name);
        } else {
            nextParameters.set(name, value);
        }

        const query = nextParameters.toString();

        startTransition(() => {
            router.replace(query === '' ? '/' : `/?${query}`, { scroll: false });
        });
    }

    return (
        <section
            aria-labelledby="dashboard-filter-title"
            className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
        >
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-bold text-slate-950 dark:text-white" id="dashboard-filter-title">
                        Périmètre d’analyse
                    </h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Chaque sélection actualise automatiquement les indicateurs.
                    </p>
                </div>
                <span
                    aria-live="polite"
                    className="min-h-5 text-sm font-semibold text-pilot-700 dark:text-pilot-100"
                >
                    {isPending ? 'Actualisation…' : ''}
                </span>
            </div>

            <div
                aria-busy={isPending}
                className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <FilterSelect
                    disabled={isPending}
                    label="Exercice"
                    name="exercice"
                    onChange={(value) => updateFilter('exercice', value, ['responsable_id'])}
                    options={years}
                    value={currentOption(data.exercise.year, years)}
                />
                <FilterSelect
                    disabled={isPending}
                    label="Période"
                    name="periode"
                    onChange={(value) => updateFilter('periode', value)}
                    options={periods}
                    value={currentOption(data.filters.periode, periods)}
                />
                {data.direction_selector.enabled ? (
                    <>
                        <FilterSelect
                            disabled={isPending}
                            label="Direction"
                            name="direction_id"
                            onChange={(value) => updateFilter(
                                'direction_id',
                                value,
                                ['service_id', 'responsable_id'],
                            )}
                            options={directions}
                            value={currentOption(data.direction_selector.selected_id, directions)}
                        />
                        <FilterSelect
                            disabled={isPending}
                            label="Service"
                            name="service_id"
                            onChange={(value) => updateFilter('service_id', value, ['responsable_id'])}
                            options={services}
                            value={currentOption(data.direction_selector.service_selected_id, services)}
                        />
                    </>
                ) : null}
                <FilterSelect
                    disabled={isPending}
                    label="Statut de l’action"
                    name="statut_action"
                    onChange={(value) => updateFilter('statut_action', value)}
                    options={actionStatuses}
                    value={currentOption(data.filters.statut_action, actionStatuses)}
                />
                <FilterSelect
                    disabled={isPending}
                    label="Statut de suivi"
                    name="statut_suivi"
                    onChange={(value) => updateFilter('statut_suivi', value)}
                    options={trackingStatuses}
                    value={currentOption(data.filters.statut_suivi, trackingStatuses)}
                />
                <FilterSelect
                    disabled={isPending}
                    label="Respect des délais"
                    name="statut_delai"
                    onChange={(value) => updateFilter('statut_delai', value)}
                    options={delayStatuses}
                    value={currentOption(data.filters.statut_delai, delayStatuses)}
                />
                <FilterSelect
                    disabled={isPending}
                    label="Alerte d’échéance"
                    name="alerte_echeance"
                    onChange={(value) => updateFilter('alerte_echeance', value)}
                    options={deadlineAlerts}
                    value={currentOption(data.filters.alerte_echeance, deadlineAlerts)}
                />
                <FilterSelect
                    disabled={isPending}
                    label="Responsable"
                    name="responsable_id"
                    onChange={(value) => updateFilter('responsable_id', value)}
                    options={responsibles}
                    value={currentOption(data.filters.responsable_id, responsibles)}
                />
            </div>
        </section>
    );
}
