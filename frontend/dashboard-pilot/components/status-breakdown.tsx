import { formatNumber, humanizeKey } from '@/lib/formatters';
import { safeRelativeLink } from '@/lib/dashboard-contract';

export function StatusBreakdown({
    href,
    hrefs,
    title,
    values,
}: {
    href: string;
    hrefs?: Record<string, string | null>;
    title: string;
    values: Record<string, number>;
}) {
    const rows = Object.entries(values)
        .filter(([, value]) => Number.isFinite(value))
        .sort(([, first], [, second]) => second - first);
    const maximum = Math.max(1, ...rows.map(([, value]) => value));

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="font-bold text-slate-950 dark:text-white">{title}</h3>
            {rows.length === 0 ? (
                <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Aucune donnée disponible.</p>
            ) : (
                <ul className="mt-4 space-y-4">
                    {rows.map(([label, value]) => (
                        <li key={label}>
                            <a
                                className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-lg outline-none transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-pilot-600 dark:hover:bg-slate-800"
                                href={safeRelativeLink(hrefs?.[label], href)}
                            >
                                <span className="truncate text-sm text-slate-600 dark:text-slate-300">
                                    {humanizeKey(label)}
                                </span>
                                <strong className="text-sm text-slate-950 dark:text-white">
                                    {formatNumber(value)}
                                </strong>
                                <progress
                                    aria-label={humanizeKey(label)}
                                    className="col-span-2 h-2 w-full accent-emerald-600"
                                    max={maximum}
                                    value={Math.max(0, value)}
                                />
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}
