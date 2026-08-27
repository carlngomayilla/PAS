import type { ReactNode } from 'react';

type Tone = 'green' | 'blue' | 'amber' | 'red' | 'slate';

const toneClasses: Record<Tone, string> = {
    green: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
    blue: 'border-blue-200 bg-blue-50 text-blue-950 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100',
    amber: 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
    red: 'border-red-200 bg-red-50 text-red-950 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100',
    slate: 'border-slate-200 bg-white text-slate-950 dark:border-slate-800 dark:bg-slate-900 dark:text-white',
};

type MetricCardProperties = {
    label: string;
    value: ReactNode;
    description?: string;
    href?: string;
    tone?: Tone;
};

export function MetricCard({
    label,
    value,
    description,
    href,
    tone = 'slate',
}: MetricCardProperties) {
    const content = (
        <>
            <span className="text-sm font-semibold opacity-75">{label}</span>
            <strong className="mt-3 block text-3xl font-black tracking-tight">{value}</strong>
            {description ? <span className="mt-2 block text-sm opacity-75">{description}</span> : null}
            {href ? (
                <span className="mt-4 inline-flex text-xs font-bold uppercase tracking-wider underline decoration-2 underline-offset-4">
                    Voir le détail
                </span>
            ) : null}
        </>
    );
    const classes = `min-h-36 rounded-2xl border p-5 shadow-sm transition ${toneClasses[tone]}`;

    if (href) {
        return (
            <a
                aria-label={`${label} : ouvrir le détail`}
                className={`${classes} hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-pilot-600`}
                href={href}
            >
                {content}
            </a>
        );
    }

    return <article className={classes}>{content}</article>;
}
