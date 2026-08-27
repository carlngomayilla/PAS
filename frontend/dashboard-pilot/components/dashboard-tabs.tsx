'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import {
    type DashboardLinks,
    safeRelativeLink,
} from '@/lib/dashboard-contract';

export function DashboardTabs({ links }: { links: DashboardLinks }) {
    const searchParameters = useSearchParams();
    const query = searchParameters.toString();
    const pilotageLink = query === '' ? '/' : `/?${query}`;
    const tablesLink = safeRelativeLink(links.tables, '/dashboard?dashboardTab=advanced');
    const chartsLink = safeRelativeLink(links.charts, '/dashboard?dashboardTab=charts');

    return (
        <nav aria-label="Sections du tableau de bord" className="overflow-x-auto">
            <div className="inline-flex min-w-full gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:min-w-0">
                <Link
                    aria-current="page"
                    className="flex-1 rounded-xl bg-pilot-700 px-5 py-3 text-center text-sm font-bold text-white shadow-sm focus-visible:outline-2 focus-visible:outline-pilot-600 sm:flex-none"
                    href={pilotageLink}
                >
                    Pilotage
                </Link>
                <a
                    className="flex-1 rounded-xl px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-pilot-600 dark:text-slate-200 dark:hover:bg-slate-800 sm:flex-none"
                    href={tablesLink}
                >
                    Tableaux
                </a>
                <a
                    className="flex-1 rounded-xl px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-pilot-600 dark:text-slate-200 dark:hover:bg-slate-800 sm:flex-none"
                    href={chartsLink}
                >
                    Graphiques
                </a>
            </div>
        </nav>
    );
}
